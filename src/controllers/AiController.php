<?php
require_once __DIR__ . '/../services/AiFinanceContext.php';
require_once __DIR__ . '/../services/AiService.php';
require_once __DIR__ . '/../services/PlanService.php';

class AiController
{
    private PDO $db;
    private AiFinanceContext $ctxBuilder;
    private AiService $ai;
    private PlanService $planService;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ctxBuilder = new AiFinanceContext($db);
        $this->ai = new AiService($db);
        $this->planService = new PlanService($db);
    }

    public function page(): void
    {
        requireLogin();
        $userId = (int)$_SESSION['user_id'];
        $user = (new User($this->db))->findById($userId);
        $plano = PlanService::normalizeSlug($user->plano ?? null);
        $limitInfo = $this->ai->checkRateLimit($userId, $plano);
        $isConfigured = AiService::isConfigured();
        $contextPreview = $this->ctxBuilder->build($userId);

        $pageTitle = 'Assistente Financeiro';
        $pageSubtitle = 'Sua IA analisa suas finanças e responde com base nos seus dados reais.';
        $activeMenu = 'assistente_ia';
        $showPeriodPicker = false;

        // Dados para dashboard insight (se houver tempo)
        require basePath('assistente_ia.php');
    }

    public function chat(): void
    {
        // Produção: erros no log, nunca no output (evita HTML antes do JSON)
        // Causa raiz (curl_close) já corrigida em AiService.php; isto é proteção adicional
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL);
        // Garante JSON limpo — remove qualquer saída anterior (BOM, warnings)
        if (ob_get_length()) ob_clean();
        // Limpa qualquer buffer pendente para garantir headers
        while (ob_get_level() > 0 && ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        // Apenas POST JSON
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false, 'error'=>'Método não permitido.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Valida sessão
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success'=>false, 'error'=>'Não autenticado. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userId = (int)$_SESSION['user_id'];
        $user = (new User($this->db))->findById($userId);
        if (!$user) { http_response_code(401); echo json_encode(['success'=>false, 'error'=>'Usuário não encontrado.'], JSON_UNESCAPED_UNICODE); return; }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;
        $message = trim((string)($data['message'] ?? ''));
        $history = $data['history'] ?? [];
        if (!is_array($history)) $history = [];

        // Validações
        $msgLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($message === '') { http_response_code(400); echo json_encode(['success'=>false, 'error'=>'Digite uma pergunta.'], JSON_UNESCAPED_UNICODE); return; }
        if ($msgLen > 2000) { http_response_code(400); echo json_encode(['success'=>false, 'error'=>'Mensagem muito longa (máx. 2000 caracteres).'], JSON_UNESCAPED_UNICODE); return; }
        // sanitiza histórico: apenas role/content, sem campos extras
        $cleanHistory = [];
        foreach (array_slice($history, -8) as $h) {
            if (!is_array($h) || !isset($h['role'], $h['content'])) continue;
            $role = $h['role'] === 'user' ? 'user' : 'assistant';
            $content = (string)$h['content'];
            $cLen = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
            if ($cLen > 2000) $content = substr($content, 0, 2000);
            $cleanHistory[] = ['role' => $role, 'content' => $content];
        }

        // Rate limit — plano determinado pelo servidor via PlanService
        $planoSlug = PlanService::normalizeSlug($user->plano ?? null);
        $limitInfo = $this->ai->checkRateLimit($userId, $planoSlug);
        if (!$limitInfo['canProceed']) {
            http_response_code(429);
            $planoNome = $this->planService->getPlanDisplayName($planoSlug);
            echo json_encode([
                'success'=>false,
                'error'=>"Limite diário atingido ({$limitInfo['limit']} mensagens/dia no plano {$planoNome}). Tente novamente amanhã.",
                'remaining'=>0
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Detecta intenção para reduzir tokens do contexto enviado à IA
        $intent = AiService::detectIntent($message);

        // Monta contexto financeiro otimizado (sempre isolado por user_id da sessão)
        try {
            $context = $this->ctxBuilder->buildForIntent($userId, $intent);
        } catch (Throwable $e) {
            error_log('[ai context] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false, 'error'=>'Erro ao carregar seus dados financeiros.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Tenta resposta determinística primeiro (economia) — sem expor erros de contagem
        $deterministic = $this->ai->tryDeterministicAnswer($message, $context);
        if ($deterministic !== null) {
            try { $dLen = function_exists('mb_strlen') ? mb_strlen($deterministic) : strlen($deterministic); $this->ai->incrementUsage($userId, (int)($dLen/4)); } catch (Throwable $e) { error_log('[ai] increment deterministic failed: '.$e->getMessage()); }
            $remaining = max(0, $limitInfo['limit'] - $limitInfo['used'] - 1);
            $out = json_encode(['success'=>true, 'response'=>$deterministic, 'reply'=>$deterministic, 'source'=>'deterministic', 'remaining'=>$remaining], JSON_UNESCAPED_UNICODE);
            if ($out === false) { error_log('[ai] json_encode deterministic failed: '.json_last_error_msg()); $out = json_encode(['success'=>true, 'response'=>$deterministic, 'reply'=>$deterministic, 'source'=>'deterministic'], JSON_UNESCAPED_UNICODE); }
            echo $out;
            return;
        }

        // Chama IA
        try {
            $reply = $this->ai->callAi($message, $context, $cleanHistory, $intent);
            try { $mLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message); $rLen = function_exists('mb_strlen') ? mb_strlen($reply) : strlen($reply); $this->ai->incrementUsage($userId, (int)(($mLen+$rLen)/4)); } catch (Throwable $e) { error_log('[ai] increment ai failed: '.$e->getMessage()); }
            $remaining = max(0, $limitInfo['limit'] - $limitInfo['used'] - 1);
            $out = json_encode(['success'=>true, 'response'=>$reply, 'reply'=>$reply, 'source'=>'ai', 'remaining'=>$remaining], JSON_UNESCAPED_UNICODE);
            if ($out === false) { error_log('[ai] json_encode ai failed: '.json_last_error_msg()); $out = json_encode(['success'=>true, 'response'=>$reply, 'reply'=>$reply, 'source'=>'ai'], JSON_UNESCAPED_UNICODE); }
            echo $out;
        } catch (Throwable $e) {
            error_log('[ai call] '.$e->getMessage());
            $msg = $e->getMessage();
            // Erros com hints internos nunca vão para o cliente
            if (str_contains($msg, 'não configurada')) {
                http_response_code(503);
                echo json_encode(['success'=>false, 'error'=>'O assistente financeiro ainda não está configurado no servidor. Configure AI_API_KEY para ativar.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            // Timeout da IA gratuita
            if (str_contains($msg, 'demorou mais que o esperado')) {
                http_response_code(504);
                echo json_encode(['success'=>false, 'error'=>'A IA gratuita demorou mais que o esperado. Tente novamente em alguns instantes.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            // Modelos gratuitos indisponíveis
            if (str_contains($msg, 'temporariamente indisponíveis')) {
                http_response_code(503);
                echo json_encode(['success'=>false, 'error'=>'Os modelos gratuitos estão temporariamente indisponíveis. Tente novamente em alguns instantes.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            // Erro genérico amigável — nunca expõe detalhes internos
            http_response_code(502);
            echo json_encode(['success'=>false, 'error'=>'Não foi possível obter uma resposta da IA. Tente novamente.'], JSON_UNESCAPED_UNICODE);
        }
    }
}

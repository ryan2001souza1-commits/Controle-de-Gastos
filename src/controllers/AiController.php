<?php
require_once __DIR__ . '/../services/AiFinanceContext.php';
require_once __DIR__ . '/../services/AiService.php';

class AiController
{
    private PDO $db;
    private AiFinanceContext $ctxBuilder;
    private AiService $ai;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ctxBuilder = new AiFinanceContext($db);
        $this->ai = new AiService($db);
    }

    public function page(): void
    {
        requireLogin();
        $userId = (int)$_SESSION['user_id'];
        $user = (new User($this->db))->findById($userId);
        $plano = $user->plano ?? 'gratuito';
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
        // Garante JSON limpo — remove qualquer saída anterior (BOM, warnings)
        if (ob_get_length()) ob_clean();
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
        // sanitiza histórico tamanho
        if (count($history) > 20) $history = array_slice($history, -20);

        // Rate limit
        $limitInfo = $this->ai->checkRateLimit($userId, $user->plano ?? 'gratuito');
        if (!$limitInfo['canProceed']) {
            http_response_code(429);
            echo json_encode(['success'=>false, 'error'=>"Limite diário atingido ({$limitInfo['limit']} mensagens/dia no plano ".strtoupper($user->plano)."). Tente novamente amanhã.", 'limit'=>$limitInfo], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Monta contexto financeiro resumido (sempre isolado por user_id da sessão)
        try {
            $context = $this->ctxBuilder->build($userId);
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
            $out = json_encode(['success'=>true, 'response'=>$deterministic, 'reply'=>$deterministic, 'source'=>'deterministic', 'limit'=>$this->ai->checkRateLimit($userId, $user->plano ?? 'gratuito')], JSON_UNESCAPED_UNICODE);
            if ($out === false) { error_log('[ai] json_encode deterministic failed: '.json_last_error_msg()); $out = json_encode(['success'=>true, 'response'=>$deterministic, 'reply'=>$deterministic, 'source'=>'deterministic'], JSON_UNESCAPED_UNICODE); }
            echo $out;
            return;
        }

        // Chama IA
        try {
            $reply = $this->ai->callAi($message, $context, $history);
            try { $mLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message); $rLen = function_exists('mb_strlen') ? mb_strlen($reply) : strlen($reply); $this->ai->incrementUsage($userId, (int)(($mLen+$rLen)/4)); } catch (Throwable $e) { error_log('[ai] increment ai failed: '.$e->getMessage()); }
            $out = json_encode(['success'=>true, 'response'=>$reply, 'reply'=>$reply, 'source'=>'ai', 'limit'=>$this->ai->checkRateLimit($userId, $user->plano ?? 'gratuito')], JSON_UNESCAPED_UNICODE);
            if ($out === false) { error_log('[ai] json_encode ai failed: '.json_last_error_msg()); $out = json_encode(['success'=>true, 'response'=>$reply, 'reply'=>$reply, 'source'=>'ai'], JSON_UNESCAPED_UNICODE); }
            echo $out;
        } catch (Throwable $e) {
            error_log('[ai call] '.$e->getMessage());
            $msg = $e->getMessage();
            if (str_contains($msg, 'não configurada')) {
                $fallback = "IA ainda não configurada no servidor. Mas com base nos seus dados de {$context['periodo']}: receitas ".number_format($context['receitas'],2,',','.').", despesas ".number_format($context['despesas'],2,',','.').", saldo ".number_format($context['saldo'],2,',','.').". Configure AI_API_KEY para respostas completas.";
                http_response_code(200);
                echo json_encode(['success'=>true, 'response'=>$fallback, 'reply'=>$fallback, 'source'=>'fallback', 'warning'=>$msg], JSON_UNESCAPED_UNICODE);
                return;
            }
            $eLen = function_exists('mb_strlen') ? mb_strlen($msg) : strlen($msg);
            $friendly = 'Não foi possível obter uma resposta da IA. Tente novamente.';
            if ($eLen < 200 && $msg !== '') $friendly .= ' ('.$msg.')';
            http_response_code(502);
            echo json_encode(['success'=>false, 'error'=>$friendly], JSON_UNESCAPED_UNICODE);
        }
    }
}

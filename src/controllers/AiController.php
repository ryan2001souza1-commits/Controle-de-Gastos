<?php
require_once __DIR__ . '/../services/AiFinanceContext.php';
require_once __DIR__ . '/../services/AiService.php';
require_once __DIR__ . '/../services/PlanService.php';
require_once __DIR__ . '/../services/AiLimitService.php';

class AiController
{
    private PDO $db;
    private AiFinanceContext $ctxBuilder;
    private AiService $ai;
    private PlanService $planService;
    private AiLimitService $aiLimitService;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ctxBuilder = new AiFinanceContext($db);
        $this->ai = new AiService($db);
        $this->planService = new PlanService($db);
        $this->aiLimitService = new AiLimitService($db, $this->planService, $this->ai);
    }

    public function page(): void
    {
        requireLogin();
        $userId = (int)$_SESSION['user_id'];

        $limitInfo = [
            'used' => $this->aiLimitService->countToday($userId),
            'limit' => $this->aiLimitService->getLimit($userId) ?? PHP_INT_MAX,
        ];

        $isConfigured = AiService::isConfigured();
        $contextPreview = $this->ctxBuilder->build($userId);

        $canHistory = $this->planService->userHasFeature($userId, 'ia_history');
        $canUpload  = $this->planService->userHasFeature($userId, 'ia_upload');
        $canImages  = $this->planService->userHasFeature($userId, 'ia_images');
        $availableModels = $this->planService->getAvailableModels($userId);
        $firstModel = $this->planService->getFirstAvailableModel($userId);

        $userModel = new User($this->db);
        $user = $userModel->findById($userId);
        $planoLabel = strtoupper($user->plano ?? 'GRATUITO');

        $pageTitle = 'Assistente Financeiro';
        $pageSubtitle = 'Sua IA analisa suas finanças e responde com base nos seus dados reais.';
        $activeMenu = 'assistente_ia';
        $showPeriodPicker = false;

        require basePath('assistente_ia.php');
    }

    public function chat(): void
    {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL);
        if (ob_get_length()) ob_clean();
        while (ob_get_level() > 0 && ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false, 'error'=>'Metodo nao permitido.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success'=>false, 'error'=>'Nao autenticado. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userId = (int)$_SESSION['user_id'];

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;
        $message = trim((string)($data['message'] ?? ''));

        // Bloqueio: upload de arquivos (PRO/PREMIUM)
        if (($data['upload'] ?? false) && !$this->planService->userHasFeature($userId, 'ia_upload')) {
            http_response_code(403);
            echo json_encode(['success'=>false, 'error'=>'Upload disponivel apenas nos planos PRO e PREMIUM.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bloqueio: geracao de imagens (PREMIUM)
        if (($data['generateImage'] ?? false) && !$this->planService->userHasFeature($userId, 'ia_images')) {
            http_response_code(403);
            echo json_encode(['success'=>false, 'error'=>'Geracao de imagens disponivel apenas no plano PREMIUM.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Rate limit — via AiLimitService (PlanService como fonte)
        $check = $this->aiLimitService->check($userId);
        if (!$check['allowed']) {
            http_response_code(429);
            $limite = $this->aiLimitService->getLimit($userId);
            echo json_encode([
                'success'=>false,
                'error'=>'Voce atingiu o limite diario do seu plano.',
                'remaining'=>0
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Modelo: sanitizado pelo backend — substitui se indisponivel
        $requestedModel = (string)($data['model'] ?? '');
        if ($requestedModel === '') {
            $requestedModel = $this->planService->getFirstAvailableModel($userId);
        } elseif (!$this->planService->isModelAllowedForUser($userId, $requestedModel)) {
            $requestedModel = $this->planService->getFirstAvailableModel($userId);
        }

        // Historico: FREE ignora completamente; PRO/PREMIUM salva
        $hasHistory = $this->planService->userHasFeature($userId, 'ia_history');
        if ($hasHistory) {
            $history = $data['history'] ?? [];
            if (!is_array($history)) $history = [];
            $cleanHistory = [];
            foreach (array_slice($history, -8) as $h) {
                if (!is_array($h) || !isset($h['role'], $h['content'])) continue;
                $role = $h['role'] === 'user' ? 'user' : 'assistant';
                $content = (string)$h['content'];
                $cLen = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
                if ($cLen > 2000) $content = substr($content, 0, 2000);
                $cleanHistory[] = ['role' => $role, 'content' => $content];
            }
        } else {
            $cleanHistory = [];
        }

        // Validacoes de mensagem
        $msgLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($message === '') {
            http_response_code(400);
            echo json_encode(['success'=>false, 'error'=>'Digite uma pergunta.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($msgLen > 2000) {
            http_response_code(400);
            echo json_encode(['success'=>false, 'error'=>'Mensagem muito longa (max. 2000 caracteres).'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Detecta intencao
        $intent = AiService::detectIntent($message);

        // Contexto financeiro
        try {
            $context = $this->ctxBuilder->buildForIntent($userId, $intent);
        } catch (Throwable $e) {
            error_log('[ai context] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false, 'error'=>'Erro ao carregar seus dados financeiros.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Resposta deterministica
        $deterministic = $this->ai->tryDeterministicAnswer($message, $context);
        if ($deterministic !== null) {
            try { $dLen = function_exists('mb_strlen') ? mb_strlen($deterministic) : strlen($deterministic); $this->aiLimitService->incrementUsage($userId, (int)($dLen/4)); } catch (Throwable $e) { error_log('[ai] increment deterministic failed: '.$e->getMessage()); }
            $remaining = $this->aiLimitService->getRemaining($userId);
            $remainingVal = $remaining === null ? PHP_INT_MAX : $remaining;
            $out = json_encode(['success'=>true, 'response'=>$deterministic, 'reply'=>$deterministic, 'source'=>'deterministic', 'remaining'=>max(0, $remainingVal-1)], JSON_UNESCAPED_UNICODE);
            if ($out === false) { error_log('[ai] json_encode deterministic failed: '.json_last_error_msg()); $out = json_encode(['success'=>true, 'response'=>$deterministic, 'reply'=>$deterministic, 'source'=>'deterministic'], JSON_UNESCAPED_UNICODE); }
            echo $out;
            return;
        }

        // Chama IA
        try {
            $reply = $this->ai->callAi($message, $context, $cleanHistory, $intent);
            try { $mLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message); $rLen = function_exists('mb_strlen') ? mb_strlen($reply) : strlen($reply); $this->aiLimitService->incrementUsage($userId, (int)(($mLen+$rLen)/4)); } catch (Throwable $e) { error_log('[ai] increment ai failed: '.$e->getMessage()); }
            $remaining = $this->aiLimitService->getRemaining($userId);
            $remainingVal = $remaining === null ? PHP_INT_MAX : $remaining;
            $out = json_encode(['success'=>true, 'response'=>$reply, 'reply'=>$reply, 'source'=>'ai', 'remaining'=>max(0, $remainingVal-1)], JSON_UNESCAPED_UNICODE);
            if ($out === false) { error_log('[ai] json_encode ai failed: '.json_last_error_msg()); $out = json_encode(['success'=>true, 'response'=>$reply, 'reply'=>$reply, 'source'=>'ai'], JSON_UNESCAPED_UNICODE); }
            echo $out;
        } catch (Throwable $e) {
            error_log('[ai call] '.$e->getMessage());
            $msg = $e->getMessage();
            if (str_contains($msg, 'nao configurada')) {
                http_response_code(503);
                echo json_encode(['success'=>false, 'error'=>'O assistente financeiro ainda nao esta configurado no servidor. Configure AI_API_KEY para ativar.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if (str_contains($msg, 'demorou mais que o esperado')) {
                http_response_code(504);
                echo json_encode(['success'=>false, 'error'=>'A IA gratuita demorou mais que o esperado. Tente novamente em alguns instantes.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if (str_contains($msg, 'temporariamente indisponiveis')) {
                http_response_code(503);
                echo json_encode(['success'=>false, 'error'=>'Os modelos gratuitos estao temporariamente indisponiveis. Tente novamente em alguns instantes.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            http_response_code(502);
            echo json_encode(['success'=>false, 'error'=>'Nao foi possivel obter uma resposta da IA. Tente novamente.'], JSON_UNESCAPED_UNICODE);
        }
    }
}


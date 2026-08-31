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
        header('Content-Type: application/json; charset=utf-8');
        // Apenas POST JSON
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error'=>'Método não permitido.']);
            return;
        }
        // Valida sessão
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['error'=>'Não autenticado. Faça login novamente.']);
            return;
        }
        $userId = (int)$_SESSION['user_id'];
        $user = (new User($this->db))->findById($userId);
        if (!$user) { http_response_code(401); echo json_encode(['error'=>'Usuário não encontrado.']); return; }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;
        $message = trim((string)($data['message'] ?? ''));
        $history = $data['history'] ?? [];
        if (!is_array($history)) $history = [];

        // Validações
        $msgLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($message === '') { http_response_code(400); echo json_encode(['error'=>'Digite uma pergunta.']); return; }
        if ($msgLen > 2000) { http_response_code(400); echo json_encode(['error'=>'Mensagem muito longa (máx. 2000 caracteres).']); return; }
        // sanitiza histórico tamanho
        if (count($history) > 20) $history = array_slice($history, -20);

        // Rate limit
        $limitInfo = $this->ai->checkRateLimit($userId, $user->plano ?? 'gratuito');
        if (!$limitInfo['canProceed']) {
            http_response_code(429);
            echo json_encode(['error'=>"Limite diário atingido ({$limitInfo['limit']} mensagens/dia no plano ".strtoupper($user->plano)."). Tente novamente amanhã.", 'limit'=>$limitInfo]);
            return;
        }

        // Monta contexto financeiro resumido (sempre isolado por user_id da sessão)
        try {
            $context = $this->ctxBuilder->build($userId);
        } catch (Throwable $e) {
            error_log('[ai context] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['error'=>'Erro ao carregar seus dados financeiros.']);
            return;
        }

        // Tenta resposta determinística primeiro (economia)
        $deterministic = $this->ai->tryDeterministicAnswer($message, $context);
        if ($deterministic !== null) {
            $dLen = function_exists('mb_strlen') ? mb_strlen($deterministic) : strlen($deterministic);
            $this->ai->incrementUsage($userId, (int)($dLen/4));
            echo json_encode(['reply'=>$deterministic, 'source'=>'deterministic', 'context'=>$context, 'limit'=>$this->ai->checkRateLimit($userId, $user->plano ?? 'gratuito')]);
            return;
        }

        // Chama IA
        try {
            $reply = $this->ai->callAi($message, $context, $history);
            $mLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
            $rLen = function_exists('mb_strlen') ? mb_strlen($reply) : strlen($reply);
            $tokensApprox = (int)(($mLen+$rLen)/4);
            $this->ai->incrementUsage($userId, $tokensApprox);
            echo json_encode(['reply'=>$reply, 'source'=>'ai', 'limit'=>$this->ai->checkRateLimit($userId, $user->plano ?? 'gratuito')]);
        } catch (Throwable $e) {
            error_log('[ai call] '.$e->getMessage());
            // Não expõe stack, mensagem amigável
            $msg = $e->getMessage();
            // Se não configurada, oferece fallback com contexto
            if (str_contains($msg, 'não configurada')) {
                $fallback = "IA ainda não configurada no servidor. Mas com base nos seus dados de {$context['periodo']}: receitas ".number_format($context['receitas'],2,',','.').", despesas ".number_format($context['despesas'],2,',','.').", saldo ".number_format($context['saldo'],2,',','.').". Configure AI_API_KEY para respostas completas.";
                http_response_code(200);
                echo json_encode(['reply'=>$fallback, 'source'=>'fallback', 'warning'=>$msg]);
                return;
            }
            $eLen = function_exists('mb_strlen') ? mb_strlen($msg) : strlen($msg);
            http_response_code(502);
            echo json_encode(['error'=>'Não consegui responder agora. ' . ($eLen < 200 ? $msg : 'Tente novamente em instantes.')]);
        }
    }
}

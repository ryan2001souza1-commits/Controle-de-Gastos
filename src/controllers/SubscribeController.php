<?php
/**
 * SubscribeController — primeira etapa funcional da integracao oficial.
 *
 * POST /index.php?action=subscribe_start
 *
 * Fluxo:
 *  1. valida metodo, autenticacao (sessao) e CSRF (feito no front controller);
 *  2. valida plan=pro|premium contra o catalogo interno (preco do catalogo);
 *  3. registra tentativa local como pending (SEM alterar usuarios.plano);
 *  4. chama POST /preapproval na API oficial via MercadoPagoClient;
 *  5. grava mp_preapproval_id/raw_status/checkout_url e devolve init_point.
 *
 * PROIBIDO aqui: webhook, ativacao de plano pago, preco do frontend,
 * user_id do navegador, token no frontend/logs/banco.
 */
class SubscribeController
{
    private PDO $db;
    private User $userModel;
    private PlanService $planService;
    private ?MercadoPagoClient $client;

    private const ALLOWED_SLUGS = ['pro', 'premium'];
    private const REUSE_WINDOW = '15 minutes';

    public function __construct(PDO $db, User $userModel, PlanService $planService, ?MercadoPagoClient $client = null)
    {
        $this->db = $db;
        $this->userModel = $userModel;
        $this->planService = $planService;
        $this->client = $client;
    }

    public function start(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(405, ['success' => false, 'error' => 'metodo_nao_permitido']);
            return;
        }
        if (!isLoggedIn()) {
            $this->json(401, ['success' => false, 'error' => 'nao_autenticado']);
            return;
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->json(401, ['success' => false, 'error' => 'nao_autenticado']);
            return;
        }

        $input = $_POST;
        if (!isset($input['plan']) && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
        // O frontend envia SOMENTE plan. Qualquer user_id/price/amount vindo
        // do navegador e deliberadamente ignorado (fonte: sessao + catalogo).
        $plan = strtolower(trim((string)($input['plan'] ?? '')));
        if (!in_array($plan, self::ALLOWED_SLUGS, true)) {
            $this->json(400, ['success' => false, 'error' => 'plano_invalido']);
            return;
        }

        try {
            $user = $this->userModel->findById($userId);
        } catch (Throwable $e) {
            error_log('[subscribe_start] db user err=' . $e->getMessage());
            $this->json(500, ['success' => false, 'error' => 'erro_banco']);
            return;
        }
        if (!$user) {
            $this->json(401, ['success' => false, 'error' => 'nao_autenticado']);
            return;
        }

        // Preco e dados do plano SEMPRE do catalogo interno.
        try {
            $plano = $this->planService->getPlanoBySlug($plan);
            $price = $this->planService->getPlanNumericPrice($plan);
            BillingSyncService::catalogPriceOrFail($price, $plan);
        } catch (Throwable $e) {
            error_log('[subscribe_start] catalogo plan=' . $plan . ' err=' . $e->getMessage());
            $this->json(503, ['success' => false, 'error' => 'catalogo_sem_preco']);
            return;
        }

        $accessToken = MercadoPagoClient::readEnv(MercadoPagoClient::ENV_ACCESS_TOKEN);
        if ($accessToken === '') {
            $this->json(503, ['success' => false, 'error' => 'mp_not_configured']);
            return;
        }
        $mpPlanId = MercadoPagoClient::readEnv($plan === 'pro' ? 'MERCADOPAGO_PLAN_ID_PRO' : 'MERCADOPAGO_PLAN_ID_PREMIUM');
        if ($mpPlanId === '') {
            $this->json(503, ['success' => false, 'error' => 'mp_plan_not_configured']);
            return;
        }

        try {
            // Idempotencia de double-click: tentativa pending recente do mesmo
            // usuario+plano que ja tenha checkout_url e reutilizada.
            $reuse = $this->findReusableAttempt($userId, $plan);
            if ($reuse !== null) {
                error_log("[subscribe_start] user={$userId} plan={$plan} reused=1 sub={$reuse['id']}");
                $this->json(200, ['success' => true, 'checkout_url' => $reuse['checkout_url'], 'reused' => true]);
                return;
            }

            $attemptToken = bin2hex(random_bytes(16));
            $externalRef = BillingSyncService::buildExternalReference($userId, $plan, $attemptToken);

            $ins = $this->db->prepare(
                'INSERT INTO subscriptions (user_id, plan_id, plan_slug, status, provider, provider_plan_id, attempt_token, external_reference)
                 VALUES (?, ?, ?, \'pending\', \'mercadopago\', ?, ?, ?)'
            );
            $ins->execute([$userId, (int)$plano['id'], $plan, $mpPlanId, $attemptToken, $externalRef]);
            $attemptId = (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log("[subscribe_start] user={$userId} plan={$plan} err=erro_banco");
            $this->json(500, ['success' => false, 'error' => 'erro_banco']);
            return;
        }

        $payload = [
            'preapproval_plan_id' => $mpPlanId,
            'reason'              => 'Plano ' . $plano['nome'] . ' — Controle de Gastos',
            'external_reference'  => $externalRef,
            'payer_email'         => $user->email,
        ];
        $backUrl = $this->backUrl();
        if ($backUrl !== '') {
            $payload['back_url'] = $backUrl;
        }

        $client = $this->client ?? new MercadoPagoClient($accessToken);
        try {
            $resp = $client->createPreapproval($payload);
        } catch (MercadoPagoException $e) {
            $this->markAttemptFailed($attemptId, $userId);
            $http = $this->httpForClientError($e->getErrorCode());
            error_log("[subscribe_start] user={$userId} plan={$plan} attempt={$attemptId} err={$e->getErrorCode()}");
            $this->json($http, ['success' => false, 'error' => $e->getErrorCode()]);
            return;
        } catch (Throwable $e) {
            $this->markAttemptFailed($attemptId, $userId);
            error_log("[subscribe_start] user={$userId} plan={$plan} attempt={$attemptId} err=mp_unexpected");
            $this->json(502, ['success' => false, 'error' => 'mp_erro']);
            return;
        }

        $mpId = isset($resp['id']) ? trim((string)$resp['id']) : '';
        $initPoint = isset($resp['init_point']) ? trim((string)$resp['init_point']) : '';
        $rawStatus = substr(trim((string)($resp['status'] ?? 'pending')), 0, 40);
        if ($mpId === '' || !$this->isHttpsUrl($initPoint)) {
            $this->markAttemptFailed($attemptId, $userId);
            error_log("[subscribe_start] user={$userId} plan={$plan} attempt={$attemptId} err=checkout_indisponivel");
            $this->json(502, ['success' => false, 'error' => 'checkout_indisponivel']);
            return;
        }

        try {
            $upd = $this->db->prepare(
                'UPDATE subscriptions SET mp_preapproval_id = ?, raw_status = ?, checkout_url = ?, updated_at = NOW()
                 WHERE id = ? AND user_id = ?'
            );
            $upd->execute([$mpId, $rawStatus !== '' ? $rawStatus : 'pending', $initPoint, $attemptId, $userId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('attempt update mismatch');
            }
        } catch (Throwable $e) {
            error_log("[subscribe_start] user={$userId} plan={$plan} attempt={$attemptId} err=erro_banco");
            $this->json(500, ['success' => false, 'error' => 'erro_banco']);
            return;
        }

        // CRIAR PREAPPROVAL NAO E PAGAMENTO APROVADO: usuarios.plano segue intacto.
        error_log("[subscribe_start] user={$userId} plan={$plan} attempt={$attemptId} sub={$mpId} ok=1");
        $this->json(200, ['success' => true, 'checkout_url' => $initPoint, 'reused' => false]);
    }

    /**
     * @return array{id:int, checkout_url:string}|null
     */
    private function findReusableAttempt(int $userId, string $plan): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, checkout_url FROM subscriptions
              WHERE user_id = ? AND plan_slug = ? AND status = 'pending' AND provider = 'mercadopago'
                AND checkout_url IS NOT NULL AND checkout_url <> ''
                AND created_at > NOW() - INTERVAL '" . self::REUSE_WINDOW . "'
              ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId, $plan]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)($row['id'] ?? 0) <= 0 || trim((string)($row['checkout_url'] ?? '')) === '') {
            return null;
        }
        return ['id' => (int)$row['id'], 'checkout_url' => (string)$row['checkout_url']];
    }

    private function markAttemptFailed(int $attemptId, int $userId): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE subscriptions SET raw_status = 'init_error', updated_at = NOW()
                  WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$attemptId, $userId]);
        } catch (Throwable $e) {
            error_log('[subscribe_start] mark failed err=' . $e->getMessage());
        }
    }

    private function backUrl(): string
    {
        $env = MercadoPagoClient::readEnv('APP_URL');
        if ($env !== '') {
            return rtrim($env, '/') . '/index.php?action=meu_plano';
        }
        return '';
    }

    private function isHttpsUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2000) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function httpForClientError(string $code): int
    {
        return match ($code) {
            'mp_not_configured', 'mp_plan_not_configured', 'catalogo_sem_preco' => 503,
            'mp_timeout' => 504,
            default => 502,
        };
    }

    private function json(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

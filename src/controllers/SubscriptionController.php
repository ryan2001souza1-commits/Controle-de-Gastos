<?php
/**
 * SubscriptionController — handlers de criacao/cancelamento de assinatura.
 *
 * Apenas o frontend logado pode chamar (CSRF + requireLogin).
 * O return URL NAO e gerenciado por este controller (apenas UX em
 * public/mercadopago_return.php).
 */
class SubscriptionController
{
    private PDO $db;
    private User $userModel;
    private PlanService $planService;
    private Subscription $subscriptions;
    private MercadoPagoService $mp;

    public function __construct(
        PDO $db,
        User $userModel,
        PlanService $planService,
        Subscription $subscriptions,
        MercadoPagoService $mp
    ) {
        $this->db = $db;
        $this->userModel = $userModel;
        $this->planService = $planService;
        $this->subscriptions = $subscriptions;
        $this->mp = $mp;
    }

    /**
     * POST /index.php?action=subscription_create
     * Body: plan_slug (pro|premium), csrf_token
     * Resposta: redirect 302 para init_point do MP.
     */
    public function create(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Location: /?action=meu_plano&error=method');
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $user = $this->userModel->findById($userId);
        if (!$user) {
            header('Location: /?action=login');
            return;
        }

        $planSlug = PlanService::normalizeSlug((string)($_POST['plan_slug'] ?? ''));
        if (!in_array($planSlug, [PlanService::SLUG_PRO, PlanService::SLUG_PREMIUM], true)) {
            error_log('[SubscriptionController] plano_slug invalido: ' . var_export($planSlug, true));
            header('Location: /?action=meu_plano&error=invalid_plan');
            return;
        }

        $plan = $this->planService->getPlanoBySlug($planSlug);
        if ($plan === null) {
            error_log('[SubscriptionController] plano nao encontrado: ' . $planSlug);
            header('Location: /?action=meu_plano&error=plan_not_found');
            return;
        }

        $existing = $this->subscriptions->findActiveByUserId($userId);
        if ($existing !== null) {
            error_log('[SubscriptionController] assinatura ja existe para user_id=' . $userId . ' slug=' . $planSlug);
            header('Location: /?action=meu_plano&error=already_subscribed');
            return;
        }

        $planIdEnvKey = 'MERCADOPAGO_PLAN_ID_' . strtoupper($planSlug);
        $planId = (string)(getenv($planIdEnvKey) ?: '');
        if ($planId === '') {
            error_log('[SubscriptionController] plano MP nao configurado: ' . $planSlug . ' env=' . $planIdEnvKey);
            header('Location: /?action=meu_plano&error=plan_not_configured');
            return;
        }

        $extRef = 'user_' . $userId . '_' . $planSlug;
        $appUrl = rtrim((string)(getenv('APP_URL') ?: 'https://example.com'), '/');
        $backUrl = $appUrl . '/mercadopago_return.php?ref=' . urlencode($extRef);

        if (getenv('CSRF_DIAG') === '1') {
            error_log('[CSRF_DIAG-sub_create] planSlug=' . $planSlug
                . ' planId=' . substr($planId, 0, 8) . '...'
                . ' planIdEnvKey=' . $planIdEnvKey
                . ' extRef=' . $extRef
            );
        }

        $planResp = $this->mp->getPreapprovalPlan($planId);
        $checkoutUrl = '';
        if ($planResp['ok']) {
            $baseUrl = (string)($planResp['data']['init_point']
                ?? $planResp['data']['sandbox_init_point']
                ?? '');
            if ($baseUrl !== '') {
                $separator = (str_contains($baseUrl, '?')) ? '&' : '?';
                $checkoutUrl = $baseUrl
                    . $separator . 'external_reference=' . urlencode($extRef)
                    . '&back_url=' . urlencode($backUrl);
            }
        }

        if (getenv('CSRF_DIAG') === '1') {
            $hasInit = !empty($planResp['data']['init_point']);
            $hasSand = !empty($planResp['data']['sandbox_init_point']);
            error_log('[CSRF_DIAG-sub_create] API GET /preapproval_plan/' . substr($planId, 0, 8) . '...: http='
                . ($planResp['status'] ?? 0) . ' ok=' . ($planResp['ok'] ? '1' : '0')
                . ' has_init_point=' . ($hasInit ? '1' : '0')
                . ' has_sandbox=' . ($hasSand ? '1' : '0')
                . ' checkout_url=' . ($checkoutUrl !== '' ? substr($checkoutUrl, 0, 80) . '...' : 'EMPTY')
                . ' error=' . ($planResp['error'] ?? 'none')
            );
        }

        if ($checkoutUrl === '') {
            error_log('[SubscriptionController] getPreapprovalPlan falhou para planId=' . substr($planId, 0, 8)
                . ' http=' . ($planResp['status'] ?? 0)
                . ' error=' . ($planResp['error'] ?? 'unknown')
            );
            header('Location: /?action=meu_plano&error=mp_create_failed');
            return;
        }

        if (getenv('CSRF_DIAG') === '1') {
            error_log('[CSRF_DIAG-sub_create] REDIRECT to checkout: ' . substr($checkoutUrl, 0, 80) . '...');
        }

        header('Location: ' . $checkoutUrl);
        return;
    }

    /**
     * POST /index.php?action=subscription_cancel
     * Body: csrf_token
     * Resposta: redirect para meu_plano.
     */
    public function cancel(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Location: /?action=meu_plano&error=method');
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $active = $this->subscriptions->findActiveByUserId($userId);
        if ($active === null) {
            header('Location: /?action=meu_plano&error=no_active_subscription');
            return;
        }

        $mpId = (string)($active['mp_preapproval_id'] ?? '');
        $resp = $this->mp->cancelPreapproval($mpId);

        if (!$resp['ok']) {
            error_log('[SubscriptionController] cancel falhou no MP: ' . ($resp['error'] ?? 'unknown'));
        }

        // Independente do resultado no MP, marca como cancelled localmente.
        // O webhook vai reconciliar se necessario.
        $this->subscriptions->updateStatusByMpId(
            $mpId,
            Subscription::STATUS_CANCELLED,
            'cancelled_by_user',
            null,
            null
        );
        $updated = $this->subscriptions->findByMpPreapprovalId($mpId);
        if ($updated !== null) {
            $this->subscriptions->applyStatusToUser($updated);
        }

        header('Location: /?action=meu_plano&cancelled=1');
        return;
    }
}

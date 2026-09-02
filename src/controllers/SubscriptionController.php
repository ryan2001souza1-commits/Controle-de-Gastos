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
            header('Location: /?action=meu_plano&error=invalid_plan');
            return;
        }

        $plan = $this->planService->getPlanoBySlug($planSlug);
        if ($plan === null) {
            header('Location: /?action=meu_plano&error=plan_not_found');
            return;
        }

        // Impede multiplas assinaturas ativas para o mesmo usuario+plano
        $existing = $this->subscriptions->findActiveByUserId($userId);
        if ($existing !== null) {
            header('Location: /?action=meu_plano&error=already_subscribed');
            return;
        }

        $planId = (string)(getenv('MERCADOPAGO_PLAN_ID_' . strtoupper($planSlug)) ?: '');
        if ($planId === '') {
            error_log('[SubscriptionController] plano MP nao configurado: ' . $planSlug);
            header('Location: /?action=meu_plano&error=plan_not_configured');
            return;
        }

        $extRef = 'user_' . $userId . '_' . $planSlug;
        $appUrl = rtrim((string)(getenv('APP_URL') ?: 'https://example.com'), '/');
        $backUrl = $appUrl . '/mercadopago_return.php?ref=' . urlencode($extRef);

        $resp = $this->mp->createPreapproval(
            $planId,
            (string)$user->email,
            $extRef,
            $backUrl,
            'Assinatura ' . $plan['nome']
        );

        if (!$resp['ok'] || empty($resp['data']['id']) || empty($resp['data']['init_point'])) {
            error_log('[SubscriptionController] createPreapproval falhou: ' . ($resp['error'] ?? 'unknown'));
            header('Location: /?action=meu_plano&error=mp_create_failed');
            return;
        }

        $pre = $resp['data'];
        $pre['_user_id_local'] = $userId;
        $pre['_plan_id_local'] = (int)$plan['id'];
        $pre['_plan_slug_local'] = $planSlug;

        try {
            $subId = $this->subscriptions->createFromPreapproval($pre);
            $_SESSION['pending_subscription_id'] = $subId;
        } catch (PDOException $e) {
            // Se for UNIQUE em mp_preapproval_id, redireciona para o init_point mesmo assim
            if (!(str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate'))) {
                throw $e;
            }
        }

        header('Location: ' . (string)$pre['init_point']);
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

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
     * Body: plan_slug, card_token_id, csrf_token
     * Resposta: redirect 302 para meu_plano (com status).
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

        $cardTokenId = trim((string)($_POST['card_token_id'] ?? ''));
        if ($cardTokenId === '') {
            header('Location: /?action=meu_plano&error=missing_card_token');
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

        $resp = $this->mp->createPreapproval(
            $planId,
            $user->email,
            $cardTokenId,
            $extRef,
            $backUrl,
            'Assinatura ' . $plan['nome'] . ' - Controle de Gastos'
        );

        if (!$resp['ok']) {
            $mpId  = (string)($resp['data']['id'] ?? '');
            $err   = (string)($resp['error'] ?? 'unknown');
            $tokenSig = substr($cardTokenId, 0, 4) . '...' . substr($cardTokenId, -4);
            error_log('[SubscriptionController] createPreapproval falhou para user=' . $userId
                . ' slug=' . $planSlug
                . ' http=' . $resp['status']
                . ' mp_id=' . ($mpId !== '' ? $mpId : 'none')
                . ' token_sig=' . $tokenSig
                . ' error=' . $err
            );
            header('Location: /?action=meu_plano&error=mp_create_failed');
            return;
        }

        $mpId = (string)($resp['data']['id'] ?? '');
        $mpStatus = (string)($resp['data']['status'] ?? 'pending');
        if ($mpId === '') {
            error_log('[SubscriptionController] createPreapproval OK sem id retornado');
            header('Location: /?action=meu_plano&error=mp_no_id');
            return;
        }

        $resp['data']['external_reference'] = $extRef;
        $resp['data']['_user_id_local']    = $userId;
        $resp['data']['_plan_id_local']    = (int)($plan['id'] ?? 0);
        $resp['data']['_plan_slug_local']  = $planSlug;

        $this->subscriptions->createFromPreapproval($resp['data']);

        $row = $this->subscriptions->findByMpPreapprovalId($mpId);
        if ($row !== null) {
            $this->subscriptions->applyStatusToUser($row);
        }

        header('Location: /?action=meu_plano&subscribed=1');
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

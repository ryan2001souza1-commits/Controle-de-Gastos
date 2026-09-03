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

        $mpIdMasked = '';
        $respKeys = is_array($resp['data'] ?? null) ? array_keys($resp['data']) : [];
        $mpStatus = (string)($resp['data']['status'] ?? 'none');
        $mpPlanId = (string)($resp['data']['preapproval_plan_id'] ?? '');
        $respNext = isset($resp['data']['next_payment_date']) ? (string)$resp['data']['next_payment_date'] : 'none';
        $respPayerId = isset($resp['data']['payer_id']) ? (string)$resp['data']['payer_id'] : 'none';
        $respAuto = isset($resp['data']['auto_recurring']) && is_array($resp['data']['auto_recurring'])
            ? sprintf(
                'amount=%s currency=%s freq=%s/%s',
                (string)($resp['data']['auto_recurring']['transaction_amount'] ?? 'n/a'),
                (string)($resp['data']['auto_recurring']['currency_id'] ?? 'n/a'),
                (string)($resp['data']['auto_recurring']['frequency'] ?? 'n/a'),
                (string)($resp['data']['auto_recurring']['frequency_type'] ?? 'n/a')
            )
            : 'none';
        if (!$resp['ok']) {
            $mpId  = (string)($resp['data']['id'] ?? '');
            $mpIdMasked = ($mpId !== '' ? substr($mpId, 0, 3) . '…' . substr($mpId, -3) : 'none');
            $err   = (string)($resp['error'] ?? 'unknown');
            $errCode = (string)($resp['data']['code'] ?? '');
            $errMsg  = (string)($resp['data']['message'] ?? '');
            error_log('[SubscriptionController] createPreapproval FAILED user=' . $userId
                . ' slug=' . $planSlug
                . ' http=' . $resp['status']
                . ' mp_id=' . $mpIdMasked
                . ' error=' . $err
                . ' api_code=' . $errCode
                . ' api_msg=' . substr($errMsg, 0, 200)
            );
            header('Location: /?action=meu_plano&error=mp_create_failed');
            return;
        }

        $mpId = (string)($resp['data']['id'] ?? '');
        $mpIdMasked = ($mpId !== '' ? substr($mpId, 0, 3) . '…' . substr($mpId, -3) : 'none');
        error_log('[SubscriptionController] createPreapproval OK user=' . $userId
            . ' slug=' . $planSlug
            . ' http=' . $resp['status']
            . ' mp_id=' . $mpIdMasked
            . ' mp_status=' . $mpStatus
            . ' mp_plan_id=' . ($mpPlanId !== '' ? substr($mpPlanId, 0, 3) . '…' . substr($mpPlanId, -3) : 'none')
            . ' next_payment=' . $respNext
            . ' payer_id=' . ($respPayerId !== 'none' ? substr($respPayerId, 0, 3) . '…' : 'none')
            . ' auto=' . $respAuto
            . ' resp_keys=' . implode(',', $respKeys)
        );

        if ($mpId === '') {
            error_log('[SubscriptionController] createPreapproval OK sem id retornado');
            header('Location: /?action=meu_plano&error=mp_no_id');
            return;
        }

        $resp['data']['external_reference'] = $extRef;
        $resp['data']['_user_id_local']    = $userId;
        $resp['data']['_plan_id_local']    = (int)($plan['id'] ?? 0);
        $resp['data']['_plan_slug_local']  = $planSlug;

        $subId = $this->subscriptions->createFromPreapproval($resp['data']);
        error_log('[SubscriptionController] createFromPreapproval persisted user=' . $userId
            . ' sub_id=' . $subId
            . ' mp_id=' . $mpIdMasked
        );

        // Armazena mercadopago_payer_id no usuario local, somente se for
        // seguro (preapproval_id jah vinculado a este usuario e payer_id
        // ainda nao pertence a OUTRO usuario local).
        $mpPayerId = (string)($resp['data']['payer_id'] ?? '');
        if ($mpPayerId !== '') {
            $this->linkMpPayerIdToUser($userId, $mpPayerId, $mpId);
        }

        $row = $this->subscriptions->findByMpPreapprovalId($mpId);
        if ($row !== null) {
            $applied = $this->subscriptions->applyStatusToUser($row);
            error_log('[SubscriptionController] applyStatusToUser user=' . $userId
                . ' sub_id=' . (int)($row['id'] ?? 0)
                . ' status=' . (string)($row['status'] ?? 'none')
                . ' applied=' . ($applied ? '1' : '0')
            );
        } else {
            error_log('[SubscriptionController] row not found after insert mp_id=' . $mpIdMasked);
        }

        header('Location: /?action=meu_plano&subscribed=1');
        return;
    }

    /**
     * Associa o mercadopago_payer_id ao usuario local SOMENTE se for
     * seguro:
     *   - NUNCA sobrescreve um payer_id ja gravado para outro usuario
     *     (o indice UNIQUE parcial ja protege; aqui detecta o conflito
     *     e aborta de forma controlada).
     *   - NUNCA atualiza se o usuario logado ja tem outro payer_id
     *     diferente (evita troca de identidade em cenarios de
     *     compartilhamento de conta MP entre login e pagamento).
     *
     * Em caso de conflito, registra erro tecnico e nao quebra o fluxo
     * de assinatura (que jah foi criada localmente). A vinculacao
     * tera de ser revisada manualmente.
     */
    private function linkMpPayerIdToUser(int $userId, string $mpPayerId, string $mpPreapprovalId): void
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT mercadopago_payer_id FROM usuarios WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $current = $stmt->fetchColumn();
            $current = ($current !== false && $current !== null) ? (string)$current : '';

            if ($current === $mpPayerId) {
                // Jah associado e consistente
                return;
            }

            if ($current !== '') {
                error_log('[SubscriptionController] linkMpPayerIdToUser CONFLITO: user=' . $userId
                    . ' jah possui payer_id=' . substr($current, 0, 3) . '…'
                    . ' ignorando novo=' . substr($mpPayerId, 0, 3) . '…'
                    . ' mp_id=' . substr($mpPreapprovalId, 0, 3) . '…'
                );
                return;
            }

            // Verifica se o payer_id ja esta vinculado a OUTRO usuario.
            $stmt = $this->db->prepare(
                'SELECT id FROM usuarios WHERE mercadopago_payer_id = ? LIMIT 1'
            );
            $stmt->execute([$mpPayerId]);
            $other = $stmt->fetchColumn();
            if ($other !== false && (int)$other !== $userId) {
                error_log('[SubscriptionController] linkMpPayerIdToUser ORFAO: payer_id=' . substr($mpPayerId, 0, 3) . '…'
                    . ' ja pertence a outro usuario. Nao associar. mp_id=' . substr($mpPreapprovalId, 0, 3) . '…'
                );
                return;
            }

            $up = $this->db->prepare(
                'UPDATE usuarios SET mercadopago_payer_id = ?, updated_at = NOW() WHERE id = ?'
            );
            $up->execute([$mpPayerId, $userId]);
        } catch (PDOException $e) {
            // 23505 = unique_violation
            if ((string)$e->getCode() === '23505') {
                error_log('[SubscriptionController] linkMpPayerIdToUser unique_violation user=' . $userId);
                return;
            }
            error_log('[SubscriptionController] linkMpPayerIdToUser error: ' . $e->getMessage());
        }
    }

    /**
     * GET /index.php?action=subscription_redirect&plan=pro|premium
     * Redireciona o usuario logado para a URL de checkout (init_point)
     * do Preapproval Plan configurado no Mercado Pago para o slug informado.
     *
     * Esse fluxo NAO exige captura manual de cartao nesta tela: o MP
     * apresenta o checkout hospedado (Checkout Bricks / pagina de assinatura)
     * para o usuario finalizar o pagamento.
     *
     * Resposta: 302 Location para init_point (ou de volta para meu_plano com erro).
     */
    public function redirect(): void
    {
        requireLogin();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            header('Location: /?action=meu_plano&error=incomplete_profile');
            return;
        }

        $planSlug = PlanService::normalizeSlug((string)($_GET['plan'] ?? $_POST['plan'] ?? ''));
        if (!in_array($planSlug, [PlanService::SLUG_PRO, PlanService::SLUG_PREMIUM], true)) {
            header('Location: /?action=meu_plano&error=invalid_plan');
            return;
        }

        $existing = $this->subscriptions->findActiveByUserId($userId);
        if ($existing !== null) {
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

        $checkoutUrl = $this->mp->getPlanCheckoutUrl($planId);
        if ($checkoutUrl === '') {
            error_log('[SubscriptionController] checkout url indisponivel para plan_id=' . $planId . ' slug=' . $planSlug);
            header('Location: /?action=meu_plano&error=mp_not_configured');
            return;
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

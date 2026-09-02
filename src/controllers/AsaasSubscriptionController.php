<?php
/**
 * AsaasSubscriptionController — handlers de criacao/cancelamento de assinatura Asaas.
 *
 * A criacao de assinatura:
 *   - Exige usuario autenticado
 *   - Exige CSRF (validado no router public/index.php)
 *   - Verifica se ja existe assinatura ativa
 *   - Determina preco server-side (NUNCA confia no frontend)
 *   - Envia remoteIp do pagador (header X-Forwarded-For/X-Real-IP)
 *   - Dados de cartao transitam no body apenas durante a chamada
 *     e sao descartados imediatamente apos a resposta do Asaas
 */
require_once __DIR__ . '/../services/CpfValidator.php';

class AsaasSubscriptionController
{
    private const PLAN_PRICES = [
        'pro'     => 9.90,
        'premium' => 19.90,
    ];

    private PDO $db;
    private User $userModel;
    private PlanService $planService;
    private Subscription $subscriptions;
    private AsaasService $asaas;

    public function __construct(
        PDO $db,
        User $userModel,
        PlanService $planService,
        Subscription $subscriptions,
        AsaasService $asaas
    ) {
        $this->db = $db;
        $this->userModel = $userModel;
        $this->planService = $planService;
        $this->subscriptions = $subscriptions;
        $this->asaas = $asaas;
    }

    /**
     * POST /index.php?action=asaas_subscription_create
     * Body:
     *   - plan_slug: 'pro' | 'premium'
     *   - csrf_token
     *   - card_holder_name, card_number, card_expiry_month, card_expiry_year, card_ccv
     *   - holder_cpf, holder_postal_code, holder_address_number, holder_phone, holder_mobile_phone
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

        $existing = $this->subscriptions->findActiveByUserId($userId);
        if ($existing !== null) {
            header('Location: /?action=meu_plano&error=already_subscribed');
            return;
        }

        $cpf = CpfValidator::digits((string)($user->cpf ?? ''));
        if ($cpf === null || !CpfValidator::isValid($cpf)) {
            header('Location: /?action=meu_plano&error=invalid_cpf');
            return;
        }

        $userName  = trim((string)($user->name ?? ''));
        $userEmail = trim((string)($user->email ?? ''));
        if ($userName === '' || $userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('[AsaasSub] pre-check failed: missing or invalid user profile data');
            header('Location: /?action=meu_plano&error=incomplete_profile');
            return;
        }

        $cardData = $this->readCardDataFromPost();
        $missing = [];
        if ($cardData['holderName'] === '') $missing[] = 'card_holder_name';
        if ($cardData['number']      === '') $missing[] = 'card_number';
        if ($cardData['expiryMonth'] === '') $missing[] = 'card_expiry_month';
        if ($cardData['expiryYear']  === '') $missing[] = 'card_expiry_year';
        if ($cardData['ccv']         === '') $missing[] = 'card_ccv';
        if ($missing !== []) {
            header('Location: /?action=meu_plano&error=missing_card_data');
            return;
        }

        $holderInfo = [
            'name'         => $cardData['holderName'],
            'email'        => $userEmail,
            'cpfCnpj'      => $cpf,
            'postalCode'   => preg_replace('/\D/', '', (string)($_POST['holder_postal_code'] ?? '')),
            'addressNumber'=> (string)($_POST['holder_address_number'] ?? ''),
            'phone'        => preg_replace('/\D/', '', (string)($_POST['holder_phone'] ?? '')),
            'mobilePhone'  => preg_replace('/\D/', '', (string)($_POST['holder_mobile_phone'] ?? '')),
        ];
        if ($holderInfo['postalCode'] === '' || $holderInfo['addressNumber'] === '' || $holderInfo['mobilePhone'] === '') {
            header('Location: /?action=meu_plano&error=missing_holder_data');
            return;
        }

        $value = self::PLAN_PRICES[$planSlug];

        $customer = $this->asaas->findOrCreateCustomer(
            $this->db,
            $userId,
            $userName,
            $userEmail,
            $cpf
        );
        if (!$customer['ok'] || empty($customer['customerId'])) {
            error_log('[AsaasSub] customer creation failed: ' . ($customer['error'] ?? 'unknown'));
            header('Location: /?action=meu_plano&error=asaas_customer_failed');
            return;
        }

        $remoteIp = AsaasService::getRealClientIp();
        $extRef   = 'user_' . $userId . '_' . $planSlug;
        $nextDue  = date('Y-m-d');
        $description = 'Assinatura ' . ($plan['nome'] ?? ucfirst($planSlug)) . ' - Controle de Gastos';

        $cardPayload = [
            'holderName'  => $cardData['holderName'],
            'number'      => $cardData['number'],
            'expiryMonth' => $cardData['expiryMonth'],
            'expiryYear'  => $cardData['expiryYear'],
            'ccv'         => $cardData['ccv'],
            'holderInfo'  => $holderInfo,
        ];

        $resp = $this->asaas->createSubscription(
            (string)$customer['customerId'],
            $planSlug,
            $value,
            $nextDue,
            $description,
            $extRef,
            $remoteIp,
            $cardPayload
        );
        unset($cardData, $cardPayload, $holderInfo, $cpf);

        if (!$resp['ok']) {
            $msg = AsaasService::friendlyError($resp);
            $errCode = isset($resp['data']['code']) ? (string)$resp['data']['code'] : '';
            $errMsgShort = substr((string)($resp['error'] ?? ''), 0, 200);
            $userIdLog = (int)$userId;
            $planSlugLog = $planSlug;
            $httpLog = (int)$resp['status'];
            error_log('[AsaasSub] create subscription FAILED user=' . $userIdLog
                . ' slug=' . $planSlugLog
                . ' http=' . $httpLog
                . ' code=' . $errCode
                . ' msg=' . $errMsgShort
            );
            unset($resp['data']);
            header('Location: /?action=meu_plano&error=asaas_create_failed');
            return;
        }

        $subData = $resp['data'];
        $asaasSubId = (string)($subData['id'] ?? '');
        $asaasCustId = (string)($subData['customer'] ?? '');
        $subStatus   = (string)($subData['status'] ?? 'PENDING');
        $nextDue     = (string)($subData['nextDueDate'] ?? date('Y-m-d'));
        $subValue    = isset($subData['value']) ? (float)$subData['value'] : $value;

        if ($asaasSubId === '') {
            error_log('[AsaasSub] subscription created without id (synchronous response empty)');
            header('Location: /?action=meu_plano&error=asaas_no_id');
            return;
        }

        $existingByAsaas = $this->subscriptions->findByAsaasSubscriptionId($asaasSubId);
        if ($existingByAsaas !== null) {
            header('Location: /?action=meu_plano&error=already_subscribed');
            return;
        }

        $localStatus = ($subStatus === 'ACTIVE') ? Subscription::STATUS_ACTIVE : Subscription::STATUS_PENDING;
        $localSubId = $this->createLocalSubscription(
            $userId,
            (int)($plan['id'] ?? 0),
            $planSlug,
            $asaasCustId,
            $asaasSubId,
            $extRef,
            $localStatus,
            $subStatus,
            $subValue,
            $nextDue
        );

        $row = $this->subscriptions->findById($localSubId);
        if ($row !== null) {
            $this->subscriptions->applyStatusToUser($row);
        }

        error_log('[AsaasSub] create OK user=' . $userId
            . ' slug=' . $planSlug
            . ' sub_id=' . $localSubId
            . ' asaas_sub=' . substr($asaasSubId, 0, 8) . '...'
            . ' status=' . $subStatus
        );

        header('Location: /?action=meu_plano&subscribed=1');
        return;
    }

    /**
     * POST /index.php?action=asaas_subscription_cancel
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

        $asaasSubId = (string)($active['asaas_subscription_id'] ?? '');
        if ($asaasSubId !== '') {
            $resp = $this->asaas->cancelSubscription($asaasSubId);
            if (!$resp['ok']) {
                error_log('[AsaasSub] cancel remote failed: '
                    . substr((string)($resp['error'] ?? ''), 0, 200));
            }
        }

        $this->db->beginTransaction();
        try {
            $this->subscriptions->updateStatusById(
                (int)$active['id'],
                Subscription::STATUS_CANCELLED,
                'cancelled_by_user',
                null,
                null
            );
            $row = $this->subscriptions->findById((int)$active['id']);
            if ($row !== null) {
                $this->subscriptions->applyStatusToUser($row);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        header('Location: /?action=meu_plano&cancelled=1');
        return;
    }

    private function readCardDataFromPost(): array
    {
        $onlyDigits = static fn(string $s): string => preg_replace('/\D/', '', $s);

        return [
            'holderName'  => trim((string)($_POST['card_holder_name'] ?? '')),
            'number'      => $onlyDigits((string)($_POST['card_number'] ?? '')),
            'expiryMonth' => trim((string)($_POST['card_expiry_month'] ?? '')),
            'expiryYear'  => trim((string)($_POST['card_expiry_year'] ?? '')),
            'ccv'         => $onlyDigits((string)($_POST['card_ccv'] ?? '')),
        ];
    }

    private function createLocalSubscription(
        int $userId,
        int $planId,
        string $planSlug,
        string $asaasCustomerId,
        string $asaasSubscriptionId,
        string $externalReference,
        string $status,
        string $providerStatus,
        float $value,
        string $nextDueDate
    ): int {
        $amountCents = (int)round($value * 100);
        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions
                (user_id, plan_id, plan_slug, asaas_customer_id, asaas_subscription_id,
                 external_reference, status, raw_status, amount_cents, currency, frequency, frequency_type,
                 provider, provider_status, next_billing_date)
             VALUES
                (:user_id, :plan_id, :plan_slug, :asaas_cust, :asaas_sub,
                 :ext_ref, :status, :raw_status, :amount, :currency, :freq, :freq_type,
                 :provider, :provider_status, :next_due)'
        );
        $stmt->execute([
            ':user_id'         => $userId,
            ':plan_id'         => $planId,
            ':plan_slug'       => $planSlug,
            ':asaas_cust'      => $asaasCustomerId,
            ':asaas_sub'       => $asaasSubscriptionId,
            ':ext_ref'         => $externalReference,
            ':status'          => $status,
            ':raw_status'      => $providerStatus,
            ':amount'          => $amountCents,
            ':currency'        => 'BRL',
            ':freq'            => 1,
            ':freq_type'       => 'months',
            ':provider'        => 'asaas',
            ':provider_status' => $providerStatus,
            ':next_due'        => $nextDueDate,
        ]);
        return (int)$this->db->lastInsertId();
    }
}

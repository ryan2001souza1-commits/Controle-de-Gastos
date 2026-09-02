<?php
/**
 * AsaasWebhookService — processa webhooks do Asaas de forma idempotente.
 *
 * Seguranca:
 *   - NUNCA usa sessao, CSRF ou login
 *   - Valida token de webhook (asaas-access-token header) via hash_equals
 *   - Idempotencia via event_id UNIQUE na tabela payment_webhooks
 *   - Log sanitizado: nunca contem ASAAS_WEBHOOK_TOKEN, cartao, CVV, CPF,
 *     payload bruto, access_token, customer_id completo do Asaas
 *   - Responde 200 rapidamente
 *
 * Fluxo de idempotencia:
 *   1. Receber payload + header token
 *   2. Validar token (hash_equals com ASAAS_WEBHOOK_TOKEN de env var)
 *   3. Parsear JSON; extrair event_id e event (tipo)
 *   4. INSERT INTO payment_webhooks (event_id UNIQUE)
 *      - Se INSERT falhar (unique violation) → duplicate, retorna 200
 *   5. Marcar processing
 *   6. Processar evento
 *   7. Marcar processed (ou failed)
 *
 * Eventos tratados:
 *   Cobrancas (PAYMENT_*):
 *     - PAYMENT_CONFIRMED / PAYMENT_RECEIVED → ativar plano
 *     - PAYMENT_OVERDUE → marcar inadimplencia (grace period existente)
 *     - PAYMENT_REFUNDED → sincronizar / iniciar revogacao
 *     - PAYMENT_DELETED → sincronizar cobranca removida
 *     - PAYMENT_CREDIT_CARD_CAPTURE_REFUSED → log + sem ativacao
 *     - PAYMENT_CREATED / PAYMENT_UPDATED → auditoria
 *   Assinaturas (SUBSCRIPTION_*):
 *     - SUBSCRIPTION_CREATED → sincronizar (raro via webhook, mas tratado)
 *     - SUBSCRIPTION_UPDATED → atualizar dados
 *     - SUBSCRIPTION_INACTIVATED → pausar ou cancelar
 *     - SUBSCRIPTION_DELETED → cancelar
 */
class AsaasWebhookService
{
    private PDO $db;
    private Subscription $subscriptions;

    private const HANDLED_PAYMENT_EVENTS = [
        'PAYMENT_CONFIRMED',
        'PAYMENT_RECEIVED',
        'PAYMENT_OVERDUE',
        'PAYMENT_REFUNDED',
        'PAYMENT_DELETED',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
        'PAYMENT_CREATED',
        'PAYMENT_UPDATED',
    ];

    private const HANDLED_SUBSCRIPTION_EVENTS = [
        'SUBSCRIPTION_CREATED',
        'SUBSCRIPTION_UPDATED',
        'SUBSCRIPTION_INACTIVATED',
        'SUBSCRIPTION_DELETED',
    ];

    public function __construct(PDO $db, Subscription $subscriptions)
    {
        $this->db = $db;
        $this->subscriptions = $subscriptions;
    }

    /**
     * Valida o token do webhook Asaas.
     * O token vem no header: asaas-access-token
     */
    public static function validateToken(?string $provided): bool
    {
        $expected = (string)(getenv('ASAAS_WEBHOOK_TOKEN') ?: '');
        if ($expected === '' || $provided === null || $provided === '') {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    /**
     * Processa um webhook do Asaas.
     *
     * @return array{status:int, duplicate:bool, processed:bool, reason:?string}
     */
    public function handle(string $rawBody, ?string $accessToken, ?string $sourceIp): array
    {
        if (!self::validateToken($accessToken)) {
            error_log('[AsaasWebhook] rejected: invalid or missing token');
            return ['status' => 401, 'duplicate' => false, 'processed' => false, 'reason' => 'invalid_token'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            error_log('[AsaasWebhook] rejected: bad_payload');
            return ['status' => 400, 'duplicate' => false, 'processed' => false, 'reason' => 'bad_payload'];
        }

        $eventId = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : null;
        $event   = isset($payload['event']) && is_string($payload['event']) ? $payload['event'] : null;

        if ($event === null || $event === '') {
            return ['status' => 400, 'duplicate' => false, 'processed' => false, 'reason' => 'missing_event'];
        }

        if ($eventId === null || $eventId === '') {
            $eventId = hash('sha256', $rawBody);
        }

        $eventMasked = strlen($event) > 30 ? substr($event, 0, 27) . '...' : $event;
        $eventIdMasked = $eventId !== null
            ? (strlen($eventId) > 12 ? substr($eventId, 0, 6) . '...' : $eventId)
            : 'none';
        error_log('[AsaasWebhook] received: event=' . $eventMasked . ' id=' . $eventIdMasked);

        $webhookId = $this->insertEvent($eventId, $event, $rawBody, $sourceIp);
        if ($webhookId === null) {
            return ['status' => 200, 'duplicate' => true, 'processed' => false, 'reason' => 'duplicate'];
        }

        $this->markProcessing($webhookId);

        try {
            $processed = $this->processEvent($payload, $event, $webhookId);
            $this->markProcessed($webhookId);
            return ['status' => 200, 'duplicate' => false, 'processed' => $processed, 'reason' => null];
        } catch (Throwable $e) {
            $safe = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $e->getMessage()), 0, 200);
            error_log('[AsaasWebhook] process error: ' . $safe);
            $this->markFailed($webhookId, $safe);
            return ['status' => 200, 'duplicate' => false, 'processed' => false, 'reason' => 'processing_error'];
        }
    }

    private function processEvent(array $payload, string $event, int $webhookId): bool
    {
        $subscription = $payload['subscription'] ?? null;
        $payment     = $payload['payment']     ?? null;

        if (in_array($event, self::HANDLED_PAYMENT_EVENTS, true)) {
            return $this->handlePaymentEvent($event, $payment, $subscription, $webhookId);
        }
        if (in_array($event, self::HANDLED_SUBSCRIPTION_EVENTS, true)) {
            return $this->handleSubscriptionEvent($event, $subscription, $webhookId);
        }

        error_log('[AsaasWebhook] unhandled event: ' . $event);
        $this->markSkipped($webhookId);
        return false;
    }

    private function handlePaymentEvent(string $event, ?array $payment, ?array $subscription, int $webhookId): bool
    {
        if (!is_array($payment)) {
            return false;
        }

        $subId   = isset($payment['subscription']) && is_string($payment['subscription'])
            ? $payment['subscription'] : null;
        $custId  = isset($payment['customer']) && is_string($payment['customer'])
            ? $payment['customer'] : null;
        $payId   = isset($payment['id']) && is_string($payment['id'])
            ? $payment['id'] : null;
        $extRef  = isset($payment['externalReference']) && is_string($payment['externalReference'])
            ? $payment['externalReference'] : null;
        $status  = isset($payment['status']) && is_string($payment['status'])
            ? $payment['status'] : null;

        if ($subId !== null) {
            $row = $this->findByAsaasSubscriptionId($subId);
        } elseif ($extRef !== null) {
            $row = $this->findByExternalReference($extRef);
        } else {
            $row = null;
        }

        $userId = null;
        $localSubId = null;
        if ($row !== null) {
            $userId    = (int)($row['user_id'] ?? 0);
            $localSubId = (int)($row['id'] ?? 0);
            $this->linkWebhookToEntities($webhookId, $userId, $localSubId);
        }

        switch ($event) {
            case 'PAYMENT_CONFIRMED':
            case 'PAYMENT_RECEIVED':
                if ($userId > 0 && $localSubId > 0) {
                    $this->activateSubscription($localSubId, $userId, $row['plan_slug'] ?? '');
                    return true;
                }
                break;

            case 'PAYMENT_OVERDUE':
                if ($userId > 0 && $localSubId > 0) {
                    $this->markOverdue($localSubId);
                    return true;
                }
                break;

            case 'PAYMENT_REFUNDED':
                if ($userId > 0 && $localSubId > 0) {
                    $this->handleRefund($localSubId, $userId, $row['plan_slug'] ?? '');
                    return true;
                }
                break;

            case 'PAYMENT_DELETED':
                if ($localSubId > 0) {
                    $this->markDeleted($localSubId);
                    return true;
                }
                break;

            case 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED':
                error_log('[AsaasWebhook] card_capture_refused: subscription='
                    . ($subId !== null ? substr($subId, 0, 8) . '...' : 'none')
                    . ' user=' . ($userId > 0 ? $userId : 'unknown')
                );
                return false;

            case 'PAYMENT_CREATED':
            case 'PAYMENT_UPDATED':
                return false;
        }

        return false;
    }

    private function handleSubscriptionEvent(string $event, ?array $subscription, int $webhookId): bool
    {
        if (!is_array($subscription)) {
            return false;
        }

        $subId   = isset($subscription['id']) && is_string($subscription['id'])
            ? $subscription['id'] : null;
        $custId  = isset($subscription['customer']) && is_string($subscription['customer'])
            ? $subscription['customer'] : null;
        $extRef  = isset($subscription['externalReference']) && is_string($subscription['externalReference'])
            ? $subscription['externalReference'] : null;
        $subStatus = isset($subscription['status']) && is_string($subscription['status'])
            ? $subscription['status'] : null;
        $value = isset($subscription['value']) ? (float)$subscription['value'] : null;

        $row = null;
        if ($subId !== null) {
            $row = $this->findByAsaasSubscriptionId($subId);
        } elseif ($extRef !== null) {
            $row = $this->findByExternalReference($extRef);
        }

        $userId = null;
        $localSubId = null;
        if ($row !== null) {
            $userId    = (int)($row['user_id'] ?? 0);
            $localSubId = (int)($row['id'] ?? 0);
            $this->linkWebhookToEntities($webhookId, $userId, $localSubId);
        }

        switch ($event) {
            case 'SUBSCRIPTION_CREATED':
                if ($subId !== null && $localSubId === 0 && $extRef !== null) {
                    $uid = $this->extractUserIdFromRef($extRef);
                    if ($uid !== null) {
                        $this->syncNewSubscription($uid, $subId, $custId, $subStatus, $value);
                    }
                }
                return false;

            case 'SUBSCRIPTION_UPDATED':
                if ($localSubId > 0 && $subStatus !== null) {
                    $this->updateProviderStatus($localSubId, $subStatus);
                    return true;
                }
                break;

            case 'SUBSCRIPTION_INACTIVATED':
                if ($localSubId > 0) {
                    $this->inactivateSubscription($localSubId, $userId, $row['plan_slug'] ?? '');
                    return true;
                }
                break;

            case 'SUBSCRIPTION_DELETED':
                if ($localSubId > 0) {
                    $this->cancelSubscription($localSubId, $userId, $row['plan_slug'] ?? '');
                    return true;
                }
                break;
        }

        return false;
    }

    private function findByAsaasSubscriptionId(string $asaasSubId): ?array
    {
        return $this->subscriptions->findByAsaasSubscriptionId($asaasSubId);
    }

    private function findByExternalReference(string $extRef): ?array
    {
        return $this->subscriptions->findByExternalReference($extRef);
    }

    private function extractUserIdFromRef(string $extRef): ?int
    {
        if (!preg_match('/^user_(\d+)_[a-z]+$/', $extRef, $m)) {
            if (preg_match('/^user_(\d+)$/', $extRef, $m)) {
                $uid = (int)$m[1];
                $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE id = ?');
                $stmt->execute([$uid]);
                return $stmt->fetchColumn() ? $uid : null;
            }
            return null;
        }
        $uid = (int)$m[1];
        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE id = ?');
        $stmt->execute([$uid]);
        return $stmt->fetchColumn() ? $uid : null;
    }

    private function syncNewSubscription(int $userId, string $asaasSubId, ?string $asaasCustId, ?string $asaasStatus, ?float $value): void
    {
        $stmt = $this->db->prepare('SELECT id, plan_slug FROM subscriptions WHERE user_id = ? AND status IN (\'pending\') LIMIT 1');
        $stmt->execute([$userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) return;

        $cents = $value !== null ? (int)round($value * 100) : null;
        $upd = $this->db->prepare(
            'UPDATE subscriptions SET
                asaas_subscription_id = :asaas_sub,
                asaas_customer_id = :asaas_cust,
                provider = \'asaas\',
                provider_status = :pstatus,
                amount_cents = COALESCE(:amount, amount_cents),
                updated_at = NOW()
              WHERE id = :id'
        );
        $upd->execute([
            ':asaas_sub' => $asaasSubId,
            ':asaas_cust' => $asaasCustId,
            ':pstatus' => $asaasStatus,
            ':amount' => $cents,
            ':id' => $existing['id'],
        ]);
    }

    private function activateSubscription(int $localSubId, int $userId, string $planSlug): void
    {
        $this->db->beginTransaction();
        try {
            $this->subscriptions->updateStatusById(
                $localSubId,
                Subscription::STATUS_ACTIVE,
                'PAYMENT_CONFIRMED',
                null,
                null
            );
            $row = $this->subscriptions->findById($localSubId);
            if ($row !== null) {
                $this->subscriptions->applyStatusToUser($row);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function markOverdue(int $localSubId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE subscriptions SET
                provider_status = \'OVERDUE\',
                updated_at = NOW()
              WHERE id = ?'
        );
        $stmt->execute([$localSubId]);
    }

    private function handleRefund(int $localSubId, int $userId, string $planSlug): void
    {
        $this->db->beginTransaction();
        try {
            $this->subscriptions->updateStatusById(
                $localSubId,
                Subscription::STATUS_CANCELLED,
                'PAYMENT_REFUNDED',
                null,
                null
            );
            $row = $this->subscriptions->findById($localSubId);
            if ($row !== null) {
                $this->subscriptions->applyStatusToUser($row);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function markDeleted(int $localSubId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE subscriptions SET provider_status = \'DELETED\', updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$localSubId]);
    }

    private function updateProviderStatus(int $localSubId, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE subscriptions SET provider_status = :s, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':s' => $status, ':id' => $localSubId]);
    }

    private function inactivateSubscription(int $localSubId, int $userId, string $planSlug): void
    {
        $this->db->beginTransaction();
        try {
            $this->subscriptions->updateStatusById(
                $localSubId,
                Subscription::STATUS_PAUSED,
                'SUBSCRIPTION_INACTIVATED',
                null,
                null
            );
            $row = $this->subscriptions->findById($localSubId);
            if ($row !== null) {
                $this->subscriptions->applyStatusToUser($row);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function cancelSubscription(int $localSubId, int $userId, string $planSlug): void
    {
        $this->db->beginTransaction();
        try {
            $this->subscriptions->updateStatusById(
                $localSubId,
                Subscription::STATUS_CANCELLED,
                'SUBSCRIPTION_DELETED',
                null,
                null
            );
            $row = $this->subscriptions->findById($localSubId);
            if ($row !== null) {
                $this->subscriptions->applyStatusToUser($row);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertEvent(string $eventId, string $event, string $rawBody, ?string $sourceIp): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO payment_webhooks
                    (event_id, topic, resource_id, payload, source_ip, status)
                 VALUES
                    (:eid, :topic, :rid, :payload::jsonb, :ip, :status)'
            );
            $stmt->execute([
                ':eid'     => $eventId,
                ':topic'   => $event,
                ':rid'     => null,
                ':payload' => $rawBody,
                ':ip'      => $sourceIp,
                ':status'  => 'received',
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                return null;
            }
            throw $e;
        }
    }

    private function markProcessing(int $webhookId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks SET status = 'processing', error_message = NULL WHERE id = :id"
        );
        $stmt->execute([':id' => $webhookId]);
    }

    private function markProcessed(int $webhookId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks SET status = 'processed', processed_at = NOW(), error_message = NULL WHERE id = :id"
        );
        $stmt->execute([':id' => $webhookId]);
    }

    private function markSkipped(int $webhookId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks SET status = 'skipped', processed_at = NOW() WHERE id = :id"
        );
        $stmt->execute([':id' => $webhookId]);
    }

    private function markFailed(int $webhookId, string $msg): void
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks SET status = 'failed', error_message = :msg WHERE id = :id"
        );
        $stmt->execute([':msg' => $msg, ':id' => $webhookId]);
    }

    private function linkWebhookToEntities(int $webhookId, ?int $userId, ?int $subscriptionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_webhooks SET user_id = :uid, subscription_id = :sid WHERE id = :id'
        );
        $stmt->execute([':uid' => $userId, ':sid' => $subscriptionId, ':id' => $webhookId]);
    }
}

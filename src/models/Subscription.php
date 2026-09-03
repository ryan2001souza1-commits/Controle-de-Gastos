<?php
/**
 * Subscription — modelo de dados da tabela `subscriptions`.
 *
 * Encapsula todas as queries relacionadas a assinaturas. NUNCA aceita
 * dados de plano/preço vindos do frontend: o servidor sempre lê do
 * banco.
 */
class Subscription
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_REJECTED  = 'rejected';

    private const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_REJECTED,
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Cria um registro de assinatura a partir de um preapproval do MP.
     * Retorna o ID inserido.
     *
     * @throws PDOException quando mp_preapproval_id ja existe (UNIQUE).
     */
    public function createFromPreapproval(array $preapproval): int
    {
        $mpId    = (string)($preapproval['id'] ?? '');
        $extRef  = (string)($preapproval['external_reference'] ?? '');
        $planId  = (int)($preapproval['_plan_id_local'] ?? 0);
        $planSlug = (string)($preapproval['_plan_slug_local'] ?? '');
        $userId  = (int)($preapproval['_user_id_local'] ?? 0);
        $mpPlanId = (string)($preapproval['preapproval_plan_id'] ?? '');
        $mpPayerId = isset($preapproval['payer_id']) ? (string)$preapproval['payer_id'] : null;
        $status  = $this->mapMpStatus((string)($preapproval['status'] ?? 'pending'));

        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions
                (user_id, plan_id, plan_slug, mp_preapproval_id, mp_plan_id, mp_payer_id,
                 external_reference, status, raw_status,
                 amount_cents, currency, frequency, frequency_type,
                 start_date, next_billing_date, grace_period_end)
             VALUES
                (:user_id, :plan_id, :plan_slug, :mp_preapproval_id, :mp_plan_id, :mp_payer_id,
                 :external_reference, :status, :raw_status,
                 :amount_cents, :currency, :frequency, :frequency_type,
                 :start_date, :next_billing_date, :grace_period_end)'
        );

        $auto = $preapproval['auto_recurring'] ?? [];
        $amount = isset($auto['transaction_amount']) ? (int)round(((float)$auto['transaction_amount']) * 100) : null;
        $currency = isset($auto['currency_id']) ? substr((string)$auto['currency_id'], 0, 3) : 'BRL';
        $frequency = isset($auto['frequency']) ? (int)$auto['frequency'] : null;
        $frequencyType = isset($auto['frequency_type']) ? (string)$auto['frequency_type'] : null;

        $startDate = isset($preapproval['date_created']) ? $preapproval['date_created'] : null;
        $nextBilling = isset($preapproval['next_payment_date']) ? $preapproval['next_payment_date'] : null;

        $stmt->execute([
            ':user_id' => $userId,
            ':plan_id' => $planId,
            ':plan_slug' => $planSlug,
            ':mp_preapproval_id' => $mpId,
            ':mp_plan_id' => $mpPlanId,
            ':mp_payer_id' => $mpPayerId,
            ':external_reference' => $extRef,
            ':status' => $status,
            ':raw_status' => (string)($preapproval['status'] ?? ''),
            ':amount_cents' => $amount,
            ':currency' => $currency,
            ':frequency' => $frequency,
            ':frequency_type' => $frequencyType,
            ':start_date' => $startDate,
            ':next_billing_date' => $nextBilling,
            ':grace_period_end' => null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Atualiza o status de uma assinatura pelo mp_preapproval_id.
     * Retorna true se atualizou, false se nao encontrada.
     */
    public function updateStatusByMpId(
        string $mpPreapprovalId,
        string $newStatus,
        ?string $rawStatus,
        ?string $nextBillingDate,
        ?string $gracePeriodEnd
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE subscriptions
                SET status = :status,
                    raw_status = :raw_status,
                    next_billing_date = COALESCE(:next_billing_date, next_billing_date),
                    grace_period_end = COALESCE(:grace_period_end, grace_period_end),
                    cancelled_at = CASE WHEN :status_cancelled THEN NOW() ELSE cancelled_at END,
                    paused_at    = CASE WHEN :status_paused    THEN NOW() ELSE paused_at    END,
                    expired_at   = CASE WHEN :status_expired   THEN NOW() ELSE expired_at   END,
                    start_date   = COALESCE(start_date, :start_date_now),
                    updated_at   = NOW()
              WHERE mp_preapproval_id = :mp_id'
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':raw_status' => $rawStatus,
            ':next_billing_date' => $nextBillingDate,
            ':grace_period_end' => $gracePeriodEnd,
            ':status_cancelled' => $newStatus === self::STATUS_CANCELLED ? 1 : 0,
            ':status_paused'    => $newStatus === self::STATUS_PAUSED    ? 1 : 0,
            ':status_expired'   => $newStatus === self::STATUS_EXPIRED   ? 1 : 0,
            ':start_date_now' => $nextBillingDate,
            ':mp_id' => $mpPreapprovalId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Busca assinatura por mp_preapproval_id.
     */
    public function findByMpPreapprovalId(string $mpId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE mp_preapproval_id = ? LIMIT 1'
        );
        $stmt->execute([$mpId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca assinatura por external_reference.
     */
    public function findByExternalReference(string $extRef): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE external_reference = ? LIMIT 1'
        );
        $stmt->execute([$extRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca assinatura por id local (PK).
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscriptions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Atualiza o status de uma assinatura pelo id local (PK).
     * Retorna true se atualizou.
     */
    public function updateStatusById(
        int $id,
        string $newStatus,
        ?string $rawStatus,
        ?string $nextBillingDate,
        ?string $gracePeriodEnd
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE subscriptions
                SET status = :status,
                    raw_status = :raw_status,
                    next_billing_date = COALESCE(:next_billing_date, next_billing_date),
                    grace_period_end = COALESCE(:grace_period_end, grace_period_end),
                    cancelled_at = CASE WHEN :status_cancelled THEN NOW() ELSE cancelled_at END,
                    paused_at    = CASE WHEN :status_paused    THEN NOW() ELSE paused_at    END,
                    expired_at   = CASE WHEN :status_expired   THEN NOW() ELSE expired_at   END,
                    start_date   = COALESCE(start_date, :start_date_now),
                    updated_at   = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':raw_status' => $rawStatus,
            ':next_billing_date' => $nextBillingDate,
            ':grace_period_end' => $gracePeriodEnd,
            ':status_cancelled' => $newStatus === self::STATUS_CANCELLED ? 1 : 0,
            ':status_paused'    => $newStatus === self::STATUS_PAUSED    ? 1 : 0,
            ':status_expired'   => $newStatus === self::STATUS_EXPIRED   ? 1 : 0,
            ':start_date_now' => $nextBillingDate,
            ':id' => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Busca assinatura ativa de um usuario. Retorna null se nenhuma.
     */
    public function findActiveByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM subscriptions
              WHERE user_id = :uid AND status IN ('active','paused')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private const MP_STATUS_MAP = [
        'pending'    => self::STATUS_PENDING,
        'authorized' => self::STATUS_ACTIVE,
        'active'     => self::STATUS_ACTIVE,
        'paused'     => self::STATUS_PAUSED,
        'cancelled'  => self::STATUS_CANCELLED,
        'canceled'   => self::STATUS_CANCELLED,
        'expired'    => self::STATUS_EXPIRED,
        'rejected'   => self::STATUS_REJECTED,
    ];

    /**
     * Mapeia o status cru do Mercado Pago para um status interno.
     * Mapeia valores MP reais (authorized, canceled) que nao existem na lista interna.
     */
    public function mapMpStatus(string $mpStatus): string
    {
        $s = strtolower(trim($mpStatus));
        return self::MP_STATUS_MAP[$s] ?? self::STATUS_PENDING;
    }

    /**
     * Decrementa/atualiza o plano de um usuario em funcao do status.
     * Retorna true se o usuario foi alterado.
     */
    public function applyStatusToUser(array $subscription): bool
    {
        $userId = (int)$subscription['user_id'];
        $planSlug = (string)$subscription['plan_slug'];
        $status = (string)$subscription['status'];

        if ($userId <= 0 || $planSlug === '') {
            return false;
        }

        if ($status === self::STATUS_ACTIVE || $status === self::STATUS_PAUSED) {
            $this->grantAccess($userId, $planSlug, (int)$subscription['id']);
            return true;
        }
        if ($status === self::STATUS_CANCELLED || $status === self::STATUS_EXPIRED) {
            $this->startGracePeriodOrRevoke($userId, $planSlug, (int)$subscription['id'], $subscription);
            return true;
        }
        // pending/rejected: nao altera o usuario
        return false;
    }

    private function grantAccess(int $userId, string $planSlug, int $subscriptionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios
                SET plano = :plan,
                    plano_status = 'ativo',
                    plano_inicio = COALESCE(plano_inicio, NOW()),
                    plano_fim = NULL,
                    active_subscription_id = :sub_id,
                    updated_at = NOW()
              WHERE id = :uid"
        );
        $stmt->execute([':plan' => $planSlug, ':sub_id' => $subscriptionId, ':uid' => $userId]);
    }

    private function startGracePeriodOrRevoke(int $userId, string $planSlug, int $subscriptionId, array $sub): void
    {
        $grace = $sub['grace_period_end'] ?? null;
        if ($grace && strtotime($grace) > time()) {
            // Ainda em carência: apenas muda status, mantém plano
            $stmt = $this->db->prepare(
                "UPDATE usuarios
                    SET plano_status = 'pendente',
                        plano_fim = :grace,
                        updated_at = NOW()
                  WHERE id = :uid"
            );
            $stmt->execute([':grace' => $grace, ':uid' => $userId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE usuarios
                    SET plano = 'gratuito',
                        plano_status = 'cancelado',
                        plano_fim = NOW(),
                        active_subscription_id = NULL,
                        updated_at = NOW()
                  WHERE id = :uid"
            );
            $stmt->execute([':uid' => $userId]);
        }
    }
}

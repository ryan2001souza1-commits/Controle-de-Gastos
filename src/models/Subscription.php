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

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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

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

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Busca assinatura por id local (PK).
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscriptions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
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
     * Busca assinatura mais recente para um usuario, independente do status.
     */
    public function findLatestByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca assinatura pelo mp_preapproval_id (ID retornado pelo Mercado Pago).
     * Retorna null se nao encontrada.
     */
    public function findByMpId(string $mpPreapprovalId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE mp_preapproval_id = :mpid ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':mpid' => $mpPreapprovalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca assinatura ativa ou pendente de um usuario para um plano especifico.
     * Util para idempotencia no checkout: evita criar multiplas preapprovals
     * para o mesmo usuario/plano quando o usuario clica repetidamente.
     */
    public function findActiveOrPendingByUserAndPlan(int $userId, string $planSlug): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM subscriptions
              WHERE user_id = :uid
                AND plan_slug = :slug
                AND status IN ('pending','active','paused')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':slug' => $planSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca assinatura ativa de um usuario que JA POSSUI preapproval_id no Mercado Pago.
     * Exclui pendings sem mp_preapproval_id (que ainda estao no checkout).
     * Exclui registros cancelados/encerrados.
     *
     * Usada para:
     *  - cancelamento de assinatura
     *  - protecao contra cobranca dupla em upgrade
     *
     * @return array|null Assinatura ativa com mp_preapproval_id, ou null se nao encontrada.
     */
    public function findActiveByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM subscriptions
              WHERE user_id = :uid
                AND status IN ('active','paused')
                AND mp_preapproval_id IS NOT NULL
                AND mp_preapproval_id <> ''
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria uma assinatura pendente (preapproval) para um usuario.
     * Idempotente: se ja existir pendente/ativa/pausada do mesmo plano,
     * retorna a existente sem inserir nova.
     *
     * @return array{id:int,created:bool} id da assinatura + se foi criada agora
     */
    /**
     * Armazena o init_point de MP em checkout_url para reutilizacao
     * em cliques subsequentes. Evita criar multiplas preapprovals no MP.
     * Idempotente: segunda chamada nao sobrescreve checkout_url existente.
     */
    public function storeInitPoint(int $subscriptionId, string $initPoint): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE subscriptions
                SET checkout_url = :init
              WHERE id = :id
                AND checkout_url IS NULL"
        );
        $stmt->execute([':init' => $initPoint, ':id' => $subscriptionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Recupera o init_point armazenado via storeInitPoint.
     * Prioriza checkout_url; fallback para raw_status legado.
     */
    public function getStoredInitPoint(int $subscriptionId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT checkout_url FROM subscriptions WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $subscriptionId]);
        $url = $stmt->fetchColumn();
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $stmt = $this->db->prepare(
            'SELECT raw_status FROM subscriptions WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $subscriptionId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) return null;
        if (preg_match('/\|init:(\S+)/', $raw, $m)) {
            return $m[1];
        }
        return null;
    }

    public function createPending(
        int $userId,
        string $planSlug,
        int $planId,
        string $externalReference
    ): array {
        $existing = $this->findActiveOrPendingByUserAndPlan($userId, $planSlug);
        if ($existing !== null) {
            return ['id' => (int)$existing['id'], 'created' => false];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions
                (user_id, plan_id, plan_slug, status, raw_status, external_reference)
             VALUES
                (:user_id, :plan_id, :plan_slug, :status, :raw_status, :external_reference)
             RETURNING id'
        );
        $stmt->execute([
            ':user_id'            => $userId,
            ':plan_id'            => $planId,
            ':plan_slug'          => $planSlug,
            ':status'             => self::STATUS_PENDING,
            ':raw_status'         => 'pending',
            ':external_reference' => $externalReference,
        ]);
        $id = (int)$stmt->fetchColumn();
        return ['id' => $id, 'created' => true];
    }

    /**
     * Vincula o preapproval_id do Mercado Pago a uma assinatura pendente.
     * Idempotente: se o mp_preapproval_id ja estiver vinculado, nao sobrescreve.
     */
    public function attachMpPreapprovalId(int $subscriptionId, string $mpPreapprovalId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE subscriptions
                SET mp_preapproval_id = :mpid,
                    updated_at = NOW()
              WHERE id = :id
                AND (mp_preapproval_id IS NULL OR mp_preapproval_id = '')"
        );
        $stmt->execute([':mpid' => $mpPreapprovalId, ':id' => $subscriptionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cria uma nova assinatura para o usuario com os dados iniciais.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions
                (user_id, plan_id, plan_slug, mp_preapproval_id, status,
                 start_date, next_billing_date, raw_status, external_reference)
             VALUES
                (:user_id, :plan_id, :plan_slug, :mp_preapproval_id, :status,
                 :start_date, :next_billing_date, :raw_status, :external_reference)
             RETURNING id'
        );
        $stmt->execute([
            ':user_id'           => (int)$data['user_id'],
            ':plan_id'           => (int)$data['plan_id'],
            ':plan_slug'         => (string)$data['plan_slug'],
            ':mp_preapproval_id' => (string)$data['mp_preapproval_id'],
            ':status'            => (string)($data['status'] ?? self::STATUS_PENDING),
            ':start_date'        => $data['start_date'] ?? null,
            ':next_billing_date' => $data['next_billing_date'] ?? null,
            ':raw_status'        => (string)($data['raw_status'] ?? ''),
            ':external_reference'=> (string)($data['external_reference'] ?? ''),
        ]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Atualiza os dados de MP em uma assinatura existente. Idempotente.
     */
    public function updateMpData(int $id, string $mpPreapprovalId, string $rawStatus, ?string $nextBillingDate): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE subscriptions
                SET mp_preapproval_id = :mpid,
                    raw_status = :raw_status,
                    next_billing_date = COALESCE(:next_billing_date, next_billing_date),
                    updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([
            ':mpid' => $mpPreapprovalId,
            ':raw_status' => $rawStatus,
            ':next_billing_date' => $nextBillingDate,
            ':id' => $id,
        ]);
        return $stmt->rowCount() >= 0;
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
                    grace_period_end = CASE WHEN :clear_grace = 1 THEN NULL ELSE COALESCE(:grace_period_end, grace_period_end) END,
                    cancelled_at = CASE WHEN :status_cancelled THEN NOW() ELSE cancelled_at END,
                    paused_at    = CASE WHEN :status_paused    THEN NOW() ELSE paused_at    END,
                    expired_at   = CASE WHEN :status_expired   THEN NOW() ELSE expired_at   END,
                    start_date   = COALESCE(start_date, :start_date_now),
                    updated_at   = NOW()
              WHERE id = :id'
        );
        $clearGrace = ($newStatus === self::STATUS_ACTIVE || $newStatus === self::STATUS_PAUSED) ? 1 : 0;
        $stmt->execute([
            ':status' => $newStatus,
            ':raw_status' => $rawStatus,
            ':next_billing_date' => $nextBillingDate,
            ':grace_period_end' => $gracePeriodEnd,
            ':clear_grace' => $clearGrace,
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
            $stmtPlan = $this->db->prepare('SELECT plano FROM usuarios WHERE id = :uid LIMIT 1');
            $stmtPlan->execute([':uid' => $userId]);
            $currentPlan = (string)$stmtPlan->fetchColumn();
            if (in_array($currentPlan, ['pro', 'premium'], true) && $currentPlan !== $planSlug) {
                return false;
            }
            $stmtCheck = $this->db->prepare(
                'SELECT active_subscription_id FROM usuarios WHERE id = :uid LIMIT 1'
            );
            $stmtCheck->execute([':uid' => $userId]);
            $currentActive = $stmtCheck->fetchColumn();
            if ((int)$currentActive !== (int)$subscription['id']) {
                $stmtRestore = $this->db->prepare(
                    "UPDATE usuarios SET active_subscription_id = :sub_id, updated_at = NOW()
                       WHERE id = :uid"
                );
                $stmtRestore->execute([':sub_id' => $subscription['id'], ':uid' => $userId]);
            }
            $this->clearGracePeriod($userId);
            $this->grantAccess($userId, $planSlug, (int)$subscription['id']);
            return true;
        }
        if ($status === self::STATUS_REJECTED) {
            $stmtCheck = $this->db->prepare(
                'SELECT active_subscription_id FROM usuarios WHERE id = :uid LIMIT 1'
            );
            $stmtCheck->execute([':uid' => $userId]);
            $currentActive = $stmtCheck->fetchColumn();
            if ((int)$currentActive !== (int)$subscription['id']) {
                return false;
            }
            $this->startGracePeriodOrRevoke($userId, $planSlug, (int)$subscription['id'], $subscription);
            return true;
        }
        if ($status === self::STATUS_CANCELLED || $status === self::STATUS_EXPIRED) {
            $stmtCheck = $this->db->prepare(
                'SELECT active_subscription_id FROM usuarios WHERE id = :uid LIMIT 1'
            );
            $stmtCheck->execute([':uid' => $userId]);
            $currentActive = $stmtCheck->fetchColumn();
            if ((int)$currentActive !== (int)$subscription['id']) {
                return false;
            }
            $this->startGracePeriodOrRevoke($userId, $planSlug, (int)$subscription['id'], $subscription);
            return true;
        }
        return false;
    }

    private function clearGracePeriod(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios SET plano_fim = NULL, updated_at = NOW() WHERE id = :uid"
        );
        $stmt->execute([':uid' => $userId]);
    }

    private function grantAccess(int $userId, string $planSlug, int $subscriptionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios
                SET plano = :plan,
                    plano_status = 'ativo',
                    plano_inicio = COALESCE(plano_inicio, NOW()),
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

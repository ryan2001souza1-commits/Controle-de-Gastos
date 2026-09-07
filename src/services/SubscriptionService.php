<?php
/**
 * SubscriptionService — orquestra o ciclo de vida das assinaturas MP.
 *
 * Responsabilidades:
 * - mapear slug interno (pro|premium) -> MERCADOPAGO_PLAN_ID_* (via getenv,
 *   nunca do frontend);
 * - gerar attempt_token (random_bytes) + external_reference
 *   "user_<USER_ID>_<plan>_<attempt>" e persistir ANTES de chamar a API;
 * - criar preapproval (POST /preapproval) e guardar mp_preapproval_id +
 *   checkout_url (init_point);
 * - sincronizar estado local a partir do objeto da API (fonte da verdade),
 *   de forma idempotente e transacional;
 * - cancelar a assinatura ativa do próprio usuário (PUT /preapproval).
 *
 * Regras de segurança:
 * - payer_id/e-mail/CPF NUNCA identificam o usuário; só external_reference
 *   + attempt_token + mp_preapproval_id, validados contra o banco.
 * - status "pending" NUNCA libera plano pago; só "authorized" ativa.
 */
class SubscriptionService
{
    public const ALLOWED_PLANS = ['pro', 'premium'];

    // Status locais (enum da tabela subscriptions).
    public const LOCAL_PENDING   = 'pending';
    public const LOCAL_ACTIVE    = 'active';
    public const LOCAL_PAUSED    = 'paused';
    public const LOCAL_CANCELLED = 'cancelled';
    public const LOCAL_EXPIRED   = 'expired';
    public const LOCAL_REJECTED  = 'rejected';

    private PDO $db;
    private MercadoPagoClient $mp;

    public function __construct(PDO $db, ?MercadoPagoClient $mp = null)
    {
        $this->db = $db;
        $this->mp = $mp ?? new MercadoPagoClient();
    }

    // =====================================================================
    // Configuração (via ambiente, nunca hardcoded)
    // =====================================================================

    public static function planIdFor(string $planSlug): string
    {
        $key = $planSlug === 'premium'
            ? 'MERCADOPAGO_PLAN_ID_PREMIUM'
            : 'MERCADOPAGO_PLAN_ID_PRO';
        $v = getenv($key);
        if (is_string($v) && trim($v) !== '') return trim($v);
        if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') return trim((string)$_ENV[$key]);
        if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') return trim((string)$_SERVER[$key]);
        return '';
    }

    public static function isConfigured(string $planSlug): bool
    {
        return self::planIdFor($planSlug) !== '' && self::accessTokenPresent();
    }

    public static function accessTokenPresent(): bool
    {
        $v = getenv('MERCADOPAGO_ACCESS_TOKEN');
        if (is_string($v) && $v !== '') return true;
        if (isset($_ENV['MERCADOPAGO_ACCESS_TOKEN']) && $_ENV['MERCADOPAGO_ACCESS_TOKEN'] !== '') return true;
        if (isset($_SERVER['MERCADOPAGO_ACCESS_TOKEN']) && $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] !== '') return true;
        return false;
    }

    // =====================================================================
    // external_reference
    // =====================================================================

    public static function buildAttemptToken(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex chars
    }

    public static function buildExternalReference(int $userId, string $planSlug, string $attemptToken): string
    {
        return 'user_' . $userId . '_' . $planSlug . '_' . $attemptToken;
    }

    /**
     * Parse estrito de "user_<ID>_<plan>_<attempt>".
     * @return array{user_id:int,plan:string,attempt:string}|null
     */
    public static function parseExternalReference(string $ref): ?array
    {
        if (strlen($ref) > 120) return null;
        if (!preg_match('/^user_(\d+)_(pro|premium)_([0-9a-f]{32})$/', $ref, $m)) {
            return null;
        }
        $userId = (int)$m[1];
        if ($userId <= 0) return null;
        return ['user_id' => $userId, 'plan' => $m[2], 'attempt' => $m[3]];
    }

    // =====================================================================
    // Início da assinatura (chamado pela action POST autenticada + CSRF)
    // =====================================================================

    /**
     * @return array{ok:bool,error:string,init_point:string,subscription_id:int}
     *   error: invalid_plan|already_subscribed|config_error|service_error
     */
    public function start(int $userId, string $userEmail, string $planSlug): array
    {
        $planSlug = strtolower(trim($planSlug));
        if (!in_array($planSlug, self::ALLOWED_PLANS, true)) {
            return ['ok' => false, 'error' => 'invalid_plan', 'init_point' => '', 'subscription_id' => 0];
        }
        if ($userId <= 0 || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid_plan', 'init_point' => '', 'subscription_id' => 0];
        }

        // Bloqueia nova tentativa se já existe assinatura ativa/pendente.
        $existing = $this->findOpenForUser($userId);
        if ($existing !== null) {
            return ['ok' => false, 'error' => 'already_subscribed', 'init_point' => '', 'subscription_id' => 0];
        }

        $mpPlanId = self::planIdFor($planSlug);
        if ($mpPlanId === '' || !self::accessTokenPresent()) {
            error_log('[subscription] start config ausente para plano=' . $planSlug);
            return ['ok' => false, 'error' => 'config_error', 'init_point' => '', 'subscription_id' => 0];
        }

        $planRow = $this->findPlanRow($planSlug);
        if ($planRow === null) {
            return ['ok' => false, 'error' => 'invalid_plan', 'init_point' => '', 'subscription_id' => 0];
        }

        // Persiste a tentativa ANTES de chamar a API (garante unicidade do
        // attempt_token mesmo se o MP demorar/falhar).
        $attempt = self::buildAttemptToken();
        $externalRef = self::buildExternalReference($userId, $planSlug, $attempt);
        try {
            $ins = $this->db->prepare(
                'INSERT INTO subscriptions (user_id, plan_id, plan_slug, status, attempt_token, external_reference)
                 VALUES (?, ?, ?, \'pending\', ?, ?)'
            );
            $ins->execute([$userId, (int)$planRow['id'], $planSlug, $attempt, $externalRef]);
            $localId = (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[subscription] insert pending falhou: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'service_error', 'init_point' => '', 'subscription_id' => 0];
        }

        $payload = [
            'preapproval_plan_id' => $mpPlanId,
            'reason'              => $this->reasonFor($planSlug),
            'payer_email'         => $userEmail,
            'external_reference'  => $externalRef,
            'back_url'            => $this->backUrl(),
        ];

        $res = $this->mp->createSubscription($payload);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => 'service_error', 'init_point' => '', 'subscription_id' => $localId];
        }

        $mpId = (string)($res['data']['id'] ?? '');
        $initPoint = (string)($res['data']['init_point'] ?? '');
        $rawStatus = strtolower(trim((string)($res['data']['status'] ?? 'pending')));
        if ($mpId === '' || $initPoint === '' || !$this->isHttpsMpUrl($initPoint)) {
            error_log('[subscription] resposta MP sem init_point valido');
            return ['ok' => false, 'error' => 'service_error', 'init_point' => '', 'subscription_id' => $localId];
        }

        try {
            $up = $this->db->prepare(
                'UPDATE subscriptions
                    SET mp_preapproval_id = ?, checkout_url = ?, raw_status = ?, updated_at = NOW()
                  WHERE id = ?'
            );
            $up->execute([$mpId, $initPoint, substr($rawStatus, 0, 40), $localId]);
        } catch (Throwable $e) {
            // UNIQUE violado = corrida improvável (mp_id já vinculado); mantém tentativa.
            error_log('[subscription] update mp_id falhou id=' . $localId . ': ' . $e->getMessage());
        }

        return ['ok' => true, 'error' => '', 'init_point' => $initPoint, 'subscription_id' => $localId];
    }

    // =====================================================================
    // Sincronização a partir do objeto da API (fonte da verdade)
    // =====================================================================

    /**
     * Sincroniza assinatura local com o objeto retornado por GET /preapproval.
     * Idempotente: reaplicar o mesmo estado não muda nada nem duplica.
     *
     * @param array $api objeto da assinatura vindo da API do MP
     * @return array{ok:bool,error:string,local_status:string}
     */
    public function syncFromApi(array $api): array
    {
        $mpId = (string)($api['id'] ?? '');
        $apiStatus = strtolower(trim((string)($api['status'] ?? '')));
        $externalRef = (string)($api['external_reference'] ?? '');

        if ($mpId === '' || $apiStatus === '' || $externalRef === '') {
            return ['ok' => false, 'error' => 'invalid_api_object', 'local_status' => ''];
        }

        $parsed = self::parseExternalReference($externalRef);
        if ($parsed === null) {
            error_log('[subscription] external_reference invalido no sync');
            return ['ok' => false, 'error' => 'external_reference_mismatch', 'local_status' => ''];
        }

        // Localiza a tentativa: primeiro por mp_id, depois por attempt.
        $local = $this->findByMpId($mpId);
        if ($local === null) {
            $local = $this->findByAttempt($parsed['attempt']);
        }
        if ($local === null) {
            error_log('[subscription] sync sem tentativa local mp_id=' . $this->maskId($mpId));
            return ['ok' => false, 'error' => 'unknown_subscription', 'local_status' => ''];
        }

        // Validação cruzada estrita: nada é atualizado sob inconsistência.
        if ((int)$local['user_id'] !== $parsed['user_id']
            || (string)$local['plan_slug'] !== $parsed['plan']
            || (string)($local['external_reference'] ?? '') !== $externalRef
        ) {
            error_log('[subscription] divergencia external_reference x banco');
            return ['ok' => false, 'error' => 'external_reference_mismatch', 'local_status' => ''];
        }
        // Nunca associar somente por payer: o payer da API é só auditoria.
        // (Nenhum campo payer_* é usado para localizar usuário.)

        $mapped = $this->mapApiStatus($apiStatus);
        if ($mapped === null) {
            error_log('[subscription] status MP desconhecido: ' . substr($apiStatus, 0, 40));
            return ['ok' => false, 'error' => 'unknown_status', 'local_status' => ''];
        }

        try {
            $this->db->beginTransaction();

            $upd = $this->db->prepare(
                'UPDATE subscriptions
                    SET mp_preapproval_id = COALESCE(NULLIF(mp_preapproval_id,\'\'), ?),
                        raw_status = ?, status = ?, updated_at = NOW()
                  WHERE id = ?'
            );
            // raw_status limitado a 40 chars (constraint atual do schema).
            $upd->execute([$mpId, substr($apiStatus, 0, 40), $mapped, (int)$local['id']]);

            $userId = (int)$local['user_id'];
            $planSlug = (string)$local['plan_slug'];
            $localId = (int)$local['id'];

            if ($mapped === self::LOCAL_ACTIVE) {
                $u = $this->db->prepare(
                    "UPDATE usuarios
                        SET plano = ?, plano_status = 'ativo', plano_inicio = COALESCE(plano_inicio, NOW()),
                            plano_fim = NULL, active_subscription_id = ?, updated_at = NOW()
                      WHERE id = ?"
                );
                $u->execute([$planSlug, $localId, $userId]);
            } elseif ($mapped === self::LOCAL_CANCELLED || $mapped === self::LOCAL_EXPIRED) {
                // Downgrade somente se a assinatura cancelada era a ativa.
                $chk = $this->db->prepare('SELECT active_subscription_id, plano FROM usuarios WHERE id = ?');
                $chk->execute([$userId]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                if ($row && (int)($row['active_subscription_id'] ?? 0) === $localId) {
                    $u = $this->db->prepare(
                        "UPDATE usuarios
                            SET plano = 'gratuito', plano_status = 'ativo',
                                active_subscription_id = NULL, updated_at = NOW()
                          WHERE id = ?"
                    );
                    $u->execute([$userId]);
                }
            }
            // pending/paused/rejected: atualizam só a linha da assinatura,
            // nunca liberam nem revogam plano pago.

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[subscription] sync transaction falhou: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db_error', 'local_status' => ''];
        }

        return ['ok' => true, 'error' => '', 'local_status' => $mapped];
    }

    /**
     * Mapeia status da API (preapproval) -> status local.
     * Confirmado na documentação: pending, authorized, paused, cancelled.
     */
    public function mapApiStatus(string $apiStatus): ?string
    {
        switch (strtolower(trim($apiStatus))) {
            case 'pending':   return self::LOCAL_PENDING;
            case 'authorized':return self::LOCAL_ACTIVE;
            case 'paused':    return self::LOCAL_PAUSED;
            case 'cancelled': return self::LOCAL_CANCELLED;
            default:          return null;
        }
    }

    // =====================================================================
    // Cancelamento (assinatura ativa do próprio usuário)
    // =====================================================================

    /**
     * @return array{ok:bool,error:string}
     *   error: no_active_subscription|cancel_service_error
     */
    public function cancelActive(int $userId): array
    {
        $active = $this->findActiveForUser($userId);
        if ($active === null || empty($active['mp_preapproval_id'])) {
            return ['ok' => false, 'error' => 'no_active_subscription'];
        }
        $mpId = (string)$active['mp_preapproval_id'];

        $res = $this->mp->updateSubscription($mpId, ['status' => 'cancelled']);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => 'cancel_service_error'];
        }

        // Reconsulta a API (fonte da verdade) e sincroniza.
        $fresh = $this->mp->getSubscription($mpId);
        if ($fresh['ok']) {
            $sync = $this->syncFromApi($fresh['data']);
            if (!$sync['ok']) {
                return ['ok' => false, 'error' => 'cancel_service_error'];
            }
            return ['ok' => true, 'error' => ''];
        }
        // Se a leitura falhar, marca cancelamento local pendente de webhook.
        try {
            $up = $this->db->prepare(
                "UPDATE subscriptions SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW()
                  WHERE id = ?"
            );
            $up->execute([(int)$active['id']]);
        } catch (Throwable $e) {
            error_log('[subscription] cancel fallback falhou: ' . $e->getMessage());
        }
        return ['ok' => true, 'error' => ''];
    }

    // =====================================================================
    // Consultas
    // =====================================================================

    /** Assinatura aberta (pending/paused com checkout) do usuário, se houver. */
    public function findOpenForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM subscriptions
              WHERE user_id = ? AND status IN ('pending','paused')
              ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Assinatura ativa (local) do usuário, se houver. */
    public function findActiveForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM subscriptions
              WHERE user_id = ? AND status = 'active'
              ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByMpId(string $mpId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscriptions WHERE mp_preapproval_id = ? LIMIT 1');
        $stmt->execute([$mpId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByAttempt(string $attempt): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscriptions WHERE attempt_token = ? LIMIT 1');
        $stmt->execute([$attempt]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    private function findPlanRow(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM planos WHERE slug = ? AND status = 'ativo' LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function reasonFor(string $planSlug): string
    {
        return $planSlug === 'premium'
            ? 'Assinatura Premium — Controle de Gastos'
            : 'Assinatura Pro — Controle de Gastos';
    }

    private function backUrl(): string
    {
        $base = getenv('APP_URL');
        if (!is_string($base) || $base === '') {
            $base = $_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? '');
        }
        $base = rtrim((string)$base, '/');
        if ($base === '' || stripos($base, 'http') !== 0) {
            $vercel = getenv('VERCEL_URL');
            if (is_string($vercel) && $vercel !== '') {
                $base = 'https://' . ltrim($vercel, '/');
            }
        }
        if ($base === '') $base = 'https://controle-de-gastos-one-silk.vercel.app';
        return $base . '/index.php?action=mp_return';
    }

    /** init_point deve ser HTTPS em host do Mercado Pago. */
    public function isHttpsMpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') return false;
        foreach (['mercadopago.com', 'mercadopago.com.br', 'mercadolibre.com'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) return true;
        }
        return false;
    }

    public function maskId(string $id): string
    {
        $len = strlen($id);
        if ($len <= 8) return '****';
        return substr($id, 0, 4) . '****' . substr($id, -4);
    }
}

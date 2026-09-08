<?php
/**
 * SubscriptionService — gerencia assinaturas internas do sistema.
 *
 * Separação de responsabilidades (ETAPA 1):
 *   SubscriptionService = regra de negócio (planos, usuário, banco, status).
 *   MercadoPagoClient   = SOMENTE comunicação HTTP com a API oficial.
 *
 * Referência oficial (fonte de verdade):
 *   https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/overview
 *
 * Status locais (enum da tabela subscriptions):
 *   pending   - tentativa criada, aguardando autorização no checkout MP
 *   active    - ativa (ativação SOMENTE via confirmação backend — ETAPA 2)
 *   paused    - pausada
 *   cancelled - cancelada
 *   expired   - período pago terminou
 *   rejected  - recusada
 *
 * REGRA: criar /preapproval NÃO ativa plano pago. A ativação acontecerá
 * somente após confirmação pelo backend (API/webhook — ETAPA 2).
 */
if (!class_exists('MercadoPagoClient', false)) {
    require_once __DIR__ . '/MercadoPagoClient.php';
}

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
    // Configuração (lida de ambiente, SOMENTE backend)
    // =====================================================================

    /**
     * Verifica se há gateway configurado para o plano (token + plan ID).
     * Sem configuração, start() falha com config_error (fail-closed).
     */
    public static function isConfigured(string $planSlug): bool
    {
        return MercadoPagoClient::readAccessToken() !== ''
            && self::planMpId($planSlug) !== '';
    }

    /**
     * Mapeia slug interno -> preapproval_plan_id do Mercado Pago.
     * Estrito: qualquer slug fora de pro/premium retorna ''.
     */
    public static function planMpId(string $planSlug): string
    {
        if ($planSlug === 'pro') {
            return MercadoPagoClient::readEnv('MERCADOPAGO_PLAN_ID_PRO');
        }
        if ($planSlug === 'premium') {
            return MercadoPagoClient::readEnv('MERCADOPAGO_PLAN_ID_PREMIUM');
        }
        return '';
    }

    // =====================================================================
    // external_reference (estrutura preservada para futuro gateway)
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
    // Início da assinatura (POST /preapproval oficial)
    // =====================================================================

    /**
     * Cria tentativa local + assinatura no Mercado Pago e devolve o
     * checkout (init_point) para redirecionar o usuário.
     *
     * NÃO ativa plano pago: a linha local nasce 'pending' e o plano do
     * usuário permanece inalterado até confirmação backend (ETAPA 2).
     *
     * @return array{ok:bool,error:string,init_point:string,subscription_id:int}
     *   error: invalid_plan|plan_not_found|already_subscribed|config_error|
     *          gateway_error|service_error
     */
    public function start(int $userId, string $userEmail, string $planSlug): array
    {
        $fail = static fn(string $e) => ['ok' => false, 'error' => $e, 'init_point' => '', 'subscription_id' => 0];

        $planSlug = strtolower(trim($planSlug));
        if (!in_array($planSlug, self::ALLOWED_PLANS, true)) {
            return $fail('invalid_plan');
        }

        $planRow = $this->findPlanRow($planSlug);
        if ($planRow === null) {
            return $fail('plan_not_found');
        }

        if ($this->findActiveForUser($userId) !== null) {
            return $fail('already_subscribed');
        }

        // Tentativa aberta anterior: retoma checkout existente (evita
        // duplicar preapproval no MP) ou descarta órfã sem mp_id.
        $open = $this->findOpenForUser($userId);
        if ($open !== null) {
            if (!empty($open['mp_preapproval_id']) && !empty($open['checkout_url'])
                && $this->isHttpsUrl((string)$open['checkout_url'])
            ) {
                return [
                    'ok' => true, 'error' => '',
                    'init_point' => (string)$open['checkout_url'],
                    'subscription_id' => (int)$open['id'],
                ];
            }
            try {
                $del = $this->db->prepare('DELETE FROM subscriptions WHERE id = ?');
                $del->execute([(int)$open['id']]);
            } catch (Throwable $e) {
                error_log('[subscription] limpeza de tentativa orfa falhou');
                return $fail('service_error');
            }
        }

        $mpPlanId = self::planMpId($planSlug);
        if (!$this->mp->hasToken() || $mpPlanId === '') {
            return $fail('config_error');
        }

        $attempt = self::buildAttemptToken();
        $externalRef = self::buildExternalReference($userId, $planSlug, $attempt);

        try {
            $ins = $this->db->prepare(
                'INSERT INTO subscriptions
                    (user_id, plan_id, plan_slug, status, attempt_token, external_reference)
                 VALUES (?, ?, ?, \'pending\', ?, ?)'
            );
            $ins->execute([$userId, (int)$planRow['id'], $planSlug, $attempt, $externalRef]);
            $localId = (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[subscription] insert de tentativa falhou');
            return $fail('service_error');
        }

        // Payload SOMENTE com campos oficiais (POST /preapproval):
        // preapproval_plan_id + payer_email + external_reference + reason + back_url.
        // Sem card_token_id: o pagador autoriza no checkout (init_point).
        $res = $this->mp->createPreapproval([
            'preapproval_plan_id' => $mpPlanId,
            'reason'              => $this->reasonFor($planSlug),
            'external_reference'  => $externalRef,
            'payer_email'         => $userEmail,
            'back_url'            => $this->backUrl(),
        ]);

        if (!$res['ok']) {
            try {
                $del = $this->db->prepare('DELETE FROM subscriptions WHERE id = ?');
                $del->execute([$localId]);
            } catch (Throwable $e) {
                error_log('[subscription] rollback de tentativa falhou id=' . $localId);
            }
            error_log('[subscription] create preapproval falhou err=' . substr($res['error'], 0, 40));
            return $fail('gateway_error');
        }

        $mpId = (string)($res['data']['id'] ?? '');
        $initPoint = (string)($res['data']['init_point'] ?? '');
        $rawStatus = strtolower(trim((string)($res['data']['status'] ?? '')));
        if ($mpId === '' || !$this->isHttpsUrl($initPoint)) {
            try {
                $del = $this->db->prepare('DELETE FROM subscriptions WHERE id = ?');
                $del->execute([$localId]);
            } catch (Throwable $e) {
                error_log('[subscription] rollback de resposta invalida falhou id=' . $localId);
            }
            error_log('[subscription] resposta MP sem id/init_point validos');
            return $fail('gateway_error');
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
    // Cancelamento (via PUT /preapproval/{id} + baixa local)
    // =====================================================================

    /**
     * Cancela a assinatura ativa do usuário.
     *
     * Quando a assinatura possui mp_preapproval_id e há token configurado,
     * cancela primeiro no Mercado Pago (PUT status "canceled", conforme
     * guia oficial de gerenciamento) e só então baixa localmente — assim
     * nunca deixamos cobrança ativa no gateway com plano liberado aqui.
     * Sem vínculo externo, cancela apenas localmente.
     *
     * @return array{ok:bool,error:string}
     *   error: no_active_subscription|cancel_service_error|service_error
     */
    public function cancelActive(int $userId): array
    {
        $active = $this->findActiveForUser($userId);
        if ($active === null) {
            return ['ok' => false, 'error' => 'no_active_subscription'];
        }

        $mpId = (string)($active['mp_preapproval_id'] ?? '');
        if ($mpId !== '') {
            if (!$this->mp->hasToken()) {
                return ['ok' => false, 'error' => 'cancel_service_error'];
            }
            $res = $this->mp->updatePreapproval($mpId, ['status' => 'canceled']);
            if (!$res['ok']) {
                error_log('[subscription] cancel no MP falhou err=' . substr($res['error'], 0, 40));
                return ['ok' => false, 'error' => 'cancel_service_error'];
            }
        }

        try {
            $this->db->beginTransaction();

            $upd = $this->db->prepare(
                "UPDATE subscriptions
                    SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW()
                  WHERE id = ?"
            );
            $upd->execute([(int)$active['id']]);

            // Downgrade do usuário para gratuito se esta era a assinatura ativa
            $chk = $this->db->prepare('SELECT active_subscription_id, plano FROM usuarios WHERE id = ?');
            $chk->execute([$userId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)($row['active_subscription_id'] ?? 0) === (int)$active['id']) {
                $u = $this->db->prepare(
                    "UPDATE usuarios
                        SET plano = 'gratuito', plano_status = 'ativo',
                            active_subscription_id = NULL, updated_at = NOW()
                      WHERE id = ?"
                );
                $u->execute([$userId]);
            }

            $this->db->commit();
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[subscription] cancel falhou: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'service_error'];
        }
    }

    // =====================================================================
    // Consultas (preservadas para uso interno)
    // =====================================================================

    /** Assinatura aberta (pending/paused) do usuário, se houver. */
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

    /**
     * URL de retorno pós-checkout. Resolvida SOMENTE de configuração
     * (APP_URL/VERCEL_URL), nunca de hostname enviado pelo cliente.
     */
    private function backUrl(): string
    {
        $base = MercadoPagoClient::readEnv('APP_URL');
        if ($base === '' || stripos($base, 'http') !== 0) {
            $vercel = MercadoPagoClient::readEnv('VERCEL_URL');
            if ($vercel !== '') {
                $base = 'https://' . ltrim($vercel, '/');
            }
        }
        if ($base === '') $base = 'https://controle-de-gastos-one-silk.vercel.app';
        return rtrim($base, '/') . '/index.php?action=mp_return';
    }

    /** init_point/back_url: exige HTTPS com host (anti open-redirect). */
    public function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') return false;
        return ((string)($parts['host'] ?? '')) !== '';
    }

    public function maskId(string $id): string
    {
        $len = strlen($id);
        if ($len <= 8) return '****';
        return substr($id, 0, 4) . '****' . substr($id, -4);
    }
}
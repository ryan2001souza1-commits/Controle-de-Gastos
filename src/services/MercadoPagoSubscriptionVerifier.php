<?php
require_once __DIR__ . '/MercadoPagoCheckoutStarter.php';

/**
 * MercadoPagoSubscriptionVerifier — verificacao autenticada de assinatura
 * (etapa verify, SEM efeitos no banco).
 *
 * Contexto: ao receber um webhook subscription_preapproval com x-signature
 * valida, a fonte de verdade passa a ser a API oficial — NUNCA o payload
 * do webhook. Este verificador consulta:
 *
 *   GET https://api.mercadopago.com/preapproval/{ID}
 *   Authorization: Bearer <token lido do ambiente no backend>
 *
 * e valida: HTTP 2xx, JSON, id igual ao solicitado, status reconhecido e
 * preapproval_plan_id mapeado para pro/premium via ambiente.
 *
 * Correlacao (separada da verificacao): o checkout hospedado atual apenas
 * redireciona para o init_point do plano — ele NAO define
 * external_reference user_{id}_{plan}. Por isso:
 *   A) verified_subscription = assinatura MP valida (id+status+plano ok);
 *   B) correlated_user = referencia presente no formato esperado E
 *      consistente com o plano (user_id extraido com seguranca).
 * Sem referencia (ausente/vazia) => A pode ser true com B=false e
 * user_id=null. Formato inesperado => rejeitado (nada inventado).
 * payer_email/payer_id NUNCA identificam usuario aqui.
 *
 * NESTA ETAPA: nenhum status altera banco — o resultado e apenas
 * estruturado para consumo futuro. Sem INSERT/UPDATE/DELETE, sem
 * migration, sem ativacao.
 *
 * Seguranca:
 * - ID validado por regex antes de entrar na URL (anti-SSRF); base da API
 *   fixa em api.mercadopago.com com TLS obrigatorio (https).
 * - Timeouts 7s/3s (compativeis com maxDuration 10s da Vercel).
 * - NUNCA loga Authorization, Access Token ou webhook secret.
 * - payer_id/payer_email sao ignorados (nao identificam usuario aqui).
 */
class MercadoPagoSubscriptionVerifier
{
    public const PREAPPROVAL_API_BASE = 'https://api.mercadopago.com/preapproval/';

    /** Status reconhecidos (nenhum deles altera banco nesta etapa). */
    public const KNOWN_STATUSES = ['authorized', 'pending', 'paused', 'cancelled'];

    private const TIMEOUT_SECONDS = 7;
    private const CONNECT_TIMEOUT_SECONDS = 3;

    private $httpHandler;
    private string $accessToken;
    private string $planIdPro;
    private string $planIdPremium;

    /** @param callable|null $httpHandler fn(string $method, string $url, array $headers): array{http_code:int, body:string, error:string} */
    public function __construct(?callable $httpHandler = null, string $accessToken = '', string $planIdPro = '', string $planIdPremium = '')
    {
        $this->httpHandler = $httpHandler;
        $this->accessToken = $accessToken;
        $this->planIdPro = $planIdPro;
        $this->planIdPremium = $planIdPremium;
    }

    public static function fromEnv(?callable $httpHandler = null): self
    {
        return new self(
            $httpHandler,
            MercadoPagoCheckoutStarter::getAccessToken(),
            MercadoPagoCheckoutStarter::getPlanId('pro'),
            MercadoPagoCheckoutStarter::getPlanId('premium')
        );
    }

    /** ID seguro para compor a URL (anti-SSRF junto a base fixa + TLS). */
    public static function isValidPreapprovalId($id): bool
    {
        if (!is_string($id) && !is_int($id)) {
            return false;
        }
        $v = trim((string)$id);
        return $v !== '' && strlen($v) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $v) === 1;
    }

    public static function buildPreapprovalUrl(string $id): string
    {
        return self::PREAPPROVAL_API_BASE . rawurlencode($id);
    }

    /**
     * Resolve plano SOMENTE por preapproval_plan_id (env). Desconhecido => null.
     */
    public static function resolvePlanById(string $planId, string $planIdPro, string $planIdPremium): ?string
    {
        $planId = trim($planId);
        if ($planId === '') {
            return null;
        }
        if ($planIdPro !== '' && hash_equals($planIdPro, $planId)) {
            return 'pro';
        }
        if ($planIdPremium !== '' && hash_equals($planIdPremium, $planId)) {
            return 'premium';
        }
        return null;
    }

    /**
     * Extrai (user_id, plan_slug) de external_reference `user_{ID}_{PLAN}`.
     * Retorna null quando o formato e invalido ou o plano fora de pro/premium.
     *
     * @return array{user_id:int, plan_slug:string}|null
     */
    public static function parseExternalReference($ref): ?array
    {
        if (!is_string($ref)) {
            return null;
        }
        if (!preg_match('/^user_(\d+)_([a-z]+)$/', trim($ref), $m)) {
            return null;
        }
        $userId = (int)$m[1];
        $plan = strtolower($m[2]);
        if ($userId <= 0 || !in_array($plan, ['pro', 'premium'], true)) {
            return null;
        }
        return ['user_id' => $userId, 'plan_slug' => $plan];
    }

    /**
     * Verifica a assinatura real na API oficial.
     *
     * @return array{verified_subscription:bool, correlated_user:bool, reason:string, subscription_id:string|null, user_id:int|null, plan_slug:string|null, status:string|null, retryable:bool}
     *   - verified_subscription: id+status+plano confirmados na API.
     *   - correlated_user: external_reference valida E consistente (user_id
     *     seguro; null quando nao ha correlacao segura — nada e inventado).
     *   - retryable=true: falha temporaria (timeout/5xx/rede/config) — o
     *     chamador deve responder de forma a permitir retry do webhook.
     *   - Sem Access Token, segredo ou dados pessoais no retorno/logs.
     */
    public function verify(string $preapprovalId): array
    {
        $id = trim($preapprovalId);
        if (!self::isValidPreapprovalId($id)) {
            return $this->denied('invalid_id', null, null, null, null);
        }
        if ($this->accessToken === '') {
            error_log('[mp_verify] access token ausente');
            return $this->temporal('missing_token', $id);
        }

        $result = $this->doHttp('GET', self::buildPreapprovalUrl($id), $this->accessToken);
        $httpCode = $result['http_code'];
        $body = $result['body'];
        $curlError = $result['error'];

        if ($curlError !== '') {
            $isTimeout = stripos($curlError, 'timed out') !== false
                || stripos($curlError, 'timeout') !== false
                || stripos($curlError, 'timedout') !== false;
            error_log('[mp_verify] transporte falhou timeout=' . ($isTimeout ? '1' : '0')
                . ' err=' . substr($curlError, 0, 150));
            return $this->temporal($isTimeout ? 'timeout' : 'api_unavailable', $id);
        }
        if ($httpCode === 0) {
            error_log('[mp_verify] http_code=0');
            return $this->temporal('api_unavailable', $id);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            // Qualquer erro da API (401/403/404/429/5xx): nao descartar um
            // evento assinado — respondera de forma a permitir retry.
            error_log('[mp_verify] http_error code=' . $httpCode
                . ' resp=' . substr((string)$body, 0, 200));
            return $this->temporal('http_error', $id);
        }
        if (!is_string($body) || trim($body) === '') {
            error_log('[mp_verify] resposta vazia');
            return $this->denied('invalid_response', $id, null, null, null);
        }
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            error_log('[mp_verify] json invalido err=' . json_last_error_msg());
            return $this->denied('invalid_json', $id, null, null, null);
        }

        $returnedId = trim((string)($data['id'] ?? ''));
        if ($returnedId === '' || $returnedId !== $id) {
            error_log('[mp_verify] id divergente');
            return $this->denied('id_mismatch', $id, null, null, null);
        }

        $status = strtolower(trim((string)($data['status'] ?? '')));
        if (!in_array($status, self::KNOWN_STATUSES, true)) {
            error_log('[mp_verify] status nao reconhecido status=' . substr($status, 0, 20));
            return $this->denied('unknown_status', $id, null, null, $status === '' ? null : $status);
        }

        $planSlug = self::resolvePlanById((string)($data['preapproval_plan_id'] ?? ''), $this->planIdPro, $this->planIdPremium);
        if ($planSlug === null) {
            error_log('[mp_verify] plan_id desconhecido');
            return $this->denied('plan_unknown', $id, null, null, $status);
        }

        // Correlacao B (separada da verificacao A): o checkout hospedado
        // atual NAO define external_reference — ausente/vazio => assinatura
        // pode ser valida sem usuario correlacionado (user_id=null).
        $rawRef = $data['external_reference'] ?? null;
        if ($rawRef === null || (is_string($rawRef) && trim($rawRef) === '')) {
            return [
                'verified_subscription' => true,
                'correlated_user' => false,
                'reason' => 'uncorrelated',
                'subscription_id' => $id,
                'user_id' => null,
                'plan_slug' => $planSlug,
                'status' => $status,
                'retryable' => false,
            ];
        }

        $ref = self::parseExternalReference($rawRef);
        if ($ref === null) {
            if (is_string($rawRef) && preg_match('/^user_(\d+)_([a-z]+)$/', trim($rawRef), $m) && (int)$m[1] <= 0) {
                error_log('[mp_verify] user_id invalido na referencia');
                return $this->denied('invalid_user', $id, null, $planSlug, $status);
            }
            error_log('[mp_verify] external_reference inesperada');
            return $this->denied('bad_reference', $id, null, $planSlug, $status);
        }
        if ($ref['plan_slug'] !== $planSlug) {
            error_log('[mp_verify] divergencia plano_api=' . $planSlug . ' plano_ref=' . $ref['plan_slug']);
            return $this->denied('divergence', $id, null, $planSlug, $status);
        }

        return [
            'verified_subscription' => true,
            'correlated_user' => true,
            'reason' => 'verified',
            'subscription_id' => $id,
            'user_id' => $ref['user_id'],
            'plan_slug' => $planSlug,
            'status' => $status,
            'retryable' => false,
        ];
    }

    /** @return array{verified_subscription:bool, correlated_user:bool, reason:string, subscription_id:string|null, user_id:int|null, plan_slug:string|null, status:string|null, retryable:bool} */
    private function denied(string $reason, ?string $id, ?int $userId, ?string $plan, ?string $status): array
    {
        return [
            'verified_subscription' => false,
            'correlated_user' => false,
            'reason' => $reason,
            'subscription_id' => $id,
            'user_id' => $userId,
            'plan_slug' => $plan,
            'status' => $status,
            'retryable' => false,
        ];
    }

    /** @return array{verified_subscription:bool, correlated_user:bool, reason:string, subscription_id:string|null, user_id:int|null, plan_slug:string|null, status:string|null, retryable:bool} */
    private function temporal(string $reason, ?string $id): array
    {
        return [
            'verified_subscription' => false,
            'correlated_user' => false,
            'reason' => $reason,
            'subscription_id' => $id,
            'user_id' => null,
            'plan_slug' => null,
            'status' => null,
            'retryable' => true,
        ];
    }

    /** @return array{http_code:int, body:string, error:string} */
    private function doHttp(string $method, string $url, string $token): array
    {
        if ($this->httpHandler !== null) {
            $fn = $this->httpHandler;
            $res = $fn($method, $url, ['Authorization: Bearer ***REDACTED***']);
            return [
                'http_code' => (int)($res['http_code'] ?? 0),
                'body' => (string)($res['body'] ?? ''),
                'error' => (string)($res['error'] ?? ''),
            ];
        }

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'Authorization: Bearer ' . $token . "\r\n",
                    'timeout' => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            $code = 0;
            $hdr = $http_response_header ?? [];
            foreach ($hdr as $h) {
                if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $h, $m)) {
                    $code = (int)$m[1];
                    break;
                }
            }
            if ($resp === false) {
                $last = error_get_last();
                return ['http_code' => $code, 'body' => '', 'error' => (string)($last['message'] ?? 'conexao falhou')];
            }
            return ['http_code' => $code, 'body' => (string)$resp, 'error' => ''];
        }

        // curl SEM curl_close (compat PHP 8.5 — igual aos demais services).
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);
        $resp = curl_exec($ch);
        $err = (string)curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            return ['http_code' => $code, 'body' => '', 'error' => $err !== '' ? $err : 'conexao falhou'];
        }
        return ['http_code' => $code, 'body' => (string)$resp, 'error' => ''];
    }
}

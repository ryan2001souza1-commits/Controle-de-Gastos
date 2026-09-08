<?php
/**
 * MercadoPagoCheckoutStarter — inicio MINIMO de assinatura via checkout
 * hospedado do plano (etapa revisada).
 *
 * Motivo da troca: a API atual do Mercado Pago exige `card_token_id` ao
 * criar assinatura com plano via POST /preapproval (HTTP 400 sem ele).
 * Por decisao do projeto NAO ha CardForm/tokenizacao no site, portanto
 * esta etapa NAO faz POST /preapproval. Em vez disso, consulta o plano
 * existente e redireciona para o checkout OFICIAL dele:
 *
 *   GET https://api.mercadopago.com/preapproval_plan/{PLAN_ID}
 *   Authorization: Bearer MERCADOPAGO_ACCESS_TOKEN
 *
 * Escopo desta etapa:
 * - SOMENTE le o plano e redireciona para o init_point OFICIAL retornado.
 * - NAO implementa webhook, NAO altera banco, NAO ativa plano,
 *   NAO usa CardForm/MercadoPago.js, NAO tokeniza cartao,
 *   NAO faz polling, NAO usa Public Key, NAO cria planos no MP,
 *   NAO faz POST /preapproval.
 *
 * Seguranca:
 * - Le SOMENTE: MERCADOPAGO_ACCESS_TOKEN, MERCADOPAGO_PLAN_ID_PRO,
 *   MERCADOPAGO_PLAN_ID_PREMIUM.
 * - NUNCA expoe o Access Token ao frontend/HTML/JS/logs/erros/URL.
 * - O retorno do checkout NAO ativa plano (serve so para voltar ao site).
 */

class MpCheckoutException extends RuntimeException
{
    /** Codigo interno (nunca exibido com detalhes sensiveis ao usuario). */
    public string $reason;

    public function __construct(string $reason, string $publicMessage)
    {
        $this->reason = $reason;
        parent::__construct($publicMessage);
    }
}

class MercadoPagoCheckoutStarter
{
    public const PLAN_API_BASE = 'https://api.mercadopago.com/preapproval_plan/';

    /** Whitelist estrita de planos aceitos nesta etapa. */
    public const ALLOWED_PLANS = ['pro', 'premium'];

    /**
     * Timeouts curtos e explicitos — devem ficar ABAIXO do maxDuration
     * da function Vercel (10s em vercel.json). Nao aumentar sem
     * justificativa tecnica forte; prefira falhar rapido e orientar retry.
     */
    private const TIMEOUT_SECONDS = 7;
    private const CONNECT_TIMEOUT_SECONDS = 3;

    /** @var callable|null Handler injetavel para testes: fn(string $method, string $url, array $headers): array{http_code:int, body:string, error:string} */
    private $httpHandler;

    public function __construct(?callable $httpHandler = null)
    {
        $this->httpHandler = $httpHandler;
    }

    // ------------------------------------------------------------------
    // Env (somente as chaves permitidas nesta etapa)
    // ------------------------------------------------------------------

    private static function env(string $key): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                $v = (string)$_ENV[$key];
            } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                $v = (string)$_SERVER[$key];
            } else {
                return '';
            }
        }
        $v = trim((string)$v);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        return trim($v);
    }

    public static function normalizePlan(?string $plan): string
    {
        return strtolower(trim((string)($plan ?? '')));
    }

    public static function isPlanAllowed(?string $plan): bool
    {
        return in_array(self::normalizePlan($plan), self::ALLOWED_PLANS, true);
    }

    public static function getAccessToken(): string
    {
        return self::env('MERCADOPAGO_ACCESS_TOKEN');
    }

    public static function getPlanId(string $plan): string
    {
        $p = self::normalizePlan($plan);
        if ($p === 'pro') {
            return self::env('MERCADOPAGO_PLAN_ID_PRO');
        }
        if ($p === 'premium') {
            return self::env('MERCADOPAGO_PLAN_ID_PREMIUM');
        }
        return '';
    }

    /**
     * URL de consulta do plano (GET). O ID vem do ambiente, nunca do navegador.
     */
    public static function buildPlanUrl(string $planId): string
    {
        return self::PLAN_API_BASE . rawurlencode($planId);
    }

    /**
     * Extrai a URL OFICIAL de checkout da resposta da API do plano.
     * Nunca monta URL manualmente quando a API fornece uma.
     *
     * Seguranca: aceita SOMENTE HTTPS em host oficial do Mercado Pago
     * (mercadopago.com / mercadopago.com.br e subdominios). Qualquer outro
     * host e rejeitado (falha segura) para evitar open-redirect.
     */
    public static function extractCheckoutUrl(array $data): ?string
    {
        foreach (['init_point', 'sandbox_init_point'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $url = trim($data[$key]);
                if (self::isOfficialCheckoutUrl($url)) {
                    return $url;
                }
            }
        }
        return null;
    }

    /**
     * Verifica se a URL e HTTPS em host oficial esperado do Mercado Pago.
     */
    public static function isOfficialCheckoutUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') {
            return false;
        }
        if ($host === 'mercadopago.com' || $host === 'mercadopago.com.br') {
            return true;
        }
        if (str_ends_with($host, '.mercadopago.com') || str_ends_with($host, '.mercadopago.com.br')) {
            return true;
        }
        return false;
    }

    /**
     * Valida a resposta do GET /preapproval_plan/{id} e retorna o
     * init_point oficial. Falha segura em qualquer divergencia.
     *
     * @throws MpCheckoutException com reason especifico e mensagem GENERICA.
     */
    public static function validatePlanResponse(array $data, string $expectedPlanId, string $plan, int $userId): string
    {
        $returnedId = trim((string)($data['id'] ?? ''));
        if ($returnedId === '' || $returnedId !== $expectedPlanId) {
            error_log('[mp_checkout] plan_id divergente plano=' . $plan . ' user_id=' . $userId);
            throw new MpCheckoutException('plan_mismatch', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }

        $status = strtolower(trim((string)($data['status'] ?? '')));
        if ($status !== 'active') {
            error_log('[mp_checkout] plano nao ativo plano=' . $plan . ' user_id=' . $userId . ' status=' . substr($status, 0, 20));
            throw new MpCheckoutException('plan_inactive', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }

        $checkoutUrl = self::extractCheckoutUrl($data);
        if ($checkoutUrl === null) {
            error_log('[mp_checkout] checkout_url ausente/invalida plano=' . $plan . ' user_id=' . $userId
                . ' keys=' . implode(',', array_slice(array_keys($data), 0, 10)));
            throw new MpCheckoutException('missing_checkout_url', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }

        return $checkoutUrl;
    }

    /**
     * Consulta o plano no Mercado Pago e retorna a URL OFICIAL de checkout.
     * Lanca MpCheckoutException com mensagem GENERICA (sem segredos).
     */
    public function resolveCheckoutUrl(int $userId, string $plan): string
    {
        $p = self::normalizePlan($plan);
        if (!in_array($p, self::ALLOWED_PLANS, true)) {
            throw new MpCheckoutException('invalid_plan', 'Plano inválido.');
        }
        if ($userId <= 0) {
            throw new MpCheckoutException('invalid_user', 'Sessão expirada. Entre novamente.');
        }
        $token = self::getAccessToken();
        if ($token === '') {
            error_log('[mp_checkout] access token ausente');
            throw new MpCheckoutException('missing_token', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }
        $planId = self::getPlanId($p);
        if ($planId === '') {
            error_log('[mp_checkout] plan_id ausente para plano=' . $p);
            throw new MpCheckoutException('missing_plan_id', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }

        $url = self::buildPlanUrl($planId);

        // NUNCA logar o token nem os headers de autorizacao.
        $result = $this->doHttp('GET', $url, $token);
        $httpCode = $result['http_code'];
        $body = $result['body'];
        $curlError = $result['error'];

        if ($curlError !== '') {
            $isTimeout = stripos($curlError, 'timed out') !== false
                || stripos($curlError, 'timeout') !== false
                || stripos($curlError, 'timedout') !== false;
            error_log('[mp_checkout] http transport falhou plano=' . $p . ' user_id=' . $userId
                . ' timeout=' . ($isTimeout ? '1' : '0')
                . ' err=' . substr($curlError, 0, 150));
            throw new MpCheckoutException(
                $isTimeout ? 'timeout' : 'api_unavailable',
                'O serviço de pagamento está indisponível. Tente novamente em instantes.'
            );
        }

        if ($httpCode === 0) {
            error_log('[mp_checkout] http_code=0 plano=' . $p . ' user_id=' . $userId);
            throw new MpCheckoutException('api_unavailable', 'O serviço de pagamento está indisponível. Tente novamente em instantes.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Loga snippet sanitizado (sem token) para diagnostico.
            error_log('[mp_checkout] http_error code=' . $httpCode . ' plano=' . $p . ' user_id=' . $userId
                . ' resp=' . substr((string)$body, 0, 300));
            throw new MpCheckoutException('http_error', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }

        if (!is_string($body) || trim($body) === '') {
            error_log('[mp_checkout] resposta vazia code=' . $httpCode . ' plano=' . $p . ' user_id=' . $userId);
            throw new MpCheckoutException('invalid_response', 'Resposta inválida do serviço de pagamento. Tente novamente.');
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            error_log('[mp_checkout] json invalido code=' . $httpCode . ' plano=' . $p . ' user_id=' . $userId
                . ' err=' . json_last_error_msg());
            throw new MpCheckoutException('invalid_json', 'Resposta inválida do serviço de pagamento. Tente novamente.');
        }

        return self::validatePlanResponse($data, $planId, $p, $userId);
    }

    /**
     * @return array{http_code:int, body:string, error:string}
     */
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
            $headers = "Authorization: Bearer " . $token . "\r\n";
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $headers,
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

        // curl SEM curl_close (compat PHP 8.5 — igual a AiService/Mailer).
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

<?php
/**
 * MercadoPagoCheckoutStarter — inicio MINIMO de assinatura (etapa 1).
 *
 * Escopo desta etapa (reconstrucao limpa, incremental):
 * - SOMENTE cria preapproval via POST https://api.mercadopago.com/preapproval
 *   e redireciona para o checkout OFICIAL retornado pela API (init_point).
 * - NAO implementa webhook, NAO altera banco, NAO ativa plano,
 *   NAO usa CardForm/MercadoPago.js, NAO tokeniza cartao,
 *   NAO faz polling, NAO usa Public Key, NAO cria planos no MP.
 *
 * Seguranca:
 * - Le SOMENTE: MERCADOPAGO_ACCESS_TOKEN, MERCADOPAGO_PLAN_ID_PRO,
 *   MERCADOPAGO_PLAN_ID_PREMIUM (+ APP_URL para back_url).
 * - NUNCA expoe o Access Token ao frontend/HTML/JS/logs/erros/URL.
 * - external_reference deterministica: user_{id}_{plano} (ID vem da sessao).
 * - back_url aponta para rota segura de retorno (meu_plano) e NAO ativa plano.
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
    public const API_URL = 'https://api.mercadopago.com/preapproval';

    /** Whitelist estrita de planos aceitos nesta etapa. */
    public const ALLOWED_PLANS = ['pro', 'premium'];

    /**
     * Timeouts curtos e explicitos — devem ficar ABAIXO do maxDuration
     * da function Vercel (10s em vercel.json). Nao aumentar sem
     * justificativa tecnica forte; prefira falhar rapido e orientar retry.
     */
    private const TIMEOUT_SECONDS = 7;
    private const CONNECT_TIMEOUT_SECONDS = 3;

    /** @var callable|null Handler injetavel para testes: fn(string $url, array $headers, string $payload): array{http_code:int, body:string, error:string} */
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
     * Referencia externa deterministica e segura.
     * O ID vem SEMPRE do servidor (sessao), nunca do navegador.
     */
    public static function buildExternalReference(int $userId, string $plan): string
    {
        $p = self::normalizePlan($plan);
        if ($userId <= 0) {
            throw new MpCheckoutException('invalid_user', 'Não foi possível iniciar a assinatura. Tente novamente.');
        }
        if (!in_array($p, self::ALLOWED_PLANS, true)) {
            throw new MpCheckoutException('invalid_plan', 'Plano inválido.');
        }
        return 'user_' . $userId . '_' . $p;
    }

    /**
     * Base URL segura: prefere APP_URL, com fallbacks deterministicos.
     * Nunca confia cegamente em Host sem sanitizar.
     */
    public static function getBaseUrl(?string $appUrlOverride = null): string
    {
        $appUrl = $appUrlOverride !== null ? trim($appUrlOverride) : self::env('APP_URL');
        if ($appUrl !== '') {
            $appUrl = rtrim($appUrl, '/');
            if (filter_var($appUrl, FILTER_VALIDATE_URL)) {
                return $appUrl;
            }
        }
        $vercel = self::env('VERCEL_URL');
        if ($vercel !== '') {
            $vercel = ltrim($vercel, '/');
            if (preg_match('/^[a-z0-9.-]+(\/.*)?$/i', $vercel)) {
                return 'https://' . $vercel;
            }
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['HTTP_X_VERCEL_FORWARDED_PROTO'] ?? '') === 'https');
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $host = preg_replace('/:\d+$/', '', $host);
        if (!preg_match('/^[a-z0-9.-]+$/', $host) || str_contains($host, '..') || $host === '') {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /**
     * back_url aponta para rota segura de retorno.
     * O retorno do navegador NAO ativa Pro/Premium — serve so para
     * trazer o usuario de volta ao site.
     */
    public static function buildBackUrl(?string $appUrlOverride = null): string
    {
        return self::getBaseUrl($appUrlOverride) . '/index.php?action=meu_plano&subscribe=return';
    }

    /**
     * Extrai a URL OFICIAL de checkout da resposta da API.
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
     * Monta o payload minimo do preapproval (sem nenhum segredo no retorno).
     *
     * @return array{url:string, payload:array}
     */
    public static function buildPayload(int $userId, string $email, string $plan, ?string $appUrlOverride = null): array
    {
        $p = self::normalizePlan($plan);
        if (!in_array($p, self::ALLOWED_PLANS, true)) {
            throw new MpCheckoutException('invalid_plan', 'Plano inválido.');
        }
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MpCheckoutException('missing_email', 'Não foi possível iniciar a assinatura. Verifique seu e-mail e tente novamente.');
        }
        $planId = self::getPlanId($p);
        if ($planId === '') {
            error_log('[mp_checkout] plan_id ausente para plano=' . $p);
            throw new MpCheckoutException('missing_plan_id', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }
        return [
            'url' => self::API_URL,
            'payload' => [
                'preapproval_plan_id' => $planId,
                'payer_email' => $email,
                'external_reference' => self::buildExternalReference($userId, $p),
                'back_url' => self::buildBackUrl($appUrlOverride),
            ],
        ];
    }

    /**
     * Inicia a assinatura e retorna a URL OFICIAL de checkout.
     * Lanca MpCheckoutException com mensagem GENERICA (sem segredos).
     */
    public function startForUser(int $userId, string $email, string $plan, ?string $appUrlOverride = null): string
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

        $built = self::buildPayload($userId, $email, $p, $appUrlOverride);
        $payloadJson = json_encode($built['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            error_log('[mp_checkout] falha ao serializar payload plano=' . $p . ' user_id=' . $userId);
            throw new MpCheckoutException('payload_error', 'Não foi possível iniciar a assinatura. Tente novamente.');
        }

        // NUNCA logar o token nem os headers de autorizacao.
        $result = $this->doHttp(self::API_URL, $token, $payloadJson);
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

        $checkoutUrl = self::extractCheckoutUrl($data);
        if ($checkoutUrl === null) {
            error_log('[mp_checkout] checkout_url ausente code=' . $httpCode . ' plano=' . $p . ' user_id=' . $userId
                . ' keys=' . implode(',', array_slice(array_keys($data), 0, 10)));
            throw new MpCheckoutException('missing_checkout_url', 'Não foi possível iniciar a assinatura. Tente novamente mais tarde.');
        }

        return $checkoutUrl;
    }

    /**
     * @return array{http_code:int, body:string, error:string}
     */
    private function doHttp(string $url, string $token, string $payloadJson): array
    {
        if ($this->httpHandler !== null) {
            $fn = $this->httpHandler;
            $res = $fn($url, ['Authorization: Bearer ***REDACTED***', 'Content-Type: application/json'], $payloadJson);
            return [
                'http_code' => (int)($res['http_code'] ?? 0),
                'body' => (string)($res['body'] ?? ''),
                'error' => (string)($res['error'] ?? ''),
            ];
        }

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payloadJson,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                ],
            ]);
            // Header Authorization via stream: montado sem logar.
            $ctxOpts = stream_context_get_options($ctx);
            $headers = "Content-Type: application/json\r\nAuthorization: Bearer " . $token . "\r\n";
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => $payloadJson,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                ],
            ]);
            unset($ctxOpts);
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
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

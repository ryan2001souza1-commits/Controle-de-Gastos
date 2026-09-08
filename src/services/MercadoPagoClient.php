<?php
/**
 * MercadoPagoClient — comunicação HTTP com a API oficial de Assinaturas.
 *
 * Referência oficial (fonte de verdade):
 *   https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/overview
 *   POST https://api.mercadopago.com/preapproval          (criar assinatura)
 *   GET  https://api.mercadopago.com/preapproval/{id}   (obter assinatura)
 *   PUT  https://api.mercadopago.com/preapproval/{id}   (atualizar; ex: status "canceled")
 *
 * Responsabilidade EXCLUSIVA: transporte HTTP + autenticação Bearer.
 * NÃO contém: banco, sessão, HTML, redirects, regras de plano.
 *
 * Segurança:
 * - Access Token existe SOMENTE no backend (lido de env).
 * - Token NUNCA vai para logs, exceptions, responses ou frontend.
 * - IDs usados no path são validados (evita path injection/SSRF).
 *
 * Retorno padrão de todas as operações:
 *   ['ok'=>bool, 'http'=>int, 'data'=>array, 'error'=>string]
 *   error: '' | missing_access_token | invalid_id | timeout |
 *          connection_error | http_400 | http_401 | http_403 | http_404 |
 *          http_409 | http_429 | http_5xx | http_error |
 *          invalid_json | empty_response
 */
class MercadoPagoClient
{
    public const BASE_URL = 'https://api.mercadopago.com';

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    private string $accessToken;

    /**
     * Transporte HTTP injetável (testes). Assinatura:
     *   fn(string $method, string $url, ?array $body, string $token): array
     * Deve retornar o mesmo formato padrão (ok/http/data/error).
     * Quando null, usa cURL (com fallback para PHP streams).
     */
    public static $transport = null;

    public function __construct(?string $accessToken = null)
    {
        $token = $accessToken ?? self::readAccessToken();
        $this->accessToken = is_string($token) ? trim($token) : '';
    }

    /**
     * Lê MERCADOPAGO_ACCESS_TOKEN de getenv/$_ENV/$_SERVER (nesta ordem).
     * Retorna '' quando ausente (o chamador decide como falhar).
     */
    public static function readAccessToken(): string
    {
        return self::readEnv('MERCADOPAGO_ACCESS_TOKEN');
    }

    public static function readEnv(string $key): string
    {
        $v = getenv($key);
        if (is_string($v) && trim($v) !== '') return trim($v);
        if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') return trim((string)$_ENV[$key]);
        if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') return trim((string)$_SERVER[$key]);
        return '';
    }

    public function hasToken(): bool
    {
        return $this->accessToken !== '';
    }

    /**
     * POST /preapproval — cria assinatura (com ou sem preapproval_plan_id).
     * Payload montado pelo chamador SOMENTE com campos oficiais.
     */
    public function createPreapproval(array $payload): array
    {
        return $this->request('POST', '/preapproval', $payload);
    }

    /** GET /preapproval/{id} — fonte da verdade sobre a assinatura. */
    public function getPreapproval(string $id): array
    {
        if (!self::isValidId($id)) {
            return self::fail(0, 'invalid_id');
        }
        return $this->request('GET', '/preapproval/' . $id, null);
    }

    /**
     * GET /authorized_payments/{id} — dados da fatura (tópico oficial
     * subscription_authorized_payment). A resposta traz `preapproval_id`,
     * usado para correlacionar com a assinatura via GET /preapproval/{id}.
     * Referência: .../subscriptions/get-authorized-payment/get
     */
    public function getAuthorizedPayment(string $id): array
    {
        if (!self::isValidId($id)) {
            return self::fail(0, 'invalid_id');
        }
        return $this->request('GET', '/authorized_payments/' . $id, null);
    }

    /**
     * PUT /preapproval/{id} — atualiza assinatura.
     * Cancelamento oficial: ['status' => 'canceled'].
     * Pausa oficial: ['status' => 'paused'].
     */
    public function updatePreapproval(string $id, array $payload): array
    {
        if (!self::isValidId($id)) {
            return self::fail(0, 'invalid_id');
        }
        return $this->request('PUT', '/preapproval/' . $id, $payload);
    }

    /** IDs do MP: opacos, alfanuméricos com - e _. Limite defensivo de 80 chars. */
    public static function isValidId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 80 && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
    }

    private function request(string $method, string $path, ?array $body): array
    {
        if ($this->accessToken === '') {
            return self::fail(0, 'missing_access_token');
        }

        $url = self::BASE_URL . $path;

        if (is_callable(self::$transport)) {
            try {
                $res = (self::$transport)($method, $url, $body, $this->accessToken);
            } catch (Throwable $e) {
                error_log('[mp-client] transport falhou: ' . substr($e->getMessage(), 0, 120));
                return self::fail(0, 'connection_error');
            }
            if (!is_array($res) || !isset($res['ok'])) {
                return self::fail(0, 'connection_error');
            }
            return [
                'ok'    => (bool)($res['ok'] ?? false),
                'http'  => (int)($res['http'] ?? 0),
                'data'  => is_array($res['data'] ?? null) ? $res['data'] : [],
                'error' => (string)($res['error'] ?? 'connection_error'),
            ];
        }

        if (function_exists('curl_init')) {
            return $this->requestCurl($method, $url, $body);
        }
        return $this->requestStream($method, $url, $body);
    }

    private function requestCurl(string $method, string $url, ?array $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return self::fail(0, 'connection_error');
        }

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        // Authorization aplicado via CURLOPT_HTTPHEADER separado para nunca
        // aparecer em logs de headers genéricos.
        $opts[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $this->accessToken;
        if ($body !== null) {
            $json = json_encode($body);
            if ($json === false) {
                return self::fail(0, 'invalid_json');
            }
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            // O handle é liberado pelo GC; não fechar manualmente (compat PHP 8.5).
            error_log('[mp-client] curl erro ' . $errno . ' ' . $method . ' ' . $this->safePath($url));
            return self::fail(0, $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'connection_error');
        }
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return self::parseResponse($http, is_string($raw) ? $raw : '', $method, $url);
    }

    private function requestStream(string $method, string $url, ?array $body): array
    {
        $content = null;
        if ($body !== null) {
            $content = json_encode($body);
            if ($content === false) {
                return self::fail(0, 'invalid_json');
            }
        }
        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'       => $content,
                'timeout'       => self::REQUEST_TIMEOUT,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        // Token via header adicional (fora do bloco genérico acima).
        stream_context_set_option($ctx, 'http', 'header',
            "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer " . $this->accessToken . "\r\n");

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            error_log('[mp-client] stream falhou ' . $method . ' ' . $this->safePath($url));
            return self::fail(0, 'connection_error');
        }
        $http = 0;
        // PHP 8.3+: http_get_last_response_headers() (a variável local
        // $http_response_header é deprecated no PHP 8.5). Fallback para
        // runtimes antigos preserva comportamento idêntico.
        $respHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: [])
            : (isset($http_response_header) && is_array($http_response_header) ? $http_response_header : []);
        if (isset($respHeaders[0]) && preg_match('/\s(\d{3})\s/', $respHeaders[0], $m)) {
            $http = (int)$m[1];
        }
        return self::parseResponse($http, $raw, $method, $url);
    }

    private static function parseResponse(int $http, string $raw, string $method, string $url): array
    {
        if ($http >= 200 && $http < 300) {
            if (trim($raw) === '') {
                return self::fail($http, 'empty_response');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                error_log('[mp-client] JSON invalido http=' . $http . ' ' . $method);
                return self::fail($http, 'invalid_json');
            }
            return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => ''];
        }

        error_log('[mp-client] http=' . $http . ' ' . $method);
        $detail = self::sanitizedErrorDetail($raw);
        if ($detail !== '') {
            error_log('[mp-client] detalhe sanitizado http=' . $http . ': ' . $detail);
        }
        if ($http === 400) return self::fail($http, 'http_400', $detail);
        if ($http === 401) return self::fail($http, 'http_401', $detail);
        if ($http === 403) return self::fail($http, 'http_403', $detail);
        if ($http === 404) return self::fail($http, 'http_404', $detail);
        if ($http === 409) return self::fail($http, 'http_409', $detail);
        if ($http === 429) return self::fail($http, 'http_429', $detail);
        if ($http >= 500)  return self::fail($http, 'http_5xx', $detail);
        return self::fail($http, 'http_error', $detail);
    }

    private static function fail(int $http, string $error, string $detail = ''): array
    {
        return ['ok' => false, 'http' => $http, 'data' => [], 'error' => $error, 'detail' => $detail];
    }

    /**
     * Diagnóstico seguro de corpo de erro (não-2xx).
     * Extrai SOMENTE campos allowlisted (message, error, status, code,
     * cause[].code, cause[].description), redacta qualquer chave com nome
     * sensível e limita tamanho. Nunca recebe nem loga credenciais —
     * o token circula apenas em header, fora do body analisado aqui.
     */
    public static function sanitizedErrorDetail(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $data = json_decode(substr($raw, 0, 4000), true);
        if (!is_array($data)) {
            return substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 200);
        }
        $parts = [];
        foreach (['message', 'error', 'status', 'code'] as $k) {
            if (isset($data[$k]) && (is_string($data[$k]) || is_numeric($data[$k]))) {
                $parts[] = $k . '=' . self::cleanScalar((string)$data[$k]);
            }
        }
        if (isset($data['cause']) && is_array($data['cause'])) {
            $i = 0;
            foreach ($data['cause'] as $cause) {
                if ($i >= 3 || !is_array($cause)) break;
                $c = [];
                foreach (['code', 'description'] as $k) {
                    if (isset($cause[$k]) && (is_string($cause[$k]) || is_numeric($cause[$k]))) {
                        $c[] = $k . '=' . self::cleanScalar((string)$cause[$k]);
                    }
                }
                if ($c !== []) $parts[] = 'cause[' . implode(',', $c) . ']';
                $i++;
            }
        }
        return substr(implode(' ', $parts), 0, 500);
    }

    private static function cleanScalar(string $v): string
    {
        $v = substr(preg_replace('/\s+/', ' ', trim($v)) ?? '', 0, 200);
        // Redação defensiva: valor que pareça segredo vira ***.
        if (preg_match('/^(APP_USR-|TEST-|sk-|whsec-|ya29\.|xox[bpas]-)/', $v) === 1
            || preg_match('/\b(authorization|access_token|secret|password|card|cvv)\b/i', $v) === 1
            || strlen($v) > 120) {
            return '***';
        }
        return $v;
    }

    /** Path sem query para logs (nunca inclui token). */
    private function safePath(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) ? (string)($parts['path'] ?? '?') : '?';
    }
}

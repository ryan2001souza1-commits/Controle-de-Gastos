<?php
/**
 * MercadoPagoClient — cliente REST mínimo para a API de Assinaturas.
 *
 * Usa cURL nativo (sem SDK). Cobre o fluxo oficial:
 *   POST /preapproval          — criar assinatura vinculada a um plano
 *   GET  /preapproval/{id}     — consultar assinatura (fonte da verdade)
 *   PUT  /preapproval/{id}     — pausar/cancelar assinatura
 *
 * Segurança:
 * - Access Token lido SOMENTE via getenv()/$_ENV/$_SERVER em runtime.
 * - Token NUNCA vai para logs, exceptions ou respostas.
 * - Timeouts curtos (connect 5s, total 8s) compatíveis com Vercel (10s).
 * - Sem curl_close() (evita depreciação no PHP 8.5).
 *
 * Testabilidade: o transporte HTTP pode ser substituído via
 * MercadoPagoClient::$transport (callable) nos testes — nenhuma
 * requisição real é feita pela suíte automatizada.
 */
class MercadoPagoClient
{
    public const API_BASE = 'https://api.mercadopago.com';

    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT = 8;

    /** @var callable|null (string $method, string $url, array|null $body, string $token): array */
    public static $transport = null;

    /**
     * Cria uma assinatura vinculada a um plano existente.
     * NUNCA cria preapproval_plan (planos de produção já existem).
     *
     * @return array{ok:bool,http:int,data:array,error:string}
     */
    public function createSubscription(array $payload): array
    {
        return $this->request('POST', self::API_BASE . '/preapproval', $payload);
    }

    /**
     * Consulta uma assinatura pela API (fonte da verdade).
     *
     * @return array{ok:bool,http:int,data:array,error:string}
     */
    public function getSubscription(string $id): array
    {
        $id = trim($id);
        if ($id === '' || strlen($id) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'invalid_id'];
        }
        return $this->request('GET', self::API_BASE . '/preapproval/' . $id, null);
    }

    /**
     * Atualiza uma assinatura (ex: ['status' => 'cancelled']).
     *
     * @return array{ok:bool,http:int,data:array,error:string}
     */
    public function updateSubscription(string $id, array $payload): array
    {
        $id = trim($id);
        if ($id === '' || strlen($id) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'invalid_id'];
        }
        return $this->request('PUT', self::API_BASE . '/preapproval/' . $id, $payload);
    }

    /**
     * @return array{ok:bool,http:int,data:array,error:string}
     */
    private function request(string $method, string $url, ?array $body): array
    {
        $token = $this->readToken();
        if ($token === '') {
            error_log('[mp] MERCADOPAGO_ACCESS_TOKEN ausente no ambiente');
            return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'missing_token'];
        }

        if (self::$transport !== null) {
            try {
                $res = (self::$transport)($method, $url, $body, $token);
                if (!is_array($res)) {
                    return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'transport_error'];
                }
                return [
                    'ok'    => (bool)($res['ok'] ?? false),
                    'http'  => (int)($res['http'] ?? 0),
                    'data'  => is_array($res['data'] ?? null) ? $res['data'] : [],
                    'error' => (string)($res['error'] ?? 'unknown'),
                ];
            } catch (Throwable $e) {
                error_log('[mp] transport exception: ' . $e->getMessage());
                return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'transport_error'];
            }
        }

        if (!function_exists('curl_init')) {
            error_log('[mp] curl indisponivel no ambiente');
            return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'curl_unavailable'];
        }

        $json = null;
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'json_encode_error'];
            }
        }

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno !== 0 ? (string)curl_error($ch) : '';
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Sem curl_close(): o handle é liberado pelo GC; evita depreciação no PHP 8.5.

        if ($resp === false) {
            error_log('[mp] curl fail method=' . $method . ' http=0 errno=' . $errno
                . ' err=' . substr($err, 0, 120));
            return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'network_error'];
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            error_log('[mp] invalid json method=' . $method . ' http=' . $http
                . ' json_err=' . json_last_error_msg());
            return ['ok' => false, 'http' => $http, 'data' => [], 'error' => 'invalid_json'];
        }

        if ($http < 200 || $http >= 300) {
            $msg = (string)($data['message'] ?? $data['error'] ?? 'http_error');
            error_log('[mp] http_error method=' . $method . ' http=' . $http
                . ' msg=' . substr($msg, 0, 160));
            return ['ok' => false, 'http' => $http, 'data' => $data, 'error' => 'http_' . $http];
        }

        return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => ''];
    }

    private function readToken(): string
    {
        $v = getenv('MERCADOPAGO_ACCESS_TOKEN');
        if (is_string($v) && $v !== '') return $v;
        if (isset($_ENV['MERCADOPAGO_ACCESS_TOKEN']) && $_ENV['MERCADOPAGO_ACCESS_TOKEN'] !== '') {
            return (string)$_ENV['MERCADOPAGO_ACCESS_TOKEN'];
        }
        if (isset($_SERVER['MERCADOPAGO_ACCESS_TOKEN']) && $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] !== '') {
            return (string)$_SERVER['MERCADOPAGO_ACCESS_TOKEN'];
        }
        return '';
    }
}

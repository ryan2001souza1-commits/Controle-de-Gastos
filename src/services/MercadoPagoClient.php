<?php
/**
 * MercadoPagoException — erro controlado das chamadas a API oficial.
 *
 * $errorCode e vocabulario estavel para o backend mapear resposta HTTP
 * amigavel, sem vazar credenciais ou payloads sensiveis.
 */
class MercadoPagoException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->code = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getErrorCode(): string
    {
        return (string)$this->code;
    }
}

/**
 * MercadoPagoClient — cliente HTTP minimo (PHP + cURL, sem SDK) para a
 * API oficial de Assinaturas do Mercado Pago.
 *
 * Referencia: https://www.mercadopago.com.br/developers/pt/reference
 * Endpoint desta etapa: POST https://api.mercadopago.com/preapproval
 *
 * Regras rigidas:
 *  - Access Token SOMENTE do ambiente (MERCADOPAGO_ACCESS_TOKEN);
 *  - Authorization Bearer montado aqui, nunca sai deste servico;
 *  - token nunca vai para logs, banco, frontend ou mensagens de erro;
 *  - timeouts curtos (compativel com serverless 10s);
 *  - transporte injetavel para testes (nenhum teste bate na API real).
 */
class MercadoPagoClient
{
    public const BASE_URL = 'https://api.mercadopago.com';
    public const ENV_ACCESS_TOKEN = 'MERCADOPAGO_ACCESS_TOKEN';

    private string $accessToken;
    /** @var callable|null */
    private $transport;
    private int $timeout;
    private int $connectTimeout;

    /**
     * @param string|null $accessToken Se null, le do ambiente.
     * @param callable|null $transport Assinatura p/ testes:
     *   fn(string $method, string $url, array $headers, string $body): array{status:int, body:string, error:string}
     */
    public function __construct(?string $accessToken = null, ?callable $transport = null, int $timeout = 8, int $connectTimeout = 5)
    {
        $this->accessToken = $accessToken ?? self::readEnv(self::ENV_ACCESS_TOKEN);
        $this->transport = $transport;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public static function readEnv(string $key): string
    {
        $v = getenv($key);
        if ($v !== false && trim((string)$v) !== '') {
            return trim((string)$v);
        }
        if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
            return trim((string)$_ENV[$key]);
        }
        if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
            return trim((string)$_SERVER[$key]);
        }
        return '';
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== '';
    }

    /**
     * Valida formato do Device Session ID (doc oficial Subscriptions).
     * Defensivo: somente formato, nunca o valor e registrado/logado.
     */
    public static function isValidDeviceSessionId(?string $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $v = trim($value);
        return $v !== '' && strlen($v) <= 128 && preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $v) === 1;
    }

    /**
     * Sanitiza fragmento vindo da API para log: sem quebras de linha
     * (anti log-injection) e truncado. Nunca recebe segredos.
     */
    private static function logSafe(string $v, int $max = 200): string
    {
        $v = str_replace(["\r", "\n"], ' ', $v);
        return substr(trim($v), 0, $max);
    }

    /**
     * Cria uma assinatura (preapproval) com plano associado + cartao
     * tokenizado no frontend (Core Methods / MercadoPago.js).
     * Checkout com cartao: exige card_token_id e status authorized —
     * o Mercado Pago debita/cobra conforme o plano associado.
     *
     * O numero do cartao/CVV NUNCA chegam aqui: so o card_token_id
     * temporario (uso unico, 7 dias), que jamais e logado ou persistido.
     *
     * @param array{preapproval_plan_id:string, reason:string, external_reference:string, payer_email:string, card_token_id:string, status?:string, back_url?:string} $payload
     * @param string|null $deviceSessionId Device ID (MP_DEVICE_SESSION_ID).
     *   Enviado SOMENTE no header X-meli-session-id, nunca no body, nunca
     *   em log. Null/ausente = header omitido (fluxo continua funcionando).
     * @return array Resposta decodificada (id, init_point, status, ...).
     * @throws MercadoPagoException
     */
    public function createPreapproval(array $payload, ?string $deviceSessionId = null): array
    {
        if (!$this->isConfigured()) {
            throw new MercadoPagoException('mp_not_configured', 'Integracao de pagamento nao configurada no servidor.');
        }
        foreach (['preapproval_plan_id', 'reason', 'external_reference', 'payer_email', 'card_token_id'] as $required) {
            if (!isset($payload[$required]) || trim((string)$payload[$required]) === '') {
                throw new MercadoPagoException('mp_payload_invalido', "Campo obrigatorio ausente: {$required}.");
            }
        }
        $extraHeaders = [];
        if (self::isValidDeviceSessionId($deviceSessionId)) {
            $extraHeaders[] = 'X-meli-session-id: ' . trim((string)$deviceSessionId);
        }
        return $this->request('POST', '/preapproval', $payload, 'create_preapproval', $extraHeaders);
    }

    /**
     * Consulta oficial de uma assinatura (fonte da verdade para o webhook).
     * GET https://api.mercadopago.com/preapproval/{id}
     *
     * @return array Resposta decodificada (id, status, external_reference, ...).
     * @throws MercadoPagoException
     */
    public function getPreapproval(string $id): array
    {
        if (!$this->isConfigured()) {
            throw new MercadoPagoException('mp_not_configured', 'Integracao de pagamento nao configurada no servidor.');
        }
        $id = trim($id);
        if ($id === '' || strlen($id) > 120 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new MercadoPagoException('mp_payload_invalido', 'Identificador de assinatura invalido.');
        }
        return $this->request('GET', '/preapproval/' . rawurlencode($id), null, 'get_preapproval');
    }

    /**
     * @param array|null $payload Null para GET (sem corpo).
     * @param string[] $extraHeaders Headers adicionais seguros (ex: antifraude).
     * @return array Resposta JSON decodificada.
     * @throws MercadoPagoException
     */
    private function request(string $method, string $path, ?array $payload, string $operation, array $extraHeaders = []): array
    {
        $url = self::BASE_URL . $path;
        $body = '';
        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new MercadoPagoException('mp_encode_error', 'Falha ao serializar requisicao de pagamento.');
            }
            $body = $encoded;
        }

        // Authorization e montado aqui e jamais logado. Extra headers sao
        // valores estaticos/seguros definidos pelo chamador (ex: antifraude).
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ], array_values($extraHeaders));

        $start = microtime(true);
        if ($this->transport !== null) {
            $res = ($this->transport)($method, $url, $headers, $body);
            $httpStatus = (int)($res['status'] ?? 0);
            $respBody = (string)($res['body'] ?? '');
            $curlError = (string)($res['error'] ?? '');
        } else {
            if (!function_exists('curl_init')) {
                throw new MercadoPagoException('mp_no_http', 'Cliente HTTP indisponivel no servidor.');
            }
            $ch = curl_init($url);
            $curlOptions = [
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            ];
            if ($method === 'GET') {
                $curlOptions[CURLOPT_HTTPGET] = true;
            } else {
                $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
                $curlOptions[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $curlOptions);
            $exec = curl_exec($ch);
            $curlError = (string)curl_error($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $respBody = $exec === false ? '' : (string)$exec;
        }
        $durationMs = (int)((microtime(true) - $start) * 1000);

        if ($curlError !== '') {
            if (stripos($curlError, 'timed out') !== false || stripos($curlError, 'timeout') !== false || stripos($curlError, 'timedout') !== false) {
                error_log("[mp] op={$operation} http=0 err=mp_timeout dur_ms={$durationMs}");
                throw new MercadoPagoException('mp_timeout', 'O Mercado Pago demorou a responder. Tente novamente.', 0);
            }
            error_log("[mp] op={$operation} http=0 err=mp_connection dur_ms={$durationMs}");
            throw new MercadoPagoException('mp_connection', 'Falha de conexao com o Mercado Pago. Tente novamente.', 0);
        }

        $data = json_decode($respBody, true);
        if (!is_array($data)) {
            error_log("[mp] op={$operation} http={$httpStatus} err=mp_invalid_json dur_ms={$durationMs} len=" . strlen($respBody));
            throw new MercadoPagoException('mp_invalid_json', 'Resposta invalida do Mercado Pago.', $httpStatus);
        }

        if ($httpStatus === 200 || $httpStatus === 201) {
            $subId = isset($data['id']) ? substr((string)$data['id'], 0, 80) : 'none';
            error_log("[mp] op={$operation} http={$httpStatus} sub={$subId} dur_ms={$durationMs}");
            return $data;
        }

        // Detalhe seguro da resposta de erro: SOMENTE campos nao sensiveis
        // (error/message/causes/status_detail). Nunca inclui request,
        // headers, tokens ou dados de cartao.
        $detail = self::errorDetail($data);
        $map = [
            400 => ['mp_http_400', 'Requisicao recusada pelo Mercado Pago.'],
            401 => ['mp_http_401', 'Credencial do Mercado Pago invalida.'],
            403 => ['mp_http_403', 'Operacao nao permitida pelo Mercado Pago.'],
            404 => ['mp_http_404', 'Recurso nao encontrado no Mercado Pago.'],
            409 => ['mp_http_409', 'Conflito no Mercado Pago. Tente novamente.'],
            429 => ['mp_http_429', 'Muitas tentativas. Aguarde e tente novamente.'],
        ];
        if (isset($map[$httpStatus])) {
            [$code, $msg] = $map[$httpStatus];
        } elseif ($httpStatus >= 500) {
            $code = 'mp_http_5xx';
            $msg = 'Mercado Pago indisponivel no momento. Tente novamente.';
        } else {
            $code = 'mp_http_' . $httpStatus;
            $msg = 'Erro inesperado do Mercado Pago.';
        }
        error_log("[mp] op={$operation} http={$httpStatus} err={$code} dur_ms={$durationMs}{$detail}");
        throw new MercadoPagoException($code, $msg, $httpStatus);
    }

    /**
     * Monta sufixo de diagnostico a partir da resposta de erro oficial.
     * Extrai apenas: error, message, causes[].code, causes[].description,
     * status_detail — todos sanitizados e truncados. Retorna '' se nada
     * seguro existir. Nunca inventa campos.
     */
    private static function errorDetail(array $data): string
    {
        $parts = '';
        if (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
            $parts .= ' api_error=' . self::logSafe($data['error'], 60);
        }
        if (isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
            $parts .= ' msg=' . self::logSafe($data['message']);
        }
        if (isset($data['status_detail']) && is_string($data['status_detail']) && $data['status_detail'] !== '') {
            $parts .= ' status_detail=' . self::logSafe($data['status_detail'], 80);
        }
        if (isset($data['cause']) && is_array($data['cause'])) {
            $codes = [];
            foreach (array_slice($data['cause'], 0, 3) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $code = isset($c['code']) && is_string($c['code']) ? self::logSafe($c['code'], 60) : '';
                $desc = isset($c['description']) && is_string($c['description']) ? self::logSafe($c['description'], 120) : '';
                if ($code !== '' || $desc !== '') {
                    $codes[] = trim($code . ' ' . $desc);
                }
            }
            if ($codes !== []) {
                $parts .= ' cause_code=' . implode('|', $codes);
            }
        }
        // Algumas respostas usam 'causes' (plural).
        if (isset($data['causes']) && is_array($data['causes'])) {
            $codes = [];
            foreach (array_slice($data['causes'], 0, 3) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $code = isset($c['code']) && is_string($c['code']) ? self::logSafe($c['code'], 60) : '';
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            if ($codes !== []) {
                $parts .= ' cause_code=' . implode('|', $codes);
            }
        }
        return $parts;
    }
}

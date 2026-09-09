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
     * Cria uma assinatura (preapproval) com plano associado.
     * Checkout hospedado: sem card_token_id/status — o Mercado Pago
     * retorna status pending + init_point para aprovacao do assinante.
     *
     * @param array{preapproval_plan_id:string, reason:string, external_reference:string, payer_email:string, back_url?:string} $payload
     * @return array Resposta decodificada (id, init_point, status, ...).
     * @throws MercadoPagoException
     */
    public function createPreapproval(array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new MercadoPagoException('mp_not_configured', 'Integracao de pagamento nao configurada no servidor.');
        }
        foreach (['preapproval_plan_id', 'reason', 'external_reference', 'payer_email'] as $required) {
            if (!isset($payload[$required]) || trim((string)$payload[$required]) === '') {
                throw new MercadoPagoException('mp_payload_invalido', "Campo obrigatorio ausente: {$required}.");
            }
        }
        return $this->request('POST', '/preapproval', $payload, 'create_preapproval');
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
     * @return array Resposta JSON decodificada.
     * @throws MercadoPagoException
     */
    private function request(string $method, string $path, ?array $payload, string $operation): array
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

        // Authorization e montado aqui e jamais logado.
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];

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

        // Mensagem do provedor somente para log sanitizado (nunca com segredos).
        $providerMsg = '';
        if (isset($data['message']) && is_string($data['message'])) {
            $providerMsg = substr($data['message'], 0, 200);
        }
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
        error_log("[mp] op={$operation} http={$httpStatus} err={$code} dur_ms={$durationMs} msg={$providerMsg}");
        throw new MercadoPagoException($code, $msg, $httpStatus);
    }
}

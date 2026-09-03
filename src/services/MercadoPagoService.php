<?php
/**
 * MercadoPagoService — cliente HTTP da API do Mercado Pago.
 *
 * Implementa:
 *   - createPreapproval(): cria uma assinatura vinculada a um preapproval_plan_id
 *   - getPreapproval(): consulta o estado atual de uma assinatura no MP
 *   - cancelPreapproval(): cancela uma assinatura no MP
 *
 * Autenticacao: Authorization: Bearer {ACCESS_TOKEN}
 *
 * Seguranca:
 *   - Access Token lido APENAS de getenv() (NUNCA do codigo, banco ou sessao)
 *   - Webhook Secret (MERCADOPAGO_WEBHOOK_SECRET) lido APENAS de getenv()
 *   - Timeout HTTP curto (5s) para respeitar limite serverless da Vercel (10s)
 *   - Erros nunca logam o token nem o payload completo
 *   - Nenhuma chamada externa ao MP dentro do webhook (regra: processar e responder rapido)
 */
class MercadoPagoService
{
    private const API_BASE = 'https://api.mercadopago.com';
    private const TIMEOUT  = 5;

    private string $accessToken;
    private ?string $webhookSecret;
    private string $publicKey;
    private string $mode;

    public function __construct()
    {
        $token = (string)(getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '');
        if ($token === '') {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN nao configurado');
        }
        $this->accessToken = $token;
        $this->webhookSecret = getenv('MERCADOPAGO_WEBHOOK_SECRET') ?: null;
        $this->publicKey = (string)(getenv('MERCADOPAGO_PUBLIC_KEY') ?: '');
        $this->mode = strtolower((string)(getenv('MERCADOPAGO_MODE') ?: 'production'));
    }

    public static function isConfigured(): bool
    {
        $t = getenv('MERCADOPAGO_ACCESS_TOKEN');
        return is_string($t) && $t !== '';
    }

    public static function isSandboxConfigured(): bool
    {
        $t = getenv('MERCADOPAGO_ACCESS_TOKEN');
        return is_string($t) && str_starts_with($t, 'TEST-');
    }

    /**
     * Retorna o segredo usado para validar a assinatura (HMAC-SHA256) do
     * webhook do Mercado Pago.
     *
     * Variavel de ambiente esperada (padrao):
     *   MERCADOPAGO_WEBHOOK_SECRET
     *
     * Retorna null se nao configurado (o WebhookService trata como rejeicao).
     */
    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isSandbox(): bool
    {
        return $this->mode === 'sandbox' || str_starts_with($this->accessToken, 'TEST-');
    }

    /**
     * Cria uma assinatura (preapproval) vinculada a um preapproval_plan_id.
     *
     * Fluxo usado em producao: o cartao e tokenizado no frontend via MercadoPago.js
     * (Card Payment Brick) e o `card_token_id` chega aqui pelo POST do
     * SubscriptionController. O token e one-shot (uso unico) e nunca e
     * persistido em log/banco.
     *
     * Endpoint MP: POST /preapproval
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function createPreapproval(
        string $preapprovalPlanId,
        string $payerEmail,
        string $cardTokenId,
        string $externalReference,
        string $backUrl,
        ?string $reason = null
    ): array {
        $payload = [
            'preapproval_plan_id' => $preapprovalPlanId,
            'payer_email' => $payerEmail,
            'card_token_id' => $cardTokenId,
            'external_reference' => $externalReference,
            'back_url' => $backUrl,
            'status' => 'authorized',
        ];
        if ($reason !== null && $reason !== '') {
            $payload['reason'] = $reason;
        }

        /* =====================================================
           DIAGNOSTICO TEMPORARIO — remover apos identificar erro
           ===================================================== */
        $diagPayload = [
            'preapproval_plan_id' => $payload['preapproval_plan_id'],
            'payer_email' => substr($payload['payer_email'], 0, 3) . '***',
            'card_token_present' => ($payload['card_token_id'] !== ''),
            'card_token_length' => strlen($payload['card_token_id']),
            'card_token_prefix' => strlen($payload['card_token_id']) >= 4
                ? substr($payload['card_token_id'], 0, 4) . '***'
                : '[short]',
            'status' => $payload['status'] ?? '[null]',
            'reason' => $payload['reason'] ?? '[null]',
            'back_url_present' => ($payload['back_url'] !== ''),
            'external_reference' => $payload['external_reference'],
        ];
        error_log('[MP-DIAG] createPreapproval ENVIANDO: ' . json_encode($diagPayload));

        $resp = $this->request('POST', '/preapproval', $payload);

        /* =====================================================
           DIAGNOSTICO TEMPORARIO — remover apos identificar erro
           ===================================================== */
        $diagResp = [
            'ok' => $resp['ok'],
            'http' => $resp['status'],
            'message' => $resp['data']['message'] ?? '[no_message]',
            'code' => $resp['data']['code'] ?? '[no_code]',
        ];
        $causeKeys = [];
        if (isset($resp['data']['cause']) && is_array($resp['data']['cause'])) {
            foreach ($resp['data']['cause'] as $i => $c) {
                if (is_array($c)) {
                    $causeKeys[] = [
                        'code' => $c['code'] ?? '[no_code]',
                        'description' => isset($c['description']) ? substr($c['description'], 0, 100) : '[no_desc]',
                    ];
                }
            }
        }
        if ($causeKeys !== []) {
            $diagResp['cause'] = $causeKeys;
        }
        error_log('[MP-DIAG] createPreapproval RESP: ' . json_encode($diagResp));
        /* =====================================================
           FIM DO DIAGNOSTICO TEMPORARIO
           ===================================================== */

        return $resp;
    }

    /**
     * Obtem os dados de um Preapproval Plan ja criado, incluindo a URL
     * de checkout (init_point / sandbox_init_point) para onde o cliente
     * deve ser redirecionado.
     *
     * Endpoint: GET /preapproval_plan/{id}
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function getPreapprovalPlan(string $planId): array
    {
        return $this->request('GET', '/preapproval_plan/' . rawurlencode($planId));
    }

    /**
     * Retorna a URL de checkout de um Preapproval Plan.
     *
     * Em ambiente sandbox (token TEST-*), usa sandbox_init_point se existir;
     * caso contrario usa init_point (que ja e roteada para sandbox automaticamente
     * quando o token e de teste).
     *
     * @return string URL valida de checkout ou string vazia se nao disponivel.
     */
    public function getPlanCheckoutUrl(string $planId): string
    {
        $resp = $this->getPreapprovalPlan($planId);
        if (!$resp['ok']) {
            return '';
        }
        $data = $resp['data'];
        $sandbox = isset($data['sandbox_init_point']) ? (string)$data['sandbox_init_point'] : '';
        $init    = isset($data['init_point']) ? (string)$data['init_point'] : '';
        if ($this->isSandbox() && $sandbox !== '') {
            return $sandbox;
        }
        return $init;
    }

    /**
     * Consulta o estado atual de uma assinatura (preapproval) no MP.
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function getPreapproval(string $mpId): array
    {
        return $this->request('GET', '/preapproval/' . rawurlencode($mpId));
    }

    /**
     * Cancela uma assinatura no MP. status=cancelled e PUT /preapproval/{id}.
     */
    public function cancelPreapproval(string $mpId): array
    {
        return $this->request('PUT', '/preapproval/' . rawurlencode($mpId), [
            'status' => 'cancelled',
        ]);
    }

    /**
     * Faz uma requisicao autenticada para a API do Mercado Pago.
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::API_BASE . $path;
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Controle-de-Gastos/1.0',
        ];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => true,
        ];
        if ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($response === false) {
            error_log('[MercadoPago] curl error: ' . $err);
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'conexao'];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $mpRequestId = null;
        foreach (explode("\r\n", $rawHeaders) as $header) {
            if (str_starts_with(strtolower($header), 'x-request-id:')) {
                $mpRequestId = trim(substr($header, 12));
                break;
            }
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'status' => $httpCode, 'data' => $data, 'error' => null];
        }

        $errCode = isset($data['code']) ? (string)$data['code'] : null;
        $errMsg  = isset($data['message']) ? (string)$data['message'] : ('http ' . $httpCode);

        $causeSummary = '';
        if (isset($data['cause']) && is_array($data['cause'])) {
            $causeParts = [];
            foreach ($data['cause'] as $c) {
                if (is_array($c)) {
                    $causeCode = isset($c['code']) ? (string)$c['code'] : null;
                    $causeDesc = isset($c['description']) ? substr((string)$c['description'], 0, 80) : null;
                    if ($causeCode !== null) {
                        $causeParts[] = $causeCode . ($causeDesc !== null ? ':' . $causeDesc : '');
                    }
                }
            }
            if ($causeParts !== []) {
                $causeSummary = ' causes=[' . implode('; ', $causeParts) . ']';
            }
        }

        $reqIdLog = $mpRequestId !== null ? ' req_id=' . $mpRequestId : '';
        error_log('[MercadoPago] api error: http=' . $httpCode
            . ' code=' . ($errCode ?? '')
            . ' msg=' . substr((string)$errMsg, 0, 200)
            . $causeSummary
            . $reqIdLog
        );

        return ['ok' => false, 'status' => $httpCode, 'data' => $data, 'error' => $errMsg];
    }
}

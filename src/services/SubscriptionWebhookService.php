<?php
/**
 * SubscriptionWebhookService — valida e processa webhooks de assinaturas.
 *
 * Fonte oficial:
 *   https://www.mercadopago.com.br/developers/pt/reference (webhooks/notifications)
 *   Tópicos -> endpoints: subscription_preapproval => GET /preapproval/{id};
 *   subscription_authorized_payment => GET /authorized_payments/{id} (traz
 *   preapproval_id) => GET /preapproval/{id}.
 *   subscription_preapproval_plan => plano do MP (NUNCA altera plano de usuário).
 *
 * Validação oficial de origem (sem SDK):
 *   manifest = "id:{dataId};request-id:{xRequestId};ts:{ts};"
 *   (pares ausentes são omitidos; dataId lowercased se alfanumérico)
 *   esperado = HMAC_SHA256(manifest, secret) em hex, comparado com
 *   hash_equals() contra o v1 de "x-signature: ts=...,v1=...".
 *   Falha => 401. Secret ausente => 500 fail-closed (retry até configurar).
 *
 * REGRA CRÍTICA: o body NUNCA é fonte de status. Após validar, consulta a
 * API (fonte da verdade) e sincroniza via SubscriptionService::syncFromApi.
 * Usuário resolvido SOMENTE por correlação interna (mp_id/attempt +
 * external_reference validado) — nunca por payer_id/e-mail do payload.
 *
 * Sem sessão, sem CSRF, sem HTML aqui. Secret/token NUNCA em logs.
 */
if (!class_exists('MercadoPagoClient', false)) {
    require_once __DIR__ . '/MercadoPagoClient.php';
}
if (!class_exists('SubscriptionService', false)) {
    require_once __DIR__ . '/SubscriptionService.php';
}

class SubscriptionWebhookService
{
    private MercadoPagoClient $mp;
    private SubscriptionService $subs;

    public function __construct(SubscriptionService $subs, ?MercadoPagoClient $mp = null)
    {
        $this->subs = $subs;
        $this->mp = $mp ?? new MercadoPagoClient();
    }

    public static function readWebhookSecret(): string
    {
        return MercadoPagoClient::readEnv('MERCADOPAGO_WEBHOOK_SECRET');
    }

    /**
     * Valida x-signature conforme documentação oficial.
     * @return array{ok:bool,error:string}
     *   error: missing_secret|missing_signature|invalid_signature
     */
    public static function validateSignature(string $xSignature, string $xRequestId, string $dataId): array
    {
        $secret = self::readWebhookSecret();
        if ($secret === '') {
            return ['ok' => false, 'error' => 'missing_secret'];
        }
        $parts = self::parseSignatureHeader($xSignature);
        if ($parts === null) {
            return ['ok' => false, 'error' => 'missing_signature'];
        }
        $manifest = self::buildManifest($dataId, $xRequestId, $parts['ts']);
        if ($manifest === '') {
            return ['ok' => false, 'error' => 'missing_signature'];
        }
        $expected = hash_hmac('sha256', $manifest, $secret);
        if (!hash_equals($expected, strtolower($parts['v1']))) {
            return ['ok' => false, 'error' => 'invalid_signature'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ts:string,v1:string}|null */
    public static function parseSignatureHeader(string $header): ?array
    {
        $ts = null; $v1 = null;
        foreach (explode(',', $header) as $chunk) {
            $kv = explode('=', trim($chunk), 2);
            if (count($kv) !== 2) continue;
            $k = strtolower(trim($kv[0]));
            $v = trim($kv[1]);
            if ($k === 'ts') $ts = $v;
            if ($k === 'v1') $v1 = $v;
        }
        if ($ts === null || $ts === '' || $v1 === null || $v1 === '') return null;
        if (!preg_match('/^[0-9]+$/', $ts)) return null;
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $v1)) return null;
        return ['ts' => $ts, 'v1' => $v1];
    }

    /**
     * Manifesto oficial; pares ausentes são omitidos (conforme docs/SDK).
     * dataId lowercased quando alfanumérico (comportamento do SDK oficial).
     */
    public static function buildManifest(string $dataId, string $xRequestId, string $ts): string
    {
        $manifest = '';
        $dataId = trim($dataId);
        if ($dataId !== '') {
            if (preg_match('/^[A-Za-z0-9]+$/', $dataId) === 1) $dataId = strtolower($dataId);
            $manifest .= 'id:' . $dataId . ';';
        }
        if (trim($xRequestId) !== '') {
            $manifest .= 'request-id:' . trim($xRequestId) . ';';
        }
        if (trim($ts) !== '') {
            $manifest .= 'ts:' . trim($ts) . ';';
        }
        return $manifest;
    }

    /**
     * Extrai o ID do recurso (query tem precedência — é o que assina o HMAC;
     * body como fallback). Normalizado para validação de formato MP.
     */
    public static function extractResourceId(array $query, ?array $body): string
    {
        $candidates = [];
        foreach (['data.id', 'data_id', 'id'] as $k) {
            if (isset($query[$k])) $candidates[] = (string)$query[$k];
        }
        if (is_array($body)) {
            if (isset($body['data']['id'])) $candidates[] = (string)$body['data']['id'];
            elseif (isset($body['data_id'])) $candidates[] = (string)$body['data_id'];
            elseif (isset($body['id']) && !isset($body['data'])) $candidates[] = (string)$body['id'];
        }
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '' && strlen($c) <= 80 && preg_match('/^[A-Za-z0-9_-]+$/', $c)) {
                return $c;
            }
        }
        return '';
    }

    /** Tipo do evento (query topic/type/action, depois body action/type/topic). */
    public static function extractEventType(array $query, ?array $body): string
    {
        foreach (['topic', 'type', 'action'] as $k) {
            if (isset($query[$k]) && is_string($query[$k]) && trim($query[$k]) !== '') {
                return strtolower(trim((string)$query[$k]));
            }
        }
        if (is_array($body)) {
            foreach (['action', 'type', 'topic'] as $k) {
                if (isset($body[$k]) && is_string($body[$k]) && trim($body[$k]) !== '') {
                    return strtolower(trim((string)$body[$k]));
                }
            }
        }
        return '';
    }

    public static function isPreapprovalEvent(string $eventType): bool
    {
        foreach (['subscription_preapproval'] as $known) {
            if ($eventType === $known || str_contains($eventType, $known)) {
                // subscription_preapproval_plan é plano do MP: não sincroniza usuário.
                if (str_contains($eventType, 'subscription_preapproval_plan')) return false;
                return true;
            }
        }
        return $eventType === 'preapproval';
    }

    public static function isAuthorizedPaymentEvent(string $eventType): bool
    {
        return $eventType === 'subscription_authorized_payment'
            || str_contains($eventType, 'subscription_authorized_payment');
    }

    /**
     * Processa um webhook (headers + query + raw body já lidos).
     * Ordem: extrai ID -> valida HMAC -> roteia -> consulta API -> sincroniza.
     * HTTP NUNCA aberto durante transação (transação vive só no syncFromApi).
     *
     * @return array{http:int,code:string,event:string}
     */
    public function handle(string $xSignature, string $xRequestId, array $query, string $rawBody): array
    {
        $body = null;
        if (trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) $body = $decoded;
        }

        $eventType = self::extractEventType($query, $body);
        $resourceId = self::extractResourceId($query, $body);

        if ($resourceId === '') {
            error_log('[mp-webhook] evento sem id type=' . substr($eventType, 0, 60));
            return ['http' => 400, 'code' => 'missing_id', 'event' => $eventType];
        }

        $sig = self::validateSignature($xSignature, $xRequestId, $resourceId);
        if (!$sig['ok']) {
            if ($sig['error'] === 'missing_secret') {
                error_log('[mp-webhook] secret ausente — processamento recusado');
                return ['http' => 500, 'code' => 'missing_secret', 'event' => $eventType];
            }
            error_log('[mp-webhook] assinatura invalida req=' . substr($xRequestId, 0, 32));
            return ['http' => 401, 'code' => 'invalid_signature', 'event' => $eventType];
        }

        if (self::isAuthorizedPaymentEvent($eventType)) {
            return $this->handleAuthorizedPayment($resourceId, $eventType, $xRequestId);
        }

        if (self::isPreapprovalEvent($eventType) || $eventType === '') {
            return $this->handlePreapproval($resourceId, $eventType, $xRequestId);
        }

        error_log('[mp-webhook] evento ignorado type=' . substr($eventType, 0, 60));
        return ['http' => 200, 'code' => 'ignored', 'event' => $eventType];
    }

    private function handlePreapproval(string $mpId, string $eventType, string $xRequestId): array
    {
        // Fonte da verdade: GET /preapproval/{id} (nunca o body).
        $res = $this->mp->getPreapproval($mpId);
        if (!$res['ok']) {
            error_log('[mp-webhook] GET preapproval falhou id=' . $this->subs->maskId($mpId)
                . ' err=' . substr((string)($res['error'] ?? ''), 0, 40));
            return ['http' => 500, 'code' => 'fetch_failed', 'event' => $eventType];
        }
        return $this->applySync($mpId, $eventType, $xRequestId, $res['data']);
    }

    private function handleAuthorizedPayment(string $invoiceId, string $eventType, string $xRequestId): array
    {
        // Fatura -> preapproval_id oficial -> assinatura oficial.
        $inv = $this->mp->getAuthorizedPayment($invoiceId);
        if (!$inv['ok']) {
            error_log('[mp-webhook] GET authorized_payment falhou id=' . $this->subs->maskId($invoiceId)
                . ' err=' . substr((string)($inv['error'] ?? ''), 0, 40));
            return ['http' => 500, 'code' => 'fetch_failed', 'event' => $eventType];
        }
        $mpId = (string)($inv['data']['preapproval_id'] ?? '');
        if (!MercadoPagoClient::isValidId($mpId)) {
            error_log('[mp-webhook] fatura sem preapproval_id id=' . $this->subs->maskId($invoiceId));
            return ['http' => 200, 'code' => 'ignored', 'event' => $eventType];
        }
        $res = $this->mp->getPreapproval($mpId);
        if (!$res['ok']) {
            error_log('[mp-webhook] GET preapproval (via fatura) falhou id=' . $this->subs->maskId($mpId));
            return ['http' => 500, 'code' => 'fetch_failed', 'event' => $eventType];
        }
        return $this->applySync($mpId, $eventType, $xRequestId, $res['data']);
    }

    private function applySync(string $mpId, string $eventType, string $xRequestId, array $apiData): array
    {
        $masked = $this->subs->maskId($mpId);
        $sync = $this->subs->syncFromApi($apiData);
        if (!$sync['ok']) {
            $err = $sync['error'];
            error_log('[mp-webhook] sync falhou id=' . $masked . ' err=' . substr($err, 0, 40));
            if ($err === 'unknown_subscription') {
                return ['http' => 404, 'code' => 'unknown_subscription', 'event' => $eventType];
            }
            if ($err === 'db_error') {
                return ['http' => 500, 'code' => 'sync_error', 'event' => $eventType];
            }
            // invalid_api_object / external_reference_mismatch / unknown_status:
            // nada a aplicar; ack para evitar retry infinito, com log.
            return ['http' => 200, 'code' => 'sync_error', 'event' => $eventType];
        }

        error_log('[mp-webhook] ok id=' . $masked
            . ' status=' . substr((string)$sync['local_status'], 0, 20)
            . ' req=' . substr($xRequestId, 0, 16));
        return ['http' => 200, 'code' => 'processed', 'event' => $eventType];
    }
}

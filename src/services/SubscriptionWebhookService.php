<?php
/**
 * SubscriptionWebhookService — valida e processa webhooks de assinaturas.
 *
 * Segurança:
 * - Assinatura x-signature (HMAC SHA-256) OBRIGATÓRIA; sem secret
 *   configurado (MERCADOPAGO_WEBHOOK_SECRET), nenhum processamento
 *   sensível acontece (fail-closed).
 * - O body do webhook NUNCA é fonte de status: após validar, o serviço
 *   faz GET /preapproval/{id} e sincroniza a partir da API.
 * - Usuário resolvido SOMENTE via external_reference validado contra o
 *   banco (nunca via payer_id/e-mail do payload).
 * - Secret/token NUNCA em logs.
 */
class SubscriptionWebhookService
{
    private PDO $db;
    private MercadoPagoClient $mp;
    private SubscriptionService $subs;

    public function __construct(PDO $db, ?MercadoPagoClient $mp = null, ?SubscriptionService $subs = null)
    {
        $this->db = $db;
        $this->mp = $mp ?? new MercadoPagoClient();
        $this->subs = $subs ?? new SubscriptionService($db, $this->mp);
    }

    public static function readWebhookSecret(): string
    {
        foreach (['MERCADOPAGO_WEBHOOK_SECRET'] as $key) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') return trim($v);
            if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') return trim((string)$_ENV[$key]);
            if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') return trim((string)$_SERVER[$key]);
        }
        return '';
    }

    /**
     * Valida x-signature conforme documentação oficial:
     *   manifest = "id:{dataId};request-id:{xRequestId};ts:{ts};"
     *   esperado = HMAC_SHA256(manifest, secret) em hex
     *
     * @return array{ok:bool,error:string} error: missing_secret|missing_signature|invalid_signature
     */
    public static function validateSignature(string $xSignature, string $xRequestId, string $dataId): array
    {
        $secret = self::readWebhookSecret();
        if ($secret === '') {
            return ['ok' => false, 'error' => 'missing_secret'];
        }
        $parts = self::parseSignatureHeader($xSignature);
        if ($parts === null || $xRequestId === '' || $dataId === '') {
            return ['ok' => false, 'error' => 'missing_signature'];
        }
        $manifest = 'id:' . $dataId . ';request-id:' . $xRequestId . ';ts:' . $parts['ts'] . ';';
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
     * Extrai o ID do preapproval do evento (query + JSON).
     * Aceita: query data.id | data_id | id, JSON data.id.
     */
    public static function extractPreapprovalId(array $query, ?array $body): string
    {
        $candidates = [];
        foreach (['data.id', 'data_id', 'id'] as $k) {
            if (isset($query[$k]) && is_string($query[$k])) $candidates[] = $query[$k];
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

    /** Tipo do evento (topic/action/type), '' se ausente. */
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

    public static function isSubscriptionEvent(string $eventType): bool
    {
        // Formatos de Assinaturas (quando aplicável): preapproval direto,
        // plano (ignorado p/ sincronização de usuário) e pagamento autorizado.
        foreach (['subscription_preapproval', 'subscription_preapproval_plan', 'subscription_authorized_payment', 'preapproval'] as $known) {
            if ($eventType === $known || str_contains($eventType, $known)) return true;
        }
        return false;
    }

    /**
     * Processa um webhook já lido (headers + query + raw body).
     *
     * @return array{http:int,code:string,preapproval:string,event:string}
     *   http: 200 ok | 400 evento inválido | 401 assinatura inválida |
     *         404 assinatura desconhecida | 503 secret ausente
     */
    public function handle(string $xSignature, string $xRequestId, array $query, string $rawBody): array
    {
        $body = null;
        if (trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) $body = $decoded;
        }

        $eventType = self::extractEventType($query, $body);
        $preapprovalId = self::extractPreapprovalId($query, $body);

        if ($preapprovalId === '') {
            error_log('[mp-webhook] evento sem id type=' . substr($eventType, 0, 60));
            return ['http' => 400, 'code' => 'missing_id', 'preapproval' => '', 'event' => $eventType];
        }

        $sig = self::validateSignature($xSignature, $xRequestId, $preapprovalId);
        if (!$sig['ok']) {
            if ($sig['error'] === 'missing_secret') {
                error_log('[mp-webhook] secret ausente — processamento recusado');
                return ['http' => 503, 'code' => 'missing_secret', 'preapproval' => '', 'event' => $eventType];
            }
            error_log('[mp-webhook] assinatura invalida req=' . substr($xRequestId, 0, 32));
            return ['http' => 401, 'code' => 'invalid_signature', 'preapproval' => '', 'event' => $eventType];
        }

        if (!self::isSubscriptionEvent($eventType) && $eventType !== '') {
            // Evento de outro produto: ack sem processar.
            error_log('[mp-webhook] evento ignorado type=' . substr($eventType, 0, 60));
            return ['http' => 200, 'code' => 'ignored', 'preapproval' => '', 'event' => $eventType];
        }

        // Fonte da verdade: GET /preapproval/{id} (nunca o body).
        $res = $this->mp->getSubscription($preapprovalId);
        if (!$res['ok']) {
            error_log('[mp-webhook] GET preapproval falhou id=' . $this->subs->maskId($preapprovalId)
                . ' err=' . substr((string)($res['error'] ?? ''), 0, 40));
            return ['http' => 200, 'code' => 'fetch_failed', 'preapproval' => $this->subs->maskId($preapprovalId), 'event' => $eventType];
        }

        $sync = $this->subs->syncFromApi($res['data']);
        if (!$sync['ok']) {
            $code = $sync['error'] === 'unknown_subscription' ? 'unknown_subscription' : 'sync_error';
            $http = $code === 'unknown_subscription' ? 404 : 200;
            error_log('[mp-webhook] sync falhou id=' . $this->subs->maskId($preapprovalId)
                . ' err=' . substr($sync['error'], 0, 40));
            return ['http' => $http, 'code' => $code, 'preapproval' => $this->subs->maskId($preapprovalId), 'event' => $eventType];
        }

        error_log('[mp-webhook] ok id=' . $this->subs->maskId($preapprovalId)
            . ' status=' . substr((string)$sync['local_status'], 0, 20)
            . ' req=' . substr($xRequestId, 0, 16));
        return ['http' => 200, 'code' => 'processed', 'preapproval' => $this->subs->maskId($preapprovalId), 'event' => $eventType];
    }
}

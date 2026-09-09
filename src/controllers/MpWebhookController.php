<?php
/**
 * MpWebhookController — webhook oficial do Mercado Pago (etapa 2).
 *
 * Endpoint publico, SEM login/sessao/CSRF. Fluxo obrigatorio:
 *  receber -> parse seguro -> validar x-signature -> reservar no ledger
 *  -> GET /preapproval/{id} (fonte da verdade) -> vincular subscription
 *  local -> sincronizar em UMA transacao via BillingSyncService.
 *
 * O body do webhook NUNCA ativa plano sozinho: e apenas gatilho para a
 * consulta oficial autenticada.
 *
 * Validacao de origem (documentacao oficial):
 *  manifest = "id:{data.id};request-id:{x-request-id};ts:{ts};"
 *  (partes ausentes sao removidas; data.id alfanumerico vai em lowercase)
 *  HMAC-SHA256 hex com MERCADOPAGO_WEBHOOK_SECRET, comparado via
 *  hash_equals() com o v1 do header x-signature. Falha => HTTP 401.
 */
class MpWebhookController
{
    public const ENV_SECRET = 'MERCADOPAGO_WEBHOOK_SECRET';

    /**
     * Status oficiais de GET /preapproval/{id} -> vocabulario interno.
     * Fonte: referencia oficial (pending, authorized, paused, canceled).
     */
    private const API_STATUS_MAP = [
        'authorized' => BillingSyncService::SUBSCRIPTION_ACTIVE,
        'pending'    => BillingSyncService::SUBSCRIPTION_PENDING,
        'paused'     => BillingSyncService::SUBSCRIPTION_PAUSED,
        'canceled'   => BillingSyncService::SUBSCRIPTION_CANCELLED,
    ];

    private PDO $db;
    private User $userModel;
    private PlanService $planService;
    private ?MercadoPagoClient $client;

    public function __construct(PDO $db, User $userModel, PlanService $planService, ?MercadoPagoClient $client = null)
    {
        $this->db = $db;
        $this->userModel = $userModel;
        $this->planService = $planService;
        $this->client = $client;
    }

    /**
     * @param array{method:string, headers:array<string,string>, query:array, rawBody:string} $req
     */
    public function handle(array $req): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if (strtoupper((string)($req['method'] ?? 'GET')) !== 'POST') {
            $this->json(405, ['success' => false, 'error' => 'metodo_nao_permitido']);
            return;
        }

        $rawBody = (string)($req['rawBody'] ?? '');
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->json(400, ['success' => false, 'error' => 'payload_invalido']);
            return;
        }

        $query = is_array($req['query'] ?? null) ? $req['query'] : [];
        $resourceId = $this->extractResourceId($query, $body);
        if ($resourceId === null) {
            $this->json(400, ['success' => false, 'error' => 'resource_id_ausente']);
            return;
        }

        // ---- Validacao de origem ANTES de qualquer DB/API ----
        $headers = $req['headers'] ?? [];
        $sigHeader = trim((string)($headers['x-signature'] ?? ''));
        $requestId = trim((string)($headers['x-request-id'] ?? ''));
        if ($sigHeader === '') {
            $this->json(401, ['success' => false, 'error' => 'assinatura_ausente']);
            return;
        }
        if ($requestId === '' || strlen($requestId) > 120) {
            $this->json(401, ['success' => false, 'error' => 'request_id_ausente']);
            return;
        }
        $secret = MercadoPagoClient::readEnv(self::ENV_SECRET);
        if ($secret === '') {
            $this->diag('config', 'webhook_nao_configurado');
            $this->json(500, ['success' => false, 'error' => 'webhook_nao_configurado']);
            return;
        }
        $parts = $this->parseSignature($sigHeader);
        if ($parts['ts'] === null) {
            $this->json(401, ['success' => false, 'error' => 'ts_ausente']);
            return;
        }
        if ($parts['v1'] === null) {
            $this->json(401, ['success' => false, 'error' => 'v1_ausente']);
            return;
        }
        $manifestId = $this->manifestDataId($query);
        $manifest = '';
        if ($manifestId !== null) {
            $manifest .= 'id:' . $manifestId . ';';
        }
        $manifest .= 'request-id:' . $requestId . ';ts:' . $parts['ts'] . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);
        if (!hash_equals($expected, strtolower($parts['v1']))) {
            $this->diag('signature', 'assinatura_invalida', 'req=' . substr($requestId, 0, 40));
            $this->json(401, ['success' => false, 'error' => 'assinatura_invalida']);
            return;
        }

        // ---- Filtro de topico: so assinaturas ----
        $type = strtolower(trim((string)($body['type'] ?? '')));
        if ($type !== '' && !str_contains($type, 'preapproval') && !str_contains($type, 'subscription')) {
            $this->json(200, ['success' => true, 'ignored' => true, 'reason' => 'tipo_nao_assinatura']);
            return;
        }

        // ---- Reserva idempotente (atomica) ----
        try {
            $event = WebhookLedger::normalizeEvent([
                'provider' => 'mercadopago',
                'provider_event_id' => $requestId,
                'event_type' => $type !== '' ? $type : 'subscription',
                'subscription_id' => null,
                'resource_id' => $resourceId,
                'payload' => $body,
            ]);
            $claim = WebhookLedger::claim($this->db, $event);
        } catch (Throwable $e) {
            $this->diag('ledger', 'erro_interno', 'req=' . substr($requestId, 0, 40));
            $this->json(500, ['success' => false, 'error' => 'erro_interno']);
            return;
        }
        if ($claim === 'duplicate') {
            $this->json(200, ['success' => true, 'duplicate' => true]);
            return;
        }

        // ---- Consulta oficial: UNICA fonte de verdade do status ----
        $client = $this->client ?? new MercadoPagoClient();
        try {
            $pre = $client->getPreapproval($resourceId);
        } catch (MercadoPagoException $e) {
            $this->failEvent($requestId);
            $code = $e->getErrorCode();
            $http = $code === 'mp_timeout' ? 504 : (($code === 'mp_http_429' || $code === 'mp_http_5xx') ? 503 : 500);
            $this->diag('api', $code, 'req=' . substr($requestId, 0, 40) . ' res=' . substr($resourceId, 0, 40));
            $this->json($http, ['success' => false, 'error' => $code]);
            return;
        } catch (Throwable $e) {
            $this->failEvent($requestId);
            $this->diag('api', 'mp_unexpected', 'req=' . substr($requestId, 0, 40));
            $this->json(500, ['success' => false, 'error' => 'erro_interno']);
            return;
        }

        $apiId = trim((string)($pre['id'] ?? ''));
        $apiStatus = strtolower(trim((string)($pre['status'] ?? '')));
        $apiRef = trim((string)($pre['external_reference'] ?? ''));
        if ($apiId === '') {
            $this->failEvent($requestId);
            $this->diag('api', 'resposta_invalida', 'req=' . substr($requestId, 0, 40));
            $this->json(500, ['success' => false, 'error' => 'resposta_invalida']);
            return;
        }

        // ---- Vinculacao: SOMENTE via registros locais previos ----
        try {
            $local = $this->findLocalByMpId($apiId);
            if ($local === null && $apiRef !== '') {
                $local = $this->findLocalByExternalRef($apiRef);
            }
        } catch (Throwable $e) {
            $this->failEvent($requestId);
            $this->diag('db', 'erro_interno', 'req=' . substr($requestId, 0, 40));
            $this->json(500, ['success' => false, 'error' => 'erro_interno']);
            return;
        }
        if ($local === null) {
            if (!$this->markProcessedSafe($requestId)) {
                $this->json(500, ['success' => false, 'error' => 'erro_interno']);
                return;
            }
            $this->diag('link', 'subscription_desconhecida', 'res=' . substr($apiId, 0, 40));
            $this->json(200, ['success' => true, 'ignored' => true, 'reason' => 'subscription_desconhecida']);
            return;
        }
        if (($local['provider'] ?? '') !== BillingSyncService::PROVIDER_MERCADOPAGO) {
            if (!$this->markProcessedSafe($requestId)) {
                $this->json(500, ['success' => false, 'error' => 'erro_interno']);
                return;
            }
            $this->diag('link', 'provider_divergente', 'sub=' . (int)$local['id']);
            $this->json(200, ['success' => true, 'ignored' => true, 'reason' => 'provider_divergente']);
            return;
        }

        $internal = self::API_STATUS_MAP[$apiStatus] ?? null;
        if ($internal === null) {
            if (!$this->markProcessedSafe($requestId)) {
                $this->json(500, ['success' => false, 'error' => 'erro_interno']);
                return;
            }
            $this->diag('link', 'status_desconhecido', 'api_status=' . substr($apiStatus, 0, 20));
            $this->json(200, ['success' => true, 'ignored' => true, 'reason' => 'status_desconhecido']);
            return;
        }

        try {
            $resolution = BillingSyncService::resolvePlanUpdate($internal, (string)$local['plan_slug']);
        } catch (Throwable $e) {
            if (!$this->markProcessedSafe($requestId)) {
                $this->json(500, ['success' => false, 'error' => 'erro_interno']);
                return;
            }
            $this->diag('link', 'plano_invalido', 'sub=' . (int)$local['id']);
            $this->json(200, ['success' => true, 'ignored' => true, 'reason' => 'plano_invalido']);
            return;
        }

        $checkout = isset($pre['init_point']) && is_string($pre['init_point']) && str_starts_with($pre['init_point'], 'https://')
            ? substr($pre['init_point'], 0, 2000)
            : null;

        try {
            BillingSyncService::syncProviderStatus(
                $this->db,
                ['id' => (int)$local['id'], 'user_id' => (int)$local['user_id']],
                $resolution,
                ['mp_preapproval_id' => $apiId, 'raw_status' => $apiStatus, 'checkout_url' => $checkout],
                'mercadopago',
                $requestId
            );
        } catch (Throwable $e) {
            $this->failEvent($requestId);
            $this->diag('sync', 'erro_interno', 'sub=' . (int)$local['id']);
            $this->json(500, ['success' => false, 'error' => 'erro_interno']);
            return;
        }

        error_log('[mp_webhook] ok=1 req=' . substr($requestId, 0, 40) . ' sub=' . $apiId . ' status=' . $internal);
        $this->json(200, ['success' => true, 'status' => $internal]);
    }

    /**
     * Resource ID: query data.id | query id | body data.id. Normalizado.
     * Nota: o PHP converte "?data.id=" em $_GET['data_id'] — aceita ambos.
     */
    private function extractResourceId(array $query, array $body): ?string
    {
        foreach ([$query['data.id'] ?? null, $query['data_id'] ?? null, $query['id'] ?? null] as $q) {
            $v = $this->cleanResourceId($q);
            if ($v !== null) {
                return $v;
            }
        }
        $data = $body['data'] ?? null;
        if (is_array($data)) {
            $v = $this->cleanResourceId($data['id'] ?? null);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    private function cleanResourceId(mixed $v): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $s = trim((string)$v);
        if ($s === '' || strlen($s) > 120 || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
            return null;
        }
        // Mesma normalizacao do manifest: alfanumerico em lowercase, para
        // que validacao, lookup e GET usem o mesmo identificador.
        return ctype_alnum($s) ? strtolower($s) : $s;
    }

    /**
     * data.id para o manifest: ESTRITAMENTE o query param oficial
     * (documentacao). Sem fallbacks: qualquer parte ausente e removida do
     * manifest, exatamente como o Mercado Pago calcula.
     */
    private function manifestDataId(array $query): ?string
    {
        $raw = $query['data.id'] ?? $query['data_id'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $s = trim((string)$raw);
        if ($s === '' || strlen($s) > 120 || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
            return null;
        }
        return ctype_alnum($s) ? strtolower($s) : $s;
    }

    /**
     * @return array{ts:?string, v1:?string}
     */
    private function parseSignature(string $header): array
    {
        $ts = null;
        $v1 = null;
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            $k = trim($kv[0]);
            $v = trim($kv[1]);
            if ($k === 'ts' && $v !== '' && ctype_digit($v)) {
                $ts = $v;
            } elseif ($k === 'v1' && $v !== '') {
                $v1 = $v;
            }
        }
        return ['ts' => $ts, 'v1' => $v1];
    }

    /**
     * @return array{id:int, user_id:int, plan_slug:string, provider:string}|null
     */
    private function findLocalByMpId(string $mpId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, plan_slug, provider FROM subscriptions WHERE mp_preapproval_id = ? LIMIT 1'
        );
        $stmt->execute([$mpId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{id:int, user_id:int, plan_slug:string, provider:string}|null
     */
    private function findLocalByExternalRef(string $ref): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, plan_slug, provider FROM subscriptions WHERE external_reference = ? LIMIT 1'
        );
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function failEvent(string $requestId): void
    {
        try {
            WebhookLedger::markFailed($this->db, 'mercadopago', $requestId);
        } catch (Throwable $e) {
            $this->diag('ledger', 'mark_failed', 'req=' . substr($requestId, 0, 40));
        }
    }

    /**
     * Marca conclusao fora da transacao principal (paths ignored).
     * Falha aqui e 500 (retryavel), nunca 200 silencioso.
     */
    private function markProcessedSafe(string $requestId): bool
    {
        try {
            WebhookLedger::markProcessed($this->db, 'mercadopago', $requestId);
            return true;
        } catch (Throwable $e) {
            $this->diag('ledger', 'mark_failed', 'req=' . substr($requestId, 0, 40));
            return false;
        }
    }

    /**
     * Diagnostico staged de TODOS os caminhos de erro: stage identifica a
     * etapa (config/signature/ledger/api/link/sync/db/fatal) e err um
     * codigo seguro. Nunca inclui HMAC, secret, token ou payload sensivel.
     */
    private function diag(string $stage, string $code, string $extra = ''): void
    {
        $suffix = $extra !== '' ? ' ' . $extra : '';
        error_log("[mp_webhook] stage={$stage} err={$code}{$suffix}");
    }

    private function json(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

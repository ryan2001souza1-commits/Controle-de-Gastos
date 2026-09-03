<?php
/**
 * WebhookService — processa webhooks do Mercado Pago de forma idempotente.
 *
 * Fluxo:
 *   1. Recebe raw body e headers
 *   2. Valida X-Signature (HMAC-SHA256) com timing-safe compare
 *   3. Identifica o evento (id, topic)
 *   4. Insere em payment_webhooks com event_id UNIQUE (idempotencia primaria)
 *      - INSERT ON CONFLICT DO NOTHING: se ja processado, sai cedo
 *   5. Marca o evento como 'processing'
 *   6. Busca o preapproval no MP (fonte confiavel)
 *   7. Identifica usuario e plano via external_reference
 *   8. Atualiza a subscription e o acesso do usuario
 *   9. Marca o evento como 'processed' (ou 'failed')
 *
 * Em caso de qualquer excecao entre passos 5-9:
 *   - Marca status='failed' e error_message
 *   - Permite reprocessamento futuro via rotina de retry
 */
class WebhookService
{
    private PDO $db;
    private Subscription $subscriptions;
    private MercadoPagoService $mp;

    public function __construct(PDO $db, Subscription $subscriptions, MercadoPagoService $mp)
    {
        $this->db = $db;
        $this->subscriptions = $subscriptions;
        $this->mp = $mp;
    }

    /**
     * Processa um webhook. Retorna:
     *   ['status' => 200, 'duplicate' => bool, 'processed' => bool, 'reason' => ?string]
     */
public function handle(
         string $rawBody,
         ?string $xSignature,
         ?string $xRequestId,
         ?string $xDataId,
         ?string $sourceIp
     ): array {
         if ($xSignature === null || $xSignature === '') {
             return $this->reject('missing_signature');
         }

         $secret = $this->mp->getWebhookSecret();
         if ($secret === null || $secret === '') {
             return $this->reject('missing_secret');
         }

         // Fallback para payload['data']['id'] se xDataId não fornecido via query string
         if ($xDataId === null || $xDataId === '') {
             $payload = json_decode($rawBody, true);
             if (is_array($payload) && isset($payload['data']['id']) && is_string($payload['data']['id'])) {
                 $xDataId = (string)$payload['data']['id'];
             }
         }

         if (!$this->verifySignature($xSignature, $secret, $xRequestId, $xDataId)) {
             return $this->reject('bad_signature');
         }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->reject('bad_payload');
        }

        $topic = $this->resolveTopic($payload);
        $eventId = $this->resolveEventId($payload);
        $resourceId = $this->resolveResourceId($payload);

        if (!in_array($topic, ['preapproval', 'subscription', 'subscription_preapproval', 'subscription_authorized_payment', 'subscription_preapproval_plan', 'payment', 'plan', 'invoice'], true)) {
            return $this->reject('unknown_topic');
        }

        if ($eventId === null || $eventId === '') {
            // Sem id de evento: gera um determinístico a partir do resource_id + body hash
            $eventId = substr(hash('sha256', ($resourceId ?? '') . '|' . $rawBody), 0, 80);
        }

        // === ETAPA DE REGISTRO (idempotência) ===
        $webhookId = $this->insertEvent($eventId, $topic, $resourceId, $rawBody, $xSignature, $sourceIp);
        if ($webhookId === null) {
            // Event_id ja existia → duplicata, nao processar
            return ['status' => 200, 'duplicate' => true, 'processed' => false, 'reason' => 'duplicate'];
        }

        $this->markProcessing($webhookId);

        // === PROCESSAMENTO ===
        try {
            $this->process($payload, $topic, $resourceId, $webhookId);
            $this->markProcessed($webhookId);
            return ['status' => 200, 'duplicate' => false, 'processed' => true, 'reason' => null];
        } catch (Throwable $e) {
            error_log('[WebhookService] process error: ' . $e->getMessage());
            $this->markFailed($webhookId, $e->getMessage());
            return ['status' => 200, 'duplicate' => false, 'processed' => false, 'reason' => 'processing_error'];
        }
    }

    /**
     * Valida a assinatura X-Signature do Mercado Pago.
     *
     * Formato oficial do header X-Signature: "ts=<timestamp>,v1=<hmac>"
     *
     * Manifesto canonico (documentacao atual do MP):
     *   "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
     * - Apenas os pares presentes no request sao incluidos (ausentes sao omitidos).
     * - O rawBody NAO faz parte do manifesto (apenas dos 3 pares acima).
     * - Separador entre pares: ';'
     * - Termina com ';' no final do ultimo par presente.
     *
     * Validacao: hash_hmac('sha256', manifest, secret) === v1 (timing-safe).
     */
    public function verifySignature(
        string $xSignature,
        string $secret,
        ?string $xRequestId = null,
        ?string $xDataId = null
    ): bool {
        $parts = [];
        foreach (explode(',', $xSignature) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq !== false) {
                $k = substr($kv, 0, $eq);
                $v = substr($kv, $eq + 1);
                $parts[trim($k)] = trim($v);
            }
        }
        if (!isset($parts['ts']) || !isset($parts['v1'])) {
            return false;
        }
        $ts = $parts['ts'];
        $v1 = $parts['v1'];

        // Constrói o manifesto SOMENTE com os pares presentes (omite ausentes)
        $segments = [];
        if ($xDataId !== null && $xDataId !== '') {
            $segments[] = 'id:' . $xDataId;
        }
        if ($xRequestId !== null && $xRequestId !== '') {
            $segments[] = 'request-id:' . $xRequestId;
        }
        if ($ts !== '') {
            $segments[] = 'ts:' . $ts;
        }
        $manifest = implode(';', $segments) . ';';

        $expected = hash_hmac('sha256', $manifest, $secret);
        return hash_equals($expected, $v1);
    }

    private function process(array $payload, string $topic, ?string $resourceId, int $webhookId): void
    {
        $resourceIdMasked = ($resourceId !== null && $resourceId !== '')
            ? (strlen($resourceId) > 6 ? substr($resourceId, 0, 3) . '…' . substr($resourceId, -3) : '***')
            : 'none';
        $extRefMasked = isset($payload['external_reference']) && is_string($payload['external_reference'])
            ? substr($payload['external_reference'], 0, 16) . '…'
            : 'none';
        error_log('[WebhookService] processing topic=' . $topic . ' resource=' . $resourceIdMasked . ' ext_ref=' . $extRefMasked);
        // Tópicos que ALTERAM o estado do preapproval (gatilho para getPreapproval)
        //   - preapproval
        //   - subscription (alias antigo)
        //   - subscription_preapproval (novo nome oficial)
        $preapprovalTopics = [
            'preapproval',
            'subscription',
            'subscription_preapproval',
        ];
        // subscription_authorized_payment: recurso e um payment_id, NAO preapproval_id.
        // Apenas registramos para auditoria — a fonte de verdade continua sendo
        // o proximo webhook de subscription_preapproval disparado pelo MP.
        if ($topic === 'subscription_authorized_payment') {
            $this->linkWebhookToEntities($webhookId, null, null);
            return;
        }
        // subscription_preapproval_plan: atualizacao de plano de preapproval.
        // Apenas registramos para auditoria — nao altera o estado da assinatura.
        if ($topic === 'subscription_preapproval_plan') {
            $this->linkWebhookToEntities($webhookId, null, null);
            return;
        }
        if (!in_array($topic, $preapprovalTopics, true)) {
            return;
        }
        if ($resourceId === null || $resourceId === '') {
            return;
        }

        // === BUSCA A VERDADE NO MERCADO PAGO ===
        $resp = $this->mp->getPreapproval($resourceId);
        if (!$resp['ok']) {
            throw new RuntimeException('mp_get_failed');
        }
        $preapproval = $resp['data'];
        if (empty($preapproval['id']) || empty($preapproval['external_reference'])) {
            throw new RuntimeException('mp_invalid_preapproval');
        }

        $extRef = (string)$preapproval['external_reference'];
        $userId = $this->extractUserIdFromRef($extRef);
        if ($userId === null) {
            // external_reference desconhecido
            return;
        }

        $existing = $this->subscriptions->findByMpPreapprovalId((string)$preapproval['id']);
        if ($existing === null) {
            // Fluxo via checkout de Preapproval Plan: o preapproval foi criado
            // pelo Mercado Pago, nao pela API. Criamos a subscription local
            // agora, derivada de preapproval_plan_id + external_reference.
            $this->db->beginTransaction();
            try {
                $local = $this->buildLocalPreapproval($preapproval, $extRef, $userId);
                $this->subscriptions->createFromPreapproval($local);
                $this->db->commit();
                $existing = $this->subscriptions->findByMpPreapprovalId((string)$preapproval['id']);
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        // === ATUALIZA STATUS ===
        $newStatus = $this->subscriptions->mapMpStatus((string)($preapproval['status'] ?? 'pending'));
        $rawStatus = (string)($preapproval['status'] ?? '');
        $nextBilling = isset($preapproval['next_payment_date']) ? (string)$preapproval['next_payment_date'] : null;
        $graceEnd = isset($preapproval['end_date']) ? (string)$preapproval['end_date'] : null;

        $this->db->beginTransaction();
        try {
            $this->subscriptions->updateStatusByMpId(
                (string)$preapproval['id'],
                $newStatus,
                $rawStatus,
                $nextBilling,
                $graceEnd
            );
            $updated = $this->subscriptions->findByMpPreapprovalId((string)$preapproval['id']);
            if ($updated !== null) {
                $this->subscriptions->applyStatusToUser($updated);
            }
            $this->linkWebhookToEntities($webhookId, $userId, $updated['id'] ?? null);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Constroi o payload local para createFromPreapproval a partir de um
     * preapproval retornado pelo MP no fluxo de checkout de plano.
     */
    private function buildLocalPreapproval(array $preapproval, string $extRef, int $userId): array
    {
        $mpPlanId = (string)($preapproval['preapproval_plan_id'] ?? '');
        $planSlug = $this->resolvePlanSlugByMpPlanId($mpPlanId);
        $planId = 0;
        if ($planSlug !== '') {
            $stmt = $this->db->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1');
            $stmt->execute([$planSlug]);
            $planId = (int)($stmt->fetchColumn() ?: 0);
        }

        $pre = $preapproval;
        $pre['_user_id_local'] = $userId;
        $pre['_plan_id_local'] = $planId;
        $pre['_plan_slug_local'] = $planSlug;
        return $pre;
    }

    private function resolvePlanSlugByMpPlanId(string $mpPlanId): string
    {
        if ($mpPlanId === '') return '';
        $pro = (string)(getenv('MERCADOPAGO_PLAN_ID_PRO') ?: '');
        $premium = (string)(getenv('MERCADOPAGO_PLAN_ID_PREMIUM') ?: '');
        if ($pro !== '' && hash_equals($pro, $mpPlanId)) return 'pro';
        if ($premium !== '' && hash_equals($premium, $mpPlanId)) return 'premium';
        return '';
    }

    private function extractUserIdFromRef(string $extRef): ?int
    {
        // Formato: "user_{userId}_{planSlug}" (ex: "user_42_pro")
        if (!preg_match('/^user_(\d+)_[a-z]+$/', $extRef, $m)) {
            return null;
        }
        $uid = (int)$m[1];
        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE id = ?');
        $stmt->execute([$uid]);
        return $stmt->fetchColumn() ? $uid : null;
    }

    private function resolveTopic(array $payload): string
    {
        if (isset($payload['topic']) && is_string($payload['topic'])) {
            return strtolower($payload['topic']);
        }
        if (isset($payload['type']) && is_string($payload['type'])) {
            return strtolower($payload['type']);
        }
        return 'unknown';
    }

    private function resolveEventId(array $payload): ?string
    {
        if (isset($payload['id']) && is_string($payload['id'])) {
            return $payload['id'];
        }
        if (isset($payload['data']['id']) && is_string($payload['data']['id'])) {
            return $payload['data']['id'];
        }
        return null;
    }

    private function resolveResourceId(array $payload): ?string
    {
        if (isset($payload['data']['id']) && is_string($payload['data']['id'])) {
            return $payload['data']['id'];
        }
        if (isset($payload['id']) && is_string($payload['id'])) {
            return $payload['id'];
        }
        return null;
    }

    private function insertEvent(
        string $eventId,
        string $topic,
        ?string $resourceId,
        string $rawBody,
        ?string $xSignature,
        ?string $sourceIp
    ): ?int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO payment_webhooks
                    (event_id, topic, resource_id, payload, signature_header, source_ip, status)
                 VALUES
                    (:event_id, :topic, :resource_id, :payload::jsonb, :signature, :ip, :status)'
            );
            $stmt->execute([
                ':event_id' => $eventId,
                ':topic' => $topic,
                ':resource_id' => $resourceId,
                ':payload' => $rawBody,
                ':signature' => $xSignature,
                ':ip' => $sourceIp,
                ':status' => 'received',
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // UNIQUE violation em event_id → duplicata
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                return null;
            }
            throw $e;
        }
    }

    private function markProcessing(int $webhookId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks
                SET status = 'processing',
                    error_message = NULL
              WHERE id = :id"
        );
        $stmt->execute([':id' => $webhookId]);
    }

    private function markProcessed(int $webhookId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks
                SET status = 'processed',
                    processed_at = NOW(),
                    error_message = NULL
              WHERE id = :id"
        );
        $stmt->execute([':id' => $webhookId]);
    }

    private function markFailed(int $webhookId, string $msg): void
    {
        // Trunca a mensagem para evitar vazamento
        $safe = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $msg), 0, 500);
        $stmt = $this->db->prepare(
            "UPDATE payment_webhooks
                SET status = 'failed',
                    error_message = :msg
              WHERE id = :id"
        );
        $stmt->execute([':msg' => $safe, ':id' => $webhookId]);
    }

    private function linkWebhookToEntities(int $webhookId, ?int $userId, ?int $subscriptionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_webhooks
                SET user_id = :uid, subscription_id = :sid
              WHERE id = :id'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':sid' => $subscriptionId,
            ':id' => $webhookId,
        ]);
    }

    private function reject(string $reason): array
    {
        // Log de tentativas rejeitadas (sem dados sensiveis)
        error_log('[WebhookService] rejected: ' . $reason);
        return ['status' => 401, 'duplicate' => false, 'processed' => false, 'reason' => $reason];
    }
}

<?php
/**
 * MercadoPagoWebhookService — processa notificações do Mercado Pago.
 *
 * Responsabilidades:
 * - extrair o ID da assinatura (preapproval) de payloads do Mercado Pago;
 * - consultar a fonte oficial (GET /preapproval/{id}) para confirmar o estado;
 * - mapear status do MP para o status interno;
 * - persistir/atualizar a assinatura e aplicar o plano no usuario;
 * - garantir idempotência.
 */
class MercadoPagoWebhookService
{
    private PDO $db;
    private MercadoPagoService $mpService;
    private Plan $planModel;
    private Subscription $subscriptionModel;

    public const REGEX_EXTERNAL_REFERENCE = '/^user_(\d+)_(pro|premium)$/';

    /**
     * Tolerância máxima (em segundos) entre o timestamp do header x-signature
     * e a hora do servidor. Recomendação do Mercado Pago: 5 minutos.
     */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(PDO $db, MercadoPagoService $mpService)
    {
        $this->db = $db;
        $this->mpService = $mpService;
        $this->planModel = new Plan($db);
        $this->subscriptionModel = new Subscription($db);
    }

    /**
     * Valida o header x-signature enviado pelo Mercado Pago.
     *
     * Formato atual do header:
     *   x-signature: ts=<unix_timestamp>,v1=<hex_sha256_hmac>
     *
     * O HMAC é computado sobre:
     *   <id>;<ts>
     * onde <id> é o data.id recebido (parâmetro ?data.id=... ou body data.id).
     *
     * @param string $secret   MERCADOPAGO_WEBHOOK_SECRET do .env
     * @param string $id       ID da assinatura (data.id) recebido
     * @param string $ts       timestamp unix extraido do header
     * @param string $v1       valor hex do HMAC extraido do header
     * @param int    $now      timestamp atual (injetado para testes)
     * @return bool            true se a assinatura for válida
     */
    public static function validateSignature(
        string $secret,
        string $id,
        string $ts,
        string $v1,
        int $now = 0
    ): bool {
        if ($secret === '' || $id === '' || $ts === '' || $v1 === '') {
            return false;
        }
        if (!ctype_digit($ts)) {
            return false;
        }
        if ($now <= 0) {
            $now = time();
        }
        $tsInt = (int)$ts;
        $diff = $now - $tsInt;
        if ($diff > self::SIGNATURE_TOLERANCE_SECONDS || $diff < -self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }
        $manifest = $id . ';' . $ts;
        $expected = hash_hmac('sha256', $manifest, $secret);
        return hash_equals($expected, strtolower($v1));
    }

    /**
     * Extrai ts e v1 do header x-signature.
     * Retorna [ts, v1] ou [null, null] se malformado.
     */
    public static function parseSignatureHeader(string $header): array
    {
        if ($header === '') {
            return [null, null];
        }
        $parts = explode(',', $header);
        $ts = null;
        $v1 = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, 'ts=')) {
                $ts = trim(substr($part, 3));
            } elseif (str_starts_with($part, 'v1=')) {
                $v1 = trim(substr($part, 3));
            }
        }
        return [$ts, $v1];
    }

    /**
     * Extrai o ID da assinatura do payload recebido.
     * Aceita formatos comuns: query string, JSON, form-urlencoded.
     *
     * @param array $query   $_GET
     * @param array $body    parsed body
     * @param array $headers cabeçalhos HTTP relevantes
     */
    public static function extractPreapprovalId(array $query, array $body, array $headers = []): ?string
    {
        $candidates = [];

        if (isset($headers['x-data-id']) && is_string($headers['x-data-id'])) {
            $candidates[] = $headers['x-data-id'];
        }
        if (isset($headers['HTTP_X_DATA_ID']) && is_string($headers['HTTP_X_DATA_ID'])) {
            $candidates[] = $headers['HTTP_X_DATA_ID'];
        }
        if (isset($headers['X_DATA_ID']) && is_string($headers['X_DATA_ID'])) {
            $candidates[] = $headers['X_DATA_ID'];
        }

        if (isset($query['data_id'])) $candidates[] = (string)$query['data_id'];
        if (isset($query['data']['id']) && is_string($query['data']['id'])) {
            $candidates[] = $query['data']['id'];
        }
        if (isset($query['id'])) $candidates[] = (string)$query['id'];
        if (isset($query['preapproval_id'])) $candidates[] = (string)$query['preapproval_id'];

        if (isset($body['data_id'])) $candidates[] = (string)$body['data_id'];
        if (isset($body['data']['id']) && is_string($body['data']['id'])) {
            $candidates[] = $body['data']['id'];
        }
        if (isset($body['id'])) $candidates[] = (string)$body['id'];
        if (isset($body['preapproval_id'])) $candidates[] = (string)$body['preapproval_id'];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Parse um external_reference no formato user_{ID}_{pro|premium}.
     * Retorna [userId, planSlug] ou null se inválido.
     */
    public static function parseExternalReference(string $ref): ?array
    {
        if (!preg_match(self::REGEX_EXTERNAL_REFERENCE, $ref, $matches)) {
            return null;
        }
        $userId = (int)$matches[1];
        $planSlug = $matches[2];
        if ($userId <= 0) {
            return null;
        }
        return [$userId, $planSlug];
    }

    /**
     * Resolve o slug interno (pro|premium) a partir do preapproval_plan_id.
     * Retorna null se o plano nao corresponder aos IDs configurados no .env.
     */
    public static function resolvePlanSlugFromMpPlanId(string $mpPlanId): ?string
    {
        $proId = (string)getenv('MERCADOPAGO_PLAN_ID_PRO');
        $premId = (string)getenv('MERCADOPAGO_PLAN_ID_PREMIUM');

        if ($proId !== '' && hash_equals($proId, $mpPlanId)) {
            return 'pro';
        }
        if ($premId !== '' && hash_equals($premId, $mpPlanId)) {
            return 'premium';
        }
        return null;
    }

    /**
     * Mapeia o status do Mercado Pago para o status interno do sistema.
     * Documentacao oficial do MP: preapproval status values.
     */
    public static function mapMpStatusToInternal(string $mpStatus): ?string
    {
        $mpStatus = strtolower(trim($mpStatus));
        return match ($mpStatus) {
            'authorized'   => Subscription::STATUS_ACTIVE,
            'active'       => Subscription::STATUS_ACTIVE,
            'paused'       => Subscription::STATUS_PAUSED,
            'cancelled',
            'canceled'     => Subscription::STATUS_CANCELLED,
            'expired'      => Subscription::STATUS_EXPIRED,
            'pending',
            'in_process'   => Subscription::STATUS_PENDING,
            'rejected',
            'failure'      => Subscription::STATUS_REJECTED,
            default        => null,
        };
    }

    /**
     * Processa o webhook do Mercado Pago.
     *
     * @param string $mpPreapprovalId ID da assinatura
     * @return array{ok:bool, action:string, http_status:int}
     */
    public function process(string $mpPreapprovalId): array
    {
        $result = $this->mpService->getPreapproval($mpPreapprovalId);
        if ($result['ok'] === false) {
            $httpCode = $result['status'];
            if ($httpCode === 404) {
                return ['ok' => true, 'action' => 'not_found', 'http_status' => 200];
            }
            if ($httpCode >= 500 || $httpCode === 0) {
                return ['ok' => false, 'action' => 'transient_error', 'http_status' => 503];
            }
            return ['ok' => true, 'action' => 'invalid', 'http_status' => 200];
        }

        $data = $result['data'];

        $mpStatus = strtolower(trim((string)($data['status'] ?? '')));
        $externalRef = (string)($data['external_reference'] ?? '');
        $mpPlanId = (string)($data['preapproval_plan_id'] ?? '');
        $nextBillingDate = isset($data['next_payment_date']) ? (string)$data['next_payment_date'] : null;

        if ($externalRef === '' || $mpPlanId === '' || $mpStatus === '') {
            return ['ok' => true, 'action' => 'incomplete_payload', 'http_status' => 200];
        }

        $parsed = self::parseExternalReference($externalRef);
        if ($parsed === null) {
            error_log('[MPWebhook] external_reference invalido (sanitizado): ' . preg_replace('/[^a-zA-Z0-9_]/', '_', $externalRef));
            return ['ok' => true, 'action' => 'invalid_external_reference', 'http_status' => 200];
        }
        [$userId, $expectedPlanSlug] = $parsed;

        $planFromMp = self::resolvePlanSlugFromMpPlanId($mpPlanId);
        if ($planFromMp === null) {
            error_log('[MPWebhook] preapproval_plan_id nao reconhecido');
            return ['ok' => true, 'action' => 'unknown_plan', 'http_status' => 200];
        }

        if ($planFromMp !== $expectedPlanSlug) {
            error_log('[MPWebhook] inconsistencia: ext_ref diz ' . $expectedPlanSlug . ' mas plan_id indica ' . $planFromMp);
            return ['ok' => true, 'action' => 'plan_mismatch', 'http_status' => 200];
        }

        $internalStatus = self::mapMpStatusToInternal($mpStatus);
        if ($internalStatus === null) {
            error_log('[MPWebhook] status nao mapeado: ' . $mpStatus);
            return ['ok' => true, 'action' => 'unmapped_status', 'http_status' => 200];
        }

        $planRow = $this->planModel->findBySlug($planFromMp);
        if ($planRow === null) {
            return ['ok' => true, 'action' => 'plan_not_in_db', 'http_status' => 200];
        }
        $planId = (int)$planRow['id'];

        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetchColumn() === false) {
            return ['ok' => true, 'action' => 'user_not_found', 'http_status' => 200];
        }

        $existing = $this->subscriptionModel->findByMpId($mpPreapprovalId);
        $isNew = false;
        if ($existing === null) {
            $existingByRef = $this->subscriptionModel->findLatestByUserId($userId);
            if ($existingByRef !== null && $existingByRef['plan_slug'] === $planFromMp) {
                $this->subscriptionModel->updateMpData(
                    (int)$existingByRef['id'],
                    $mpPreapprovalId,
                    $mpStatus,
                    $nextBillingDate
                );
                $subscriptionId = (int)$existingByRef['id'];
            } else {
                $subscriptionId = $this->subscriptionModel->create([
                    'user_id'           => $userId,
                    'plan_id'           => $planId,
                    'plan_slug'         => $planFromMp,
                    'mp_preapproval_id' => $mpPreapprovalId,
                    'status'            => $internalStatus,
                    'raw_status'        => $mpStatus,
                    'start_date'        => null,
                    'next_billing_date' => $nextBillingDate,
                    'external_reference'=> $externalRef,
                ]);
                $isNew = true;
            }
        } else {
            $subscriptionId = (int)$existing['id'];
            $this->subscriptionModel->updateMpData(
                $subscriptionId,
                $mpPreapprovalId,
                $mpStatus,
                $nextBillingDate
            );
        }

        $sub = $this->subscriptionModel->findById($subscriptionId);
        if ($sub === null) {
            return ['ok' => false, 'action' => 'subscription_disappeared', 'http_status' => 500];
        }

        $previousStatus = (string)$sub['status'];
        $this->subscriptionModel->updateStatusById(
            $subscriptionId,
            $internalStatus,
            $mpStatus,
            $nextBillingDate,
            null
        );

        if ($previousStatus !== $internalStatus || $isNew) {
            $fresh = $this->subscriptionModel->findById($subscriptionId);
            if ($fresh !== null) {
                $this->subscriptionModel->applyStatusToUser($fresh);
            }
        }

        return ['ok' => true, 'action' => 'processed', 'http_status' => 200];
    }
}

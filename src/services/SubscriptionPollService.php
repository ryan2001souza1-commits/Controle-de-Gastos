<?php
/**
 * SubscriptionPollService — polling da tentativa com reconciliação controlada.
 *
 * Problema resolvido: o endpoint subscription_status lia SOMENTE o banco
 * local. Se o POST /preapproval deixou a linha em pending (ou o webhook
 * atrasou/perdeu-se), o poll repetia "pending" até o deadline sem jamais
 * descobrir que o MP já autorizou (ou recusou).
 *
 * Comportamento:
 * - Linha inexistente ou de outro usuário: 404 idêntico (sem vazar existência).
 * - Status terminal (active/paused/rejected/cancelled/expired) ou tentativa
 *   sem mp_preapproval_id: resposta local, ZERO chamadas ao MP.
 * - pending + mp_preapproval_id + updated_at recente (THROTTLE_SECONDS):
 *   resposta local, sem rede (o estado acabou de ser sincronizado).
 * - pending + mp_preapproval_id + stale: UMA consulta GET /preapproval/{id}
 *   (rede, SEM transação aberta) → mapa canônico central →
 *     . mesmo status : atualiza o marcador de throttle, responde local;
 *     . mudou        : persiste (statements autocommit idempotentes),
 *                      aplica plano se active (idempotente), responde novo;
 *     . 404/transiente/desconhecido: fail-open, responde local sem escrever.
 *
 * Limites: no máximo ~1 GET ao MP a cada THROTTLE_SECONDS por tentativa
 * (marcador = updated_at, sem migração). Timeout do GET (8s) < maxDuration
 * da função (10s). NENHUMA rede ocorre dentro de transação; NENHUM
 * begin/commit neste fluxo (autocommit por statement).
 */
class SubscriptionPollService
{
    /** Segundos mínimos entre duas consultas ao MP para a mesma tentativa. */
    public const MP_REFRESH_SECONDS = 10;

    private $db;
    private MercadoPagoService $mpService;
    private Subscription $subscriptionModel;

    public function __construct($db, MercadoPagoService $mpService)
    {
        $this->db = $db;
        $this->mpService = $mpService;
        $this->subscriptionModel = new Subscription($db);
    }

    /**
     * @return array{http:int, body:array}
     */
    public function getStatus(int $userId, string $attemptToken): array
    {
        $attemptToken = strtolower(trim($attemptToken));
        $row = $this->subscriptionModel->findByAttemptToken($attemptToken);
        if ($row === null || (int)($row['user_id'] ?? 0) !== $userId) {
            return $this->out(404, ['ok' => false, 'error' => 'not_found']);
        }

        $status = (string)($row['status'] ?? Subscription::STATUS_PENDING);
        $mpId = (string)($row['mp_preapproval_id'] ?? '');

        // Terminal ou sem vínculo: nada a reconciliar — zero rede.
        if (
            $mpId === ''
            || !preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $mpId)
            || $status !== Subscription::STATUS_PENDING
        ) {
            return $this->localOut($row, $status, $mpId, false);
        }

        // Throttle: estado sincronizado há pouco — responde local, sem rede.
        if (!$this->isStale($row)) {
            return $this->localOut($row, $status, $mpId, false);
        }

        // Reconciliação controlada: UMA leitura ao MP, sem transação aberta.
        $check = $this->mpService->getPreapproval($mpId);
        if ($check['ok'] !== true || !is_array($check['data'] ?? null)) {
            // 404/transiente: fail-open — mantém o pending local. Registra o
            // check no throttle (backoff) sem alterar nenhum estado.
            $this->subscriptionModel->updateMpData(
                (int)$row['id'],
                $mpId,
                (string)($row['raw_status'] ?? ''),
                null
            );
            $this->logSync($attemptToken, $mpId, $status, 'mp_unavailable');
            return $this->localOut($row, $status, $mpId, false);
        }

        $data = $check['data'];
        $mpStatus = strtolower(trim((string)($data['status'] ?? '')));
        $nextBillingDate = isset($data['next_payment_date']) ? (string)$data['next_payment_date'] : null;
        $internal = MercadoPagoWebhookService::mapMercadoPagoSubscriptionStatus($mpStatus);
        if ($internal === null) {
            // Status desconhecido: nunca ativar. Backoff sem mudar estado.
            $this->subscriptionModel->updateMpData(
                (int)$row['id'],
                $mpId,
                (string)($row['raw_status'] ?? ''),
                null
            );
            $this->logSync($attemptToken, $mpId, $status, 'unmapped:' . $this->safeToken($mpStatus));
            return $this->localOut($row, $status, $mpId, false);
        }

        $subscriptionId = (int)$row['id'];
        $previousStatus = $status;
        if ($internal === $status) {
            // Sem mudança: só refresca o marcador de throttle.
            $this->subscriptionModel->updateMpData($subscriptionId, $mpId, $mpStatus, $nextBillingDate);
            $this->logSync($attemptToken, $mpId, $status, 'unchanged');
            $fresh = $this->subscriptionModel->findById($subscriptionId);
            if (is_array($fresh)) {
                $row = $fresh;
                $status = (string)($fresh['status'] ?? $status);
            }
            return $this->localOut($row, $status, $mpId, true);
        }

        // Mudou no MP: persiste (autocommit idempotente) e aplica se active.
        // Paridade com o webhook: rejected ganha grace period.
        $gracePeriodEnd = null;
        if ($internal === Subscription::STATUS_REJECTED) {
            $gracePeriodEnd = $nextBillingDate !== null
                ? $nextBillingDate
                : date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
        }
        $this->subscriptionModel->updateMpData($subscriptionId, $mpId, $mpStatus, $nextBillingDate);
        $this->subscriptionModel->updateStatusById(
            $subscriptionId,
            $internal,
            $mpStatus,
            $nextBillingDate,
            $gracePeriodEnd
        );
        $fresh = $this->subscriptionModel->findById($subscriptionId);
        if (is_array($fresh)) {
            $row = $fresh;
            $status = (string)($fresh['status'] ?? $internal);
            // Paridade com o webhook: aplica ao usuário quando o status
            // mudou. Idempotente — repetição não duplica (grant/restore
            // escrevem os mesmos valores).
            if ($previousStatus !== $internal) {
                $this->subscriptionModel->applyStatusToUser($fresh);
            }
        }
        $this->logSync($attemptToken, $mpId, $status, 'synced');
        return $this->localOut($row, $status, $mpId, true);
    }

    /**
     * Stale = updated_at mais antigo que THROTTLE_SECONDS (ou ilegível).
     * updated_at é NOT NULL com DEFAULT CURRENT_TIMESTAMP (schema), então
     * ausência/erro de parse = stale (direção fail-open: reconcilia).
     */
    private function isStale(array $row): bool
    {
        $updatedAt = (string)($row['updated_at'] ?? '');
        if ($updatedAt === '') {
            return true;
        }
        $ts = strtotime($updatedAt);
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) >= self::MP_REFRESH_SECONDS;
    }

    /**
     * @return array{http:int, body:array}
     */
    private function localOut(array $row, string $status, string $mpId, bool $synced): array
    {
        return $this->out(200, [
            'ok' => true,
            'status' => $status,
            'plan_slug' => (string)($row['plan_slug'] ?? ''),
            'linked' => ($mpId !== ''),
            'outcome' => SubscriptionCheckoutService::outcomeFor($status),
            'synced' => $synced,
        ]);
    }

    /**
     * @return array{http:int, body:array}
     */
    private function out(int $http, array $body): array
    {
        return ['http' => $http, 'body' => $body];
    }

    /**
     * Log sanitizado: sufixos de 8 chars, status e decisão. Nunca attempt
     * completo, mp id completo, email, token ou dados do MP.
     */
    private function logSync(string $attemptToken, string $mpId, string $localStatus, string $decision): void
    {
        error_log(sprintf(
            '[subscription_status] attempt_suffix=%s mp_suffix=%s local=%s decision=%s',
            substr($attemptToken, -8),
            substr($mpId, -8),
            preg_replace('/[^a-z_]/', '', $localStatus),
            preg_replace('/[^a-z_:]/', '', $decision)
        ));
    }

    /**
     * Redige status bruto desconhecido para charset seguro de log.
     */
    private function safeToken(string $raw): string
    {
        $t = strtolower(trim($raw));
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $t)) {
            return 'invalid';
        }
        return $t;
    }
}

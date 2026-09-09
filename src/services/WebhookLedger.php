<?php
/**
 * WebhookLedger — ledger minimo de idempotencia para futuros webhooks.
 *
 * Cada notificacao de gateway deve ser registrada UMA vez em
 * webhook_events. A UNIQUE (provider, provider_event_id) permite
 * INSERT ... ON CONFLICT DO NOTHING: a primeira reserva retorna true,
 * qualquer redelivery retorna false e NAO deve produzir efeito.
 *
 * Nenhum segredo (tokens, chaves, dados de cartao) pode chegar ao
 * payload persistido — normalizeEvent() sanitiza antes.
 */
final class WebhookLedger
{
    public const TABLE = 'webhook_events';

    /**
     * Valida e normaliza um evento antes de reservar.
     *
     * @return array{provider:string, provider_event_id:string, event_type:string,
     *     subscription_id:?int, resource_id:?string, payload:array}
     *
     * @throws InvalidArgumentException em qualquer campo invalido.
     */
    public static function normalizeEvent(array $event): array
    {
        $provider = BillingSyncService::normalizeProvider((string)($event['provider'] ?? ''));
        if ($provider === null) {
            throw new InvalidArgumentException('provider invalido.');
        }

        $eventId = trim((string)($event['provider_event_id'] ?? ''));
        if ($eventId === '' || strlen($eventId) > 120) {
            throw new InvalidArgumentException('provider_event_id invalido.');
        }

        $subscriptionId = $event['subscription_id'] ?? null;
        if ($subscriptionId !== null) {
            $subscriptionId = (int)$subscriptionId;
            if ($subscriptionId <= 0) {
                throw new InvalidArgumentException('subscription_id invalido.');
            }
        }

        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('payload invalido.');
        }

        $resourceId = $event['resource_id'] ?? null;
        if ($resourceId !== null) {
            $resourceId = trim((string)$resourceId);
            if ($resourceId === '') {
                $resourceId = null;
            } elseif (strlen($resourceId) > 120) {
                throw new InvalidArgumentException('resource_id invalido.');
            }
        }

        return [
            'provider'          => $provider,
            'provider_event_id' => $eventId,
            'event_type'        => substr(trim((string)($event['event_type'] ?? '')), 0, 80),
            'subscription_id'   => $subscriptionId,
            'resource_id'       => $resourceId,
            'payload'           => BillingSyncService::sanitizePayload($payload),
        ];
    }

    /**
     * INSERT idempotente: insere ou ignora silenciosamente quando o evento
     * ja foi registrado (redelivery do gateway).
     */
    public static function insertSql(): string
    {
        return 'INSERT INTO webhook_events '
            . '(provider, provider_event_id, event_type, subscription_id, resource_id, payload) '
            . 'VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON CONFLICT (provider, provider_event_id) DO NOTHING';
    }

    /**
     * Reserva o evento. Retorna true somente para a PRIMEIRA reserva;
     * redelivery retorna false e o chamador nao deve aplicar efeito.
     *
     * Deve ser chamado DENTRO da transacao do processamento do webhook.
     *
     * @param array $event Evento ja normalizado por normalizeEvent().
     */
    public static function reserve(PDO $db, array $event): bool
    {
        $json = json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('payload do evento nao pode ser serializado.');
        }
        $stmt = $db->prepare(self::insertSql());
        $stmt->execute([
            $event['provider'],
            $event['provider_event_id'],
            $event['event_type'] ?? '',
            $event['subscription_id'] ?? null,
            $event['resource_id'] ?? null,
            $json,
        ]);
        return $stmt->rowCount() === 1;
    }

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    /**
     * Janela apos a qual um 'processing' sem conclusao e considerado
     * obsoleto (crash) e pode ser retomado por redelivery.
     */
    public const STALE_PROCESSING_MINUTES = 10;

    /**
     * Reserva atomica com ciclo de vida (sem race condition):
     *  - INSERT ... ON CONFLICT DO NOTHING; inseriu => 'new';
     *  - conflito + status processed => 'duplicate' (sem efeitos);
     *  - conflito + failed/received/stale processing => retoma ('retry').
     *
     * @param array $event Evento ja normalizado por normalizeEvent().
     * @return 'new'|'retry'|'duplicate'
     */
    public static function claim(PDO $db, array $event): string
    {
        $json = json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('payload do evento nao pode ser serializado.');
        }
        $ins = $db->prepare(
            'INSERT INTO webhook_events (provider, provider_event_id, event_type, subscription_id, resource_id, payload, status, attempts)
             VALUES (?, ?, ?, ?, ?, ?, \'processing\', 1)
             ON CONFLICT (provider, provider_event_id) DO NOTHING'
        );
        $ins->execute([
            $event['provider'],
            $event['provider_event_id'],
            $event['event_type'] ?? '',
            $event['subscription_id'] ?? null,
            $event['resource_id'] ?? null,
            $json,
        ]);
        if ($ins->rowCount() === 1) {
            return 'new';
        }

        $sel = $db->prepare(
            'SELECT status, attempts, updated_at FROM webhook_events WHERE provider = ? AND provider_event_id = ?'
        );
        $sel->execute([$event['provider'], $event['provider_event_id']]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'duplicate';
        }
        $status = (string)($row['status'] ?? '');
        if ($status === self::STATUS_PROCESSED) {
            return 'duplicate';
        }
        if ($status === self::STATUS_PROCESSING && !self::isStale($row['updated_at'] ?? null)) {
            // Em processamento por outra entrega agora: nao rouba o claim.
            return 'duplicate';
        }
        $claim = $db->prepare(
            "UPDATE webhook_events SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
              WHERE provider = ? AND provider_event_id = ? AND status = ?"
        );
        $claim->execute([$event['provider'], $event['provider_event_id'], $status]);
        return $claim->rowCount() === 1 ? 'retry' : 'duplicate';
    }

    /**
     * Marca conclusao. Chamado dentro da mesma transacao do processamento;
     * redeliveries futuros retornam 'duplicate'.
     */
    public static function markProcessed(PDO $db, string $provider, string $eventId, ?int $subscriptionId = null): void
    {
        if ($subscriptionId !== null) {
            $stmt = $db->prepare(
                "UPDATE webhook_events SET status = 'processed', processed_at = NOW(), updated_at = NOW(), subscription_id = ?
                  WHERE provider = ? AND provider_event_id = ?"
            );
            $stmt->execute([$subscriptionId, $provider, $eventId]);
            return;
        }
        $stmt = $db->prepare(
            "UPDATE webhook_events SET status = 'processed', processed_at = NOW(), updated_at = NOW()
              WHERE provider = ? AND provider_event_id = ?"
        );
        $stmt->execute([$provider, $eventId]);
    }

    /**
     * Marca falha transitoria. NAO trava: proximo redelivery pode retomar
     * via claim(). O motivo vai so para log, nunca para o banco.
     */
    public static function markFailed(PDO $db, string $provider, string $eventId): void
    {
        $stmt = $db->prepare(
            "UPDATE webhook_events SET status = 'failed', updated_at = NOW()
              WHERE provider = ? AND provider_event_id = ?"
        );
        $stmt->execute([$provider, $eventId]);
    }

    private static function isStale(mixed $updatedAt): bool
    {
        if (!is_string($updatedAt) || $updatedAt === '') {
            return true;
        }
        $ts = strtotime($updatedAt);
        if ($ts === false) {
            return true;
        }
        return $ts < (time() - self::STALE_PROCESSING_MINUTES * 60);
    }
}

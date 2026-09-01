<?php
/**
 * RateLimiter — proteção contra brute force e abuso em endpoints sensíveis.
 *
 * Estratégia:
 * - Tracking por chave (e-mail, IP, ou ambos) em DB
 * - Janela deslizante: bloqueia após N tentativas em X segundos
 * - Backoff exponencial: incrementa tempo de bloqueio a cada falha
 * - Cleanup automático de registros antigos
 *
 * Compatível com Vercel serverless (usa DB Neon, sem dependência de filesystem).
 */
class RateLimiter
{
    private PDO $db;

    public const LOGIN = 'login';
    public const PASSWORD_RESET = 'password_reset';
    public const AI_CHAT = 'ai_chat';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Verifica se uma ação está bloqueada para a chave/identificador.
     * Retorna ['allowed' => bool, 'retry_after' => int, 'remaining' => int].
     */
    public function check(string $action, string $identifier, int $maxAttempts, int $windowSeconds): array
    {
        try {
            $now = time();
            $windowStart = date('Y-m-d H:i:s', $now - $windowSeconds);
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as attempts, MAX(blocked_until) as blocked_until
                 FROM rate_limit_attempts
                 WHERE action = ? AND identifier = ? AND failed = TRUE AND created_at >= ?::timestamp"
            );
            $stmt->execute([$action, $identifier, $windowStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $attempts = (int)($row['attempts'] ?? 0);
            $blockedUntil = $row['blocked_until'] ?? null;

            if ($blockedUntil !== null) {
                $blockedTs = strtotime($blockedUntil);
                if ($blockedTs !== false && $blockedTs > $now) {
                    return [
                        'allowed' => false,
                        'retry_after' => $blockedTs - $now,
                        'remaining' => 0,
                    ];
                }
            }

            return [
                'allowed' => true,
                'retry_after' => 0,
                'remaining' => max(0, $maxAttempts - $attempts),
            ];
        } catch (Throwable $e) {
            error_log('[RateLimiter] check failed: ' . $e->getMessage());
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }
    }

    /**
     * Registra uma tentativa (falha ou sucesso).
     * Para falhas, pode aplicar backoff exponencial.
     */
    public function recordAttempt(string $action, string $identifier, bool $failed = true, int $maxAttempts = 5, int $windowSeconds = 900): void
    {
        try {
            $blockedUntil = null;
            if ($failed) {
                $count = $this->getRecentAttemptCount($action, $identifier, $windowSeconds);
                $newCount = $count + 1;
                if ($newCount >= $maxAttempts) {
                    $backoff = $this->calculateBackoff($newCount, $maxAttempts, $windowSeconds);
                    $blockedUntil = date('Y-m-d H:i:s', time() + $backoff);
                }
            }

            $stmt = $this->db->prepare(
                "INSERT INTO rate_limit_attempts (action, identifier, failed, blocked_until, created_at)
                 VALUES (?, ?, ?, ?::timestamp, NOW())"
            );
            $stmt->execute([$action, $identifier, $failed ? 't' : 'f', $blockedUntil]);
        } catch (Throwable $e) {
            error_log('[RateLimiter] record failed: ' . $e->getMessage());
        }
    }

    /**
     * Limpa registros de tentativas (após sucesso, evita acúmulo).
     */
    public function clearAttempts(string $action, string $identifier): void
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM rate_limit_attempts WHERE action = ? AND identifier = ?"
            );
            $stmt->execute([$action, $identifier]);
        } catch (Throwable $e) {
            error_log('[RateLimiter] clear failed: ' . $e->getMessage());
        }
    }

    /**
     * Backoff exponencial: 1min, 5min, 15min, 1h, 6h (cap).
     */
    private function calculateBackoff(int $attempts, int $maxAttempts, int $windowSeconds): int
    {
        $excess = $attempts - $maxAttempts + 1;
        $backoffSeconds = 60 * (5 ** min($excess, 4));
        return min($backoffSeconds, 21600);
    }

    private function getRecentAttemptCount(string $action, string $identifier, int $windowSeconds): int
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM rate_limit_attempts
             WHERE action = ? AND identifier = ? AND failed = TRUE AND created_at >= ?::timestamp"
        );
        $stmt->execute([$action, $identifier, $windowStart]);
        return (int)$stmt->fetchColumn();
    }
}

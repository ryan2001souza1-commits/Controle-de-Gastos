<?php
/**
 * Handler de sessão em PostgreSQL para ambiente serverless (Vercel).
 * Armazena sessões no banco para sobreviver entre instâncias efêmeras.
 * Fallback para handler de arquivos se DB indisponível.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $db;
    private int $lifetime;

    public function __construct(PDO $db, int $lifetime = 604800)
    {
        $this->db = $db;
        $this->lifetime = $lifetime;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db->prepare("SELECT data FROM sessions WHERE id = ? AND expires_at > NOW()");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['data'] ?? '';
        } catch (Throwable $e) {
            error_log('[session read] ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $expires = date('Y-m-d H:i:s', time() + $this->lifetime);
            $stmt = $this->db->prepare("
                INSERT INTO sessions (id, data, expires_at) VALUES (?, ?, ?::timestamp)
                ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at
            ");
            return $stmt->execute([$id, $data, $expires]);
        } catch (Throwable $e) {
            error_log('[session write] ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
        } catch (Throwable $e) {}
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

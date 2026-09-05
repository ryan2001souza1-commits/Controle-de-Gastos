<?php

class PasswordReset
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Invalida todos os tokens ainda válidos do usuário.
     * Estratégia: marca como expirados no passado (não remove para manter histórico).
     */
    public function invalidateForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE password_resets
             SET expires_at = NOW() - INTERVAL \'1 minute\'
             WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$userId]);
    }

    public function create(int $userId, string $tokenHash, ?string $expiresAt = null): bool
    {
        if ($expiresAt !== null) {
            $stmt = $this->db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
            );
            return $stmt->execute([$userId, $tokenHash, $expiresAt]);
        }
        // Usa relógio do DB para evitar divergência entre PHP e Postgres (Vercel vs Neon)
        $stmt = $this->db->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '1 minute')"
        );
        return $stmt->execute([$userId, $tokenHash]);
    }

    /**
     * Localiza token válido: não usado e dentro do prazo.
     * Usa índice em token_hash para performance e hash_equals para timing.
     */
    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, token_hash, expires_at, used_at
             FROM password_resets
             WHERE token_hash = ?
             AND used_at IS NULL
             AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if ($row && hash_equals((string)$row['token_hash'], (string)$tokenHash)) {
            return $row;
        }
        return null;
    }

    public function markUsed(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL'
        );
        return $stmt->execute([$id]);
    }
}

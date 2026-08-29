<?php

class PasswordReset
{
    private PDO $db;

    public function __construct(PDO $db)
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

    public function create(int $userId, string $tokenHash, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        return $stmt->execute([$userId, $tokenHash, $expiresAt]);
    }

    /**
     * Localiza token válido: não usado e dentro do prazo.
     * Usa hash_equals para evitar timing attacks.
     */
    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, token_hash, expires_at, used_at
             FROM password_resets
             WHERE used_at IS NULL
             AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            if (hash_equals((string)$row['token_hash'], (string)$tokenHash)) {
                return $row;
            }
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

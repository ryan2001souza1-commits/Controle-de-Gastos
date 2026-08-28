<?php

class Category
{
    public ?int $id = null;
    public string $name;
    public string $type;
    public ?int $user_id = null;
    public ?string $created_at = null;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(
        int $userId,
        string $type = 'expense'
    ): array {
        $stmt = $this->db->prepare('
            SELECT
                id,
                nome AS name,
                tipo AS type,
                usuario_id AS user_id
            FROM categorias
            WHERE usuario_id = ?
              AND tipo = ?
            ORDER BY nome
        ');

        $stmt->execute([
            $userId,
            $type
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(
        string $name,
        string $type,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            INSERT INTO categorias
                (nome, tipo, usuario_id)
            VALUES
                (?, ?, ?)
        ');

        return $stmt->execute([
            $name,
            $type,
            $userId
        ]);
    }

    public function delete(
        int $id,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            DELETE FROM categorias
            WHERE id = ?
              AND usuario_id = ?
        ');

        return $stmt->execute([
            $id,
            $userId
        ]);
    }
}
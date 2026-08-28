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

    public function findAll(int $userId, string $type = 'expense'): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM categories WHERE user_id = ? AND type = ? ORDER BY name'
        );
        $stmt->execute([$userId, $type]);
        return $stmt->fetchAll();
    }

    public function create(string $name, string $type, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO categories (name, type, user_id) VALUES (?, ?, ?)'
        );
        return $stmt->execute([$name, $type, $userId]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM categories WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$id, $userId]);
    }
}

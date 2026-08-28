<?php

class Income
{
    public ?int $id = null;
    public string $description;
    public float $amount;
    public string $date;
    public int $user_id;
    public ?string $created_at = null;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUser(int $userId, ?string $startDate = null, ?string $endDate = null): array
    {
        $sql = 'SELECT * FROM incomes WHERE user_id = ?';
        $params = [$userId];

        if ($startDate) {
            $sql .= ' AND date >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND date <= ?';
            $params[] = $endDate;
        }

        $sql .= ' ORDER BY date DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(string $description, float $amount, string $date, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO incomes (description, amount, date, user_id) VALUES (?, ?, ?, ?)'
        );
        return $stmt->execute([$description, $amount, $date, $userId]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM incomes WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$id, $userId]);
    }

    public function getTotalByUser(int $userId, ?string $startDate = null, ?string $endDate = null): float
    {
        $sql = 'SELECT COALESCE(SUM(amount), 0) as total FROM incomes WHERE user_id = ?';
        $params = [$userId];

        if ($startDate) {
            $sql .= ' AND date >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND date <= ?';
            $params[] = $endDate;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetch()['total'];
    }
}

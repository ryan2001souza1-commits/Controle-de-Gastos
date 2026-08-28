<?php

class Expense
{
    public ?int $id = null;
    public string $description;
    public float $amount;
    public string $date;
    public ?int $category_id = null;
    public int $user_id;
    public ?string $created_at = null;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUser(int $userId, ?string $startDate = null, ?string $endDate = null): array
    {
        $sql = 'SELECT e.*, c.name as category_name 
                FROM expenses e 
                LEFT JOIN categories c ON e.category_id = c.id 
                WHERE e.user_id = ?';

        $params = [$userId];

        if ($startDate) {
            $sql .= ' AND e.date >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND e.date <= ?';
            $params[] = $endDate;
        }

        $sql .= ' ORDER BY e.date DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(string $description, float $amount, string $date, int $categoryId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO expenses (description, amount, date, category_id, user_id) VALUES (?, ?, ?, ?, ?)'
        );
        return $stmt->execute([$description, $amount, $date, $categoryId, $userId]);
    }

    public function update(int $id, string $description, float $amount, string $date, int $categoryId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE expenses SET description = ?, amount = ?, date = ?, category_id = ? WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$description, $amount, $date, $categoryId, $id, $userId]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM expenses WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$id, $userId]);
    }

    public function getTotalByUser(int $userId, ?string $startDate = null, ?string $endDate = null): float
    {
        $sql = 'SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ?';
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

    public function getTotalByCategory(int $userId, ?string $startDate = null, ?string $endDate = null): array
    {
        $sql = 'SELECT c.name, COALESCE(SUM(e.amount), 0) as total 
                FROM categories c 
                LEFT JOIN expenses e ON c.id = e.category_id AND e.user_id = ?
                WHERE c.user_id = ? AND c.type = ?';

        $params = [$userId, $userId, 'expense'];

        if ($startDate) {
            $sql .= ' AND (e.date IS NULL OR e.date >= ?)';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND (e.date IS NULL OR e.date <= ?)';
            $params[] = $endDate;
        }

        $sql .= ' GROUP BY c.id, c.name';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

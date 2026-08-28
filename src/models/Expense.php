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

    public function countByUser(int $userId, ?string $startDate = null, ?string $endDate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM transacoes WHERE usuario_id = ? AND tipo = ?';
        $params = [$userId, 'despesa'];
        if ($startDate) { $sql .= ' AND data >= ?'; $params[] = $startDate; }
        if ($endDate)   { $sql .= ' AND data <= ?'; $params[] = $endDate; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findByUser(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $sql = '
            SELECT
                t.id,
                t.descricao AS description,
                t.valor AS amount,
                t.data AS date,
                t.tipo AS type,
                t.categoria_id AS category_id,
                t.usuario_id AS user_id,
                c.nome AS category_name
            FROM transacoes t
            LEFT JOIN categorias c ON t.categoria_id = c.id
            WHERE t.usuario_id = ?
              AND t.tipo = ?
        ';

        $params = [$userId, 'despesa'];

        if ($startDate) {
            $sql .= ' AND t.data >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND t.data <= ?';
            $params[] = $endDate;
        }

        $sql .= ' ORDER BY t.data DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                t.id,
                t.descricao AS description,
                t.valor AS amount,
                t.data AS date,
                t.tipo AS type,
                t.categoria_id AS category_id,
                t.usuario_id AS user_id,
                c.nome AS category_name
            FROM transacoes t
            LEFT JOIN categorias c ON t.categoria_id = c.id
            WHERE t.id = ?
              AND t.usuario_id = ?
              AND t.tipo = ?
        ');
        $stmt->execute([$id, $userId, 'despesa']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(
        string $description,
        float $amount,
        string $date,
        ?int $categoryId,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            INSERT INTO transacoes
                (usuario_id, categoria_id, descricao, valor, tipo, data)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ');

        return $stmt->execute([
            $userId,
            $categoryId,
            $description,
            $amount,
            'despesa',
            $date
        ]);
    }

    public function update(
        int $id,
        string $description,
        float $amount,
        string $date,
        ?int $categoryId,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            UPDATE transacoes
            SET
                descricao = ?,
                valor = ?,
                data = ?,
                categoria_id = ?
            WHERE id = ?
              AND usuario_id = ?
              AND tipo = ?
        ');

        return $stmt->execute([
            $description,
            $amount,
            $date,
            $categoryId,
            $id,
            $userId,
            'despesa'
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM transacoes
            WHERE id = ?
              AND usuario_id = ?
              AND tipo = ?
        ');

        return $stmt->execute([
            $id,
            $userId,
            'despesa'
        ]);
    }

    public function getTotalByUser(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null
    ): float {
        $sql = '
            SELECT COALESCE(SUM(valor), 0) AS total
            FROM transacoes
            WHERE usuario_id = ?
              AND tipo = ?
        ';

        $params = [$userId, 'despesa'];

        if ($startDate) {
            $sql .= ' AND data >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND data <= ?';
            $params[] = $endDate;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (float) $stmt->fetch()['total'];
    }

    public function getTotalByCategory(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $sql = '
            SELECT
                c.nome AS name,
                COALESCE(SUM(t.valor), 0) AS total,
                COUNT(t.id) AS count
            FROM categorias c
            LEFT JOIN transacoes t
                ON c.id = t.categoria_id
                AND t.usuario_id = ?
                AND t.tipo = ?
        ';

        $params = [$userId, 'despesa'];

        $sql .= '
            WHERE c.usuario_id = ?
              AND c.tipo = ?
        ';

        $params[] = $userId;
        $params[] = 'despesa';

        if ($startDate) {
            $sql .= ' AND (t.data IS NULL OR t.data >= ?)';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND (t.data IS NULL OR t.data <= ?)';
            $params[] = $endDate;
        }

        $sql .= '
            GROUP BY c.id, c.nome
            ORDER BY c.nome
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
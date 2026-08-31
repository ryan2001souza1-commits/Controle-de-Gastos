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

    public function countByUser(int $userId, ?string $startDate = null, ?string $endDate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM transacoes WHERE usuario_id = ? AND tipo = ?';
        $params = [$userId, 'receita'];
        if ($startDate) { $sql .= ' AND data >= ?'; $params[] = $startDate; }
        if ($endDate)   { $sql .= ' AND data <= ?'; $params[] = $endDate; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countByUserMonth(int $userId, int $year, int $month): int
    {
        $startDate = sprintf('%d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM transacoes
             WHERE usuario_id = ? AND tipo = 'receita'
             AND data >= ? AND data <= ?"
        );
        $stmt->execute([$userId, $startDate, $endDate]);
        return (int)$stmt->fetchColumn();
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
                t.usuario_id AS user_id,
                t.tipo AS type,
                NULL::integer AS category_id,
                NULL::varchar AS category_name
            FROM transacoes t
            WHERE t.usuario_id = ?
              AND t.tipo = ?
        ';

        $params = [$userId, 'receita'];

        if ($startDate) {
            $sql .= ' AND data >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND data <= ?';
            $params[] = $endDate;
        }

        $sql .= ' ORDER BY data DESC';

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
                t.usuario_id AS user_id,
                NULL::integer AS category_id,
                NULL::varchar AS category_name
            FROM transacoes t
            WHERE t.id = ?
              AND t.usuario_id = ?
              AND t.tipo = ?
        ');
        $stmt->execute([$id, $userId, 'receita']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findAllByUser(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $search = null
    ): array {
        $sql = '
            SELECT
                t.id,
                t.descricao AS description,
                t.valor AS amount,
                t.data AS date,
                t.tipo AS type,
                NULL::integer AS category_id,
                NULL::varchar AS category_name
            FROM transacoes t
            WHERE t.usuario_id = ?
              AND t.tipo = ?
        ';

        $params = [$userId, 'receita'];

        if ($startDate) {
            $sql .= ' AND t.data >= ?';
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= ' AND t.data <= ?';
            $params[] = $endDate;
        }
        if ($search && trim($search) !== '') {
            $sql .= ' AND t.descricao ILIKE ?';
            $params[] = '%' . trim($search) . '%';
        }

        $sql .= ' ORDER BY t.data DESC, t.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(
        string $description,
        float $amount,
        string $date,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            INSERT INTO transacoes
                (usuario_id, descricao, valor, tipo, data)
            VALUES
                (?, ?, ?, ?, ?)
        ');

        return $stmt->execute([
            $userId,
            $description,
            $amount,
            'receita',
            $date
        ]);
    }

    public function update(
        int $id,
        string $description,
        float $amount,
        string $date,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            UPDATE transacoes
            SET
                descricao = ?,
                valor = ?,
                data = ?
            WHERE id = ?
              AND usuario_id = ?
              AND tipo = ?
        ');

        $stmt->execute([
            $description,
            $amount,
            $date,
            $id,
            $userId,
            'receita'
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM transacoes
            WHERE id = ?
              AND usuario_id = ?
              AND tipo = ?
        ');

        $stmt->execute([
            $id,
            $userId,
            'receita'
        ]);

        return $stmt->rowCount() > 0;
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

        $params = [$userId, 'receita'];

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
                AND t.usuario_id = c.usuario_id
                AND t.tipo = c.tipo
        ';

        $params = [$userId, 'receita'];
        $where = ['c.usuario_id = ?', 'c.tipo = ?'];

        if ($startDate) {
            $where[] = '(t.data IS NULL OR t.data >= ?)';
            $params[] = $startDate;
        }
        if ($endDate) {
            $where[] = '(t.data IS NULL OR t.data <= ?)';
            $params[] = $endDate;
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY c.id, c.nome ORDER BY c.nome';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
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

    public function findByUser(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $sql = '
            SELECT
                id,
                descricao AS description,
                valor AS amount,
                data AS date,
                usuario_id AS user_id,
                tipo AS type
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

        $sql .= ' ORDER BY data DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                descricao AS description,
                valor AS amount,
                data AS date,
                tipo AS type,
                usuario_id AS user_id
            FROM transacoes
            WHERE id = ?
              AND usuario_id = ?
              AND tipo = ?
        ');
        $stmt->execute([$id, $userId, 'receita']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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

        return $stmt->execute([
            $description,
            $amount,
            $date,
            $id,
            $userId,
            'receita'
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
            'receita'
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

    public function getTotalsByPeriod(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        string $groupBy = 'day'
    ): array {
        if ($groupBy === 'month') {
            $dateExpr = "TO_CHAR(data, 'YYYY-MM')";
            $dateLabel = "TO_CHAR(data, 'MMM/YYYY')";
        } else {
            $dateExpr = 'data::text';
            $dateLabel = "TO_CHAR(data, 'DD/MM/YYYY')";
        }

        $sql = "
            SELECT
                {$dateExpr} AS period,
                {$dateLabel} AS label,
                COALESCE(SUM(valor), 0) AS total
            FROM transacoes
            WHERE usuario_id = ?
              AND tipo = 'receita'
        ";

        $params = [$userId];

        if ($startDate) {
            $sql .= ' AND data >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND data <= ?';
            $params[] = $endDate;
        }

        $sql .= " GROUP BY {$dateExpr} ORDER BY {$dateExpr}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
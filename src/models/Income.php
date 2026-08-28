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
                usuario_id AS user_id
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
}
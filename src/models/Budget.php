<?php

class Budget
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUserPeriod(int $userId, int $year, int $month): array
    {
        $stmt = $this->db->prepare('
            SELECT
                o.id,
                o.categoria_id AS category_id,
                c.nome AS category_name,
                o.ano AS year,
                o.mes AS month,
                o.valor_limite AS limit_amount,
                COALESCE(SUM(t.valor), 0) AS spent_amount,
                COUNT(t.id) AS transaction_count
            FROM orcamentos o
            INNER JOIN categorias c ON c.id = o.categoria_id AND c.usuario_id = o.usuario_id
            LEFT JOIN transacoes t
                ON t.categoria_id = o.categoria_id
                AND t.usuario_id = o.usuario_id
                AND t.tipo = \'despesa\'
                AND EXTRACT(YEAR FROM t.data) = o.ano
                AND EXTRACT(MONTH FROM t.data) = o.mes
            WHERE o.usuario_id = ?
              AND o.ano = ?
              AND o.mes = ?
            GROUP BY o.id, o.categoria_id, c.nome, o.ano, o.mes, o.valor_limite
            ORDER BY c.nome
        ');
        $stmt->execute([$userId, $year, $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                categoria_id AS category_id,
                ano AS year,
                mes AS month,
                valor_limite AS limit_amount
            FROM orcamentos
            WHERE id = ? AND usuario_id = ?
        ');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(int $userId, int $categoryId, int $year, int $month, float $limitAmount): bool
    {
        $stmt = $this->db->prepare('
            INSERT INTO orcamentos
                (usuario_id, categoria_id, ano, mes, valor_limite)
            VALUES
                (?, ?, ?, ?, ?)
            ON CONFLICT (usuario_id, categoria_id, ano, mes) DO UPDATE
                SET valor_limite = EXCLUDED.valor_limite
        ');
        return $stmt->execute([$userId, $categoryId, $year, $month, $limitAmount]);
    }

    public function create(int $userId, int $categoryId, int $year, int $month, float $limitAmount): bool
    {
        $stmt = $this->db->prepare('
            INSERT INTO orcamentos
                (usuario_id, categoria_id, ano, mes, valor_limite)
            VALUES
                (?, ?, ?, ?, ?)
        ');
        return $stmt->execute([$userId, $categoryId, $year, $month, $limitAmount]);
    }

    public function update(int $id, float $limitAmount, int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE orcamentos
            SET valor_limite = ?
            WHERE id = ? AND usuario_id = ?
        ');
        $stmt->execute([$limitAmount, $id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM orcamentos
            WHERE id = ? AND usuario_id = ?
        ');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}

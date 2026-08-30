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
        string $type = 'despesa',
        bool $onlyActive = false
    ): array {
        $sql = '
            SELECT
                id,
                nome AS name,
                tipo AS type,
                usuario_id AS user_id,
                cor,
                icone,
                ativo
            FROM categorias
            WHERE usuario_id = ?
              AND tipo = ?
        ';
        if ($onlyActive) {
            $sql .= ' AND ativo = 1';
        }
        $sql .= ' ORDER BY nome';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $type]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                nome AS name,
                tipo AS type,
                usuario_id AS user_id,
                cor,
                icone,
                ativo
            FROM categorias
            WHERE id = ?
              AND usuario_id = ?
        ');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findAllWithStats(
        int $userId,
        string $type,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $sql = '
            SELECT
                c.id,
                c.nome        AS name,
                c.tipo        AS type,
                c.usuario_id  AS user_id,
                c.cor         AS color,
                c.icone       AS icon,
                c.ativo       AS active,
                COUNT(t.id)   AS tx_count,
                COALESCE(SUM(t.valor), 0) AS tx_total
            FROM categorias c
            LEFT JOIN transacoes t
                ON t.categoria_id = c.id
                AND t.usuario_id  = c.usuario_id
                AND t.tipo        = c.tipo
        ';

        $params = [];
        $conditions = [];

        $conditions[] = 'c.usuario_id = ?';
        $params[] = $userId;

        $conditions[] = 'c.tipo = ?';
        $params[] = $type;

        if ($startDate) {
            $conditions[] = '(t.data IS NULL OR t.data >= ?)';
            $params[] = $startDate;
        }
        if ($endDate) {
            $conditions[] = '(t.data IS NULL OR t.data <= ?)';
            $params[] = $endDate;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= ' GROUP BY c.id, c.nome, c.tipo, c.usuario_id, c.cor, c.icone, c.ativo';
        $sql .= ' ORDER BY c.nome';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countTransactions(int $id, int $userId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM transacoes
            WHERE categoria_id = ?
              AND usuario_id = ?
        ');
        $stmt->execute([$id, $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function countByName(
        string $name,
        string $type,
        int $userId,
        ?int $excludeId = null
    ): int {
        $sql = '
            SELECT COUNT(*) FROM categorias
            WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))
              AND tipo = ?
              AND usuario_id = ?
        ';
        $params = [$name, $type, $userId];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function create(
        string $name,
        string $type,
        int $userId,
        ?string $color = null,
        ?string $icon = null,
        bool $active = true
    ): bool {
        $stmt = $this->db->prepare('
            INSERT INTO categorias
                (nome, tipo, usuario_id, cor, icone, ativo)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ');

        return $stmt->execute([
            $name,
            $type,
            $userId,
            $color ?? '#10b981',
            $icon ?? 'tag',
            $active ? 1 : 0,
        ]);
    }

    public function update(
        int $id,
        string $name,
        string $type,
        int $userId,
        ?string $color = null,
        ?string $icon = null,
        ?bool $active = null
    ): bool {
        $existing = $this->findById($id, $userId);
        if (!$existing) return false;

        $newColor = $color ?? ($existing['cor'] ?? '#10b981');
        $newIcon  = $icon  ?? ($existing['icone'] ?? 'tag');
        $newActive = $active === null ? (int)($existing['ativo'] ?? 1) : ($active ? 1 : 0);

        $stmt = $this->db->prepare('
            UPDATE categorias
            SET nome = ?,
                tipo = ?,
                cor  = ?,
                icone = ?,
                ativo = ?
            WHERE id = ?
              AND usuario_id = ?
        ');

        $stmt->execute([
            $name,
            $type,
            $newColor,
            $newIcon,
            $newActive,
            $id,
            $userId,
        ]);

        return $stmt->rowCount() > 0;
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

        $stmt->execute([
            $id,
            $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function setActive(int $id, int $userId, bool $active): bool
    {
        $stmt = $this->db->prepare('
            UPDATE categorias
            SET ativo = ?
            WHERE id = ?
              AND usuario_id = ?
        ');
        return $stmt->execute([$active ? 1 : 0, $id, $userId]);
    }
}
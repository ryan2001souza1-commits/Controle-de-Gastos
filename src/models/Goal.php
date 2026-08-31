<?php

class Goal
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAllByUser(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                nome AS name,
                valor_objetivo AS target_amount,
                valor_acumulado AS saved_amount,
                data_limite AS deadline,
                descricao AS description,
                created_at
            FROM metas
            WHERE usuario_id = ?
            ORDER BY
                CASE WHEN data_limite IS NULL THEN 1 ELSE 0 END,
                data_limite ASC,
                nome ASC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                nome AS name,
                valor_objetivo AS target_amount,
                valor_acumulado AS saved_amount,
                data_limite AS deadline,
                descricao AS description,
                created_at
            FROM metas
            WHERE id = ? AND usuario_id = ?
        ');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(
        string $name,
        float $targetAmount,
        float $savedAmount,
        ?string $deadline,
        ?string $description,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            INSERT INTO metas
                (usuario_id, nome, valor_objetivo, valor_acumulado, data_limite, descricao)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ');

        return $stmt->execute([
            $userId,
            $name,
            $targetAmount,
            $savedAmount,
            $deadline ?: null,
            $description ?: null
        ]);
    }

    public function update(
        int $id,
        string $name,
        float $targetAmount,
        float $savedAmount,
        ?string $deadline,
        ?string $description,
        int $userId
    ): bool {
        $stmt = $this->db->prepare('
            UPDATE metas
            SET
                nome = ?,
                valor_objetivo = ?,
                valor_acumulado = ?,
                data_limite = ?,
                descricao = ?
            WHERE id = ? AND usuario_id = ?
        ');

        $stmt->execute([
            $name,
            $targetAmount,
            $savedAmount,
            $deadline ?: null,
            $description ?: null,
            $id,
            $userId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updateSavedAmount(int $id, float $amount, int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE metas
            SET valor_acumulado = ?
            WHERE id = ? AND usuario_id = ?
        ');
        $stmt->execute([$amount, $id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM metas
            WHERE id = ? AND usuario_id = ?
        ');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function countByName(string $name, int $userId, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM metas WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?)) AND usuario_id = ?';
        $params = [$name, $userId];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM metas WHERE usuario_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}

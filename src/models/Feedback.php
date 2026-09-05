<?php
class Feedback
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO feedback (usuario_id, tipo, titulo, descricao, status)
             VALUES (?, ?, ?, ?, 'novo')"
        );
        $stmt->execute([
            $data['usuario_id'],
            $data['tipo'],
            $data['titulo'],
            $data['descricao'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findById(int $id, ?int $forUserId = null): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, u.nome AS usuario_nome, u.email AS usuario_email
             FROM feedback f
             LEFT JOIN usuarios u ON u.id = f.usuario_id
             WHERE f.id = ?"
             . ($forUserId !== null ? " AND f.usuario_id = ?" : "")
             . " LIMIT 1"
        );
        $params = $forUserId !== null ? [$id, $forUserId] : [$id];
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM feedback WHERE usuario_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(?string $status = null, ?string $q = null, int $limit = 20, int $offset = 0): array
    {
        $where = []; $params = [];
        if ($status !== null && $status !== '') {
            $where[] = "f.status = ?";
            $params[] = $status;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(f.titulo ILIKE ? OR f.descricao ILIKE ? OR u.nome ILIKE ? OR u.email ILIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT f.*, u.nome AS usuario_nome, u.email AS usuario_email
                FROM feedback f
                LEFT JOIN usuarios u ON u.id = f.usuario_id
                $whereSql
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $i = count($params);
        foreach ($params as $idx => $val) {
            $stmt->bindValue($idx + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(++$i, $limit, PDO::PARAM_INT);
        $stmt->bindValue(++$i, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(?string $status = null, ?string $q = null): int
    {
        $where = []; $params = [];
        if ($status !== null && $status !== '') {
            $where[] = "f.status = ?";
            $params[] = $status;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(f.titulo ILIKE ? OR f.descricao ILIKE ? OR u.nome ILIKE ? OR u.email ILIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM feedback f LEFT JOIN usuarios u ON u.id = f.usuario_id $whereSql";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function updateStatus(int $id, string $status, ?string $resposta = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE feedback SET status = ?, resposta_admin = COALESCE(?, resposta_admin), updated_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$status, $resposta, $id]);
    }

    public function stats(): array
    {
        $sql = "SELECT
                COUNT(*) AS total,
                COUNT(CASE WHEN status = 'novo' THEN 1 END) AS novos,
                COUNT(CASE WHEN status = 'em_analise' THEN 1 END) AS em_analise,
                COUNT(CASE WHEN status = 'implementado' THEN 1 END) AS implementados,
                COUNT(CASE WHEN status = 'recusado' THEN 1 END) AS recusados
            FROM feedback";
        $row = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC);
        return [
            'total' => (int)($row['total'] ?? 0),
            'novos' => (int)($row['novos'] ?? 0),
            'em_analise' => (int)($row['em_analise'] ?? 0),
            'implementados' => (int)($row['implementados'] ?? 0),
            'recusados' => (int)($row['recusados'] ?? 0),
        ];
    }
}

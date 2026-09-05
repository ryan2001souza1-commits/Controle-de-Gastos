<?php
class BugReport
{
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bug_reports (usuario_id, titulo, categoria, descricao, pagina, url, prioridade, status, navegador, sistema_operacional, screenshot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $data['usuario_id'],
            $data['titulo'],
            $data['categoria'],
            $data['descricao'],
            $data['pagina'] ?? null,
            $data['url'] ?? null,
            $data['prioridade'] ?? 'media',
            $data['status'] ?? 'novo',
            $data['navegador'] ?? null,
            $data['sistema_operacional'] ?? null,
            $data['screenshot'] ?? null,
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function findById(int $id, ?int $forUserId = null): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, u.nome as usuario_nome, u.email as usuario_email
            FROM bug_reports b LEFT JOIN usuarios u ON u.id = b.usuario_id
            WHERE b.id = ?"
            . ($forUserId !== null ? " AND b.usuario_id = ?" : "")
            . " LIMIT 1"
        );
        $params = $forUserId !== null ? [$id, $forUserId] : [$id];
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM bug_reports WHERE usuario_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(?string $status = null, ?string $search = null, int $limit = 50, int $offset = 0): array
    {
        $where = []; $params = [];
        if ($status && $status !== '') { $where[] = "b.status = ?"; $params[] = $status; }
        if ($search && trim($search) !== '') { $where[] = "(b.titulo ILIKE ? OR b.descricao ILIKE ? OR u.email ILIKE ?)"; $like = '%'.trim($search).'%'; $params[] = $like; $params[] = $like; $params[] = $like; }
        $sql = "SELECT b.*, u.nome as usuario_nome, u.email as usuario_email FROM bug_reports b LEFT JOIN usuarios u ON u.id = b.usuario_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($params as $val) {
            $stmt->bindValue($i++, $val);
        }
        $stmt->bindValue($i++, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue($i++, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(?string $status = null, ?string $search = null): int
    {
        $where = []; $params = [];
        if ($status && $status !== '') { $where[] = "b.status = ?"; $params[] = $status; }
        if ($search && trim($search) !== '') { $where[] = "(b.titulo ILIKE ? OR b.descricao ILIKE ? OR u.email ILIKE ?)"; $like = '%'.trim($search).'%'; $params[] = $like; $params[] = $like; $params[] = $like; }
        $sql = "SELECT COUNT(*) FROM bug_reports b LEFT JOIN usuarios u ON u.id = b.usuario_id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function updateStatus(int $id, string $status, ?string $resposta = null, ?string $obs = null): bool
    {
        $allowed = ['novo','recebido','em_analise','em_desenvolvimento','resolvido','fechado','nao_reproduzido'];
        if (!in_array($status, $allowed, true)) return false;
        $stmt = $this->db->prepare("UPDATE bug_reports SET status = ?, resposta_admin = COALESCE(?, resposta_admin), observacao_interna = COALESCE(?, observacao_interna), updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$status, $resposta, $obs, $id]);
    }

    public function addResponse(int $id, string $resposta, ?string $obs = null): bool
    {
        $stmt = $this->db->prepare("UPDATE bug_reports SET resposta_admin = ?, observacao_interna = COALESCE(?, observacao_interna), updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$resposta, $obs, $id]);
    }

    public function stats(): array
    {
        $total = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports")->fetchColumn();
        $novos = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'novo'")->fetchColumn();
        $recebidos = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'recebido'")->fetchColumn();
        $pend = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status IN ('novo','recebido')")->fetchColumn();
        $analise = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status IN ('em_analise','em_desenvolvimento')")->fetchColumn();
        $resolv = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status IN ('resolvido','fechado')")->fetchColumn();
        $resolvidos = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'resolvido'")->fetchColumn();
        $fechados = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'fechado'")->fetchColumn();
        $emDev = (int)$this->db->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'em_desenvolvimento'")->fetchColumn();
        return [
            'total' => $total,
            'novos' => $novos,
            'recebidos' => $recebidos,
            'pendentes' => $pend,
            'em_analise' => $analise,
            'em_desenvolvimento' => $emDev,
            'resolvidos' => $resolvidos,
            'fechados' => $fechados,
            'resolvido' => $resolv,
        ];
    }
}

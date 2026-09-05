<?php
class Plan
{
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function findAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM planos ORDER BY preco ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM planos WHERE slug = ?");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

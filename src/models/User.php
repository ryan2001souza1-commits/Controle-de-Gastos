<?php

class User
{
    public ?int $id = null;
    public string $name;
    public string $email;
    public string $password_hash;
    public ?string $created_at = null;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE email = ?'
        );

        $stmt->execute([$email]);

        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE id = ?'
        );

        $stmt->execute([$id]);

        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    public function create(
        string $name,
        string $email,
        string $password
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (nome, email, senha)
             VALUES (?, ?, ?)'
        );

        return $stmt->execute([
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT)
        ]);
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify(
            $password,
            $this->password_hash
        );
    }

    private function hydrate(array $data): User
    {
        $this->id = (int) $data['id'];
        $this->name = $data['nome'];
        $this->email = $data['email'];
        $this->password_hash = $data['senha'];
        $this->created_at = $data['created_at'];

        return $this;
    }
}
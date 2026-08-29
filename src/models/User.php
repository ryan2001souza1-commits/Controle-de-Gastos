<?php

class User
{
    public ?int $id = null;
    public string $name;
    public string $email;
    public string $password_hash;
    public ?string $reset_token = null;
    public ?string $reset_expires = null;
    public ?string $created_at = null;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))'
        );

        $stmt->execute([$email]);

        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        $user = new User($this->db);
        return $user->hydrate($data);
    }

    public function findByResetToken(string $token): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios
             WHERE reset_token = ?
             AND reset_expires IS NOT NULL
             AND reset_expires > NOW()'
        );

        $stmt->execute([$token]);
        $data = $stmt->fetch();

        if (!$data) {
            return null;
        }

        $user = new User($this->db);
        return $user->hydrate($data);
    }

    public function setResetToken(int $userId, string $tokenHash, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET reset_token = ?, reset_expires = ? WHERE id = ?'
        );

        return $stmt->execute([$tokenHash, $expiresAt, $userId]);
    }

    public function clearResetToken(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET reset_token = NULL, reset_expires = NULL WHERE id = ?'
        );

        return $stmt->execute([$userId]);
    }

    public function updatePassword(int $userId, string $newPassword): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET senha = ? WHERE id = ?'
        );

        return $stmt->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $userId
        ]);
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

        $user = new User($this->db);
        return $user->hydrate($data);
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
        $this->reset_token = $data['reset_token'] ?? null;
        $this->reset_expires = $data['reset_expires'] ?? null;
        $this->created_at = $data['created_at'];

        return $this;
    }
}
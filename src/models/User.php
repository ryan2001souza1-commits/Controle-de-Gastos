<?php

class User
{
    public ?int $id = null;
    public string $name;
    public string $email;
    public ?string $password_hash = null;
    public ?string $reset_token = null;
    public ?string $reset_expires = null;
    public ?string $created_at = null;
    public ?string $provider = null;
    public ?string $provider_sub = null;
    public ?string $telefone = null;
    public ?string $data_nascimento = null;
    public ?float $renda_mensal = null;
    public ?int $dia_recebimento = null;
    public ?string $objetivo = null;
    public ?string $moeda = null;
    public ?int $notificacoes = null;
    public int $is_admin = 0;
    public string $plano = 'gratuito';
    public string $plano_status = 'ativo';
    public ?string $plano_inicio = null;
    public ?string $plano_fim = null;

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
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET senha = ?, updated_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([$hash, $userId]);
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

    public function findByGoogleId(string $sub): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE provider = ? AND provider_sub = ?'
        );
        $stmt->execute(['google', $sub]);
        $data = $stmt->fetch();
        if (!$data) {
            return null;
        }
        $user = new User($this->db);
        return $user->hydrate($data);
    }

    public function createOAuthUser(string $name, string $email, string $provider, string $sub): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (nome, email, senha, provider, provider_sub)
             VALUES (?, ?, NULL, ?, ?)'
        );
        return $stmt->execute([$name, $email, $provider, $sub]);
    }

    public function verifyPassword(string $password): bool
    {
        if ($this->password_hash === null || $this->password_hash === '') {
            return false;
        }
        $valid = password_verify(
            $password,
            $this->password_hash
        );

        if ($valid && $this->id !== null && password_needs_rehash($this->password_hash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $upd = $this->db->prepare('UPDATE usuarios SET senha = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$newHash, $this->id]);
                $this->password_hash = $newHash;
            } catch (Throwable $e) {
                error_log('[User] rehash failed for user ' . $this->id . ': ' . $e->getMessage());
            }
        }

        return $valid;
    }

    private function hydrate(array $data): User
    {
        $this->id = (int) $data['id'];
        $this->name = $data['nome'];
        $this->email = $data['email'];
        $this->password_hash = $data['senha'] ?? null;
        $this->reset_token = $data['reset_token'] ?? null;
        $this->reset_expires = $data['reset_expires'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->provider = $data['provider'] ?? null;
        $this->provider_sub = $data['provider_sub'] ?? null;
        $this->telefone = $data['telefone'] ?? null;
        $this->data_nascimento = $data['data_nascimento'] ?? null;
        $this->renda_mensal = isset($data['renda_mensal']) && $data['renda_mensal'] !== null ? (float)$data['renda_mensal'] : null;
        $this->dia_recebimento = isset($data['dia_recebimento']) && $data['dia_recebimento'] !== null ? (int)$data['dia_recebimento'] : null;
        $this->objetivo = $data['objetivo'] ?? null;
        $this->moeda = $data['moeda'] ?? 'BRL';
        $this->notificacoes = isset($data['notificacoes']) ? (int)$data['notificacoes'] : 1;
        $this->is_admin = isset($data['is_admin']) ? (int)$data['is_admin'] : 0;
        $this->plano = $data['plano'] ?? 'gratuito';
        $this->plano_status = $data['plano_status'] ?? 'ativo';
        $this->plano_inicio = $data['plano_inicio'] ?? null;
        $this->plano_fim = $data['plano_fim'] ?? null;

        return $this;
    }

    public function isAdmin(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT is_admin FROM usuarios WHERE id = ?');
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?? 0) === 1;
    }

    public function updateProfile(int $id, array $fields): bool
    {
        $allowed = ['nome','email','telefone','data_nascimento','renda_mensal','dia_recebimento','objetivo','moeda','notificacoes'];
        $sets = []; $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "$k = ?";
            $params[] = $v;
        }
        if (empty($sets)) return false;
        $params[] = $id;
        $sql = 'UPDATE usuarios SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function isEmailTaken(string $email, int $excludeId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
        $stmt->execute([$email, $excludeId]);
        return (bool)$stmt->fetchColumn();
    }
}
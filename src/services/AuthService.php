<?php

class AuthService
{
    private User $userModel;

    public function __construct(User $userModel)
    {
        $this->userModel = $userModel;
    }

    public function login(string $email, string $password): bool
    {
        $user = $this->userModel->findByEmail($email);

        if (!$user || !$user->verifyPassword($password)) {
            return false;
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['user_name'] = $user->name;
        $_SESSION['user_email'] = $user->email;
        session_regenerate_id(true);

        return true;
    }

    public function register(string $name, string $email, string $password): array
    {
        if (empty($name) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Todos os campos são obrigatórios.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'E-mail inválido.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'message' => 'Senha deve ter no mínimo 6 caracteres.'];
        }

        if ($this->userModel->findByEmail($email)) {
            return ['success' => false, 'message' => 'E-mail já cadastrado.'];
        }

        $this->userModel->create($name, $email, $password);
        return ['success' => true, 'message' => 'Usuário cadastrado com sucesso.'];
    }

    public function logout(): void
    {
        session_unset();
        session_destroy();
        header('Location: /login.php');
        exit;
    }

    public function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public function getUserName(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }
}

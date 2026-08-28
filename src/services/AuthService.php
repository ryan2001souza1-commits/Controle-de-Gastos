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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        return true;
    }

    public function register(string $name, string $email, string $password, string $passwordConfirm): array
    {
        if (empty($name) || empty($email) || empty($password) || empty($passwordConfirm)) {
            return ['success' => false, 'message' => 'Todos os campos são obrigatórios.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'E-mail inválido.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Senha deve ter no mínimo 8 caracteres.'];
        }

        if ($password !== $passwordConfirm) {
            return ['success' => false, 'message' => 'A confirmação de senha não confere.'];
        }

        if ($this->userModel->findByEmail($email)) {
            return ['success' => false, 'message' => 'E-mail já cadastrado.'];
        }

        if (!$this->userModel->create($name, $email, $password)) {
            return ['success' => false, 'message' => 'E-mail já cadastrado.'];
        }

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

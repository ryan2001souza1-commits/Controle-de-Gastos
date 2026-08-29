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

    /**
     * Inicia o fluxo de recuperação de senha.
     * Retorna o token bruto (para exibir link de reset na UI em ambientes
     * sem SMTP). O hash do token é o que vai para o banco.
     */
    public function requestPasswordReset(string $email): array
    {
        $email = trim($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Informe um e-mail válido.'];
        }

        $user = $this->userModel->findByEmail($email);

        // Resposta genérica para evitar enumeração de e-mails cadastrados
        $generic = 'Se o e-mail estiver cadastrado, você receberá as instruções para recuperar sua senha.';

        if (!$user) {
            return ['success' => true, 'message' => $generic, 'token' => null];
        }

        $tokenPlain = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $tokenPlain);
        $expiresAt  = date('Y-m-d H:i:s', time() + 3600);

        $this->userModel->setResetToken($user->id, $tokenHash, $expiresAt);

        return [
            'success' => true,
            'message' => $generic,
            'token'   => $tokenPlain,
        ];
    }

    public function resetPasswordWithToken(string $token, string $newPassword, string $confirm): array
    {
        $token = trim($token);

        if ($token === '') {
            return ['success' => false, 'message' => 'Token inválido ou expirado.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 8 caracteres.'];
        }

        if ($newPassword !== $confirm) {
            return ['success' => false, 'message' => 'A confirmação de senha não confere.'];
        }

        $tokenHash = hash('sha256', $token);
        $user = $this->userModel->findByResetToken($tokenHash);

        if (!$user) {
            return ['success' => false, 'message' => 'Token inválido ou expirado. Solicite um novo link.'];
        }

        if (!$this->userModel->updatePassword($user->id, $newPassword)) {
            return ['success' => false, 'message' => 'Não foi possível atualizar a senha. Tente novamente.'];
        }

        $this->userModel->clearResetToken($user->id);

        return ['success' => true, 'message' => 'Senha redefinida com sucesso. Você já pode fazer login.'];
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

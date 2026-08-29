<?php

class AuthController
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($this->authService->login($email, $password)) {
                header('Location: /index.php');
                exit;
            }

            $error = 'E-mail ou senha incorretos.';
            require basePath('login.php');
            return;
        }

        require basePath('login.php');
    }

    public function register(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';

            $result = $this->authService->register($name, $email, $password, $passwordConfirm);

            if ($result['success']) {
                header('Location: /login.php?registered=1');
                exit;
            }

            $error = $result['message'];
            require basePath('register.php');
            return;
        }

        require basePath('register.php');
    }

    public function logout(): void
    {
        $this->authService->logout();
    }

    public function forgot(): void
    {
        $error   = null;
        $success = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $result = $this->authService->requestPasswordReset($email);

            if (!$result['success']) {
                $error = $result['message'];
            } else {
                $success = $result['message'];
            }
        }

        require basePath('forgot.php');
    }

    public function reset(): void
    {
        $token   = $_GET['token'] ?? ($_POST['token'] ?? '');
        $error   = null;
        $success = null;

        // Valida token antes de exibir a tela (GET) ou redefinir (POST)
        $tokenValid = $this->authService->validateResetToken($token);

        if (!$tokenValid) {
            $error = 'Token inválido, expirado ou já utilizado. Solicite um novo link.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$tokenValid) {
                // mantém erro acima; não processa
            } else {
                $newPassword = $_POST['password'] ?? '';
                $confirm     = $_POST['password_confirm'] ?? '';
                $result = $this->authService->resetPasswordWithToken($token, $newPassword, $confirm);

                if (!$result['success']) {
                    $error = $result['message'];
                } else {
                    // Redireciona para login com flag de sucesso
                    header('Location: /index.php?action=login&reset=1');
                    exit;
                }
            }
        }

        require basePath('reset.php');
    }
}

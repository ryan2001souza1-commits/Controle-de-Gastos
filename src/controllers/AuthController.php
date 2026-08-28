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
}

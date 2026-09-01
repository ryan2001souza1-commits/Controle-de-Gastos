<?php

class AuthController
{
    private AuthService $authService;
    private GoogleAuthService $googleAuth;
    private User $userModel;

    public function __construct(AuthService $authService, GoogleAuthService $googleAuth, User $userModel)
    {
        $this->authService = $authService;
        $this->googleAuth  = $googleAuth;
        $this->userModel   = $userModel;
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($this->authService->login($email, $password)) {
                if (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
                    header('Location: /index.php?action=admin');
                } else {
                    header('Location: /index.php');
                }
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Metodo nao permitido.']);
            exit;
        }
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

    /**
     * Inicia o fluxo Google OAuth redirecionando o usuário para o Google.
     */
    public function googleLogin(): void
    {
        if (!$this->googleAuth->isConfigured()) {
            // Diagnóstico: informa exatamente qual env var está faltando
            $env = function (string $k): string {
                $v = getenv($k);
                if ($v !== false && $v !== '') return 'OK';
                if (isset($_ENV[$k]) && $_ENV[$k] !== '') return 'OK(_ENV)';
                if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return 'OK(_SERVER)';
                return 'VAZIO';
            };
            error_log('[Google] não configurado: GOOGLE_CLIENT_ID=' . $env('GOOGLE_CLIENT_ID') . ' GOOGLE_CLIENT_SECRET=' . $env('GOOGLE_CLIENT_SECRET') . ' APP_ENV=' . ($env('APP_ENV') ?: 'VAZIO') . ' VERCEL_ENV=' . ($env('VERCEL_ENV') ?: 'VAZIO'));
            header('Location: /index.php?action=login&google_error=not_configured');
            exit;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $redirectUri = $this->getBaseUrl() . '/index.php?action=google-callback';
        $params = http_build_query([
            'client_id'     => $this->googleAuth->getClientId(),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;
    }

    /**
     * Callback do Google OAuth: troca code por tokens, valida id_token,
     * identifica/cria usuário e inicia sessão.
     */
    public function googleCallback(): void
    {
        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error !== '') {
            header('Location: /index.php?action=login&google_error=cancelled');
            exit;
        }

        $expectedState = $_SESSION['google_oauth_state'] ?? '';
        unset($_SESSION['google_oauth_state']);
        if ($expectedState === '' || !hash_equals($expectedState, (string)$state)) {
            error_log('[Google] state inválido');
            header('Location: /index.php?action=login&google_error=state');
            exit;
        }

        if ($code === '') {
            header('Location: /index.php?action=login&google_error=code');
            exit;
        }

        $redirectUri = $this->getBaseUrl() . '/index.php?action=google-callback';
        $tokens = $this->googleAuth->exchangeCodeForTokens($code, $redirectUri);
        if ($tokens === null) {
            error_log('[Google] falha na troca de code por tokens');
            header('Location: /index.php?action=login&google_error=exchange');
            exit;
        }

        $claims = $this->googleAuth->validateIdToken($tokens['id_token']);
        if ($claims === null) {
            error_log('[Google] id_token inválido');
            header('Location: /index.php?action=login&google_error=invalid_token');
            exit;
        }

        $sub   = (string)($claims['sub'] ?? '');
        $email = (string)($claims['email'] ?? '');
        $name  = (string)($claims['name'] ?? '');
        if ($sub === '' || $email === '') {
            error_log('[Google] claims incompletos');
            header('Location: /index.php?action=login&google_error=claims');
            exit;
        }

        $user = $this->userModel->findByGoogleId($sub);

        if (!$user) {
            $existing = $this->userModel->findByEmail($email);
            if ($existing && ($existing->password_hash !== null && $existing->password_hash !== '')) {
                // Conta com e-mail/senha já existe — bloqueia vínculo automático.
                // Usuário precisa logar com senha e vincular depois (futuro).
                error_log('[Google] tentativa de login com e-mail já cadastrado por senha: ' . $email);
                header('Location: /index.php?action=login&google_error=email_exists');
                exit;
            }

            try {
                $this->userModel->createOAuthUser($name !== '' ? $name : $email, $email, 'google', $sub);
            } catch (\PDOException $e) {
                error_log('[Google] falha ao criar usuário: ' . $e->getMessage());
                header('Location: /index.php?action=login&google_error=create_failed');
                exit;
            }
            $user = $this->userModel->findByGoogleId($sub);
            if (!$user) {
                header('Location: /index.php?action=login&google_error=create_failed');
                exit;
            }
        }

        $_SESSION['user_id']    = $user->id;
        $_SESSION['user_name']  = $user->name;
        $_SESSION['user_email'] = $user->email;
        $_SESSION['is_admin']   = (int)($user->is_admin ?? 0);
        $_SESSION['user_provider'] = 'google';
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        if (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
            header('Location: /index.php?action=admin');
        } else {
            header('Location: /index.php');
        }
        exit;
    }

    private function getBaseUrl(): string
    {
        $env = getenv('APP_URL');
        if ($env) return rtrim($env, '/');
        $vercel = getenv('VERCEL_URL');
        if ($vercel) return 'https://' . ltrim($vercel, '/');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);
        if (!preg_match('/^[a-z0-9.-]+$/', $host) || str_contains($host, '..')) {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }
}

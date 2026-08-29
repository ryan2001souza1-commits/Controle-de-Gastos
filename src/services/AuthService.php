<?php

class AuthService
{
    private User $userModel;
    private PasswordReset $resetModel;
    private Mailer $mailer;

    public function __construct(User $userModel, PasswordReset $resetModel, Mailer $mailer)
    {
        $this->userModel  = $userModel;
        $this->resetModel = $resetModel;
        $this->mailer     = $mailer;
    }

    public function login(string $email, string $password): bool
    {
        $user = $this->userModel->findByEmail($email);

        // Mitiga enumeração por timing: sempre verifica dummy hash quando usuário não existe
        if (!$user) {
            password_verify($password, '$2y$10$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I90dH6hi');
            return false;
        }
        if (!$user->verifyPassword($password)) {
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

        try {
            if (!$this->userModel->create($name, $email, $password)) {
                return ['success' => false, 'message' => 'E-mail já cadastrado.'];
            }
        } catch (PDOException $e) {
            // UNIQUE violation (23505) — corrida de dois cadastros simultâneos com mesmo e-mail
            if (($e->getCode() === '23505' || str_contains($e->getMessage(), 'duplicate'))) {
                return ['success' => false, 'message' => 'E-mail já cadastrado.'];
            }
            error_log('[AuthService] register error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao criar conta. Tente novamente.'];
        }

        return ['success' => true, 'message' => 'Usuário cadastrado com sucesso.'];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        session_unset();
        session_destroy();
        header('Location: /login.php');
        exit;
    }

    /**
     * Inicia o fluxo de recuperação de senha.
     * Gera token de 32 bytes, salva APENAS o hash SHA-256 no banco,
     * expira em 60 segundos, uso único, e envia por e-mail.
     *
     * Resposta genérica para evitar enumeração de e-mails cadastrados.
     */
    public function requestPasswordReset(string $email): array
    {
        $email = trim($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Informe um e-mail válido.'];
        }

        $user = $this->userModel->findByEmail($email);
        $generic = 'Se o e-mail estiver cadastrado, você receberá as instruções para recuperar sua senha.';

        if (!$user) {
            return ['success' => true, 'message' => $generic, 'resetUrl' => null, 'mailSent' => false];
        }

        $tokenPlain = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $tokenPlain);

        // Invalida tokens anteriores válidos do mesmo usuário
        $this->resetModel->invalidateForUser($user->id);
        $this->resetModel->create($user->id, $tokenHash); // expira em 1 min via DB NOW()

        $baseUrl = $this->getBaseUrl();
        $resetUrl = $baseUrl . '/index.php?action=reset&token=' . $tokenPlain;

        $mailSent = $this->mailer->send(
            $user->email,
            'Recuperação de senha - Controle de Gastos',
            $this->buildResetEmail($user->name, $resetUrl),
            "Abra este link para redefinir sua senha (válido por 1 minuto): {$resetUrl}"
        );

        if (!$mailSent) {
            error_log("[AuthService] Falha ao enviar e-mail de recuperação para {$user->email} — verifique RESEND_API_KEY e MAIL_FROM na Vercel");
            // Em dev local, loga link para teste manual sem expor na UI
            if (getenv('APP_ENV') === 'development' || getenv('APP_DEBUG') === 'true') {
                error_log("[AuthService:DEV] Link de recuperação (válido 1 min): {$resetUrl}");
            }
        }

        // NUNCA expõe resetUrl na resposta — usuário recebe exclusivamente por e-mail.
        // Em produção o fallback anterior exibia o link na tela; agora retorna null sempre.
        return [
            'success'  => true,
            'message'  => $generic,
            'resetUrl' => null,
            'mailSent' => $mailSent,
        ];
    }

    /**
     * Valida token (não consome). Retorna true se válido e dentro do prazo.
     */
    public function validateResetToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $tokenHash = hash('sha256', $token);
        return $this->resetModel->findValid($tokenHash) !== null;
    }

    public function resetPasswordWithToken(string $token, string $newPassword, string $confirm): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'message' => 'Token inválido ou expirado. Solicite um novo link.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 8 caracteres.'];
        }
        if ($newPassword !== $confirm) {
            return ['success' => false, 'message' => 'A confirmação de senha não confere.'];
        }

        $tokenHash = hash('sha256', $token);
        $reset = $this->resetModel->findValid($tokenHash);

        if (!$reset) {
            return ['success' => false, 'message' => 'Token inválido, expirado ou já utilizado. Solicite um novo link.'];
        }

        $userId = (int)$reset['user_id'];

        // Transação atômica: senha + invalidação do token
        $db = getDBConnection();
        try {
            $db->beginTransaction();
            if (!$this->userModel->updatePassword($userId, $newPassword)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Não foi possível atualizar a senha. Tente novamente.'];
            }
            $this->resetModel->markUsed((int)$reset['id']);
            $this->resetModel->invalidateForUser($userId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AuthService] reset transaction error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao redefinir senha. Tente novamente.'];
        }

        return ['success' => true, 'message' => 'Senha redefinida com sucesso. Você já pode fazer login.'];
    }

    private function getBaseUrl(): string
    {
        $env = getenv('APP_URL');
        if ($env) {
            return rtrim($env, '/');
        }
        // Vercel fornece VERCEL_URL (ex: controle-de-gastos-xxx.vercel.app) — confiável
        $vercelUrl = getenv('VERCEL_URL');
        if ($vercelUrl) {
            return 'https://' . ltrim($vercelUrl, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['HTTP_X_VERCEL_FORWARDED_PROTO'] ?? '') === 'https')
            || (getenv('VERCEL_ENV') !== false);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Sanitiza host para evitar Host Header Injection
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host); // remove porta
        if (!preg_match('/^[a-z0-9.-]+$/', $host) || str_contains($host, '..')) {
            $host = 'localhost';
        }
        return $scheme . '://' . $host;
    }

    private function buildResetEmail(string $name, string $url): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 12px;font-size:22px;color:#0f172a;">Olá, {$safeName}!</h1>
<p style="margin:0 0 16px;line-height:1.5;color:#475569;">
Recebemos uma solicitação para redefinir a senha da sua conta no <strong>Controle de Gastos</strong>.
</p>
<p style="margin:0 0 24px;line-height:1.5;color:#475569;">
Clique no botão abaixo para definir uma nova senha. <strong>Este link expira em 1 minuto</strong> e pode ser usado apenas uma vez.
</p>
<p style="margin:0 0 24px;">
<a href="{$url}" style="display:inline-block;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">
Redefinir senha
</a>
</p>
<p style="margin:0 0 8px;line-height:1.5;color:#64748b;font-size:13px;">
Ou copie e cole este link no seu navegador:
</p>
<p style="margin:0 0 24px;line-height:1.4;color:#4f46e5;font-size:12px;word-break:break-all;">
{$url}
</p>
<p style="margin:0;line-height:1.5;color:#94a3b8;font-size:12px;">
Se você não fez essa solicitação, ignore este e-mail. Sua senha continuará a mesma.
</p>
</td></tr>
</table>
<p style="margin:16px 0 0;color:#94a3b8;font-size:12px;">&copy; Controle de Gastos</p>
</td></tr>
</table>
</body></html>
HTML;
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

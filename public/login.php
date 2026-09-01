<?php
require_once __DIR__ . '/../src/config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/helpers/csrf.php';
$pageTitle = 'Login - Controle de Gastos';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?= $pageTitle ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/auth.css">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <aside class="auth-hero" aria-hidden="false">
            <div class="auth-hero-content">
                <div class="auth-brand">
                    <div class="auth-brand-logo">
                        <svg viewBox="0 0 32 32" width="22" height="22" aria-hidden="true">
                            <rect x="4" y="4" width="24" height="24" rx="6" fill="none" stroke="#ffffff" stroke-width="2"/>
                            <path d="M10 20 L14 14 L18 18 L22 12" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="auth-brand-text">
                        <strong>Controle de</strong>
                        <span>Gastos</span>
                    </div>
                </div>

                <h1 class="auth-hero-title">Organize suas finanças<br>e alcance seus objetivos</h1>
                <p class="auth-hero-text">Acompanhe seus gastos, planeje melhor e conquiste sua liberdade financeira com uma plataforma simples e segura.</p>
            </div>

            <div class="auth-hero-foot">
                <div class="auth-shield" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </div>
                <div>
                    <strong>Seus dados seguros</strong>
                    <span>Criptografia e privacidade garantidas</span>
                </div>
            </div>
        </aside>

        <main class="auth-main">
            <div class="auth-card">
                <div class="auth-icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="11" width="16" height="10" rx="2"/>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                    </svg>
                </div>
                <h2 class="auth-title">Bem-vindo de volta!</h2>
                <p class="auth-subtitle">Faça login para continuar</p>

                <?php if (isset($_GET['registered'])): ?>
                    <div class="auth-alert auth-alert-success">Cadastro realizado! Faça login.</div>
                <?php endif; ?>

                <?php if (isset($_GET['reset']) && $_GET['reset'] == '1'): ?>
                    <div class="auth-alert auth-alert-success">Senha redefinida com sucesso! Você já pode fazer login.</div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['google_error'])):
                    $gErr = (string)$_GET['google_error'];
                    $gMsg = match ($gErr) {
                        'cancelled'      => 'Login com Google cancelado.',
                        'state'          => 'Falha de segurança no login com Google. Tente novamente.',
                        'code'           => 'Código de autorização inválido.',
                        'exchange'       => 'Não foi possível validar o login com Google.',
                        'invalid_token'  => 'Token do Google inválido ou expirado.',
                        'claims'         => 'Não foi possível obter seus dados do Google.',
                        'email_exists'   => 'Já existe uma conta com esse e-mail. Faça login com senha e vincule o Google nas configurações.',
                        'create_failed'  => 'Não foi possível criar a conta com Google.',
                        'not_configured' => 'Login com Google indisponível no momento.',
                        default          => 'Falha no login com Google. Tente novamente.',
                    };
                ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($gMsg) ?></div>
                <?php endif; ?>

                <form action="/index.php?action=login" method="POST" class="auth-form" novalidate>
                    <div class="auth-field">
                        <label for="email">E-mail</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                                    <path d="m3 7 9 6 9-6"/>
                                </svg>
                            </span>
                            <input type="email" id="email" name="email" placeholder="seu@email.com" autocomplete="email" required>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="password">Senha</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="4" y="11" width="16" height="9" rx="2"/>
                                    <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" placeholder="Sua senha" autocomplete="current-password" required>
                            <button type="button" class="auth-toggle-pw" aria-label="Mostrar senha" aria-pressed="false" data-target="password">
                                <svg class="auth-eye-open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="auth-eye-closed" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                                    <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a19.86 19.86 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c6.5 0 10 7 10 7a19.95 19.95 0 0 1-3.17 4.19"/>
                                    <path d="M1 1l22 22"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="auth-row">
                        <label class="auth-check">
                            <input type="checkbox" name="remember" id="remember">
                            <span class="auth-check-box"></span>
                            <span>Lembrar-me</span>
                        </label>
                        <a href="/index.php?action=forgot" class="auth-link-text">Esqueci minha senha</a>
                    </div>

                    <button type="submit" class="auth-submit">
                        <span>Entrar</span>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                <?= csrf_field() ?>
                </form>

                <div class="auth-divider"><span>ou continue com</span></div>

                <div class="auth-social">
                    <button type="button" class="auth-social-btn" aria-label="Entrar com Google" id="googleSignInBtn" onclick="window.location.href='/index.php?action=google-login'">
                        <svg viewBox="0 0 48 48" width="20" height="20" aria-hidden="true">
                            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
                            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.1 35.1 26.7 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.2 5.7l6.2 5.2C41 35.6 44 30.2 44 24c0-1.3-.1-2.4-.4-3.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="auth-social-btn" aria-label="Entrar com Facebook">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                            <circle cx="12" cy="12" r="11" fill="#1877F2"/>
                            <path fill="#fff" d="M13.5 21v-7.5h2.5l.4-3h-2.9V8.7c0-.9.3-1.5 1.5-1.5h1.5V4.5c-.3 0-1.2-.1-2.3-.1-2.3 0-3.8 1.4-3.8 3.9v2.1H8v3h2.4V21h3.1z"/>
                        </svg>
                    </button>
                    <button type="button" class="auth-social-btn" aria-label="Entrar com Apple">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#0f172a" aria-hidden="true">
                            <path d="M16.4 12.6c0-2.5 2-3.7 2.1-3.8-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.2 1-4 2.4-1.7 3-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.4 0 1.9.8 3.2.8 1.3 0 2.2-1.2 3-2.3.9-1.3 1.3-2.6 1.3-2.7 0 0-2.5-1-2.5-3.9zM14 5.5c.7-.8 1.1-1.9 1-3-1 .1-2.1.7-2.8 1.5-.6.7-1.2 1.9-1 2.9 1.1.1 2.2-.5 2.8-1.4z"/>
                        </svg>
                    </button>
                </div>

                <p class="auth-foot-link">
                    Não tem conta? <a href="/index.php?action=register">Cadastre-se</a>
                </p>
            </div>
            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Desenvolvido por Ryan Souza</p>
        </main>
    </div>

    <script>
    (function () {
        document.querySelectorAll('.auth-toggle-pw').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-target');
                var input = document.getElementById(targetId);
                if (!input) return;
                var open = btn.querySelector('.auth-eye-open');
                var closed = btn.querySelector('.auth-eye-closed');
                if (input.type === 'password') {
                    input.type = 'text';
                    if (open) open.style.display = 'none';
                    if (closed) closed.style.display = '';
                    btn.setAttribute('aria-pressed', 'true');
                } else {
                    input.type = 'password';
                    if (open) open.style.display = '';
                    if (closed) closed.style.display = 'none';
                    btn.setAttribute('aria-pressed', 'false');
                }
            });
        });
    })();
    </script>
</body>
</html>

<?php $pageTitle = 'Login - Controle de Gastos'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/auth.css">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <aside class="auth-hero" aria-hidden="true">
            <div class="auth-hero-bg"></div>
            <div class="auth-hero-content">
                <div class="auth-brand">
                    <div class="auth-brand-logo">
                        <svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true">
                            <circle cx="16" cy="16" r="14" fill="#6366f1"/>
                            <path d="M16 4 a12 12 0 0 1 10.4 6 L16 16 Z" fill="#8b5cf6"/>
                            <path d="M26.4 10 a12 12 0 0 1 0 12 L16 16 Z" fill="#a855f7"/>
                            <path d="M5.6 22 a12 12 0 0 0 10.4 6 L16 16 Z" fill="#22c55e"/>
                        </svg>
                    </div>
                    <div class="auth-brand-text">
                        <strong>Controle de</strong>
                        <span>Gastos</span>
                    </div>
                </div>
                <h1 class="auth-hero-title">Organize suas finanças<br>e alcance seus objetivos</h1>
                <p class="auth-hero-text">Acompanhe seus gastos, planeje melhor e conquiste sua liberdade financeira.</p>
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

                <?php if (isset($error)): ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error) ?></div>
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
                            <button type="button" class="auth-toggle-pw" aria-label="Mostrar/ocultar senha" data-target="password">
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
                        <a href="#" class="auth-link-text">Esqueci minha senha</a>
                    </div>

                    <button type="submit" class="auth-submit">
                        <span>Entrar</span>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                </form>

                <p class="auth-foot-link">
                    Não tem conta? <a href="/index.php?action=register">Cadastre-se</a>
                </p>
            </div>
            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Desenvolvido por Ryan Souza</p>
        </main>
    </div>
    <script src="/js/app.js" defer></script>
</body>
</html>

<?php $pageTitle = 'Login - Controle de Gastos'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="auth-body">
    <main class="auth-stage">
        <div class="auth-card" role="region" aria-labelledby="authTitle">
            <header class="auth-head">
                <div class="auth-logo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 1v22"></path>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <h1 id="authTitle" class="auth-title">Controle de Gastos</h1>
                <p class="auth-subtitle">Gerencie suas finanças com simplicidade</p>
            </header>

            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">Cadastro realizado! Faça login.</div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form action="/index.php?action=login" method="POST" class="auth-form" novalidate>
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                <path d="m3 7 9 6 9-6"></path>
                            </svg>
                        </span>
                        <input type="email" id="email" name="email" placeholder="seu@email.com" autocomplete="email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Senha</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                                <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                            </svg>
                        </span>
                        <input type="password" id="password" name="password" placeholder="Sua senha" autocomplete="current-password" required>
                        <button type="button" class="toggle-password" aria-label="Mostrar/ocultar senha" data-target="password">
                            <svg class="eye-open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a19.86 19.86 0 0 1 5.06-5.94"></path>
                                <path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c6.5 0 10 7 10 7a19.95 19.95 0 0 1-3.17 4.19"></path>
                                <path d="M1 1l22 22"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="auth-row">
                    <label class="auth-check">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="auth-check-mark"></span>
                        <span class="auth-check-label">Lembrar-me</span>
                    </label>
                    <a href="#" class="auth-forgot">Esqueci minha senha</a>
                </div>

                <button type="submit" class="btn btn-primary auth-submit">Entrar</button>
            </form>

            <p class="auth-link">
                Não tem conta? <a href="/index.php?action=register">Cadastre-se</a>
            </p>
        </div>
        <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Todos os direitos reservados</p>
    </main>
    <script src="/js/app.js" defer></script>
</body>
</html>

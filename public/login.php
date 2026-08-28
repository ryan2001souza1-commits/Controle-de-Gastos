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
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="auth-logo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0z"></path>
                        <path d="M12 6v6l4 2"></path>
                    </svg>
                </div>
                <h1 class="auth-title">Controle de Gastos</h1>
                <p class="auth-subtitle">Acesse sua conta</p>
            </div>

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
                        <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary auth-submit">Entrar</button>
            </form>

            <p class="auth-link">
                Não tem conta? <a href="/index.php?action=register">Cadastre-se</a>
            </p>
        </div>
        <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos</p>
    </div>
</body>
</html>

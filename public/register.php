<?php $pageTitle = 'Cadastro - Controle de Gastos'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <aside class="auth-side" aria-hidden="true">
            <div class="auth-side-inner">
                <div class="auth-side-logo">
                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0z"></path>
                        <path d="M12 6v6l4 2"></path>
                    </svg>
                </div>
                <h2 class="auth-side-title">Comece agora mesmo</h2>
                <p class="auth-side-text">Crie sua conta gratuita e tenha o controle das suas finanças pessoais.</p>
                <ul class="auth-side-features">
                    <li><span class="dot"></span> 100% gratuito</li>
                    <li><span class="dot"></span> Sem cartão de crédito</li>
                    <li><span class="dot"></span> Seus dados seguros</li>
                </ul>
            </div>
        </aside>

        <main class="auth-main">
            <div class="auth-card">
                <div class="auth-brand">
                    <div class="auth-logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0z"></path>
                            <path d="M12 6v6l4 2"></path>
                        </svg>
                    </div>
                    <h1 class="auth-title">Criar conta</h1>
                    <p class="auth-subtitle">Preencha seus dados para começar</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form action="/index.php?action=register" method="POST" class="auth-form" novalidate>
                    <div class="form-group">
                        <label for="name">Nome completo</label>
                        <div class="input-wrap">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            </span>
                            <input type="text" id="name" name="name" placeholder="Seu nome" autocomplete="name" required>
                        </div>
                    </div>

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
                        <label for="password">Senha (mín. 8 caracteres)</label>
                        <div class="input-wrap">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                                    <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" placeholder="Crie uma senha" autocomplete="new-password" minlength="8" required>
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

                    <div class="form-group">
                        <label for="password_confirm">Confirmar senha</label>
                        <div class="input-wrap">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                                    <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                </svg>
                            </span>
                            <input type="password" id="password_confirm" name="password_confirm" placeholder="Repita a senha" autocomplete="new-password" minlength="8" required>
                            <button type="button" class="toggle-password" aria-label="Mostrar/ocultar senha" data-target="password_confirm">
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
                        <small class="input-hint" id="passwordMatchHint"></small>
                    </div>

                    <label class="auth-check auth-terms">
                        <input type="checkbox" name="terms" id="terms" required>
                        <span class="auth-check-mark"></span>
                        <span class="auth-check-label">Eu li e aceito os <a href="#">termos de uso</a></span>
                    </label>

                    <button type="submit" class="btn btn-primary auth-submit">Cadastrar</button>
                </form>

                <p class="auth-link">
                    Já tem conta? <a href="/index.php?action=login">Faça login</a>
                </p>
            </div>
            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos</p>
        </main>
    </div>
    <script src="/js/app.js" defer></script>
</body>
</html>

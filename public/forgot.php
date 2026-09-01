<?php $pageTitle = 'Recuperar Senha - Controle de Gastos'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/auth.css">
    <link rel="stylesheet" href="/css/forgot.css">
</head>
<body class="auth-body">
    <div class="auth-wrapper forgot-wrapper">
        <aside class="auth-hero forgot-hero" aria-hidden="false">
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

                <h1 class="auth-hero-title forgot-title">Recupere o<br>acesso à sua conta</h1>
                <p class="auth-hero-text">Estamos aqui para ajudar você a voltar a ter o controle das suas finanças.</p>
            </div>
        </aside>

        <main class="auth-main">
            <div class="auth-card forgot-card">
                <div class="auth-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="11" width="16" height="10" rx="2"/>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                    </svg>
                </div>
                <h2 class="auth-title">Esqueceu sua senha?</h2>
                <p class="auth-subtitle forgot-subtitle">Digite seu e-mail e enviaremos as instruções para recuperar sua senha.</p>

                <?php if (!empty($success)): ?>
                    <div class="auth-alert auth-alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form action="/index.php?action=forgot" method="POST" class="auth-form" novalidate>
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

                    <button type="submit" class="auth-submit forgot-submit">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                        <span>Enviar instruções</span>
                    </button>
                <?= csrf_field() ?>
                </form>

                <div class="forgot-divider">
                    <span>ou</span>
                </div>

                <a href="/index.php?action=login" class="forgot-back">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>Voltar para o login</span>
                </a>
            </div>

            <div class="forgot-security">
                <span class="forgot-security-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="11" width="16" height="10" rx="2"/>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                    </svg>
                </span>
                <p>Para sua segurança, o link de recuperação irá expirar em 15 minutos.</p>
            </div>

            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Desenvolvido por Ryan Souza</p>
        </main>
    </div>
</body>
</html>

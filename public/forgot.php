<?php $pageTitle = 'Recuperar Senha - Controle de Gastos'; ?>
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
    <link rel="stylesheet" href="/css/forgot.css">
</head>
<body class="auth-body">
    <div class="auth-wrapper forgot-wrapper">
        <aside class="auth-hero forgot-hero" aria-hidden="true">
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

                <h1 class="auth-hero-title forgot-title">Recupere o<br>acesso à sua conta</h1>
                <p class="auth-hero-text">Estamos aqui para ajudar você a voltar a ter o controle das suas finanças.</p>

                <div class="forgot-illustration" aria-hidden="true">
                    <svg viewBox="0 0 220 180" width="220" height="180">
                        <defs>
                            <linearGradient id="envelopeGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#ffffff" stop-opacity="0.95"/>
                                <stop offset="100%" stop-color="#ffffff" stop-opacity="0.75"/>
                            </linearGradient>
                            <linearGradient id="flapGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#c4b5fd"/>
                                <stop offset="100%" stop-color="#8b5cf6"/>
                            </linearGradient>
                        </defs>

                        <circle cx="190" cy="30" r="3" fill="#ffffff" opacity="0.5"/>
                        <circle cx="40" cy="150" r="3" fill="#ffffff" opacity="0.4"/>
                        <path d="M 165 95 q 20 -5 30 5" stroke="#ffffff" stroke-width="1.2" stroke-dasharray="3 4" fill="none" opacity="0.55"/>
                        <path d="M 25 70 q 12 -8 22 0" stroke="#ffffff" stroke-width="1.2" stroke-dasharray="3 4" fill="none" opacity="0.4"/>

                        <g transform="translate(20 30)">
                            <rect x="0" y="55" width="180" height="105" rx="10" fill="url(#envelopeGrad)"/>
                            <path d="M 0 60 L 90 115 L 180 60 L 180 65 L 90 120 L 0 65 Z" fill="#a78bfa" opacity="0.6"/>
                            <path d="M 0 55 L 90 110 L 180 55 L 180 0 L 0 0 Z" fill="url(#flapGrad)"/>
                            <rect x="60" y="30" width="60" height="45" rx="5" fill="#ffffff"/>
                            <path d="M 70 35 L 70 60 L 80 65 L 90 60 L 100 65 L 110 60 L 110 35 Z" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M 70 35 L 80 45 L 90 35" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="80" y="48" width="20" height="3" rx="1.5" fill="#cbd5e1"/>
                        </g>
                    </svg>
                </div>
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
                <p class="auth-subtitle forgot-subtitle">Digite seu e-mail e enviaremos as instruções<br>para recuperar sua senha.</p>

                <?php if (!empty($success)): ?>
                    <div class="auth-alert auth-alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (!empty($resetUrl)): ?>
                    <div class="auth-alert auth-alert-info forgot-token-box">
                        <strong>Ambiente sem envio de e-mail configurado.</strong>
                        <span>Use o link abaixo para definir uma nova senha (válido por 1 minuto):</span>
                        <a href="<?= htmlspecialchars($resetUrl) ?>" class="forgot-token-link">
                            <?= htmlspecialchars($resetUrl) ?>
                        </a>
                    </div>
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
                <p>Para sua segurança, o link de recuperação<br>irá expirar em 1 hora.</p>
            </div>

            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Desenvolvido por Ryan Souza</p>
        </main>
    </div>
    <script src="/js/app.js" defer></script>
</body>
</html>

<?php $pageTitle = 'Cadastro - Controle de Gastos'; ?>
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

                <h1 class="auth-hero-title">Comece agora e<br>transforme sua<br>vida financeira</h1>
                <p class="auth-hero-text">É rápido, fácil e gratuito! Junte-se a milhares de pessoas que já organizam suas finanças com inteligência.</p>
            </div>

            <div class="auth-hero-foot">
                <div class="auth-shield" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <polyline points="9 12 11 14 15 10"/>
                    </svg>
                </div>
                <div>
                    <strong>Seus dados estão seguros conosco.</strong>
                    <span>Privacidade e segurança são prioridade.</span>
                </div>
            </div>
        </aside>

        <main class="auth-main">
            <div class="auth-card">
                <div class="auth-icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                        <line x1="19" y1="8" x2="19" y2="14"/>
                        <line x1="22" y1="11" x2="16" y2="11"/>
                    </svg>
                </div>
                <h2 class="auth-title">Crie sua conta</h2>
                <p class="auth-subtitle">É rápido e fácil!</p>

                <?php if (isset($error)): ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form action="/index.php?action=register" method="POST" class="auth-form" novalidate>
                    <div class="auth-field">
                        <label for="name">Nome completo</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <input type="text" id="name" name="name" placeholder="Seu nome completo" autocomplete="name" required>
                        </div>
                    </div>

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

                    <div class="auth-grid-2">
                        <div class="auth-field">
                            <label for="password">Senha</label>
                            <div class="auth-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="11" width="16" height="9" rx="2"/>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                    </svg>
                                </span>
                                <input type="password" id="password" name="password" placeholder="Sua senha" autocomplete="new-password" minlength="8" required>
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

                        <div class="auth-field">
                            <label for="password_confirm">Confirmar senha</label>
                            <div class="auth-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="11" width="16" height="9" rx="2"/>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                    </svg>
                                </span>
                                <input type="password" id="password_confirm" name="password_confirm" placeholder="Repita a senha" autocomplete="new-password" minlength="8" required>
                                <button type="button" class="auth-toggle-pw" aria-label="Mostrar senha" aria-pressed="false" data-target="password_confirm">
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
                    </div>
                    <small class="auth-hint" id="passwordMatchHint"></small>

                    <div class="auth-pwd-rules" id="passwordRules" aria-live="polite">
                        <div class="auth-pwd-rules-icon">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        </div>
                        <div class="auth-pwd-rules-body">
                            <strong>Sua senha deve conter:</strong>
                            <ul>
                                <li data-rule="len"><span class="auth-pwd-check"></span>Pelo menos 8 caracteres</li>
                                <li data-rule="upper"><span class="auth-pwd-check"></span>Uma letra maiúscula</li>
                                <li data-rule="num"><span class="auth-pwd-check"></span>Um número</li>
                                <li data-rule="special"><span class="auth-pwd-check"></span>Um caractere especial</li>
                            </ul>
                        </div>
                    </div>

                    <label class="auth-check auth-terms">
                        <input type="checkbox" name="terms" id="terms" required>
                        <span class="auth-check-box"></span>
                        <span>Li e aceito os <a href="/index.php?action=termos" target="_blank" rel="noopener">Termos de Uso</a> e a <a href="/index.php?action=privacy" target="_blank" rel="noopener">Política de Privacidade</a></span>
                    </label>

                    <button type="submit" class="auth-submit">
                        <span>Cadastrar</span>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                <?= csrf_field() ?>
                </form>

                <p class="auth-foot-link-divider">
                    <span class="auth-foot-line"></span>
                    <span class="auth-foot-link-text">Já tem conta? <a href="/index.php?action=login">Faça login</a></span>
                    <span class="auth-foot-line"></span>
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

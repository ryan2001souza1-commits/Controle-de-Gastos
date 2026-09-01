<?php
require_once __DIR__ . '/../src/config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/helpers/csrf.php';
$pageTitle = 'Nova Senha - Controle de Gastos';
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
    <link rel="stylesheet" href="/css/forgot.css">
    <link rel="stylesheet" href="/css/reset.css">
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

                <h1 class="auth-hero-title forgot-title">Nova senha<br>mais segura!</h1>
                <p class="auth-hero-text">Escolha uma senha forte para proteger sua conta e continue no controle das suas finanças.</p>
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
                <h2 class="auth-title">Defina sua nova senha</h2>
                <p class="auth-subtitle forgot-subtitle">Digite sua nova senha e confirme para garantir a segurança da sua conta.</p>

                <?php if (!empty($error)): ?>
                    <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (!empty($error) && str_contains($error, 'Token')): ?>
                    <p class="auth-foot-link" style="margin-top:0;">
                        <a href="/index.php?action=forgot">← Solicitar um novo link de recuperação</a>
                    </p>
                <?php else: ?>

                <form action="/index.php?action=reset" method="POST" class="auth-form" novalidate>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? $_GET['token'] ?? '') ?>">

                    <div class="auth-field">
                        <label for="password">Nova senha</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="4" y="11" width="16" height="9" rx="2"/>
                                    <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" placeholder="Digite sua nova senha" autocomplete="new-password" minlength="8" required>
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

                    <div class="reset-strength" id="resetStrength" aria-hidden="true">
                        <div class="reset-strength-bar"><span id="resetStrengthFill"></span></div>
                    </div>
                    <small class="reset-hint" id="resetHint">A senha deve ter no mínimo 8 caracteres.</small>

                    <div class="auth-field">
                        <label for="password_confirm">Confirmar nova senha</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="4" y="11" width="16" height="9" rx="2"/>
                                    <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                </svg>
                            </span>
                            <input type="password" id="password_confirm" name="password_confirm" placeholder="Confirme sua nova senha" autocomplete="new-password" minlength="8" required>
                            <button type="button" class="auth-toggle-pw" aria-label="Mostrar/ocultar senha" data-target="password_confirm">
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

                    <button type="submit" class="auth-submit forgot-submit">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="4" y="11" width="16" height="10" rx="2"/>
                            <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                        </svg>
                        <span>Redefinir senha</span>
                    </button>
                <?= csrf_field() ?>
                </form>

                <?php endif; ?>

                <a href="/index.php?action=login" class="reset-back">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>Voltar para o login</span>
                </a>
            </div>

            <div class="reset-security">
                <span class="reset-security-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="11" width="16" height="10" rx="2"/>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                    </svg>
                </span>
                <p>Para sua segurança, mantenha sua senha em sigilo.</p>
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

        const pwd = document.getElementById('password');
        const confirm = document.getElementById('password_confirm');
        const fill = document.getElementById('resetStrengthFill');
        const hint = document.getElementById('resetHint');
        if (!pwd || !fill) return;

        const evaluate = (v) => {
            let score = 0;
            if (v.length >= 8) score++;
            if (/[A-Z]/.test(v)) score++;
            if (/[0-9]/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            return score;
        };

        const labels = ['Muito fraca', 'Fraca', 'Razoável', 'Boa', 'Forte'];
        const colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];

        pwd.addEventListener('input', () => {
            const v = pwd.value;
            if (!v) {
                fill.style.width = '0%';
                fill.style.background = '#e2e8f0';
                hint.textContent = 'A senha deve ter no mínimo 8 caracteres.';
                hint.className = 'reset-hint';
                return;
            }
            const s = evaluate(v);
            fill.style.width = (s * 25) + '%';
            fill.style.background = colors[s];
            hint.textContent = v.length < 8
                ? 'A senha deve ter no mínimo 8 caracteres.'
                : 'Força: ' + labels[s];
            hint.className = 'reset-hint ' + (s <= 1 ? 'is-weak' : s >= 3 ? 'is-strong' : 'is-ok');
        });

        if (confirm) {
            confirm.addEventListener('input', () => {
                if (confirm.value && confirm.value !== pwd.value) {
                    confirm.setCustomValidity('As senhas não coincidem');
                } else {
                    confirm.setCustomValidity('');
                }
            });
        }
    })();
    </script>
</body>
</html>

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

                <h1 class="auth-hero-title">Comece agora e<br>transforme sua<br><em>vida financeira</em></h1>
                <p class="auth-hero-text">É rápido, fácil e gratuito! Junte-se a milhares de pessoas que já organizam suas finanças com inteligência.</p>
            </div>

            <div class="auth-hero-illu" aria-hidden="true">
                <div class="auth-card-mini auth-card-phone">
                    <div class="auth-phone-frame">
                        <div class="auth-phone-notch"></div>
                        <div class="auth-phone-screen">
                            <div class="auth-phone-statusbar">
                                <span>11:14</span>
                                <span class="auth-phone-status-icons">
                                    <svg viewBox="0 0 18 12" width="18" height="12" aria-hidden="true"><rect x="0" y="7" width="3" height="5" rx="0.5" fill="#0f172a"/><rect x="5" y="5" width="3" height="7" rx="0.5" fill="#0f172a"/><rect x="10" y="2" width="3" height="10" rx="0.5" fill="#0f172a"/><rect x="15" y="0" width="3" height="12" rx="0.5" fill="#cbd5e1"/></svg>
                                    <svg viewBox="0 0 16 12" width="16" height="12" aria-hidden="true"><path d="M8 9.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2z" fill="#0f172a"/><path d="M3 5a8 8 0 0 1 10 0l-1 1.2a6.4 6.4 0 0 0-8 0z" fill="#0f172a"/><path d="M5.5 7.5a4.6 4.6 0 0 1 5 0l-1 1.2a3 3 0 0 0-3 0z" fill="#0f172a"/></svg>
                                    <svg viewBox="0 0 26 12" width="26" height="12" aria-hidden="true"><rect x="0" y="1" width="22" height="10" rx="2" fill="none" stroke="#0f172a"/><rect x="2" y="3" width="14" height="6" rx="1" fill="#0f172a"/><rect x="23" y="4" width="2" height="4" rx="0.5" fill="#0f172a"/></svg>
                                </span>
                            </div>
                            <div class="auth-phone-app">
                                <div class="auth-phone-head">
                                    <span class="auth-phone-back">‹</span>
                                    <span>Resumo</span>
                                </div>
                                <div class="auth-phone-card">
                                    <small>Resumo do mês</small>
                                    <em>Saldo atual</em>
                                    <strong>R$ 2.540,00</strong>
                                    <div class="auth-phone-line"><span>Receitas</span><span class="ok">R$ 5.300,00</span></div>
                                    <div class="auth-phone-line"><span>Despesas</span><span class="bad">R$ 2.760,00</span></div>
                                </div>
                                <div class="auth-phone-card auth-phone-card-sm">
                                    <small>Gastos por categoria</small>
                                    <div class="auth-phone-donut">
                                        <svg viewBox="0 0 60 60" width="50" height="50" aria-hidden="true">
                                            <circle cx="30" cy="30" r="20" fill="none" stroke="#e2e8f0" stroke-width="9"/>
                                            <circle cx="30" cy="30" r="20" fill="none" stroke="#22c55e" stroke-width="9" stroke-dasharray="50 126" transform="rotate(-90 30 30)"/>
                                            <circle cx="30" cy="30" r="20" fill="none" stroke="#6366f1" stroke-width="9" stroke-dasharray="32 126" stroke-dashoffset="-50" transform="rotate(-90 30 30)"/>
                                            <circle cx="30" cy="30" r="20" fill="none" stroke="#f59e0b" stroke-width="9" stroke-dasharray="22 126" stroke-dashoffset="-82" transform="rotate(-90 30 30)"/>
                                            <circle cx="30" cy="30" r="20" fill="none" stroke="#0ea5e9" stroke-width="9" stroke-dasharray="14 126" stroke-dashoffset="-104" transform="rotate(-90 30 30)"/>
                                        </svg>
                                    </div>
                                </div>
                                <div class="auth-phone-tabbar">
                                    <span class="active">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#6366f1"><path d="M12 3 3 11h2v9h5v-6h4v6h5v-9h2z"/></svg>
                                        <em>Início</em>
                                    </span>
                                    <span>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#94a3b8" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/></svg>
                                        <em>Resumo</em>
                                    </span>
                                    <span>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#94a3b8" stroke-width="2.5"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                    </span>
                                    <span>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></svg>
                                        <em>Metas</em>
                                    </span>
                                    <span>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                                        <em>Perfil</em>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="auth-card-mini auth-card-metas">
                    <div class="auth-card-mini-head">
                        <span class="auth-card-mini-goal-icon">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/></svg>
                        </span>
                        <div>
                            <strong>Metas</strong>
                            <small>8 de 12 concluídas</small>
                        </div>
                    </div>
                    <div class="auth-progress"><span style="width:66%"></span></div>
                </div>

                <div class="auth-card-mini auth-card-org">
                    <div class="auth-card-mini-head auth-card-mini-head-row">
                        <div>
                            <strong>Organização</strong>
                            <small>é liberdade!</small>
                        </div>
                        <span class="auth-card-org-chart" aria-hidden="true">
                            <svg viewBox="0 0 32 24" width="32" height="24">
                                <rect x="2"  y="14" width="4" height="10" rx="1" fill="#22c55e"/>
                                <rect x="9"  y="9"  width="4" height="15" rx="1" fill="#16a34a"/>
                                <rect x="16" y="6"  width="4" height="18" rx="1" fill="#15803d"/>
                                <rect x="23" y="11" width="4" height="13" rx="1" fill="#22c55e"/>
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="auth-illu-coin auth-illu-coin-1" aria-hidden="true">
                    <svg viewBox="0 0 64 64" width="48" height="48"><circle cx="32" cy="32" r="28" fill="#f59e0b"/><circle cx="32" cy="32" r="22" fill="#fbbf24"/><text x="32" y="40" text-anchor="middle" font-size="22" font-weight="800" fill="#92400e" font-family="Inter,sans-serif">$</text></svg>
                </div>
                <div class="auth-illu-coin auth-illu-coin-2" aria-hidden="true">
                    <svg viewBox="0 0 64 64" width="40" height="40"><circle cx="32" cy="32" r="28" fill="#f59e0b"/><circle cx="32" cy="32" r="22" fill="#fbbf24"/><text x="32" y="40" text-anchor="middle" font-size="20" font-weight="800" fill="#92400e" font-family="Inter,sans-serif">$</text></svg>
                </div>
                <div class="auth-illu-coin auth-illu-coin-3" aria-hidden="true">
                    <svg viewBox="0 0 64 64" width="36" height="36"><circle cx="32" cy="32" r="28" fill="#f59e0b"/><circle cx="32" cy="32" r="22" fill="#fbbf24"/><text x="32" y="40" text-anchor="middle" font-size="18" font-weight="800" fill="#92400e" font-family="Inter,sans-serif">$</text></svg>
                </div>

                <div class="auth-illu-wallet" aria-hidden="true">
                    <svg viewBox="0 0 140 110" width="140" height="110">
                        <!-- credit cards sticking out the top -->
                        <rect x="35" y="0" width="78" height="34" rx="5" fill="#16a34a"/>
                        <rect x="42" y="6" width="20" height="6" rx="1.5" fill="#bbf7d0"/>
                        <rect x="22" y="10" width="78" height="34" rx="5" fill="#1e293b"/>
                        <rect x="30" y="22" width="22" height="6" rx="1.5" fill="#475569"/>
                        <!-- wallet body -->
                        <path d="M10 40 Q10 30 20 30 H120 a10 10 0 0 1 10 10 V92 a10 10 0 0 1 -10 10 H20 a10 10 0 0 1 -10 -10 Z" fill="#7c3aed"/>
                        <rect x="86" y="56" width="46" height="32" rx="5" fill="#6d28d9"/>
                        <rect x="92" y="64" width="22" height="16" rx="2.5" fill="#c4b5fd"/>
                        <rect x="96" y="68" width="14" height="8" rx="1.5" fill="#a78bfa"/>
                        <circle cx="120" cy="72" r="3" fill="#a78bfa"/>
                    </svg>
                </div>

                <div class="auth-illu-plant" aria-hidden="true">
                    <svg viewBox="0 0 110 130" width="110" height="130">
                        <ellipse cx="55" cy="120" rx="38" ry="6" fill="#0f172a" opacity="0.4"/>
                        <!-- pot (white ceramic) -->
                        <path d="M26 90 Q26 78 40 78 H70 Q84 78 84 90 V108 a8 8 0 0 1 -8 8 H34 a8 8 0 0 1 -8 -8 Z" fill="#f1f5f9"/>
                        <path d="M26 90 Q26 78 40 78 H70 Q84 78 84 90 V96 H26 Z" fill="#cbd5e1"/>
                        <!-- 3 leaves -->
                        <path d="M55 90 C 35 70 18 60 24 28 C 40 30 56 56 58 80 Z" fill="#22c55e"/>
                        <path d="M55 90 C 75 70 92 60 86 28 C 70 30 54 56 52 80 Z" fill="#16a34a"/>
                        <path d="M55 90 C 55 56 51 30 55 8 C 59 30 59 56 59 80 Z" fill="#15803d"/>
                    </svg>
                </div>
            </div>

            <div class="auth-hero-foot">
                <span class="auth-shield" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2 4 5v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V5l-8-3z"/>
                        <polyline points="9 12 11 14 15 10"/>
                    </svg>
                </span>
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
                <h2 class="auth-title">Crie sua conta <span aria-hidden="true">🚀</span></h2>
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
                        <span>Li e aceito os <a href="#">Termos de Uso</a> e a <a href="#">Política de Privacidade</a></span>
                    </label>

                    <button type="submit" class="auth-submit">
                        <span>Cadastrar</span>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
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
    <script src="/js/app.js?v=20250829" defer></script>
</body>
</html>

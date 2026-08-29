<?php $pageTitle = 'Cadastro - Controle de Gastos'; ?>
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
</head>
<body class="auth-body">
    <main class="auth-stage">
        <section class="auth-visual" aria-hidden="true">
            <div class="auth-visual-bg">
                <span class="blob blob-1"></span>
                <span class="blob blob-2"></span>
                <span class="dots-pattern"></span>
            </div>

            <div class="auth-visual-content">
                <div class="auth-brand">
                    <div class="auth-brand-logo">
                        <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true">
                            <circle cx="16" cy="16" r="14" fill="#6366f1"/>
                            <path d="M16 4 a12 12 0 0 1 10.4 6 L16 16 Z" fill="#8b5cf6"/>
                            <path d="M26.4 10 a12 12 0 0 1 0 12 L16 16 Z" fill="#a855f7" opacity="0.9"/>
                            <path d="M5.6 22 a12 12 0 0 0 10.4 6 L16 16 Z" fill="#22c55e"/>
                        </svg>
                    </div>
                    <div class="auth-brand-text">
                        <strong>Controle de</strong>
                        <span>Gastos</span>
                    </div>
                </div>

                <div class="auth-headline">
                    <h2>Comece agora e<br>transforme sua <em>vida<br>financeira</em></h2>
                    <p>É rápido, fácil e gratuito! Junte-se a milhares de pessoas que já organizam suas finanças com inteligência.</p>
                </div>

                <div class="auth-illustrations register-illus">
                    <div class="illus-phone">
                        <div class="illus-phone-notch"></div>
                        <div class="illus-phone-screen">
                            <div class="illus-phone-head">
                                <span class="illus-phone-back">‹</span>
                                <span class="illus-phone-signal">
                                    <span></span><span></span><span></span><span></span>
                                </span>
                            </div>
                            <div class="illus-phone-title">Resumo do mês</div>
                            <div class="illus-phone-sub">Saldo atual</div>
                            <div class="illus-phone-balance">R$ 2.540,00</div>
                            <div class="illus-phone-line"><span>Receitas</span><b class="illus-up">R$ 5.300,00</b></div>
                            <div class="illus-phone-line"><span>Despesas</span><b class="illus-down">R$ 2.760,00</b></div>
                            <div class="illus-phone-cat-title">Gastos por categoria</div>
                            <div class="illus-phone-donut">
                                <svg viewBox="0 0 42 42" width="74" height="74" aria-hidden="true">
                                    <circle cx="21" cy="21" r="15.915" fill="#fff"/>
                                    <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#8b5cf6" stroke-width="8" stroke-dasharray="40 60" stroke-dashoffset="0" transform="rotate(-90 21 21)"/>
                                    <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#22c55e" stroke-width="8" stroke-dasharray="25 75" stroke-dashoffset="-40" transform="rotate(-90 21 21)"/>
                                    <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#f59e0b" stroke-width="8" stroke-dasharray="15 85" stroke-dashoffset="-65" transform="rotate(-90 21 21)"/>
                                    <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#3b82f6" stroke-width="8" stroke-dasharray="10 90" stroke-dashoffset="-80" transform="rotate(-90 21 21)"/>
                                </svg>
                            </div>
                            <div class="illus-phone-tabs">
                                <span>Início</span><span>Resumo</span><span>Metas</span><span>Perfil</span>
                            </div>
                        </div>
                    </div>

                    <div class="illus-card illus-metas">
                        <div class="illus-metas-icon">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></svg>
                        </div>
                        <div class="illus-metas-text">
                            <strong>Metas</strong>
                            <span>8 de 12 concluídas</span>
                        </div>
                        <div class="illus-metas-bar"><span style="width:66%"></span></div>
                    </div>

                    <div class="illus-card illus-org">
                        <div class="illus-org-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="#22c55e" stroke="none"><rect x="4" y="13" width="3" height="7" rx="1"/><rect x="10" y="9" width="3" height="11" rx="1"/><rect x="16" y="5" width="3" height="15" rx="1"/></svg>
                        </div>
                        <div class="illus-org-text">
                            <strong>Organização</strong>
                            <span>é liberdade!</span>
                        </div>
                    </div>

                    <div class="illus-coin illus-coin-1">
                        <span>R$</span>
                    </div>
                    <div class="illus-coin illus-coin-2">
                        <span>R$</span>
                    </div>
                    <div class="illus-coin illus-coin-3">
                        <span>R$</span>
                    </div>
                    <div class="illus-wallet" aria-hidden="true">
                        <div class="wallet-body"></div>
                        <div class="wallet-flap"></div>
                        <div class="wallet-card wallet-card-1"></div>
                        <div class="wallet-card wallet-card-2"></div>
                    </div>
                </div>

                <div class="auth-visual-foot">
                    <span class="shield" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V5l-8-3z"/><polyline points="9 12 11 14 15 10"/></svg>
                    </span>
                    <div>
                        <strong>Seus dados estão seguros conosco.</strong>
                        <span>Privacidade e segurança são prioridade.</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-card" role="region" aria-labelledby="authTitle">
                <div class="auth-icon-circle" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                        <line x1="19" y1="8" x2="19" y2="14"/>
                        <line x1="22" y1="11" x2="16" y2="11"/>
                    </svg>
                </div>
                <h1 id="authTitle" class="auth-title">Crie sua conta <span class="auth-emoji" aria-hidden="true">🚀</span></h1>
                <p class="auth-subtitle">É rápido e fácil!</p>

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
                            <input type="text" id="name" name="name" placeholder="Seu nome completo" autocomplete="name" required>
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

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="password">Senha</label>
                            <div class="input-wrap">
                                <span class="input-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                    </svg>
                                </span>
                                <input type="password" id="password" name="password" placeholder="Sua senha" autocomplete="new-password" minlength="8" required>
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
                        </div>
                    </div>
                    <small class="input-hint" id="passwordMatchHint"></small>

                    <div class="password-rules" id="passwordRules">
                        <span class="password-rules-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="11" width="16" height="10" rx="2"/>
                                <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                            </svg>
                        </span>
                        <div class="password-rules-content">
                            <strong>Sua senha deve conter:</strong>
                            <ul>
                                <li data-rule="length"><span class="rule-dot"></span>Pelo menos 8 caracteres</li>
                                <li data-rule="upper"><span class="rule-dot"></span>Uma letra maiúscula</li>
                                <li data-rule="number"><span class="rule-dot"></span>Um número</li>
                                <li data-rule="special"><span class="rule-dot"></span>Um caractere especial</li>
                            </ul>
                        </div>
                    </div>

                    <label class="auth-check auth-terms">
                        <input type="checkbox" name="terms" id="terms" required>
                        <span class="auth-check-mark"></span>
                        <span class="auth-check-label">Li e aceito os <a href="#">Termos de Uso</a> e a <a href="#">Política de Privacidade</a></span>
                    </label>

                    <button type="submit" class="btn btn-primary auth-submit">
                        <span>Cadastrar</span>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                </form>

                <p class="auth-link">
                    Já tem conta? <a href="/index.php?action=login">Faça login</a>
                </p>
            </div>
            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Todos os direitos reservados</p>
        </section>
    </main>
    <script src="/js/app.js" defer></script>
</body>
</html>

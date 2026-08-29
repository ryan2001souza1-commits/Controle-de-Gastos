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
                    <h2>Organize suas finanças<br>e alcance seus objetivos</h2>
                    <p>Acompanhe seus gastos, planeje melhor e conquiste sua liberdade financeira.</p>
                </div>

                <div class="auth-illustrations">
                    <div class="illus-card illus-summary">
                        <div class="illus-summary-head">
                            <span class="illus-summary-title">Resumo do mês</span>
                            <span class="illus-trend">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                            </span>
                        </div>
                        <div class="illus-row">
                            <span>Saldo atual</span>
                        </div>
                        <div class="illus-balance">R$ 2.540,00</div>
                        <div class="illus-divider"></div>
                        <div class="illus-line"><span>Receitas</span><b class="illus-up">R$ 5.300,00</b></div>
                        <div class="illus-line"><span>Despesas</span><b class="illus-down">R$ 2.760,00</b></div>
                    </div>

                    <div class="illus-card illus-categories">
                        <div class="illus-cat-title">Gastos por categoria</div>
                        <div class="illus-donut">
                            <svg viewBox="0 0 42 42" width="100" height="100" aria-hidden="true">
                                <circle cx="21" cy="21" r="15.915" fill="#fff"/>
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#8b5cf6" stroke-width="8" stroke-dasharray="40 60" stroke-dashoffset="0" transform="rotate(-90 21 21)"/>
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#22c55e" stroke-width="8" stroke-dasharray="25 75" stroke-dashoffset="-40" transform="rotate(-90 21 21)"/>
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#f59e0b" stroke-width="8" stroke-dasharray="15 85" stroke-dashoffset="-65" transform="rotate(-90 21 21)"/>
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#3b82f6" stroke-width="8" stroke-dasharray="10 90" stroke-dashoffset="-80" transform="rotate(-90 21 21)"/>
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#94a3b8" stroke-width="8" stroke-dasharray="10 90" stroke-dashoffset="-90" transform="rotate(-90 21 21)"/>
                            </svg>
                        </div>
                        <ul class="illus-cat-list">
                            <li><span class="dot dot-purple"></span>Moradia <em>40%</em></li>
                            <li><span class="dot dot-green"></span>Alimentação <em>25%</em></li>
                            <li><span class="dot dot-orange"></span>Transporte <em>15%</em></li>
                            <li><span class="dot dot-blue"></span>Lazer <em>10%</em></li>
                            <li><span class="dot dot-gray"></span>Outros <em>10%</em></li>
                        </ul>
                    </div>

                    <div class="illus-card illus-goal">
                        <div class="illus-goal-icon">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></svg>
                        </div>
                        <div class="illus-goal-text">
                            <strong>Meta do mês</strong>
                            <span>Economize mais para realizar seus sonhos!</span>
                        </div>
                        <div class="illus-goal-progress">
                            <div class="illus-goal-bar"><span style="width:72%"></span></div>
                            <span class="illus-goal-pct">72%</span>
                        </div>
                    </div>

                    <div class="illus-coin illus-coin-1">
                        <span>R$</span>
                    </div>
                    <div class="illus-coin illus-coin-2">
                        <span>R$</span>
                    </div>
                    <div class="illus-wallet" aria-hidden="true">
                        <div class="wallet-body"></div>
                        <div class="wallet-flap"></div>
                        <div class="wallet-card wallet-card-1"></div>
                        <div class="wallet-card wallet-card-2"></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-card" role="region" aria-labelledby="authTitle">
                <div class="auth-icon-circle" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="11" width="16" height="10" rx="2"/>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                    </svg>
                </div>
                <h1 id="authTitle" class="auth-title">Bem-vindo de volta!</h1>
                <p class="auth-subtitle">Faça login para continuar</p>

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

                    <button type="submit" class="btn btn-primary auth-submit">
                        <span>Entrar</span>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                </form>

                <div class="auth-divider">
                    <span>ou continue com</span>
                </div>

                <div class="auth-social">
                    <button type="button" class="auth-social-btn" aria-label="Continuar com Google">
                        <svg viewBox="0 0 48 48" width="20" height="20" aria-hidden="true">
                            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
                            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.5-5.2l-6.2-5.2C29.3 35 26.8 36 24 36c-5.3 0-9.7-3.1-11.3-7.6l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.2-4.3 5.6l6.2 5.2c-.4.4 6.8-5 6.8-14.8 0-1.3-.1-2.4-.4-3.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="auth-social-btn" aria-label="Continuar com Facebook">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" fill="#1877F2"/>
                            <path fill="#fff" d="M13.5 21v-7.5h2.5l.4-3h-2.9V8.6c0-.9.3-1.5 1.5-1.5h1.5V4.4c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.7v2.1H8.1v3h2.5V21h2.9z"/>
                        </svg>
                    </button>
                    <button type="button" class="auth-social-btn" aria-label="Continuar com Apple">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                            <path fill="#000" d="M16.4 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.8-3.5.8-.7 0-1.9-.8-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.1.8 1.3 0 2.1-1.2 2.9-2.3.9-1.3 1.3-2.6 1.3-2.7-.1 0-2.6-1-2.7-3.9zM14.1 5.6c.7-.8 1.1-1.9 1-3-.9 0-2.1.6-2.7 1.4-.6.7-1.1 1.8-1 2.9 1 .1 2-.5 2.7-1.3z"/>
                        </svg>
                    </button>
                </div>

                <p class="auth-link">
                    Não tem conta? <a href="/index.php?action=register">Cadastre-se</a>
                </p>
            </div>
            <p class="auth-footer">&copy; <?= date('Y') ?> Controle de Gastos · Todos os direitos reservados</p>
        </section>
    </main>
    <script src="/js/app.js" defer></script>
</body>
</html>

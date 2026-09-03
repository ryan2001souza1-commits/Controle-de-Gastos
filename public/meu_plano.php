<?php
$pageTitle = $pageTitle ?? 'Meu Plano';
$pageSubtitle = $pageSubtitle ?? 'Gerencie seu plano e veja os recursos disponíveis para você.';
$activeMenu = $activeMenu ?? 'meu_plano';
$showPeriodPicker = $showPeriodPicker ?? false;
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userEmail = $userEmail ?? ($_SESSION['user_email'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

<div class="panel" style="margin-bottom:var(--space-5)">
    <div class="panel-header">
        <div>
            <div class="panel-title"><?= htmlspecialchars($pageTitle) ?></div>
            <div class="panel-subtitle"><?= htmlspecialchars($pageSubtitle) ?></div>
        </div>
    </div>
</div>

<?php
$planBadge = [
    'gratuito' => 'badge-muted',
    'pro'     => 'badge-pro',
    'premium'  => 'badge-premium',
][$currentPlanSlug] ?? 'badge-muted';

$planBadgeLabel = [
    'gratuito' => 'FREE',
    'pro'     => 'PRO',
    'premium'  => 'PREMIUM',
][$currentPlanSlug] ?? 'FREE';

$planIsAtivo = $planData['is_ativo'] ?? true;
$statusLabel = $planIsAtivo ? 'Ativo' : 'Inativo';
$statusBadgeClass = $planIsAtivo ? 'badge-success' : 'badge-warning';

$flashSuccess = (($_GET['subscribed'] ?? '') === '1');
$flashCancelled = (($_GET['cancelled'] ?? '') === '1');
$flashError = (($_GET['error'] ?? '') !== '');

$errKey = (string)($_GET['error'] ?? '');
$errMessages = [
    'invalid_plan'          => 'Plano inválido.',
    'plan_not_found'        => 'Plano não encontrado.',
    'plan_not_configured'   => 'Plano não configurado no servidor.',
    'missing_card_data'     => 'Preencha todos os dados do cartão.',
    'missing_holder_data'   => 'Preencha todos os dados do titular.',
    'invalid_cpf'          => 'CPF inválido ou não cadastrado. Cadastre um CPF válido em Configurações antes de assinar um plano.',
    'incomplete_profile'   => 'Seus dados de cadastro estão incompletos. Preencha nome e e-mail em Configurações.',
    'asaas_customer_failed' => 'Não foi possível criar o cadastro no Asaas.',
    'asaas_create_failed'   => 'Não foi possível processar a assinatura. Verifique os dados do cartão e tente novamente.',
    'asaas_no_id'           => 'Resposta incompleta do serviço de pagamento.',
    'already_subscribed'    => 'Você já possui uma assinatura ativa.',
    'no_active_subscription'=> 'Você não possui uma assinatura ativa.',
    'method'                => 'Método não permitido.',
    'mp_not_configured'     => 'Serviço de pagamento não configurado.',
];
$errText = $errMessages[$errKey] ?? null;
?>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success" role="status" style="margin-bottom:var(--space-4)">
        <?= render_icon('check', 13) ?>
        <span>Assinatura criada com sucesso.</span>
    </div>
<?php endif; ?>

<?php if ($flashCancelled): ?>
    <div class="alert alert-success" role="status" style="margin-bottom:var(--space-4)">
        <?= render_icon('check', 13) ?>
        <span>Assinatura cancelada.</span>
    </div>
<?php endif; ?>

<?php if ($flashError && $errText): ?>
    <div class="alert alert-error" role="alert" style="margin-bottom:var(--space-4)">
        <?= render_icon('info', 13) ?>
        <span><?= htmlspecialchars($errText) ?></span>
        <?php if ($errKey === 'invalid_cpf'): ?>
            &nbsp;<a href="/index.php?action=configuracoes" class="alert-link" style="font-weight:600;text-decoration:underline">Ir para Configurações</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Card do plano atual -->
<section class="panel" style="margin-bottom:var(--space-5)">
    <div class="panel-body" style="display:flex;gap:var(--space-5);align-items:center;flex-wrap:wrap">
        <div style="flex:0 0 auto;text-align:center;min-width:100px">
            <div class="plan-badge-current <?= htmlspecialchars($planBadge) ?>">
                <?= htmlspecialchars($planBadgeLabel) ?>
            </div>
            <div style="margin-top:var(--space-2);font-size:13px;color:var(--color-text-2)">Plano atual</div>
        </div>
        <div style="flex:1;min-width:180px">
            <div style="font-size:20px;font-weight:700;color:var(--color-text-1);letter-spacing:-.02em">
                <?= htmlspecialchars($planData['nome'] ?? $planSvc->getPlanDisplayName($currentPlanSlug)) ?>
            </div>
            <div style="margin-top:4px;display:flex;align-items:center;gap:8px">
                <span class="badge <?= $statusBadgeClass ?>">
                    <span class="badge-dot"></span><?= htmlspecialchars($statusLabel) ?>
                </span>
                <?php if (!empty($planData['inicio'])): ?>
                    <span style="font-size:12px;color:var(--color-text-3)">
                        Desde <?= date('d/m/Y', strtotime($planData['inicio'])) ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($currentPrice !== 'Gratuito' && $currentPrice !== 'A definir'): ?>
                <div style="margin-top:6px;font-size:13px;color:var(--color-text-2)">
                    <?= htmlspecialchars($currentPrice) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Limites do plano atual -->
<section class="panel" style="margin-bottom:var(--space-5)">
    <div class="panel-header">
        <div class="panel-title">Limites do seu plano</div>
        <div class="panel-subtitle">Sua utilização mensal atual</div>
    </div>
    <div class="panel-body-sm">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--space-3)">
            <?php foreach ($limitLabels as $ltype => $lmeta): ?>
                <?php
                $valor = $currentLimits[$ltype] ?? 0;
                $ilimitado = ($valor === null);
                $valorFmt = $ilimitado ? 'Ilimitado' : number_format((int)$valor, 0, ',', '.');
                ?>
                <div style="display:flex;align-items:center;gap:var(--space-2);padding:var(--space-2) var(--space-3);background:var(--color-surface-2);border-radius:10px;border:1px solid var(--color-border)">
                    <div style="flex:0 0 auto;color:var(--color-text-3)"><?= render_icon($lmeta['icon'] ?? 'info', 16) ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:12px;color:var(--color-text-3)"><?= htmlspecialchars($lmeta['label']) ?></div>
                        <div style="font-size:15px;font-weight:600;color:var(--color-text-1);margin-top:2px">
                            <?= htmlspecialchars($valorFmt) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Recursos do plano atual -->
<section class="panel" style="margin-bottom:var(--space-5)">
    <div class="panel-header">
        <div class="panel-title">Recursos do seu plano</div>
        <div class="panel-subtitle"><?= htmlspecialchars($planSvc->getPlanDisplayName($currentPlanSlug)) ?> — <?= count(array_filter($currentFeatures, fn($v) => $v)) ?> de <?= count($currentFeatures) ?> recursos ativos</div>
    </div>
    <div class="panel-body-sm">
        <div style="display:flex;flex-direction:column;gap:var(--space-2)">
            <?php foreach ($featureLabels as $fkey => $fmeta): ?>
                <?php $tem = !empty($currentFeatures[$fkey]); ?>
                <div style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-2) var(--space-3);border-radius:8px;background:<?= $tem ? 'rgba(16,185,129,0.05)' : 'rgba(100,116,139,0.04)' ?>;border:1px solid <?= $tem ? 'rgba(16,185,129,0.15)' : 'var(--color-border)' ?>">
                    <div style="flex:0 0 auto;color:<?= $tem ? 'var(--color-success)' : 'var(--color-text-3)' ?>">
                        <?php if ($tem): ?>
                            <?= render_icon('check-circle', 18) ?>
                        <?php else: ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" opacity=".35"/>
                                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:13px;font-weight:500;color:<?= $tem ? 'var(--color-text-1)' : 'var(--color-text-3)' ?>"><?= htmlspecialchars($fmeta['label']) ?></div>
                        <div style="font-size:12px;color:var(--color-text-3);margin-top:1px"><?= htmlspecialchars($fmeta['desc']) ?></div>
                    </div>
                    <?php if (!$tem): ?>
                        <span style="flex:0 0 auto;font-size:11px;font-weight:600;color:var(--color-text-3);background:var(--color-surface-2);border:1px solid var(--color-border);padding:2px 8px;border-radius:20px">PRO+</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if (!empty($upgrades)): ?>
<!-- Planos superiores -->
<section>
    <div style="margin-bottom:var(--space-4)">
        <div style="font-size:16px;font-weight:700;color:var(--color-text-1);letter-spacing:-.02em">Faça upgrade do seu plano</div>
        <div style="font-size:13px;color:var(--color-text-3);margin-top:4px">Desbloqueie mais recursos para a sua gestão financeira.</div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--space-4)">
        <?php
        $planCardStyles = [
            'pro'      => ['border' => '1px solid var(--color-pro-border, #7c3aed33)', 'bg' => 'rgba(124,58,237,0.04)', 'header_bg' => 'rgba(124,58,237,0.08)', 'header_color' => '#7c3aed', 'accent_color' => '#7c3aed'],
            'premium'  => ['border' => '1px solid var(--color-premium-border, #f59e0b44)', 'bg' => 'rgba(245,158,11,0.04)', 'header_bg' => 'rgba(245,158,11,0.08)', 'header_color' => '#d97706', 'accent_color' => '#d97706'],
        ];
        ?>

        <?php foreach ($upgrades as $slug => $upgrade): ?>
            <?php
            $style = $planCardStyles[$slug] ?? ['border' => '1px solid var(--color-border)', 'bg' => 'var(--color-surface-1)', 'header_bg' => 'var(--color-surface-2)', 'header_color' => 'var(--color-text-2)', 'accent_color' => 'var(--color-primary)'];
            $isPro = ($slug === 'pro');
            $isPremium = ($slug === 'premium');
            $planName = $upgrade['nome'];
            $planPrice = $upgrade['preco'];
            $planFeatures = $upgrade['features'] ?? [];
            ?>
            <div style="border-radius:16px;border:<?= $style['border'] ?>;background:<?= $style['bg'] ?>;overflow:hidden">
                <div style="padding:var(--space-4) var(--space-5);background:<?= $style['header_bg'] ?>;border-bottom:1px solid <?= $style['border'] ?>">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-1)">
                        <div style="font-size:17px;font-weight:700;color:<?= $style['header_color'] ?>;letter-spacing:-.02em">
                            <?= htmlspecialchars($planName) ?>
                        </div>
                        <?php if ($isPro): ?>
                            <span style="font-size:11px;font-weight:700;color:#7c3aed;background:rgba(124,58,237,0.12);padding:2px 8px;border-radius:20px;letter-spacing:.03em">MAIS POPULAR</span>
                        <?php elseif ($isPremium): ?>
                            <span style="font-size:11px;font-weight:700;color:#d97706;background:rgba(245,158,11,0.12);padding:2px 8px;border-radius:20px;letter-spacing:.03em">COMPLETO</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($planPrice !== 'A definir' && $planPrice !== 'Gratuito'): ?>
                        <div style="font-size:22px;font-weight:700;color:<?= $style['accent_color'] ?>;margin-top:var(--space-1)">
                            <?= htmlspecialchars($planPrice) ?>
                        </div>
                    <?php elseif ($planPrice === 'A definir'): ?>
                        <div style="font-size:14px;color:var(--color-text-3);margin-top:var(--space-1)">Preço a definir</div>
                    <?php else: ?>
                        <div style="font-size:14px;color:var(--color-text-3);margin-top:var(--space-1)">Gratuito</div>
                    <?php endif; ?>
                </div>

                <div style="padding:var(--space-4) var(--space-5)">
                    <div style="display:flex;flex-direction:column;gap:var(--space-2);margin-bottom:var(--space-4)">
                        <?php foreach ($featureLabels as $fkey => $fmeta): ?>
                            <?php $tem = !empty($planFeatures[$fkey]); ?>
                            <?php if ($tem): ?>
                                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--color-text-1)">
                                    <span style="flex:0 0 auto;color:var(--color-success)"><?= render_icon('check', 15) ?></span>
                                    <?= htmlspecialchars($fmeta['label']) ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div style="width:100%">
                        <button
                            type="button"
                            class="btn"
                            data-open-checkout="<?= htmlspecialchars($slug) ?>"
                            style="width:100%;justify-content:center;border:0;cursor:pointer;font:inherit;color:inherit;background:var(--color-primary);color:#fff"
                            title="Assinar plano <?= htmlspecialchars($planName) ?>">
                            <?= render_icon('zap', 15) ?>
                            Atualizar para <?= htmlspecialchars($planName) ?>
                        </button>
                    </div>
                    <div style="margin-top:var(--space-2);font-size:11px;color:var(--color-text-3);text-align:center">
                        Pagamento seguro via Mercado Pago
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if (!empty($mpPublicKey)): ?>
<!-- Modal de checkout Mercado Pago (tokenizacao no navegador) -->
<div id="mp-checkout-modal" hidden style="position:fixed;inset:0;background:rgba(15,23,42,.75);display:none;align-items:flex-start;justify-content:center;z-index:2147483646;padding:var(--space-3);overflow-y:auto;isolation:isolate">
    <div style="background:var(--color-surface-1);background-color:var(--color-surface-1,#1e293b);border-radius:16px;max-width:460px;width:100%;max-height:90vh;overflow-y:auto;padding:var(--space-5);border:1px solid var(--color-border);position:relative;z-index:1;box-shadow:0 25px 50px rgba(0,0,0,.5);opacity:1">
        <button type="button" id="mp-close" aria-label="Fechar" style="position:absolute;top:12px;right:12px;background:transparent;border:0;cursor:pointer;font-size:20px;color:var(--color-text-3)">×</button>
        <div style="font-size:18px;font-weight:700;color:var(--color-text-1);letter-spacing:-.02em;margin-bottom:4px">
            Assinar plano <span id="mp-plan-name">Pro</span>
        </div>
        <div style="font-size:13px;color:var(--color-text-3);margin-bottom:var(--space-4)">
            Os dados do cartão são enviados diretamente ao Mercado Pago. Eles <strong>nunca</strong> passam pelo nosso servidor.
        </div>

        <form id="mp-card-form" autocomplete="off" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="plan_slug" id="mp-plan-slug" value="">
            <input type="hidden" name="card_token_id" id="mp-card-token" value="">

            <div class="mp-field-group">
                <div class="mp-field">
                    <label class="mp-label" for="mp-payer-email">E-mail do pagador</label>
                    <input type="email" id="mp-payer-email" value="<?= htmlspecialchars($userEmail) ?>" required class="mp-input">
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="mp-card-number">Número do cartão</label>
                    <div id="mp-card-number" class="mp-field-frame"></div>
                </div>

                <div class="mp-row">
                    <div class="mp-field">
                        <label class="mp-label" for="mp-card-exp">Validade</label>
                        <div id="mp-card-exp" class="mp-field-frame"></div>
                    </div>
                    <div class="mp-field">
                        <label class="mp-label" for="mp-card-cvv">CVV</label>
                        <div id="mp-card-cvv" class="mp-field-frame"></div>
                    </div>
                </div>

                <div class="mp-field">
                    <label class="mp-label" for="mp-card-holder">Nome impresso no cartão</label>
                    <input type="text" id="mp-card-holder" value="<?= htmlspecialchars($userName) ?>" required class="mp-input">
                </div>

                <div class="mp-doc-group">
                    <div class="mp-field">
                        <label class="mp-label" for="mp-card-doc-type">Tipo de documento</label>
                        <select id="mp-card-doc-type" class="mp-input"></select>
                    </div>
                    <div class="mp-field">
                        <label class="mp-label" for="mp-card-doc">CPF / CNPJ do titular</label>
                        <input type="text" id="mp-card-doc" class="mp-input" placeholder="000.000.000-00" inputmode="numeric">
                    </div>
                </div>

                <div id="mp-issuer" class="mp-field" style="display:none">
                    <label class="mp-label" for="mp-issuer-select">Banco emissor</label>
                    <select id="mp-issuer-select" class="mp-input"></select>
                </div>

                <div id="mp-installments-wrap" class="mp-field" style="display:none">
                    <label class="mp-label" for="mp-installments">Parcelas</label>
                    <select id="mp-installments" class="mp-input"></select>
                </div>
            </div>

            <div id="mp-form-error" class="mp-form-error" role="alert"></div>

            <div class="mp-actions">
                <button type="button" id="mp-cancel" class="btn mp-btn-secondary">Cancelar</button>
                <button type="submit" id="mp-submit" class="btn mp-btn-primary">
                    <span id="mp-submit-label">Assinar agora</span>
                    <span id="mp-submit-spinner" class="mp-spinner" hidden>
                        <svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="40 60"/></svg>
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes mp-spin { to { transform: rotate(360deg); } }
#mp-checkout-modal[data-open="1"] { display: flex !important; }

.mp-field-group { display: flex; flex-direction: column; gap: 12px; }
.mp-field { display: flex; flex-direction: column; gap: 6px; }
.mp-label { display: block; font-size: 12px; font-weight: 600; color: var(--color-text-2); line-height: 1.2; }
.mp-input { width: 100%; padding: 10px 12px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-surface-2); color: var(--color-text-1); font-size: 14px; box-sizing: border-box; min-height: 44px; }
.mp-input:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 3px rgba(124,58,237,.15); }
.mp-field-frame { width: 100%; height: 44px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-surface-2); box-sizing: border-box; overflow: hidden; position: relative; }
.mp-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }
.mp-doc-group { display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: start; }
.mp-form-error { display: none; margin-top: 12px; padding: 10px 12px; background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); border-radius: 8px; color: #fca5a5; font-size: 13px; }
.mp-form-error[data-visible="1"] { display: block; }
.mp-actions { display: flex; gap: 8px; margin-top: 16px; }
.mp-btn-secondary { flex: 1; justify-content: center; background: var(--color-surface-2); color: var(--color-text-2); border: 1px solid var(--color-border); }
.mp-btn-primary { flex: 2; justify-content: center; background: var(--color-primary); color: #fff; border: 0; }
#mp-submit:disabled .mp-spinner { display: inline-block; animation: mp-spin .8s linear infinite; margin-left: 8px; }
.mp-spinner { display: none; }

@media (max-width: 400px) {
    .mp-row { grid-template-columns: 1fr; }
    .mp-doc-group { grid-template-columns: 1fr; }
    #mp-checkout-modal > div { max-width: 100%; }
}
</style>

<script src="https://sdk.mercadopago.com/js/v2"></script>
<script>
(function() {
    'use strict';
    var PUBLIC_KEY = <?= json_encode($mpPublicKey, JSON_UNESCAPED_SLASHES) ?>;
    var MP_MODE = <?= json_encode($mpSandbox ? 'sandbox' : 'production') ?>;
    var PLAN_AMOUNTS = <?= json_encode($mpPlanAmounts ?? [], JSON_UNESCAPED_SLASHES) ?>;
    var CSRF_TOKEN = <?= json_encode((function () {
        $sess = session_status() === PHP_SESSION_ACTIVE;
        if (!$sess) { return ''; }
        $uid = $_SESSION['user_id'] ?? null;
        global $csrfService;
        if (!isset($csrfService)) { $csrfService = new CsrfService(); }
        $t = $csrfService->getToken($uid);
        if ($t === null && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) { $t = $_SESSION['csrf_token']; }
        return $t ?: '';
    })(), JSON_UNESCAPED_SLASHES) ?>;

    var mp = null;
    var cardForm = null;

    function $(id) { return document.getElementById(id); }
    function showError(msg) {
        var el = $('mp-form-error');
        el.textContent = msg;
        el.setAttribute('data-visible', '1');
    }
    function clearError() {
        var el = $('mp-form-error');
        el.textContent = '';
        el.removeAttribute('data-visible');
    }
    function setLoading(on) {
        $('mp-submit').disabled = !!on;
        $('mp-submit-label').textContent = on ? 'Processando…' : 'Assinar agora';
    }

    function onlyDigits(s) { return (s || '').replace(/\D+/g, ''); }
    function formatExp(s) {
        var d = onlyDigits(s).slice(0, 4);
        if (d.length < 3) return d;
        return d.slice(0, 2) + '/' + d.slice(2);
    }
    function formatCard(s) {
        var d = onlyDigits(s).slice(0, 19);
        return d.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
    }
    function formatCpf(s) {
        var d = onlyDigits(s).slice(0, 11);
        if (d.length <= 3) return d;
        if (d.length <= 6) return d.slice(0,3) + '.' + d.slice(3);
        if (d.length <= 9) return d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6);
        return d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6,9) + '-' + d.slice(9);
    }

    function ensureMp() {
        if (mp) return Promise.resolve(mp);
        return new Promise(function(resolve, reject) {
            if (!window.MercadoPago) { reject(new Error('SDK indisponível')); return; }
            mp = new window.MercadoPago(PUBLIC_KEY, { locale: 'pt-BR' });
            resolve(mp);
        });
    }

    function getBin() {
        var n = onlyDigits($('mp-card-number').value);
        return n.length >= 6 ? n.slice(0, 6) : null;
    }

    var currentPlanSlug = null;

    function buildCardForm(mpInstance) {
        if (cardForm) { try { cardForm.unmount(); } catch (e) {} }
        var amount = (currentPlanSlug && PLAN_AMOUNTS[currentPlanSlug])
            ? PLAN_AMOUNTS[currentPlanSlug]
            : '0';
        cardForm = mpInstance.cardForm({
            amount: amount,
            iframe: true,
            form: {
                id: 'mp-card-form',
                cardNumber: { id: 'mp-card-number', placeholder: '0000 0000 0000 0000' },
                expirationDate: { id: 'mp-card-exp', placeholder: 'MM/AA' },
                securityCode: { id: 'mp-card-cvv', placeholder: '123' },
                cardholderName: { id: 'mp-card-holder', placeholder: 'Nome impresso' },
                issuer: { id: 'mp-issuer-select' },
                installments: { id: 'mp-installments' },
                identificationType: { id: 'mp-card-doc-type' },
                identificationNumber: { id: 'mp-card-doc', placeholder: '000.000.000-00' },
                email: { id: 'mp-payer-email' },
            },
            callbacks: {
                onFormMounted: function() {
                    // Os campos de cartao (cardNumber, expirationDate, securityCode)
                    // sao divs cujo conteudo e substituido pelo iframe do MP.
                    // Nao precisamos de event listeners nos containers.
                },
                onBinChange: function(bin) {
                    var issuerEl = $('mp-issuer');
                    if (bin && bin.length >= 6) {
                        issuerEl.style.display = 'block';
                        try { mpInstance.getIssuers({ bin: bin }, function(err, issuers) {
                            var sel = $('mp-issuer-select');
                            sel.innerHTML = '';
                            if (err || !issuers) return;
                            issuers.forEach(function(i) {
                                var opt = document.createElement('option');
                                opt.value = i.id;
                                opt.textContent = i.name;
                                sel.appendChild(opt);
                            });
                        }); } catch (e) {}
                    } else {
                        issuerEl.style.display = 'none';
                    }
                },
                onInstallmentsReceived: function(data) {
                    var wrap = $('mp-installments-wrap');
                    if (data && data.length) {
                        wrap.style.display = 'block';
                        var sel = $('mp-installments');
                        sel.innerHTML = '';
                        data.forEach(function(it) {
                            var opt = document.createElement('option');
                            opt.value = it.installments;
                            opt.textContent = it.installments + 'x ' + (it.label || '');
                            sel.appendChild(opt);
                        });
                    } else {
                        wrap.style.display = 'none';
                    }
                },
                onError: function(errors) {
                    if (errors && errors.length) {
                        showError(errors.map(function(e){ return e.message; }).join(' '));
                    }
                },
                onSubmit: function(event) {
                    event.preventDefault();
                }
            }
        });
    }

    async function openModal(slug, planName) {
        clearError();
        currentPlanSlug = slug;
        $('mp-plan-slug').value = slug;
        $('mp-plan-name').textContent = planName || slug;
        var modal = $('mp-checkout-modal');
        modal.hidden = false;
        modal.setAttribute('data-open', '1');
        try {
            var inst = await ensureMp();
            buildCardForm(inst);
        } catch (e) {
            showError('Não foi possível carregar o sistema de pagamento. Tente novamente em instantes.');
        }
    }

    function closeModal() {
        var modal = $('mp-checkout-modal');
        modal.hidden = true;
        modal.removeAttribute('data-open');
        currentPlanSlug = null;
        if (cardForm) { try { cardForm.unmount(); } catch (e) {} cardForm = null; }
    }

    document.addEventListener('click', function(ev) {
        var btn = ev.target.closest('[data-open-checkout]');
        if (btn) {
            ev.preventDefault();
            var slug = btn.getAttribute('data-open-checkout');
            var name = btn.getAttribute('data-plan-name') || (slug === 'pro' ? 'Pro' : 'Premium');
            openModal(slug, name);
            return;
        }
        if (ev.target.id === 'mp-close' || ev.target.id === 'mp-cancel') {
            closeModal();
        }
    });

    document.getElementById('mp-card-form').addEventListener('submit', async function(ev) {
        ev.preventDefault();
        clearError();
        if (!cardForm) { showError('Sistema de pagamento não inicializado.'); return; }
        setLoading(true);
        try {
            var token = await cardForm.tokenize();
            if (!token || !token.id) {
                showError('Não foi possível tokenizar o cartão. Verifique os dados.');
                setLoading(false);
                return;
            }
            $('mp-card-token').value = token.id;
            var fd = new FormData();
            fd.append('plan_slug', $('mp-plan-slug').value);
            fd.append('card_token_id', token.id);
            fd.append('csrf_token', CSRF_TOKEN);
            var resp = await fetch('/index.php?action=subscription_create', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            var loc = resp.headers.get('Location') || '/?action=meu_plano&subscribed=1';
            window.location.href = loc;
        } catch (e) {
            showError('Não foi possível processar o pagamento. Tente novamente.');
            setLoading(false);
        }
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>



<?php include __DIR__ . '/partials/layout_end.php'; ?>

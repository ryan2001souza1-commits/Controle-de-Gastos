<?php
$pageTitle = $pageTitle ?? 'Meu Plano';
$pageSubtitle = $pageSubtitle ?? 'Gerencie seu plano e veja os recursos disponíveis para você.';
$activeMenu = $activeMenu ?? 'meu_plano';
$showPeriodPicker = $showPeriodPicker ?? false;
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userEmail = $userEmail ?? ($_SESSION['user_email'] ?? '');
$hasAsaas = $hasAsaas ?? false;
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
                            class="btn btn-asaas-subscribe"
                            data-plan-slug="<?= htmlspecialchars($slug) ?>"
                            data-plan-name="<?= htmlspecialchars($planName) ?>"
                            data-plan-price="<?= htmlspecialchars($planPrice) ?>"
                            style="width:100%;justify-content:center"
                            title="Assinar plano <?= htmlspecialchars($planName) ?>"
                            <?= $hasAsaas ? '' : 'disabled' ?>>
                            <?= render_icon('zap', 15) ?>
                            Atualizar para <?= htmlspecialchars($planName) ?>
                        </button>
                    </div>
                    <div style="margin-top:var(--space-2);font-size:11px;color:var(--color-text-3);text-align:center">
                        Cobrança segura via Asaas
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Modal de assinatura (Asaas) -->
<div id="asaas-modal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
    <div style="background:var(--color-surface-1);border:1px solid var(--color-border);border-radius:16px;padding:var(--space-5);max-width:520px;width:90%;max-height:90vh;overflow-y:auto;position:relative">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
            <div>
                <div style="font-size:16px;font-weight:700;color:var(--color-text-1)">
                    Assinar <span id="asaas-plan-name"></span>
                </div>
                <div style="font-size:13px;color:var(--color-text-2);margin-top:2px">
                    <span id="asaas-plan-price"></span> / mês
                </div>
            </div>
            <button type="button" id="asaas-modal-close" aria-label="Fechar" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--color-text-3)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <form id="asaas-submit-form" method="POST" action="/index.php?action=asaas_subscription_create" autocomplete="on">
            <input type="hidden" name="plan_slug" id="asaas-plan-slug" value="">
            <input type="hidden" name="csrf_token" id="asaas-csrf-token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div style="margin-bottom:var(--space-3)">
                <label for="asaas-card-holder" style="display:block;font-size:12px;font-weight:600;color:var(--color-text-2);margin-bottom:6px">Nome impresso no cartão</label>
                <input type="text" id="asaas-card-holder" name="card_holder_name" required maxlength="80" autocomplete="cc-name" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
            </div>

            <div style="margin-bottom:var(--space-3)">
                <label for="asaas-card-number" style="display:block;font-size:12px;font-weight:600;color:var(--color-text-2);margin-bottom:6px">Número do cartão</label>
                <input type="text" id="asaas-card-number" name="card_number" required inputmode="numeric" pattern="[0-9 ]{13,23}" maxlength="23" autocomplete="cc-number" placeholder="0000 0000 0000 0000" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-2);margin-bottom:var(--space-3)">
                <div>
                    <label for="asaas-card-month" style="display:block;font-size:12px;font-weight:600;color:var(--color-text-2);margin-bottom:6px">Mês</label>
                    <input type="text" id="asaas-card-month" name="card_expiry_month" required inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" autocomplete="cc-exp-month" placeholder="MM" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
                <div>
                    <label for="asaas-card-year" style="display:block;font-size:12px;font-weight:600;color:var(--color-text-2);margin-bottom:6px">Ano</label>
                    <input type="text" id="asaas-card-year" name="card_expiry_year" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="cc-exp-year" placeholder="AAAA" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
                <div>
                    <label for="asaas-card-ccv" style="display:block;font-size:12px;font-weight:600;color:var(--color-text-2);margin-bottom:6px">CVV</label>
                    <input type="text" id="asaas-card-ccv" name="card_ccv" required inputmode="numeric" pattern="[0-9]{3,4}" maxlength="4" autocomplete="cc-csc" placeholder="123" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
            </div>

            <div style="margin-top:var(--space-4);margin-bottom:var(--space-2);font-size:12px;font-weight:600;color:var(--color-text-2);text-transform:uppercase;letter-spacing:.04em">Dados do titular</div>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-2);margin-bottom:var(--space-3)">
                <div>
                    <label for="asaas-holder-zip" style="display:block;font-size:12px;color:var(--color-text-3);margin-bottom:4px">CEP</label>
                    <input type="text" id="asaas-holder-zip" name="holder_postal_code" required inputmode="numeric" pattern="[0-9]{8}" maxlength="9" autocomplete="postal-code" placeholder="00000000" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
                <div>
                    <label for="asaas-holder-number" style="display:block;font-size:12px;color:var(--color-text-3);margin-bottom:4px">Número</label>
                    <input type="text" id="asaas-holder-number" name="holder_address_number" required maxlength="10" autocomplete="address-line2" placeholder="123" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2);margin-bottom:var(--space-3)">
                <div>
                    <label for="asaas-holder-phone" style="display:block;font-size:12px;color:var(--color-text-3);margin-bottom:4px">Telefone</label>
                    <input type="text" id="asaas-holder-phone" name="holder_phone" inputmode="tel" maxlength="15" autocomplete="tel" placeholder="(00) 0000-0000" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
                <div>
                    <label for="asaas-holder-mobile" style="display:block;font-size:12px;color:var(--color-text-3);margin-bottom:4px">Celular</label>
                    <input type="text" id="asaas-holder-mobile" name="holder_mobile_phone" required inputmode="tel" pattern="[0-9 ]{10,15}" maxlength="15" autocomplete="tel" placeholder="(00) 00000-0000" style="width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface-1);color:var(--color-text-1);font-size:14px">
                </div>
            </div>

            <div id="asaas-error-msg" style="display:none;padding:var(--space-3);background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:8px;font-size:13px;color:var(--color-error);margin-bottom:var(--space-3)"></div>

            <button type="submit" id="asaas-submit-btn" class="btn" style="width:100%;justify-content:center;margin-top:var(--space-2)">
                <span id="asaas-submit-label">Assinar agora</span>
            </button>
            <div style="margin-top:var(--space-2);font-size:11px;color:var(--color-text-3);text-align:center">
                Pagamento processado com segurança pelo Asaas. Os dados do cartão são enviados diretamente para a processadora via HTTPS e nunca são armazenados em nossos servidores.
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const MODAL = document.getElementById('asaas-modal');
    const ERROR_BOX = document.getElementById('asaas-error-msg');
    const CLOSE_BTN = document.getElementById('asaas-modal-close');
    const SUBMIT_FORM = document.getElementById('asaas-submit-form');
    const SUBMIT_BTN = document.getElementById('asaas-submit-btn');
    const SUBMIT_LABEL = document.getElementById('asaas-submit-label');
    const PLAN_NAME_EL = document.getElementById('asaas-plan-name');
    const PLAN_PRICE_EL = document.getElementById('asaas-plan-price');
    const PLAN_SLUG_INPUT = document.getElementById('asaas-plan-slug');

    function showError(msg) {
        ERROR_BOX.textContent = msg;
        ERROR_BOX.style.display = 'block';
    }
    function clearError() {
        ERROR_BOX.textContent = '';
        ERROR_BOX.style.display = 'none';
    }
    function showModal() {
        MODAL.style.display = 'flex';
        clearError();
    }
    function closeModal() {
        MODAL.style.display = 'none';
        clearError();
        SUBMIT_FORM.reset();
        PLAN_SLUG_INPUT.value = '';
        SUBMIT_BTN.disabled = false;
        SUBMIT_LABEL.textContent = 'Assinar agora';
    }
    function setLoading(loading) {
        SUBMIT_BTN.disabled = !!loading;
        SUBMIT_LABEL.textContent = loading ? 'Processando…' : 'Assinar agora';
    }

    CLOSE_BTN.addEventListener('click', closeModal);
    MODAL.addEventListener('click', function(e) {
        if (e.target === MODAL) closeModal();
    });

    function formatCardNumber(value) {
        return value.replace(/\D/g, '').substring(0, 19).replace(/(.{4})/g, '$1 ').trim();
    }
    function formatExpiry(value) { return value.replace(/\D/g, '').substring(0, 2); }
    function formatYear(value) { return value.replace(/\D/g, '').substring(0, 4); }
    function formatCcv(value) { return value.replace(/\D/g, '').substring(0, 4); }
    function formatZip(value) { return value.replace(/\D/g, '').substring(0, 8); }
    function formatPhone(value) { return value.replace(/\D/g, '').substring(0, 11); }

    const numberInput = document.getElementById('asaas-card-number');
    numberInput.addEventListener('input', function(e) {
        const pos = e.target.selectionStart;
        e.target.value = formatCardNumber(e.target.value);
        if (typeof pos === 'number') e.target.setSelectionRange(pos, pos);
    });
    document.getElementById('asaas-card-month').addEventListener('input', function(e) {
        e.target.value = formatExpiry(e.target.value);
    });
    document.getElementById('asaas-card-year').addEventListener('input', function(e) {
        e.target.value = formatYear(e.target.value);
    });
    document.getElementById('asaas-card-ccv').addEventListener('input', function(e) {
        e.target.value = formatCcv(e.target.value);
    });
    document.getElementById('asaas-holder-zip').addEventListener('input', function(e) {
        e.target.value = formatZip(e.target.value);
    });
    document.getElementById('asaas-holder-mobile').addEventListener('input', function(e) {
        e.target.value = formatPhone(e.target.value);
    });
    document.getElementById('asaas-holder-phone').addEventListener('input', function(e) {
        e.target.value = formatPhone(e.target.value);
    });

    document.querySelectorAll('.btn-asaas-subscribe').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const slug = btn.dataset.planSlug;
            const name = btn.dataset.planName;
            const price = btn.dataset.planPrice;
            PLAN_NAME_EL.textContent = name;
            PLAN_PRICE_EL.textContent = price;
            PLAN_SLUG_INPUT.value = slug;
            showModal();
        });
    });

    SUBMIT_FORM.addEventListener('submit', function() {
        setLoading(true);
    });
})();
</script>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

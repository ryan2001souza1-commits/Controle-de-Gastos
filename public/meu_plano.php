<?php
$pageTitle = $pageTitle ?? 'Meu Plano';
$pageSubtitle = $pageSubtitle ?? 'Gerencie seu plano e veja os recursos disponíveis para você.';
$activeMenu = $activeMenu ?? 'meu_plano';
$showPeriodPicker = $showPeriodPicker ?? false;
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userEmail = $userEmail ?? ($_SESSION['user_email'] ?? '');
$mpPublicKey = $mpPublicKey ?? '';
$hasMpSdk = $mpPublicKey !== '';
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
    <?php if ($hasMpSdk): ?>
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <?php endif; ?>
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
$flashMpError = (($_GET['error'] ?? '') === 'mp_create_failed');
?>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success" role="status" style="margin-bottom:var(--space-4)">
        <?= render_icon('check', 13) ?>
        <span>Assinatura criada com sucesso.</span>
    </div>
<?php endif; ?>

<?php if ($flashMpError): ?>
    <div class="alert alert-error" role="alert" style="margin-bottom:var(--space-4)">
        <?= render_icon('info', 13) ?>
        <span>Não foi possível validar o cartão para a assinatura. Verifique os dados e tente novamente.</span>
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
        <div style="font-size:13px;color:var(--color-text-3);margin-top:4px">Desbloqueie mais recursos para sua gestão financeira.</div>
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
            $planAmount = $upgrade['numeric_price']; // float em reais (ex: 9.90) — fonte: DB, nao string formatada
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
                            class="btn btn-mp-subscribe"
                            data-plan-slug="<?= htmlspecialchars($slug) ?>"
                            data-plan-name="<?= htmlspecialchars($planName) ?>"
                            data-plan-price="<?= htmlspecialchars($planPrice) ?>"
                            data-plan-amount="<?= htmlspecialchars(number_format($planAmount, 2, '.', '')) ?>"
                            data-user-email="<?= htmlspecialchars($userEmail) ?>"
                            data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
                            style="width:100%;justify-content:center"
                            title="Assinar plano <?= htmlspecialchars($planName) ?>">
                            <?= render_icon('zap', 15) ?>
                            Atualizar para <?= htmlspecialchars($planName) ?>
                        </button>
                    </div>
                    <div style="margin-top:var(--space-2);font-size:11px;color:var(--color-text-3);text-align:center">
                        Cobrança segura via Mercado Pago
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Modal do Mercado Pago Card Payment Brick -->
<div id="mp-modal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
    <div style="background:var(--color-surface-1);border:1px solid var(--color-border);border-radius:16px;padding:var(--space-5);max-width:480px;width:90%;max-height:90vh;overflow-y:auto;position:relative">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
            <div>
                <div style="font-size:16px;font-weight:700;color:var(--color-text-1)">
                    Assinar <span id="mp-plan-name"></span>
                </div>
                <div style="font-size:13px;color:var(--color-text-2);margin-top:2px">
                    <span id="mp-plan-price"></span> / mês
                </div>
            </div>
            <button type="button" id="mp-modal-close" aria-label="Fechar" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--color-text-3)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div id="mp-card-payment-brick-container" style="margin-bottom:var(--space-4)"></div>

        <div id="mp-error-msg" style="display:none;padding:var(--space-3);background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:8px;font-size:13px;color:var(--color-error);margin-bottom:var(--space-3)"></div>
        <div id="mp-loading-msg" style="display:none;text-align:center;padding:var(--space-3);font-size:13px;color:var(--color-text-2)">
            Processando sua assinatura…
        </div>

        <form id="mp-submit-form" method="POST" action="/index.php?action=subscription_create" style="display:none">
            <input type="hidden" name="plan_slug" id="mp-plan-slug" value="">
            <input type="hidden" name="card_token_id" id="mp-card-token-id" value="">
            <input type="hidden" name="csrf_token" id="mp-csrf-token" value="">
        </form>
    </div>
</div>

<?php if ($hasMpSdk): ?>
<script>
(function() {
    const MODAL = document.getElementById('mp-modal');
    const CONTAINER = document.getElementById('mp-card-payment-brick-container');
    const ERROR_BOX = document.getElementById('mp-error-msg');
    const LOADING = document.getElementById('mp-loading-msg');
    const SUBMIT_FORM = document.getElementById('mp-submit-form');
    const CLOSE_BTN = document.getElementById('mp-modal-close');
    const PLAN_NAME_EL = document.getElementById('mp-plan-name');
    const PLAN_PRICE_EL = document.getElementById('mp-plan-price');
    const PLAN_SLUG_INPUT = document.getElementById('mp-plan-slug');
    const CARD_TOKEN_INPUT = document.getElementById('mp-card-token-id');
    const CSRF_INPUT = document.getElementById('mp-csrf-token');

    let mpInstance = null;
    let brickController = null;

    function showError(msg) {
        ERROR_BOX.textContent = msg;
        ERROR_BOX.style.display = 'block';
    }

    function clearError() {
        ERROR_BOX.style.display = 'none';
        ERROR_BOX.textContent = '';
    }

    function showModal() {
        MODAL.style.display = 'flex';
        clearError();
        LOADING.style.display = 'none';
    }

    function closeModal() {
        MODAL.style.display = 'none';
        clearError();
        if (brickController) {
            try { brickController.unmount(); } catch (e) {}
            brickController = null;
        }
    }

    CLOSE_BTN.addEventListener('click', closeModal);
    MODAL.addEventListener('click', function(e) {
        if (e.target === MODAL) closeModal();
    });

    async function openBrick(slug, planName, planPrice, planAmount, payerEmail) {
        PLAN_NAME_EL.textContent = planName;
        PLAN_PRICE_EL.textContent = planPrice;
        PLAN_SLUG_INPUT.value = slug;

        showModal();
        clearError();
        CONTAINER.innerHTML = '';
        LOADING.style.display = 'block';

        try {
            console.log('[MP] creating MercadoPago instance, public key length:', <?= json_encode(strlen($mpPublicKey)) ?>);
            if (!mpInstance) {
                mpInstance = new MercadoPago(<?= json_encode($mpPublicKey) ?>, {
                    locale: 'pt-BR',
                    advancedFraudPrevention: true,
                });
            }
            console.log('[MP] MercadoPago instance created, calling bricks().create()...');
            console.log('[MP] init amount:', planAmount, '| email:', payerEmail);

            const settings = {
                initialization: {
                    amount: planAmount,
                    payer: {
                        email: payerEmail,
                    },
                },
                callbacks: {
                    onReady: function() {
                        console.log('[MercadoPago Brick] onReady fired - Brick initialized successfully');
                        LOADING.style.display = 'none';
                    },
                    onError: function(error) {
                        var mpErrType = (error && error.constructor && error.constructor.name) ? error.constructor.name : (typeof error);
                        var mpErrMsg = (error && error.message) ? error.message : String(error);
                        var mpErrCode = (error && error.error) ? error.error : '';
                        console.error('[MercadoPago Brick onError]', {
                            type: mpErrType,
                            message: mpErrMsg,
                            code: mpErrCode
                        });
                        clearError();
                        showError('Erro ao processar cartão. Tente novamente.');
                    },
                    onSubmit: function(cardData) {
                        if (!cardData || !cardData.token) {
                            showError(' tokenização inválida. Recarregue e tente novamente.');
                            return;
                        }
                        LOADING.style.display = 'block';
                        CARD_TOKEN_INPUT.value = cardData.token;
                        CSRF_INPUT.value = document.querySelector('[data-csrf-token]') ? document.querySelector('[data-csrf-token]').dataset.csrfToken : '';
                        SUBMIT_FORM.submit();
                    },
                },
            };

            brickController = await mpInstance.bricks().create('cardPayment', 'mp-card-payment-brick-container', settings);
        } catch (err) {
            var catchErrType = (err && err.constructor && err.constructor.name) ? err.constructor.name : (typeof err);
            var catchErrMsg = (err && err.message) ? err.message : String(err);
            console.error('[MercadoPago Brick outer catch]', {
                type: catchErrType,
                message: catchErrMsg
            });
            LOADING.style.display = 'none';
            clearError();
            showError('Não foi possível iniciar o pagamento. Recarregue a página e tente novamente.');
        }
    }

    document.querySelectorAll('.btn-mp-subscribe').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const slug = btn.dataset.planSlug;
            const name = btn.dataset.planName;
            const price = btn.dataset.planPrice;
            const amount = parseFloat(btn.dataset.planAmount);
            const email = btn.dataset.userEmail;
            openBrick(slug, name, price, amount, email);
        });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

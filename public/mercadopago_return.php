<?php
/**
 * Mercado Pago Return — pagina para onde o MP redireciona apos o checkout.
 *
 * FLUXO:
 *  1. Antes do redirect (action=subscribe): cria pending local com user_id + plan_slug
 *  2. Usuario paga no checkout do MP
 *  3. MP redireciona para ca com ?preapproval_id=...
 *  4. Este arquivo: reconcilia via API do MP (fonte confiavel)
 *     a. preapproval_plan_id valido contra .env
 *     b. Busca pending local por user_id + plan_slug
 *     c. Vincula preapproval_id ao pending
 *     d. Ativa plano se status=authorized
 *
 * SEGURANCA:
 *  - NUNCA ativa plano apenas com parametros da URL.
 *  - Consulta sempre a API do MP antes de qualquer modificacao.
 *  - Valida preapproval_plan_id contra .env.
 *  - Usuario precisa estar autenticado.
 *
 * CONDICAO DE CORRIDA:
 *  - Webhook pode chegar ANTES do return.
 *  - Webhook nao encontra por mp_preapproval_id (ainda NULL no pending).
 *  - Webhook retorna 200 sem alterar nada.
 *  - Return faz a reconciliacao normalmente.
 */

require_once __DIR__ . '/../src/config/config.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_VERCEL_FORWARDED_PROTO'] ?? '') === 'https')
    || (getenv('VERCEL_ENV') !== false);

$lifetime = 604800;
if (PHP_VERSION_ID >= 70300 && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
if (session_status() === PHP_SESSION_NONE) {
    try {
        $db = getDBConnection();
        require_once __DIR__ . '/../src/config/session_handler.php';
        $handler = new DbSessionHandler($db, $lifetime);
        session_set_save_handler($handler, true);
    } catch (Throwable $e) {
        error_log('[mercadopago_return] session: ' . $e->getMessage());
        ini_set('session.gc_maxlifetime', (string)$lifetime);
    }
    session_start();
}

require_once __DIR__ . '/../src/helpers/csrf.php';
require_once __DIR__ . '/partials/icons.php';
require_once __DIR__ . '/../src/services/MercadoPagoService.php';
require_once __DIR__ . '/../src/services/MercadoPagoWebhookService.php';
require_once __DIR__ . '/../src/services/SubscriptionReconciler.php';
require_once __DIR__ . '/../src/models/Plan.php';
require_once __DIR__ . '/../src/models/Subscription.php';

$isLoggedIn = isset($_SESSION['user_id']);
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);

$userName  = $_SESSION['user_name']  ?? 'Usuário';
$userEmail = $_SESSION['user_email'] ?? '';

$pageTitle    = 'Retorno do Pagamento';
$pageSubtitle = 'Aguarde enquanto verificamos sua assinatura.';
$activeMenu   = 'meu_plano';
$showPeriodPicker = false;

$mpStatus = $_GET['status'] ?? null;
$mpCollectionId = $_GET['collection_id'] ?? null;
$mpCollectionStatus = $_GET['collection_status'] ?? null;
$mpPreferenceId = $_GET['preference_id'] ?? null;
$mpExternalRef = $_GET['external_reference'] ?? null;
$mpPreapprovalId = $_GET['preapproval_id'] ?? null;

$diagnosticoSeguro = [];
if ($mpStatus !== null) {
    $diagnosticoSeguro['status'] = htmlspecialchars($mpStatus, ENT_QUOTES, 'UTF-8');
}
if ($mpCollectionId !== null) {
    $diagnosticoSeguro['collection_id'] = htmlspecialchars($mpCollectionId, ENT_QUOTES, 'UTF-8');
}
if ($mpCollectionStatus !== null) {
    $diagnosticoSeguro['collection_status'] = htmlspecialchars($mpCollectionStatus, ENT_QUOTES, 'UTF-8');
}
if ($mpPreferenceId !== null) {
    $diagnosticoSeguro['preference_id'] = htmlspecialchars($mpPreferenceId, ENT_QUOTES, 'UTF-8');
}
if ($mpExternalRef !== null) {
    $diagnosticoSeguro['external_reference'] = htmlspecialchars($mpExternalRef, ENT_QUOTES, 'UTF-8');
}
if ($mpPreapprovalId !== null) {
    $diagnosticoSeguro['preapproval_id'] = htmlspecialchars($mpPreapprovalId, ENT_QUOTES, 'UTF-8');
}

$reconcileResult = null;
if ($isLoggedIn && $sessionUserId > 0 && $mpPreapprovalId !== null) {
    if (preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', (string)$mpPreapprovalId)) {
        try {
            $db = getDBConnection();
            $mpService = new MercadoPagoService();
            $reconciler = new SubscriptionReconciler($db, $mpService);
            $reconcileResult = $reconciler->reconcileFromReturn(
                (string)$mpPreapprovalId,
                $sessionUserId
            );
        } catch (Throwable $e) {
            error_log('[mercadopago_return] reconciliacao: ' . $e->getMessage());
        }
    }
}
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

<section class="panel" style="margin-bottom:var(--space-5);text-align:center;padding:var(--space-6) var(--space-5)">
    <?php
    $showSuccess = $reconcileResult !== null
        && ($reconcileResult['ok'] ?? false) === true
        && in_array($reconcileResult['action'] ?? '', ['created', 'activated', 'already_linked', 'updated'], true);
    $showPending = $reconcileResult !== null
        && ($reconcileResult['ok'] ?? false) === false
        && ($reconcileResult['action'] ?? '') === 'not_authorized';
    $showError = $reconcileResult !== null
        && ($reconcileResult['ok'] ?? false) === false
        && ($reconcileResult['action'] ?? '') === 'transient_error';
    $showMismatch = $reconcileResult !== null
        && ($reconcileResult['ok'] ?? false) === false
        && in_array($reconcileResult['action'] ?? '', ['unknown_plan', 'plan_mismatch', 'user_mismatch', 'user_not_found'], true);
    ?>
    <?php if ($showSuccess): ?>
    <div style="margin:0 auto var(--space-5);width:72px;height:72px;border-radius:50%;background:rgba(34,197,94,0.1);border:2px solid rgba(34,197,94,0.25);display:flex;align-items:center;justify-content:center">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
    </div>
    <h2 style="font-size:20px;font-weight:700;color:var(--color-text-1);margin-bottom:var(--space-3);letter-spacing:-.02em">
        Assinatura ativada!
    </h2>
    <p style="font-size:14px;color:var(--color-text-2);max-width:420px;margin:0 auto var(--space-5);line-height:1.6">
        Sua assinatura foi confirmada e ativada com sucesso.<br>
        Aproveite todos os recursos do seu plano.
    </p>
    <?php elseif ($showPending): ?>
    <div style="margin:0 auto var(--space-5);width:72px;height:72px;border-radius:50%;background:rgba(245,158,11,0.1);border:2px solid rgba(245,158,11,0.25);display:flex;align-items:center;justify-content:center">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
        </svg>
    </div>
    <h2 style="font-size:20px;font-weight:700;color:var(--color-text-1);margin-bottom:var(--space-3);letter-spacing:-.02em">
        Assinatura em processamento
    </h2>
    <p style="font-size:14px;color:var(--color-text-2);max-width:420px;margin:0 auto var(--space-5);line-height:1.6">
        Sua assinatura esta sendo processada pelo Mercado Pago.<br>
        O plano sera ativado automaticamente quando o pagamento for confirmado.
    </p>
    <?php elseif ($showError): ?>
    <div style="margin:0 auto var(--space-5);width:72px;height:72px;border-radius:50%;background:rgba(239,68,68,0.1);border:2px solid rgba(239,68,68,0.25);display:flex;align-items:center;justify-content:center">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
    </div>
    <h2 style="font-size:20px;font-weight:700;color:var(--color-text-1);margin-bottom:var(--space-3);letter-spacing:-.02em">
        Problema de conexao
    </h2>
    <p style="font-size:14px;color:var(--color-text-2);max-width:420px;margin:0 auto var(--space-5);line-height:1.6">
        Nao foi possivel verificar sua assinatura agora.<br>
        A verificacao ocorrera automaticamente em breve.
    </p>
    <?php else: ?>
    <div style="margin:0 auto var(--space-5);width:72px;height:72px;border-radius:50%;background:rgba(245,158,11,0.1);border:2px solid rgba(245,158,11,0.25);display:flex;align-items:center;justify-content:center">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
        </svg>
    </div>

    <h2 style="font-size:20px;font-weight:700;color:var(--color-text-1);margin-bottom:var(--space-3);letter-spacing:-.02em">
        Verificando sua assinatura
    </h2>
    <p style="font-size:14px;color:var(--color-text-2);max-width:420px;margin:0 auto var(--space-5);line-height:1.6">
        Recebemos seu retorno do Mercado Pago.<br>
        <?php if ($isLoggedIn && $sessionUserId > 0 && $mpPreapprovalId !== null): ?>
        Sua assinatura esta sendo verificada automaticamente.
        <?php else: ?>
        A verificacao pode levar alguns minutos.<br>
        <?php if (!$isLoggedIn): ?>Faça login para acompanhar.<?php endif; ?>
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <?php if (!empty($diagnosticoSeguro)): ?>
    <div style="background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:10px;padding:var(--space-3) var(--space-4);margin-bottom:var(--space-5);text-align:left;max-width:380px;margin-left:auto;margin-right:auto">
        <div style="font-size:11px;font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-2)">
            Informações do retorno
        </div>
        <?php foreach ($diagnosticoSeguro as $chave => $valor): ?>
            <div style="display:flex;gap:8px;font-size:13px;margin-bottom:4px">
                <span style="color:var(--color-text-3);min-width:130px"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $chave))) ?>:</span>
                <span style="color:var(--color-text-1);font-weight:500;word-break:break-all"><?= $valor ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;flex-direction:column;gap:var(--space-3);align-items:center;max-width:320px;margin:0 auto">
        <a
            href="/index.php?action=meu_plano"
            class="btn"
            style="width:100%;justify-content:center;text-decoration:none;display:flex;align-items:center;gap:8px"
            title="Voltar para Meu Plano">
            <?= render_icon('star', 15) ?>
            <?php if ($showSuccess): ?>
            Ver meu plano
            <?php else: ?>
            Voltar para Meu Plano
            <?php endif; ?>
        </a>

        <?php if (!$isLoggedIn): ?>
        <a
            href="/index.php?action=login"
            class="btn btn-secondary"
            style="width:100%;justify-content:center;text-decoration:none;display:flex;align-items:center;gap:8px"
            title="Fazer login">
            <?= render_icon('arrow-right', 15) ?>
            Fazer login
        </a>
        <?php endif; ?>
    </div>

    <p style="font-size:12px;color:var(--color-text-3);margin-top:var(--space-5);line-height:1.6;max-width:380px;margin-left:auto;margin-right:auto">
        Caso sua assinatura não seja atualizada em até <strong>24 horas</strong>,
        entre em contato com o suporte.
    </p>
</section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

<?php
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

$isLoggedIn = isset($_SESSION['user_id']);

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
        Sua assinatura está sendo processada e será atualizada automaticamente em breve.
    </p>

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
            Voltar para Meu Plano
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

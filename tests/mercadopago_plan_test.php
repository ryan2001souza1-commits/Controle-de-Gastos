<?php
/**
 * Testes do MercadoPagoService — novo fluxo de checkout via Preapproval Plan.
 *
 * TEST-MODE: usa vars de ambiente carregadas do .env.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$envFile = $ROOT . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (getenv($k) === false) putenv("$k=$v");
    }
}

require_once $ROOT . '/src/services/MercadoPagoService.php';

echo "=== MercadoPagoService — Preapproval Plan Flow ===\n\n";

// --- Teste 1: getPreapprovalPlan (PRO) ---
echo "--- getPreapprovalPlan (PRO) ---\n";
$mp = new MercadoPagoService();
$planIdPro = (string)(getenv('MERCADOPAGO_PLAN_ID_PRO') ?: '');
if ($planIdPro === '') {
    echo "  SKIP: MERCADOPAGO_PLAN_ID_PRO nao configurado\n";
} else {
    $resp = $mp->getPreapprovalPlan($planIdPro);
    echo "  http: " . ($resp['status'] ?? 0) . "\n";
    echo "  ok: " . ($resp['ok'] ? 'true' : 'false') . "\n";
    echo "  error: " . ($resp['error'] ?? 'none') . "\n";
    echo "  data.id: " . ($resp['data']['id'] ?? 'N/A') . "\n";
    echo "  data.reason: " . ($resp['data']['reason'] ?? 'N/A') . "\n";
    echo "  data.status: " . ($resp['data']['status'] ?? 'N/A') . "\n";
    echo "  data.init_point: " . ($resp['data']['init_point'] ?? '(ausente)') . "\n";
    echo "  data.sandbox_init_point: " . ($resp['data']['sandbox_init_point'] ?? '(ausente)') . "\n";
    echo "  data.auto_recurring.amount: " . ($resp['data']['auto_recurring']['transaction_amount'] ?? 'N/A') . "\n";
    if ($resp['ok']) {
        echo "  PASS\n";
    } else {
        echo "  FAIL: " . ($resp['error'] ?? 'unknown') . "\n";
    }
}

// --- Teste 2: getPreapprovalPlan (PREMIUM) ---
echo "\n--- getPreapprovalPlan (PREMIUM) ---\n";
$planIdPrem = (string)(getenv('MERCADOPAGO_PLAN_ID_PREMIUM') ?: '');
if ($planIdPrem === '') {
    echo "  SKIP: MERCADOPAGO_PLAN_ID_PREMIUM nao configurado\n";
} else {
    $resp = $mp->getPreapprovalPlan($planIdPrem);
    echo "  http: " . ($resp['status'] ?? 0) . "\n";
    echo "  ok: " . ($resp['ok'] ? 'true' : 'false') . "\n";
    echo "  data.reason: " . ($resp['data']['reason'] ?? 'N/A') . "\n";
    echo "  data.status: " . ($resp['data']['status'] ?? 'N/A') . "\n";
    echo "  data.init_point: " . ($resp['data']['init_point'] ?? '(ausente)') . "\n";
    echo "  data.auto_recurring.amount: " . ($resp['data']['auto_recurring']['transaction_amount'] ?? 'N/A') . "\n";
    if ($resp['ok']) {
        echo "  PASS\n";
    } else {
        echo "  FAIL: " . ($resp['error'] ?? 'unknown') . "\n";
    }
}

// --- Teste 3: getPlanCheckoutUrl (PRO) ---
echo "\n--- getPlanCheckoutUrl (PRO) ---\n";
if ($planIdPro === '') {
    echo "  SKIP\n";
} else {
    $url = $mp->getPlanCheckoutUrl($planIdPro);
    if ($url !== '' && str_starts_with($url, 'https://')) {
        echo "  URL: $url\n";
        echo "  PASS: URL valida obtida\n";
    } else {
        echo "  FAIL: URL vazia ou invalida: '$url'\n";
    }
}

// --- Teste 4: getPlanCheckoutUrl com ID invalido ---
echo "\n--- getPlanCheckoutUrl (ID invalido) ---\n";
$url = $mp->getPlanCheckoutUrl('invalid_id_xyz');
if ($url === '') {
    echo "  PASS: retornou string vazia para ID invalido\n";
} else {
    echo "  FAIL: esperava vazia, recebeu: $url\n";
}

// --- Teste 5: isSandbox ---
echo "\n--- isSandbox ---\n";
$sandbox = $mp->isSandbox();
echo "  isSandbox: " . ($sandbox ? 'true' : 'false') . "\n";
$token = (string)(getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '');
if ($token !== '' && str_starts_with($token, 'TEST-')) {
    if ($sandbox) echo "  PASS: token TEST- detectado como sandbox\n";
    else echo "  FAIL: token TEST- mas isSandbox=false\n";
} else {
    echo "  INFO: token nao comeca com TEST-\n";
}

// --- Teste 6: URL contem external_reference appended ---
echo "\n--- URL checkout com external_reference ---\n";
if ($planIdPro !== '' && $resp['ok']) {
    $baseUrl = (string)($resp['data']['init_point'] ?? '');
    if ($baseUrl !== '') {
        $extRef = 'user_99_pro';
        $backUrl = 'https://example.com/mercadopago_return.php?ref=' . urlencode($extRef);
        $sep = (str_contains($baseUrl, '?')) ? '&' : '?';
        $fullUrl = $baseUrl . $sep . 'external_reference=' . urlencode($extRef) . '&back_url=' . urlencode($backUrl);
        echo "  fullUrl: $fullUrl\n";
        echo "  PASS: URL montada corretamente\n";
    }
}

echo "\n=== DONE ===\n";

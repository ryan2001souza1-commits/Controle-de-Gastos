<?php
/**
 * Mercado Pago Plan Diagnostic Script
 * 
 * READ-ONLY diagnostic tool to check PRO and PREMIUM plan configurations.
 * Only performs GET requests - no POST/modifications.
 * 
 * Usage: php mp_plan_diag.php
 *        MP_DIAG_SKIP_SSL=1 php mp_plan_diag.php  (if SSL cert errors locally)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

const ENV_FILE = __DIR__ . '/../.env';
const API_BASE = 'https://api.mercadopago.com';
const TIMEOUT  = 10;

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && !array_key_exists($key, $_ENV) && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

function maskId(string $id): string
{
    if (strlen($id) <= 4) {
        return str_repeat('*', strlen($id));
    }
    return substr($id, 0, 4) . str_repeat('*', strlen($id) - 4);
}

function maskToken(string $token): string
{
    if (strlen($token) <= 4) {
        return str_repeat('*', strlen($token));
    }
    return substr($token, 0, 4) . str_repeat('*', max(0, strlen($token) - 4));
}

function fetchPlan(string $token, string $planId): array
{
    $skipSsl = getenv('MP_DIAG_SKIP_SSL') === '1';
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => API_BASE . '/preapproval_plan/' . rawurlencode($planId),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: Controle-de-Gastos-diag/1.0',
        ],
        CURLOPT_SSL_VERIFYPEER => !$skipSsl,
        CURLOPT_SSL_VERIFYHOST => $skipSsl ? 0 : 2,
    ];
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($response === false) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'conexao: ' . $err];
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        $data = [];
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'status' => $httpCode, 'data' => $data, 'error' => null];
    }
    $errMsg = isset($data['message']) ? (string)$data['message'] : ('http ' . $httpCode);
    return ['ok' => false, 'status' => $httpCode, 'data' => $data, 'error' => $errMsg];
}

function extractPlanData(array $data): array
{
    $autoRecurring = $data['auto_recurring'] ?? [];

    return [
        'id' => maskId($data['id'] ?? ''),
        'status' => $data['status'] ?? 'unknown',
        'reason' => $data['reason'] ?? '',
        'auto_recurring' => [
            'transaction_amount' => $autoRecurring['transaction_amount'] ?? null,
            'currency_id'       => $autoRecurring['currency_id'] ?? null,
            'frequency'         => $autoRecurring['frequency'] ?? null,
            'frequency_type'    => $autoRecurring['frequency_type'] ?? null,
            'billing_day'       => $autoRecurring['billing_day'] ?? null,
            'free_trial'        => $autoRecurring['free_trial'] ?? null,
        ],
        'payment_methods_allowed' => $data['payment_methods_allowed'] ?? [],
    ];
}

function formatPlanInfo(array $planInfo): string
{
    $ar = $planInfo['auto_recurring'];
    $trial = $ar['free_trial'];
    $trialStr = $trial !== null && is_array($trial)
        ? "{$trial['frequency']} {$trial['frequency_type']}"
        : 'none';

    $pm = $planInfo['payment_methods_allowed'];
    if (empty($pm)) {
        $pmStr = 'all';
    } else {
        $excluded = $pm['excluded_payment_types'] ?? [];
        $pmStr = 'all';
        if (!empty($excluded)) {
            $names = array_map(fn($e) => $e['id'] ?? '?', $excluded);
            $pmStr .= ' (excluded: ' . implode(', ', $names) . ')';
        }
    }

    $output = [
        "  Status:      {$planInfo['status']}",
        "  Reason:      {$planInfo['reason']}",
        "  --- Auto Recurring ---",
        "    Amount:    {$ar['transaction_amount']} {$ar['currency_id']}",
        "    Frequency: {$ar['frequency']} {$ar['frequency_type']}",
        "    Billing:   day {$ar['billing_day']}",
        "    Trial:     $trialStr",
        "  --- Payment Methods ---",
        "    $pmStr",
    ];

    return implode("\n", $output);
}

loadEnv(ENV_FILE);

$accessToken = getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
$planIdPro = getenv('MERCADOPAGO_PLAN_ID_PRO') ?: '';
$planIdPremium = getenv('MERCADOPAGO_PLAN_ID_PREMIUM') ?: '';

echo "==============================================\n";
echo "  MERCADO PAGO PLAN DIAGNOSTIC\n";
echo "  READ-ONLY (GET only)\n";
echo "==============================================\n\n";

if ($accessToken === '') {
    echo "[ERROR] MERCADOPAGO_ACCESS_TOKEN not configured\n";
    exit(1);
}

echo "[INFO] Token: " . maskToken($accessToken) . " (" . (str_starts_with($accessToken, 'TEST-') ? 'SANDBOX' : 'PRODUCTION') . ")\n";
if (getenv('MP_DIAG_SKIP_SSL') === '1') {
    echo "[INFO] SSL verification disabled (MP_DIAG_SKIP_SSL=1)\n";
}
echo "\n";

$plans = [
    'PRO' => $planIdPro,
    'PREMIUM' => $planIdPremium,
];

foreach ($plans as $planName => $planId) {
    echo "----------------------------------------------\n";
    echo "  PLAN: $planName\n";
    echo "  ID:   " . maskId($planId) . "\n";
    echo "----------------------------------------------\n";

    if ($planId === '') {
        echo "[ERROR] MERCADOPAGO_PLAN_ID_$planName not configured\n\n";
        continue;
    }

    $result = fetchPlan($accessToken, $planId);

    if (!$result['ok']) {
        $statusCode = $result['status'];
        $errorMsg = $result['error'] ?? 'unknown error';
        echo "[HTTP $statusCode] $errorMsg\n";
        $hint = match (true) {
            $statusCode === 0    => 'connection error (SSL or DNS)',
            $statusCode === 401  => 'invalid token',
            $statusCode === 403  => 'insufficient permissions',
            $statusCode === 404  => 'plan not found',
            default              => '',
        };
        if ($hint !== '') echo "[HINT] $hint\n";
        echo "\n";
        continue;
    }

    $planData = extractPlanData($result['data']);
    echo formatPlanInfo($planData) . "\n\n";
}

echo "==============================================\n";
echo "  DIAGNOSTIC COMPLETE\n";
echo "==============================================\n";
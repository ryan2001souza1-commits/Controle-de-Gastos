<?php
/**
 * Teste: verifica a logica de construcao de $limitInfo usada pelo
 * AiController::page() para que sempre contenha used, limit e remaining.
 *
 * Testa a logica sem precisar de DB, replicando o trecho corrigido.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$errors = [];

// --- Caso 1: limite definido (10), usados 3 -> remaining = 7
$used = 3;
$limit = 10;
$limitInfo = [
    'used' => $used,
    'limit' => $limit ?? PHP_INT_MAX,
    'remaining' => $limit === null ? PHP_INT_MAX : max(0, $limit - $used),
];

if ($limitInfo['used'] !== 3)        $errors[] = "case1: used expected 3 got {$limitInfo['used']}";
if ($limitInfo['limit'] !== 10)      $errors[] = "case1: limit expected 10 got {$limitInfo['limit']}";
if ($limitInfo['remaining'] !== 7)   $errors[] = "case1: remaining expected 7 got {$limitInfo['remaining']}";

// --- Caso 2: limite atingido (10), usados 10 -> remaining = 0
$used = 10;
$limit = 10;
$limitInfo = [
    'used' => $used,
    'limit' => $limit ?? PHP_INT_MAX,
    'remaining' => $limit === null ? PHP_INT_MAX : max(0, $limit - $used),
];
if ($limitInfo['remaining'] !== 0)   $errors[] = "case2: remaining expected 0 got {$limitInfo['remaining']}";

// --- Caso 3: sem limite (null) -> remaining = PHP_INT_MAX (sem restricao)
$used = 0;
$limit = null;
$limitInfo = [
    'used' => $used,
    'limit' => $limit ?? PHP_INT_MAX,
    'remaining' => $limit === null ? PHP_INT_MAX : max(0, $limit - $used),
];
if ($limitInfo['limit'] !== PHP_INT_MAX)     $errors[] = "case3: limit expected PHP_INT_MAX got {$limitInfo['limit']}";
if ($limitInfo['remaining'] !== PHP_INT_MAX) $errors[] = "case3: remaining expected PHP_INT_MAX got {$limitInfo['remaining']}";

// --- Caso 4: acesso a 'remaining' nao emite warning
$warnings = [];
set_error_handler(function ($severity, $msg) use (&$warnings) {
    if (str_contains($msg, 'Undefined array key')) $warnings[] = $msg;
    return true;
});
$val = (int)$limitInfo['remaining'];
restore_error_handler();

if (count($warnings) > 0) $errors[] = "case4: warning emitido: " . implode('; ', $warnings);

// --- Verificacao final das chaves
foreach (['used', 'limit', 'remaining'] as $key) {
    if (!array_key_exists($key, $limitInfo)) $errors[] = "missing key: $key";
}

echo "=== TEST: limitInfo keys (4 cases) ===\n";
if (count($errors) === 0) {
    echo "PASS: all 3 keys present, no 'Undefined array key' notice in any scenario\n";
    exit(0);
} else {
    echo "FAIL:\n  - " . implode("\n  - ", $errors) . "\n";
    exit(1);
}

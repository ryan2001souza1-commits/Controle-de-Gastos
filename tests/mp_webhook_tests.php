<?php
/**
 * Testes do MercadoPagoWebhookService — validação dos cenários A-G.
 *
 * Execute: php tests/mp_webhook_tests.php
 *
 * Nota: este script não faz chamadas reais à API do Mercado Pago.
 * Para testar com a API real, remova o @skipBefore e forneça um
 * preapproval_id válido de uma assinatura de teste.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function assert_test(bool $condition, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } else {
        echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n";
        $failed++;
    }
}

function skip(string $name, string $reason): void
{
    global $skipped;
    echo "  \033[33m⊘\033[0m $name (SKIP: $reason)\n";
    $skipped++;
}

echo "\n=== TESTES: MercadoPagoWebhookService ===\n\n";

echo "--- parseExternalReference() ---\n";

[$uid, $slug] = MercadoPagoWebhookService::parseExternalReference('user_15_pro');
assert_test($uid === 15 && $slug === 'pro', 'user_15_pro → [15, pro]');

[$uid, $slug] = MercadoPagoWebhookService::parseExternalReference('user_999_premium');
assert_test($uid === 999 && $slug === 'premium', 'user_999_premium → [999, premium]');

[$uid, $slug] = MercadoPagoWebhookService::parseExternalReference('user_1_pro');
assert_test($uid === 1 && $slug === 'pro', 'user_1_pro → [1, pro]');

$result = MercadoPagoWebhookService::parseExternalReference('15');
assert_test($result === null, '15 → null (sem prefixo user_)');

$result = MercadoPagoWebhookService::parseExternalReference('user_15');
assert_test($result === null, 'user_15 → null (sem plano)');

$result = MercadoPagoWebhookService::parseExternalReference('user_15_gold');
assert_test($result === null, 'user_15_gold → null (plano invalido gold)');

$result = MercadoPagoWebhookService::parseExternalReference('user_abc_pro');
assert_test($result === null, 'user_abc_pro → null (ID nao numerico)');

$result = MercadoPagoWebhookService::parseExternalReference('user_0_pro');
assert_test($result === null, 'user_0_pro → null (ID zero)');

$result = MercadoPagoWebhookService::parseExternalReference('user__pro');
assert_test($result === null, 'user__pro → null (ID vazio)');

$result = MercadoPagoWebhookService::parseExternalReference('payer_123');
assert_test($result === null, 'payer_123 → null (prefixo invalido)');

$result = MercadoPagoWebhookService::parseExternalReference('USER_15_PRO');
assert_test($result === null, 'USER_15_PRO → null (case sensitive)');

$result = MercadoPagoWebhookService::parseExternalReference('');
assert_test($result === null, 'string vazia → null');

$result = MercadoPagoWebhookService::parseExternalReference('user_-1_pro');
assert_test($result === null, 'user_-1_pro → null (ID negativo)');

echo "\n--- mapMpStatusToInternal() ---\n";

assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('authorized') === 'active',
    'authorized → active'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('ACTIVE') === 'active',
    'ACTIVE (uppercase) → active'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('paused') === 'paused',
    'paused → paused'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('cancelled') === 'cancelled',
    'cancelled → cancelled'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('canceled') === 'cancelled',
    'canceled → cancelled'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('expired') === 'expired',
    'expired → expired'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('pending') === 'pending',
    'pending → pending'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('in_process') === 'pending',
    'in_process → pending'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('rejected') === 'rejected',
    'rejected → rejected'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('failure') === 'rejected',
    'failure → rejected'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('unknown_status') === null,
    'unknown_status → null (nao mapeado)'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('') === null,
    'string vazia → null'
);

echo "\n--- resolvePlanSlugFromMpPlanId() ---\n";

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_123');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_456');

assert_test(
    MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId('plan_pro_123') === 'pro',
    'plan_pro_123 → pro'
);
assert_test(
    MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId('plan_premium_456') === 'premium',
    'plan_premium_456 → premium'
);
assert_test(
    MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId('plan_gold_789') === null,
    'plan_gold_789 → null (nao reconhecido)'
);
assert_test(
    MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId('') === null,
    'string vazia → null'
);

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n--- extractPreapprovalId() ---\n";

$query = ['data_id' => 'abc123preapproval'];
$body = [];
$headers = [];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'abc123preapproval', 'query[data_id] extraido');

$query = ['data' => ['id' => 'def456preapproval']];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'def456preapproval', 'query[data][id] extraido');

$query = ['id' => 'ghi789preapproval'];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'ghi789preapproval', 'query[id] extraido');

$query = [];
$body = ['data_id' => 'jkl012preapproval'];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'jkl012preapproval', 'body[data_id] extraido');

$query = [];
$body = ['id' => 'mno345preapproval'];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'mno345preapproval', 'body[id] extraido');

$query = [];
$body = [];
$headers = ['HTTP_X_DATA_ID' => 'pqr678preapproval'];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'pqr678preapproval', 'headers[HTTP_X_DATA_ID] extraido');

$query = [];
$body = [];
$headers = ['x-data-id' => 'stu901preapproval'];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'stu901preapproval', 'headers[x-data-id] extraido');

$query = ['id' => 'preapproval_valido_123'];
$body = [];
$headers = ['HTTP_X_DATA_ID' => 'outro_id'];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === 'outro_id', 'headers[HTTP_X_DATA_ID] tem prioridade sobre query[id]');

$query = [];
$body = [];
$headers = [];
$result = MercadoPagoWebhookService::extractPreapprovalId($query, $body, $headers);
assert_test($result === null, 'nenhum parametro → null');

echo "\n--- parseSignatureHeader() ---\n";

[$ts, $v1] = MercadoPagoWebhookService::parseSignatureHeader('ts=1745436000,v1=abc123def456');
assert_test($ts === '1745436000', 'ts extraido corretamente');
assert_test($v1 === 'abc123def456', 'v1 extraido corretamente');

[$ts, $v1] = MercadoPagoWebhookService::parseSignatureHeader('v1=xyz,v1=abc,ts=123');
assert_test($ts === '123', 'ts extraido quando vem depois de v1');
assert_test($v1 === 'abc', 'v1 extraido (ultimo valor quando duplicado)');

[$ts, $v1] = MercadoPagoWebhookService::parseSignatureHeader('');
assert_test($ts === null && $v1 === null, 'header vazio → [null, null]');

[$ts, $v1] = MercadoPagoWebhookService::parseSignatureHeader('malformed');
assert_test($ts === null && $v1 === null, 'header malformado → [null, null]');

[$ts, $v1] = MercadoPagoWebhookService::parseSignatureHeader('ts=1745436000');
assert_test($ts === '1745436000', 'ts presente, v1 ausente');
assert_test($v1 === null, 'v1 null quando ausente no header');

echo "\n--- validateSignature() ---\n";

$secret = 'my_webhook_secret_123';
$now = 1745436000;
assert_test(
    MercadoPagoWebhookService::validateSignature('', 'id123', '1745436000', 'any', $now) === false,
    'secret vazio → invalido'
);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, '', '1745436000', 'any', $now) === false,
    'id vazio → invalido'
);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'id123', '', 'any', $now) === false,
    'ts vazio → invalido'
);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'id123', 'abc', 'any', $now) === false,
    'ts nao numerico → invalido'
);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'id123', '1745436000', '', $now) === false,
    'v1 vazio → invalido'
);

$manifest = 'preapproval_test_123;1745436000';
$computedV1 = hash_hmac('sha256', $manifest, $secret);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', '1745436000', $computedV1, $now) === true,
    'HMAC correto → valido (timestamp atual)'
);
$computedV1Upper = strtoupper($computedV1);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', '1745436000', $computedV1Upper, $now) === true,
    'HMAC uppercase tambem aceito'
);

assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', '1745436000', 'wrong_hash', $now) === false,
    'HMAC errado → invalido'
);

assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', '1745436000', $computedV1, $now) === true,
    'timestamp dentro da tolerancia (0s diff) → valido'
);

$oldTs = (string)($now - 200);
$computedOld = hash_hmac('sha256', 'preapproval_test_123;' . $oldTs, $secret);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', $oldTs, $computedOld, $now) === true,
    'timestamp 200s no passado (dentro de 300s) → valido'
);

$veryOldTs = (string)($now - 400);
$computedVeryOld = hash_hmac('sha256', 'preapproval_test_123;' . $veryOldTs, $secret);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', $veryOldTs, $computedVeryOld, $now) === false,
    'timestamp 400s no passado (fora de 300s) → invalido'
);

$futureTs = (string)($now + 200);
$computedFuture = hash_hmac('sha256', 'preapproval_test_123;' . $futureTs, $secret);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', $futureTs, $computedFuture, $now) === true,
    'timestamp 200s no futuro (dentro de 300s) → valido'
);

$veryFutureTs = (string)($now + 400);
$computedVeryFuture = hash_hmac('sha256', 'preapproval_test_123;' . $veryFutureTs, $secret);
assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', $veryFutureTs, $computedVeryFuture, $now) === false,
    'timestamp 400s no futuro (fora de 300s) → invalido'
);

assert_test(
    MercadoPagoWebhookService::validateSignature($secret, 'preapproval_test_123', '1745436000', $computedV1, $now) === true,
    'HMAC valido com ID diferente → valido'
);

echo "\n--- Cenário C: inconsistencia de plano ---\n";
putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_123');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_456');

$refParsed = MercadoPagoWebhookService::parseExternalReference('user_15_pro');
assert_test($refParsed !== null, 'external_reference=user_15_pro parseado');

$planSlug = MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId('plan_premium_456');
assert_test($planSlug === 'premium', 'mp_plan_id corresponde a premium');

if ($refParsed && $planSlug) {
    [$userId, $expectedSlug] = $refParsed;
    $isMismatch = ($planSlug !== $expectedSlug);
    assert_test($isMismatch, 'pro vs premium = inconsistencia detectada');
}
putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed + $skipped;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m | \033[33mSkipped: $skipped\033[0m\n";
if ($failed > 0) {
    echo "\033[31mALGUNS TESTES FALHARAM!\033[0m\n";
    exit(1);
} else {
    echo "\033[32mTODOS OS TESTES PASSARAM!\033[0m\n";
    exit(0);
}

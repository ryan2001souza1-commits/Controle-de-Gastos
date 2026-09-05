<?php
/**
 * Testes de migrations: UNIQUE mp_preapproval_id e revisão de índices.
 */

$ROOT = dirname(__DIR__);

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name\n"; $failed++; }
}

$src = file_get_contents($ROOT . '/src/migrations.php');

echo "\n=== TESTES: migrations e indices subscriptions ===\n\n";

echo "--- MG01: UNIQUE mp_preapproval_id migration presente ---\n";
assert_test(
    strpos($src, 'uq_subscriptions_mp_preapproval_id') !== false,
    'MG01a: source contem nome do indice uq_subscriptions_mp_preapproval_id'
);
assert_test(
    strpos($src, "WHERE mp_preapproval_id IS NOT NULL") !== false,
    'MG01b: indice e partial (WHERE nao nulo e nao vazio)'
);
assert_test(
    strpos($src, "AND mp_preapproval_id <> ''") !== false,
    'MG01c: indice ignora strings vazias'
);

echo "\n--- MG02: migration verifica duplicatas antes de criar ---\n";
assert_test(
    strpos($src, "COUNT(*) > 1") !== false,
    'MG02a: query de duplicatas presente (HAVING COUNT > 1)'
);
assert_test(
    strpos($src, "GROUP BY mp_preapproval_id") !== false,
    'MG02b: GROUP BY mp_preapproval_id presente'
);
assert_test(
    strpos($src, "duplicatas") !== false || strpos($src, "duplicate") !== false,
    'MG02c: branch trata caso de duplicatas'
);

echo "\n--- MG03: indices atuais preservados ---\n";
assert_test(
    strpos($src, "idx_subscriptions_user_status") !== false,
    'MG03a: idx_subscriptions_user_status presente'
);
assert_test(
    strpos($src, "idx_subscriptions_status_renewal") !== false,
    'MG03b: idx_subscriptions_status_renewal presente'
);
assert_test(
    strpos($src, "idx_subscriptions_mp_id") !== false,
    'MG03c: idx_subscriptions_mp_id (nao-unique) presente'
);
assert_test(
    strpos($src, "idx_subscriptions_external_ref") !== false,
    'MG03d: idx_subscriptions_external_ref presente'
);

echo "\n--- MG04: idx_subscriptions_mp_id nao precisa ser dropado ---\n";
assert_test(
    strpos($src, "DROP INDEX IF EXISTS idx_subscriptions_mp_id") === false,
    'MG04a: idx_subscriptions_mp_id NAO e removido (mantido por compatibilidade)'
);

echo "\n--- MG05: nao criou UNIQUE em (user_id, status=active) ---\n";
$pattern = '/CREATE\s+UNIQUE\s+INDEX.*?\(\s*user_id.*?active/i';
assert_test(
    !preg_match($pattern, $src),
    'MG05a: NAO ha UNIQUE INDEX em (user_id, status=active) sem analise previa'
);

echo "\n--- MG06: indice UNIQUE e idempotente (IF NOT EXISTS) ---\n";
assert_test(
    strpos($src, "CREATE UNIQUE INDEX IF NOT EXISTS uq_subscriptions_mp_preapproval_id") !== false,
    'MG06a: usa IF NOT EXISTS (idempotente em deploys repetidos)'
);

echo "\n--- MG07: indice UNIQUE nao conflita com idx_subscriptions_mp_id ---\n";
$pattern = '/CREATE\s+UNIQUE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+uq_subscriptions_mp_preapproval_id[^;]*ON\s+subscriptions\s*\(\s*mp_preapproval_id/i';
assert_test(
    preg_match($pattern, $src) === 1,
    'MG07a: UNIQUE INDEX criado sobre mp_preapproval_id (mesma coluna do nao-unique existente)'
);

echo "\n--- MG08: validacao COALESCE no storeInitPoint ---\n";
$subSrc = file_get_contents($ROOT . '/src/models/Subscription.php');
assert_test(
    strpos($subSrc, "CONCAT(COALESCE(raw_status, '')") !== false,
    "MG08a: storeInitPoint usa COALESCE para evitar NULL"
);
assert_test(
    strpos($subSrc, "raw_status NOT LIKE '%|init:%'") !== false,
    'MG08b: storeInitPoint idempotente (NOT LIKE guarda)'
);

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

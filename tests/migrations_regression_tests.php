<?php
/**
 * Teste de regressao: a coluna mp_preapproval_id NAO pode ser removida
 * pelas migrations de limpeza. Se algum dia o dev reintroduzir o
 * DROP COLUMN IF EXISTS mp_preapproval_id, este teste falha.
 *
 * Cobre: src/migrations/remove_legacy_payment_gateways.php
 */

$ROOT = dirname(__DIR__);
$file = $ROOT . '/src/migrations/remove_legacy_payment_gateways.php';

$passed = 0;
$failed = 0;
function assert_test(bool $cond, string $name, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

if (!is_file($file)) {
    echo "\033[31mArquivo nao encontrado: $file\033[0m\n";
    exit(1);
}

$src = file_get_contents($file);

echo "\n=== REGRESSAO: mp_preapproval_id NAO pode ser removido por migration ===\n\n";

assert_test(
    !preg_match('/DROP\s+COLUMN\s+IF\s+EXISTS\s+"?mp_preapproval_id"?/i', $src),
    'remove_legacy_payment_gateways.php NAO contem DROP COLUMN mp_preapproval_id'
);

assert_test(
    !preg_match('/DROP\s+COLUMN\s+IF\s+EXISTS\s+`?mp_preapproval_id`?/i', $src),
    'remocao nao usa backticks tampouco'
);

assert_test(
    str_contains($src, 'mp_preapproval_id') && str_contains($src, 'NAO deve ser removido'),
    'Arquivo documenta explicitamente que mp_preapproval_id NAO deve ser removido'
);

$migrationsFile = $ROOT . '/src/migrations.php';
$migrationsSrc = file_get_contents($migrationsFile);
assert_test(
    str_contains($migrationsSrc, "ADD COLUMN IF NOT EXISTS mp_preapproval_id"),
    'migrations.php mantem o ADD COLUMN IF NOT EXISTS mp_preapproval_id'
);

assert_test(
    !preg_match('/DROP\s+COLUMN\s+IF\s+EXISTS\s+"?mp_preapproval_id"?/i', $migrationsSrc),
    'migrations.php nao contem DROP COLUMN mp_preapproval_id'
);

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

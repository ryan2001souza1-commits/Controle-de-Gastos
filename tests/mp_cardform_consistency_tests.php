<?php
/**
 * Testes de consistencia do CardForm (MP JS SDK x meu_plano.php).
 *
 * Regressao do incidente "campos desabilitados": garante que
 * - todo getElementById() do mp_subscribe.js existe no HTML;
 * - data-attributes lidos pelo JS existem na section;
 * - SDK oficial e carregado; amount real e fornecido;
 * - nenhum segredo (chave/token) e impresso em log/console.
 */

$ROOT = dirname(__DIR__);

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

$js = (string)file_get_contents($ROOT . '/public/js/mp_subscribe.js');
$view = (string)file_get_contents($ROOT . '/public/meu_plano.php');
$controller = (string)file_get_contents($ROOT . '/src/controllers/ProfileController.php');

echo "\n=== TESTES: consistencia CardForm ===\n\n";

echo "--- IDs referenciados pelo JS existem no HTML ---\n";
preg_match_all("/getElementById\('([^']+)'\)/", $js, $m);
$ids = array_unique($m[1]);
assert_test(count($ids) > 0, 'JS referencia IDs (' . count($ids) . ' encontrados)');
foreach ($ids as $id) {
    assert_test(
        str_contains($view, 'id="' . $id . '"'),
        "id \"$id\" existe em meu_plano.php"
    );
}

echo "\n--- data-attributes ---\n";
foreach (['data-mp-public-key', 'data-attempt-token', 'data-mp-amount'] as $attr) {
    assert_test(str_contains($view, $attr . '='), "view define $attr");
    assert_test(str_contains($js, $attr), "JS consome $attr");
}

echo "\n--- SDK oficial + amount real ---\n";
assert_test(str_contains($view, 'https://sdk.mercadopago.com/js/v2'), 'SDK oficial v2 carregado na view');
assert_test(str_contains($controller, "'amount'"), 'controller fornece amount a tentativa');
assert_test(str_contains($controller, 'numeric_price'), 'amount vem do catalogo do servidor');
assert_test(!str_contains($js, "amount: '0'"), 'amount zero removido do JS');

echo "\n--- sem vazamento de segredos ---\n";
assert_test(!str_contains($js, 'console.log'), 'JS sem console.log');
assert_test(strpos($js, 'innerHTML') === false, 'JS sem innerHTML');
assert_test(str_contains($js, 'removeAttribute'), 'JS remove data-attributes apos leitura');
assert_test(
    str_contains($view, "htmlspecialchars(\$mpPublicKey, ENT_QUOTES)"),
    'public key escapada com ENT_QUOTES'
);
// Diagnostico usa apenas prefixo/tipo: proibe imprimir a chave.
$leak = preg_match('/diagLine\s*\.\s*textContent\s*=\s*[^;]*PUBLIC_KEY[^;]*\+/', $js);
assert_test($leak === 0, 'diagnostico nunca concatena a chave');

echo "\n--- conformidade com formato documentado do CardForm ---\n";
foreach (['issuer', 'installments', 'identificationType', 'identificationNumber'] as $field) {
    assert_test(str_contains($js, $field . ':'), "CardForm configura '$field' (formato oficial)");
}
assert_test(str_contains($js, 'safeSerializeError'), 'serializador seguro de erros do SDK presente');
assert_test(str_contains($js, '[object-sem-campos-seguros]') || str_contains($js, 'message'), 'serializador extrai campos seguros');

echo "\n--- init robusta (anti-falha-silenciosa) ---\n";
assert_test(str_contains($js, 'new MercadoPago'), 'SDK inicializado via new MercadoPago');
assert_test(str_contains($js, 'cardForm = mp.cardForm'), 'CardForm inicializado');
assert_test(str_contains($js, 'onFormMounted'), 'callback onFormMounted presente');
assert_test(substr_count($js, 'showError(') >= 5, 'erros sao LOUD (>=5 pontos de erro visivel)');
assert_test(str_contains($js, 'boot('), 'boot com retry do SDK');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

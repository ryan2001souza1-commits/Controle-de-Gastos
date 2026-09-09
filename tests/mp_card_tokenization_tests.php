<?php
/**
 * MP Card Tokenization Tests — diagnostico seguro + normalizacao + CSP.
 *
 * - Logica pura via node (tests/mp_card_utils_test.js), sem rede/DOM.
 * - Assercoes estaticas: console seguro, locale, CSP, public key, fluxo.
 * Nenhuma chamada real, nenhum cartao real.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } else {
        echo "  \033[31m✗\033[0m $name\n";
        $failed++;
    }
}

echo "\n=== MP CARD TOKENIZATION TESTS ===\n\n";

echo "-- logica pura (node) --\n";
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  [SKIP] node indisponivel — logica pura nao executada\n";
} else {
    $out = (string)shell_exec('node ' . escapeshellarg($ROOT . '/tests/mp_card_utils_test.js') . ' 2>&1');
    echo $out;
    $nodeFail = 1;
    if (preg_match('/Failed: (\d+)/', $out, $m)) {
        $nodeFail = (int)$m[1];
    }
    assert_test($nodeFail === 0 && str_contains($out, 'TOTAL:'), 'CTN01: harness node de normalizacao/codigos verde');
}

echo "\n-- diagnostico seguro no JS --\n";
$js = (string)file_get_contents($ROOT . '/public/js/subscribe.js');
$utils = (string)file_get_contents($ROOT . '/public/js/mp-card-utils.js');
assert_test(str_contains($js, '[mp-tokenization] code='), 'CTN02: console registra SOMENTE o codigo ([mp-tokenization] code=)');
assert_test(preg_match_all('/console\.(log|info|warn|error)\s*\(/', $js, $mm) === 2, 'CTN03: exatamente 2 consoles, ambos so com codigo');
// Nenhum console recebe objeto/erro/token/chave/cartao.
$consoleLines = [];
foreach (explode("\n", $js) as $line) {
    if (str_contains($line, 'console.')) {
        $consoleLines[] = $line;
    }
}
$consoleClean = true;
foreach ($consoleLines as $line) {
    if (preg_match('/console\.\w+\s*\(\s*(e|err|error|token|card|key|resp|data)\b/i', $line)) {
        $consoleClean = false;
    }
}
assert_test($consoleClean && count($consoleLines) === 2, 'CTN04: nenhum console com erro/token/cartao/chave');
assert_test(str_contains($js, "locale: 'pt-BR'") && str_contains($js, 'new window.MercadoPago(publicKey(),'), 'CTN05: instancia com locale pt-BR');
assert_test(str_contains($js, 'normMonth') && str_contains($js, 'normYear'), 'CTN06: submit usa normalizacao de mes/ano');
assert_test(str_contains($js, 'Verifique os dados digitados no cartão.'), 'CTN07: erro local tem mensagem propria');
assert_test(str_contains($js, 'friendlySdkMessage(code)'), 'CTN08: erro SDK tem mensagem propria por codigo');
assert_test(str_contains($js, 'window.MpCardUtils'), 'CTN09: subscribe.js usa os utils testaveis');
assert_test(
    str_contains($utils, 'normalizeMonth') && str_contains($utils, 'normalizeYear')
    && str_contains($utils, 'extractSafeCode') && str_contains($utils, 'module.exports'),
    'CTN10: utils expoem as 3 funcoes puras (UMD)'
);

echo "\n-- public key e CSP --\n";
$meuPlano = (string)file_get_contents($ROOT . '/public/meu_plano.php');
assert_test(str_contains($meuPlano, 'data-pk-configured'), 'CTN11: diagnostico MERCADOPAGO_PUBLIC_KEY_CONFIGURED=yes/no no DOM');
assert_test(!preg_match('/console\.\w+.*publicKey/i', $js) && !preg_match('/console\.\w+.*data-public-key/i', $js), 'CTN12: public key nunca logada');
$vercel = (string)file_get_contents($ROOT . '/vercel.json');
if (!preg_match('/"Content-Security-Policy", "value": "([^"]+)"/', $vercel, $m)) {
    assert_test(false, 'CTN13: CSP encontrada no vercel.json');
    $csp = '';
} else {
    // JSON escapa "/" como "\/": normaliza antes de comparar hosts.
    $csp = str_replace('\\/', '/', $m[1]);
    assert_test(true, 'CTN13: CSP encontrada no vercel.json');
}
assert_test(str_contains($csp, 'https://sdk.mercadopago.com'), 'CTN14: CSP libera script SDK oficial');
assert_test(str_contains($csp, 'https://api.mercadopago.com'), 'CTN15: CSP libera API/tokenizacao oficial');
assert_test(str_contains($csp, 'https://content.mercadopago.com'), 'CTN16: CSP libera fingerprint oficial');
assert_test(!str_contains($csp, '*') && preg_match('/\bhttps:(?!\/\/)/', $csp) !== 1, 'CTN17: CSP sem wildcard amplo (*, https: solto, *.dominio)');
assert_test(str_contains($csp, "script-src 'self'"), 'CTN18: CSP continua restritiva (self)');

echo "\n-- fluxo sucesso preservado --\n";
$posUtils = strpos($meuPlano, 'mp-card-utils.js');
$posSub = strpos($meuPlano, '/js/subscribe.js');
assert_test($posUtils !== false && $posSub !== false && $posUtils < $posSub, 'CTN19: template carrega utils ANTES do subscribe.js');
assert_test(str_contains($meuPlano, 'mp-card-form') && str_contains($meuPlano, 'sdk.mercadopago.com/js/v2'), 'CTN20: form + SDK oficial presentes quando habilitado');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

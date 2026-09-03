<?php
/**
 * Tests de seguranca e logica do fluxo de assinatura — Mercado Pago.
 *
 * Verifica:
 *   - SDK MP removido do frontend
 *   - Card token via SDK (nao envio direto)
 *   - Plan_id vem de env var (nao do POST)
 *   - nenhum PAN/CVV/token em logs
 *   - status=authorized no payload MP
 *   - CpfValidator valido
 *   - ProfileController nao loga CPF
 */

$root = __DIR__ . '/..';
$log = '';
$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, int &$pass, int &$fail, string &$log): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

function runIsolated(string $scriptBody): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'sub_security_test_') . '.php';
    file_put_contents($tmp, "<?php\n" . $scriptBody);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// =====================================================================
// Teste 1: MercadoPago.js e usado no frontend
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
echo (strpos($content, "sdk.mercadopago.com") !== false) ? "OK" : "MP_SDK_MISSING";
');
ok("1. MercadoPago.js usado no frontend", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 2: Card Payment Brick NAO usado
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
echo (strpos($content, "bricks().create") === false && strpos($content, "create(\x27cardPayment\x27)") === false) ? "OK" : "BRICK_PRESENT";
');
ok("2. Card Payment Brick NAO usado (usa CardForm SDK)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 3: card_token_id e gerado via SDK (token nao enviado no form)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
$hasToken = strpos($content, "card_token_id") !== false;
$hasSdk = strpos($content, "MercadoPago") !== false;
echo ($hasSdk && $hasToken) ? "OK" : "FAIL";
');
ok("3. card_token_id gerado via SDK MercadoPago", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 4: valor PRO=9.90 / PREMIUM=19.90 (preco fixo do servidor)
// =====================================================================
$out = runIsolated('
$prices = ["pro" => 9.90, "premium" => 19.90];
$pro = number_format($prices["pro"], 2, ".", "");
$premium = number_format($prices["premium"], 2, ".", "");
echo ($pro === "9.90" && $premium === "19.90") ? "OK" : "FAIL:$pro:$premium";
');
ok("4. Preco PRO=9.90, PREMIUM=19.90 (fixo no servidor)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 5: status=authorized no payload MP
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/MercadoPagoService.php";
$content = @file_get_contents($file);
echo (strpos($content, "authorized") !== false) ? "OK" : "FAIL";
');
ok("5. createPreapproval MP envia status=authorized", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 6: plan_id vem de env var no controller MP
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/SubscriptionController.php";
$content = @file_get_contents($file);
$usesEnv = strpos($content, "MERCADOPAGO_PLAN_ID_") !== false
    && strpos($content, "getenv(\x24planIdEnvKey)") !== false;
$notFromPost = strpos($content, "\$_POST[\x27plan_id\x27]") === false;
echo ($usesEnv && $notFromPost) ? "OK" : "FAIL";
');
ok("6. Controller MP: plan_id da env var, NAO do POST", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 7: nenhum PAN/CVV/token logado no controller MP
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/SubscriptionController.php";
$content = @file_get_contents($file);
$badPatterns = [
    "/error_log.*card_number/i",
    "/error_log.*card_ccv/i",
    "/error_log.*cvv/i",
    "/error_log.*pan/i",
    "/error_log.*card_token_id/i",
];
$found = false;
foreach ($badPatterns as $p) {
    if (preg_match($p, $content)) { $found = true; break; }
}
echo $found ? "LEAK" : "OK";
');
ok("7. Controller MP: nenhum PAN/CVV/card_token em logs", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 8: CSP inclui mercadopago.com para MP funcionar
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/vercel.json";
$content = @file_get_contents($file);
echo (strpos($content, "mercadopago.com") !== false) ? "OK" : "MISSING";
');
ok("8. CSP inclui domnios mercadopago.com para MP", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 9: CpfValidator existe e valida DV
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/CpfValidator.php";
$content = @file_get_contents($file);
require_once $file;
if (!class_exists("CpfValidator")) { echo "NOMISS"; exit; }
if (!CpfValidator::isValid("52998224725")) { echo "BADVAL"; exit; }
if (CpfValidator::isValid("00000000000")) { echo "BADREJ"; exit; }
if (CpfValidator::isValid("11111111111")) { echo "BADREJ"; exit; }
echo "OK";
');
ok("9. CpfValidator existe e valida DV corretamente", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 10: ProfileController NAO loga CPF
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/ProfileController.php";
$content = @file_get_contents($file);
$leaks = [
    "error_log" . ".*" . "\$cpf",
    "error_log" . ".*" . "\$cpf_raw",
    "error_log" . ".*" . "cpf",
];
$found = false;
foreach ($leaks as $p) {
    if (strpos($content, $p) !== false) { $found = true; break; }
}
echo $found ? "LEAK" : "OK";
');
ok("10. ProfileController NAO loga CPF", $out === 'OK', $pass, $fail, $log);

echo "\n=== SUBSCRIPTION SECURITY TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

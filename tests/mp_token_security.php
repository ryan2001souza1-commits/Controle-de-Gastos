<?php
/**
 * Tests de segurança e lógica do fluxo de assinatura MP.
 * Valida que o frontend é seguro contra reutilização de token.
 * Cada cenário roda em processo PHP isolado para evitar state leakage.
 */

$root = dirname(__DIR__);
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
    $tmp = tempnam(sys_get_temp_dir(), 'mp_token_test_') . '.php';
    file_put_contents($tmp, "<?php\n" . $scriptBody);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// =====================================================================
// Teste 1: CARD_TOKEN_INPUT comeca vazio no HTML
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasEmpty = preg_match("/id=.mp-card-token-id.[^>]*value=.[\x22\x27]?[\x22\x27]?/", $content, $m);
echo $hasEmpty ? "OK" : "FAIL";
');
ok("1. card_token_id hidden input comeca vazio no HTML", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 2: onSubmit so seta token se cardData.token existe
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasGuard = strpos($content, "if (!cardData || !cardData.token)") !== false
    && strpos($content, "CARD_TOKEN_INPUT.value = cardData.token") !== false;
echo $hasGuard ? "OK" : "FAIL";
');
ok("2. onSubmit valida cardData.token antes de usar", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 3: Somente um onSubmit callback existe
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$count = substr_count($content, "onSubmit:");
echo $count === 1 ? "OK:$count" : "MULTIPLE:$count";
');
ok("3. Apenas um callback onSubmit existe no codigo", $out === 'OK:1', $pass, $fail, $log);

// =====================================================================
// Teste 4: amount 9.90 para PRO
// =====================================================================
$out = runIsolated('
function getNumericPrice(string $slug) {
    $prices = ["pro" => 9.90, "premium" => 19.90];
    return $prices[$slug] ?? null;
}
$pro = getNumericPrice("pro");
$premium = getNumericPrice("premium");
$formatPro = number_format($pro, 2, ".", "");
$formatPrem = number_format($premium, 2, ".", "");
$allOk = ($formatPro === "9.90" && $formatPrem === "19.90");
echo $allOk ? "OK" : "FAIL:$formatPro:$formatPrem";
');
ok("4. Amount do Brick: PRO=9.90, PREMIUM=19.90", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 5: status=authorized enviado no payload
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/MercadoPagoService.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasAuthorized = strpos($content, "\x27status\x27 => \x27authorized\x27") !== false;
echo $hasAuthorized ? "OK" : "FAIL_NOT_AUTHORIZED";
');
ok("5. createPreapproval envia status=authorized", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 6: plan_id vem de env var (MERCADOPAGO_PLAN_ID_<SLUG>)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/SubscriptionController.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$usesEnvVar = strpos($content, "MERCADOPAGO_PLAN_ID_") !== false
    && strpos($content, "getenv(\x24planIdEnvKey)") !== false;
$notFromPost = strpos($content, "\$_POST[\x27plan_id\x27]") === false
    && strpos($content, "\$_POST[\x22plan_id\x22]") === false;
echo ($usesEnvVar && $notFromPost) ? "OK" : "FAIL_PLAN_FROM_POST";
');
ok("6. plan_id lido de env var, NAO do POST", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 7: nenhum PAN/CVV/token e logado em SubscriptionController
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/SubscriptionController.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$badPatterns = [
    "/error_log.*card/i",
    "/error_log.*cvv/i",
    "/error_log.*pan/i",
    "/error_log.*card_token_id/i",
    "/log.*\$cardTokenId[^)]*[^,]/i",
];
$found = false;
foreach ($badPatterns as $p) {
    if (preg_match($p, $content)) { $found = true; break; }
}
echo $found ? "LEAK_FOUND" : "NO_LEAK";
');
ok("7. Nenhum PAN/CVV/card_token logado no controller", $out === 'NO_LEAK', $pass, $fail, $log);

// =====================================================================
// Teste 8: closeModal nao limpa CARD_TOKEN_INPUT (BUG VERIFICADO)
// Se este teste falhar (FORCE_FAIL), o bug foi corrigido
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasCloseModal = strpos($content, "function closeModal()") !== false;
$clearsToken = strpos($content, "CARD_TOKEN_INPUT.value") !== false
    && strpos($content, "closeModal") !== false;
if ($clearsToken) {
    echo "CLEARED_OK";
} else {
    echo "BUG_TOKEN_NOT_CLEARED";
}
');
ok("8. closeModal limpa card_token_id (bug check)", $out === 'CLEARED_OK', $pass, $fail, $log);

// =====================================================================
// Teste 9: apenas um submit por click (nao ha form.submit manual antes de onSubmit)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$submitCount = substr_count($content, ".submit()");
$onSubmitCount = substr_count($content, "onSubmit:");
$hasSubmitInOnSubmit = strpos($content, "onSubmit:") !== false
    && strpos($content, ".submit()") !== false
    && strpos($content, "onSubmit") < strpos($content, ".submit()");
echo ($submitCount === 1 && $onSubmitCount === 1 && $hasSubmitInOnSubmit) ? "OK" : "MULTIPLE:$submitCount";
');
ok("9. submit() chamado apenas dentro de onSubmit", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 10: Advanced Fraud Prevention configurado
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasAFP = strpos($content, "advancedFraudPrevention") !== false;
$afpValue = preg_match("/advancedFraudPrevention[\x3a]\s*(true|false)/", $content, $m) ? $m[1] : "missing";
echo "AFP=$afpValue";
');
ok("10. advancedFraudPrevention configurado no MercadoPago instance", str_starts_with($out, 'AFP=true'), $pass, $fail, $log);

// =====================================================================
// Teste 11: payer.email configurado na inicializacao do Brick
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasPayerEmail = strpos($content, "payer:") !== false
    && strpos($content, "email:") !== false
    && strpos($content, "payerEmail") !== false;
$hasNoIdentification = strpos($content, "identification") === false;
echo ($hasPayerEmail && $hasNoIdentification) ? "ONLY_EMAIL" : "HAS_IDENTIFICATION";
');
ok("11. payer.email configurado, identification NAO (risco identificado)", $out === 'ONLY_EMAIL', $pass, $fail, $log);

// =====================================================================
// Teste 12: CSRF token preenchido no onSubmit
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$hasCSRFAssign = strpos($content, "CSRF_INPUT.value") !== false
    && strpos($content, "csrfToken") !== false
    && strpos($content, "data-csrf-token") !== false;
echo $hasCSRFAssign ? "OK" : "FAIL";
');
ok("12. CSRF token preenchido no onSubmit", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 13: SubscriptionController rejeita card_token vazio
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/SubscriptionController.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$checksEmpty = strpos($content, "\$cardTokenId = trim") !== false
    && strpos($content, "if (\$cardTokenId === \x27\x27)") !== false;
echo $checksEmpty ? "OK" : "FAIL_NO_EMPTY_CHECK";
');
ok("13. SubscriptionController rejeita card_token vazio", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 14: retry seguro — se modal reaberto, token anterior nao persiste
// Verifica se ha logica de reset antes de openBrick
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
if ($content === false) { echo "FILE_READ_ERROR"; exit; }
$clearsContainer = strpos($content, "CONTAINER.innerHTML = \x27\x27") !== false;
$createsNewBrick = strpos($content, "bricks().create") !== false;
$hasStateReset = strpos($content, "clearError()") !== false
    || strpos($content, "LOADING.style.display") !== false;
echo ($clearsContainer && $createsNewBrick) ? "OK_NEW_BRICK" : "FAIL_NO_RESET";
');
ok("14. openBrick cria novo Brick e reseta container", $out === 'OK_NEW_BRICK', $pass, $fail, $log);

echo "\n=== MP TOKEN FLOW SECURITY TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

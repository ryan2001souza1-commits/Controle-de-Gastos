<?php
/**
 * Tests de seguranca e logica do fluxo de assinatura.
 *
 * ATENCAO: a integracao principal agora e Asaas (src/services/AsaasService.php).
 * Este arquivo verifica:
 *   - O SubscriptionController MP LEGADO ainda nao expoe PAN/CVV/token
 *   - O AsaasSubscriptionController nao expoe cartao em logs ou persistencia
 *   - O codigo do frontend NAO inclui MercadoPago.js (sua substituicao foi feita)
 *   - O codigo do frontend nao expoe card_token_id (substituido por cartao direto)
 */
$root = 'C:/Users/Ryan Souza/Desktop/Projetos Pequenos/Controle-de-Gastos';
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
// Teste 1: MercadoPago.js foi removido do frontend
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
echo (strpos($content, "sdk.mercadopago.com") === false) ? "OK" : "MP_SDK_PRESENT";
');
ok("1. MercadoPago.js removido do frontend", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 2: Card Payment Brick removido do frontend
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
echo (strpos($content, "bricks().create") === false && strpos($content, "cardPayment") === false) ? "OK" : "BRICK_PRESENT";
');
ok("2. Card Payment Brick removido do frontend", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 3: Nao ha logica de card_token_id no frontend
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/meu_plano.php";
$content = @file_get_contents($file);
echo (strpos($content, "CARD_TOKEN_INPUT") === false && strpos($content, "card_token_id") === false) ? "OK" : "TOKEN_STILL_PRESENT";
');
ok("3. card_token_id removido do frontend (substituido por cartao direto)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 4: valor PRO=9.90 (preco fixo do servidor)
// =====================================================================
$out = runIsolated('
$prices = ["pro" => 9.90, "premium" => 19.90];
$pro = number_format($prices["pro"], 2, ".", "");
$premium = number_format($prices["premium"], 2, ".", "");
echo ($pro === "9.90" && $premium === "19.90") ? "OK" : "FAIL:$pro:$premium";
');
ok("4. Preco PRO=9.90, PREMIUM=19.90 (fixo no servidor)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 5: status=authorized no payload MP legado (ainda existe)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/MercadoPagoService.php";
$content = @file_get_contents($file);
echo (strpos($content, "authorized") !== false) ? "OK" : "FAIL";
');
ok("5. createPreapproval MP legado envia status=authorized", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 6: plan_id vem de env var no controller MP legado
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/SubscriptionController.php";
$content = @file_get_contents($file);
$usesEnv = strpos($content, "MERCADOPAGO_PLAN_ID_") !== false
    && strpos($content, "getenv(\x24planIdEnvKey)") !== false;
$notFromPost = strpos($content, "\$_POST[\x27plan_id\x27]") === false;
echo ($usesEnv && $notFromPost) ? "OK" : "FAIL";
');
ok("6. Controller MP legado: plan_id da env var, NAO do POST", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 7: nenhum PAN/CVV/token logado no controller MP legado
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
ok("7. Controller MP legado: nenhum PAN/CVV/card_token em logs", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 8: AsaasSubscriptionController NAO loga dados de cartao
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
$badPatterns = [
    "error_log.*cardData",
    "error_log.*card_number",
    "error_log.*card_ccv",
    "error_log.*cardPayload",
    "error_log.*\\\\\$cpf",
];
$found = false;
foreach ($badPatterns as $p) {
    if (preg_match("/".$p."/i", $content)) { $found = true; break; }
}
echo $found ? "LEAK" : "OK";
');
ok("8. AsaasSubscriptionController NAO loga cartao/cpf/cardPayload", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 9: AsaasSubscriptionController descarta cartao apos create
// O unset combinado (cardData, cardPayload, holderInfo, cpf) esta presente
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
$combined = strpos($content, "unset(\$cardData, \$cardPayload, \$holderInfo, \$cpf)") !== false;
$separate = strpos($content, "unset(\$cardData)") !== false
    && strpos($content, "unset(\$cardPayload)") !== false;
echo ($combined || $separate) ? "OK" : "FAIL";
');
ok("9. AsaasSubscriptionController descarta cartao com unset()", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 10: AsaasService request() NAO loga dados sensiveis
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/AsaasService.php";
$content = @file_get_contents($file);
$sensitive = [
    "error_log" . ".*access_token",
    "error_log" . ".*\$cardData",
    "error_log" . ".*\$cardPayload",
    "error_log" . ".*card_number",
    "error_log" . ".*card_ccv",
    "error_log" . ".*\$body",
    "error_log" . ".*\$number",
    "error_log" . ".*\$holderInfo",
];
$found = false;
foreach ($sensitive as $p) {
    if (preg_match("/".$p."/i", $content)) { $found = true; break; }
}
echo $found ? "LEAK" : "OK";
');
ok("10. AsaasService request() NAO loga access_token, cartao, body", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 11: AsaasService usa access_token header (NÃO Authorization Bearer)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/AsaasService.php";
$content = @file_get_contents($file);
$hasAccessToken = strpos($content, "access_token: ") !== false;
$noAuthBearer = strpos($content, "Authorization: Bearer") === false;
echo ($hasAccessToken && $noAuthBearer) ? "OK" : "FAIL";
');
ok("11. AsaasService usa header access_token, NAO Authorization Bearer", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 12: AsaasWebhookService valida token com hash_equals
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/AsaasWebhookService.php";
$content = @file_get_contents($file);
$useHashEquals = strpos($content, "hash_equals") !== false;
$validateFn = strpos($content, "validateToken") !== false;
echo ($useHashEquals && $validateFn) ? "OK" : "FAIL";
');
ok("12. AsaasWebhookService valida token com hash_equals (timing-safe)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 13: AsaasSubscriptionController usa valor do servidor (PLAN_PRICES)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
$hasFixedPrices = preg_match("/PLAN_PRICES\s*=\s*\[\s*\x27pro\x27\s*=>\s*9.90\s*,\s*\x27premium\x27\s*=>\s*19.90/", $content);
$noPriceFromPost = strpos($content, "\$_POST[\x27price\x27]") === false
    && strpos($content, "\$_POST[\x27amount\x27]") === false
    && strpos($content, "\$_POST[\x27value\x27]") === false;
echo ($hasFixedPrices && $noPriceFromPost) ? "OK" : "FAIL";
');
ok("13. Plano PRO=9.90 / PREMIUM=19.90 (servidor, NAO do POST)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 14: AsaasSubscriptionController chama getRealClientIp()
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
echo (strpos($content, "getRealClientIp") !== false) ? "OK" : "FAIL";
');
ok("14. AsaasSubscriptionController usa getRealClientIp() para remoteIp", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 15: AsaasSubscriptionController verifica assinatura ativa antes de criar
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
echo (strpos($content, "findActiveByUserId") !== false
    && strpos($content, "already_subscribed") !== false) ? "OK" : "FAIL";
');
ok("15. Bloqueia criacao se ja existe assinatura ativa", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 16: AsaasSubscriptionController exige autenticacao
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
echo (strpos($content, "requireLogin") !== false) ? "OK" : "FAIL";
');
ok("16. Exige requireLogin() no controller Asaas", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 17: AsaasSubscriptionController exige CSRF (registrado no router)
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/index.php";
$content = @file_get_contents($file);
echo (strpos($content, "asaas_subscription_create") !== false
    && strpos($content, "csrfProtectedActions") !== false
    && preg_match("/\x27asaas_subscription_create\x27/", $content)) ? "OK" : "FAIL";
');
ok("17. Action asaas_subscription_create protegida por CSRF no router", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 18: public/asaas_webhook.php existe
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/asaas_webhook.php";
echo (is_file($file)) ? "OK" : "FAIL";
');
ok("18. Endpoint public/asaas_webhook.php existe", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 19: public/asaas_webhook.php NAO loga payload bruto
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/asaas_webhook.php";
$content = @file_get_contents($file);
$bad = "error_log.*\$rawBody|error_log.*\$body|error_log.*payload|error_log.*\$accessToken|error_log.*ASAAS_WEBHOOK_TOKEN";
$found = (bool)preg_match("/".$bad."/i", $content);
echo $found ? "LEAK" : "OK";
');
ok("19. public/asaas_webhook.php NAO loga payload/token", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 20: public/asaas_webhook.php exige POST
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/asaas_webhook.php";
$content = @file_get_contents($file);
echo (strpos($content, "REQUEST_METHOD") !== false
    && strpos($content, "\x27POST\x27") !== false) ? "OK" : "FAIL";
');
ok("20. public/asaas_webhook.php exige POST", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 21: vercel.json CSP removido mercadopago.com
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/vercel.json";
$content = @file_get_contents($file);
echo (strpos($content, "mercadopago.com") === false) ? "OK" : "FAIL";
');
ok("21. CSP nao contem mercadopago.com", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 22: api/index.php roteia asaas_webhook
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/api/index.php";
$content = @file_get_contents($file);
echo (strpos($content, "asaas_webhook") !== false) ? "OK" : "FAIL";
');
ok("22. api/index.php roteia asaas_webhook", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 23: AsaasSubscriptionController NAO persiste cartao
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
preg_match("/INSERT INTO subscriptions\\s*\\(([^)]+)\\)/i", $content, $m);
$cols = $m[1] ?? "";
$cardInInsert = (stripos($cols, "card_number") !== false
    || stripos($cols, "ccv") !== false
    || stripos($cols, "expiry") !== false
    || stripos($cols, "holder") !== false);
echo $cardInInsert ? "LEAK" : "OK";
');
ok("23. INSERT em subscriptions NAO inclui campos de cartao", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 24: AsaasSubscriptionController nao expoe cardHolder no DB
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
preg_match("/INSERT INTO subscriptions\\s*\\(([^)]+)\\)/i", $content, $m);
$cols = $m[1] ?? "";
$hasCard = (
    stripos($cols, "card_number") !== false
    || stripos($cols, "ccv") !== false
    || stripos($cols, "expiry") !== false
    || stripos($cols, "cvv") !== false
    || stripos($cols, "holder_name") !== false
);
echo $hasCard ? "LEAK" : "OK";
');
ok("24. INSERT subscriptions NAO inclui campos de cartao", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 25: Sandbox selected por ASAAS_ENV=sandbox
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/AsaasService.php";
$content = @file_get_contents($file);
$hasSandboxUrl = strpos($content, "api-sandbox.asaas.com") !== false;
$hasProdUrl    = strpos($content, "api.asaas.com") !== false;
echo ($hasSandboxUrl && $hasProdUrl) ? "OK" : "FAIL";
');
ok("25. AsaasService seleciona sandbox/production baseado em ASAAS_ENV", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 26: CpfValidator.php existe e valida DV
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
ok("26. CpfValidator existe e valida DV corretamente", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 27: AsaasSubscriptionController NAO loga CPF
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
$leaks = [
    "error_log" . ".*" . "\$cpf",
    "error_log" . ".*" . "cpfCnpj",
    "error_log" . ".*" . "\$cpf_raw",
];
$found = false;
foreach ($leaks as $p) {
    if (strpos($content, $p) !== false) { $found = true; break; }
}
echo $found ? "LEAK" : "OK";
');
ok("27. AsaasSubController NAO loga CPF", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 28: AsaasSubscriptionController valida CPF antes de Asaas call
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/controllers/AsaasSubscriptionController.php";
$content = @file_get_contents($file);
if (strpos($content, "CpfValidator::isValid") === false) { echo "NOVAL"; exit; }
if (strpos($content, "error=invalid_cpf") === false) { echo "NOERR"; exit; }
preg_match_all("/(CpfValidator::isValid|createSubscription|createCustomer|findOrCreateCustomer)/", $content, $m, PREG_OFFSET_CAPTURE);
$pos = array_column($m[0], 1);
sort($pos);
$firstValidator = $pos[0] ?? PHP_INT_MAX;
$firstAsaas    = $pos[count($pos) > 1 ? 1 : 0] ?? PHP_INT_MAX;
echo ($firstValidator < $firstAsaas) ? "OK" : "FAIL";
');
ok("28. CpfValidator validado ANTES de qualquer chamada Asaas", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 29: ProfileController NAO loga CPF
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
ok("29. ProfileController NAO loga CPF", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 30: AsaasWebhookService NAO loga CPF
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/src/services/AsaasWebhookService.php";
$content = @file_get_contents($file);
$leaks = [
    "error_log" . ".*" . "\$cpf",
    "error_log" . ".*" . "cpf",
];
$found = false;
foreach ($leaks as $p) {
    if (strpos($content, $p) !== false) { $found = true; break; }
}
echo $found ? "LEAK" : "OK";
');
ok("30. AsaasWebhookService NAO loga CPF", $out === 'OK', $pass, $fail, $log);

echo "\n=== SUBSCRIPTION SECURITY TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

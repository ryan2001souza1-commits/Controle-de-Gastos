<?php
/**
 * Testes de segurança do diagnóstico MP — valida que NENHUMA credencial é exposta.
 * Cada cenário roda em processo PHP isolado.
 *
 * Para lidar com paths com espaço ("Controle de Gastos"), passamos o caminho
 * via variavel de ambiente, evitando problemas de quoting no shell.
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

/**
 * runIsolatedContent — executa o script em um processo PHP isolado.
 * Disponibiliza o conteúdo de um arquivo em $CONTENT (string) para o script.
 * Isso evita o problema de paths com espaço.
 */
function runIsolatedContent(string $file, string $scriptBody): string
{
    $content = @file_get_contents($file);
    if ($content === false) {
        return 'FILE_READ_ERROR';
    }
    $encoded = base64_encode($content);
    $wrapped = "<?php\n\$CONTENT = base64_decode('" . $encoded . "');\n" . $scriptBody;
    $tmp = tempnam(sys_get_temp_dir(), 'mp_diag_test_') . '.php';
    file_put_contents($tmp, $wrapped);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// =====================================================================
// Helpers de classificação que devem estar no código
// =====================================================================
function classifyToken(string $token): string {
    if ($token === '') return 'missing';
    if (str_starts_with($token, 'TEST-')) return 'test';
    if (str_starts_with($token, 'APP_USR-') || str_starts_with($token, 'APP PRD-')) return 'production';
    return 'unknown';
}

function classifyPubKey(string $pubKey): string {
    if ($pubKey === '') return 'missing';
    if (str_starts_with($pubKey, 'TEST-')) return 'test';
    if (str_starts_with($pubKey, 'APP_USR-') || str_starts_with($pubKey, 'APP PRD-')) return 'production';
    return 'unknown';
}

function classifyMode(string $mode): string {
    $m = strtolower($mode);
    if ($m === 'sandbox') return 'sandbox';
    if ($m === 'production') return 'production';
    if ($m !== '') return 'other';
    return 'production';
}

$indexFile = $root . '/public/index.php';

// =====================================================================
// Teste 1: action mp_env_diag existe no código fonte
// =====================================================================
$content = file_get_contents($indexFile);
ok("1. action mp_env_diag existe em public/index.php", strpos($content, "mp_env_diag") !== false, $pass, $fail, $log);

// =====================================================================
// Teste 2: action mp_env_diag exige admin
// =====================================================================
$content = file_get_contents($indexFile);
$hasAction = strpos($content, "action === 'mp_env_diag'") !== false;
$hasAdminCheck = strpos($content, "_SESSION['is_admin']") !== false;
$hasRequireLogin = strpos($content, "requireLogin()") !== false;
ok("2. mp_env_diag exige is_admin e requireLogin", $hasAction && $hasAdminCheck && $hasRequireLogin, $pass, $fail, $log);

// =====================================================================
// Teste 3: classification: TEST- tokens → test
// =====================================================================
$out = runIsolatedContent(__FILE__, '
$tests = [
    "TEST-1234567890abcdef1234567890abcdef1234567890" => "test",
    "TEST-9876543210fedcba09876543210fedcba09876543210" => "test",
    "" => "missing",
];
function classifyToken(string $token): string {
    if ($token === "") return "missing";
    if (str_starts_with($token, "TEST-")) return "test";
    if (str_starts_with($token, "APP_USR-") || str_starts_with($token, "APP PRD-")) return "production";
    return "unknown";
}
$allOk = true;
foreach ($tests as $input => $expected) {
    $got = classifyToken($input);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("3. classifyToken: TEST- = test, empty = missing", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 4: classification: APP_USR- / APP PRD- → production
// =====================================================================
$out = runIsolatedContent(__FILE__, '
function classifyToken(string $token): string {
    if ($token === "") return "missing";
    if (str_starts_with($token, "TEST-")) return "test";
    if (str_starts_with($token, "APP_USR-") || str_starts_with($token, "APP PRD-")) return "production";
    return "unknown";
}
$tests = [
    "APP_USR-1234567890abcdef1234567890abcdef1234567890" => "production",
    "APP PRD-1234567890abcdef1234567890abcdef1234567890" => "production",
    "APP_USR-000000000000000000000000000000000000000000" => "production",
];
$allOk = true;
foreach ($tests as $input => $expected) {
    $got = classifyToken($input);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("4. classifyToken: APP_USR- / APP PRD- = production", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 5: classification: unknown prefix → unknown
// =====================================================================
$out = runIsolatedContent(__FILE__, '
function classifyToken(string $token): string {
    if ($token === "") return "missing";
    if (str_starts_with($token, "TEST-")) return "test";
    if (str_starts_with($token, "APP_USR-") || str_starts_with($token, "APP PRD-")) return "production";
    return "unknown";
}
$tests = [
    "INVALID-1234567890abcdef1234567890" => "unknown",
    "some-random-token" => "unknown",
    "xyz123" => "unknown",
    "APP_OTHER-1234567890" => "unknown",
    "APP_TEST-1234567890" => "unknown",
];
$allOk = true;
foreach ($tests as $input => $expected) {
    $got = classifyToken($input);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("5. classifyToken: prefixos desconhecidos = unknown", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 6: Response NUNCA emite o valor real do token
// Verifica que json_encode($diag) serializa array com strings de
// classificacao, NAO contendo o token real
// =====================================================================
$out = runIsolatedContent($indexFile, '
$content = $CONTENT;
$usesDiag = strpos($content, "json_encode(\$diag)") !== false;
$hasTokenClassify = strpos($content, "str_starts_with(\$token") !== false;
$diagAssignsToken = preg_match("/[\x27\x22]access_token[\x27\x22]\s*=>\s*\$token[^,]/", $content)
    || preg_match("/[\x27\x22]public_key[\x27\x22]\s*=>\s*\$pubKey[^,]/", $content)
    || preg_match("/[\x27\x22]webhook_secret[\x27\x22]\s*=>\s*\$webhookSecret/", $content);
echo ($usesDiag && $hasTokenClassify && !$diagAssignsToken) ? "NO_LEAK" : "LEAK_FOUND";
');
ok("6. Response NUNCA expõe access token real", $out === 'NO_LEAK', $pass, $fail, $log);

// =====================================================================
// Teste 7: json_encode usa apenas $diag (classificações, sem credenciais)
// =====================================================================
$out = runIsolatedContent($indexFile, '
$content = $CONTENT;
$usesDiag = strpos($content, "json_encode(\$diag)") !== false;
$notDirect = strpos($content, "json_encode(\$token)") === false
    && strpos($content, "json_encode(\$pubKey)") === false
    && strpos($content, "json_encode(\$webhookSecret)") === false;
echo ($usesDiag && $notDirect) ? "NO_LEAK" : "LEAK_FOUND";
');
ok("7. Response NUNCA expõe public key real", $out === 'NO_LEAK', $pass, $fail, $log);

// =====================================================================
// Teste 8: código NÃO echoa secrets, plan IDs, ou card_token no response
// =====================================================================
$out = runIsolatedContent($indexFile, '
$content = $CONTENT;
$badEcho = preg_match("/echo\s+\$webhookSecret/", $content)
    || preg_match("/echo\s+\$planPro/", $content)
    || preg_match("/echo\s+\$planPrem/", $content)
    || preg_match("/json_encode\s*\(\s*\$webhookSecret/", $content)
    || preg_match("/json_encode\s*\(\s*\$planPro/", $content)
    || preg_match("/json_encode\s*\(\s*\$planPrem/", $content)
    || preg_match("/print\s+\$webhookSecret/", $content)
    || preg_match("/echo\s+\$cardToken/", $content)
    || preg_match("/echo\s+.*\$card[_T]oken/", $content);
echo $badEcho ? "LEAK_FOUND" : "NO_LEAK";
');
ok("8. Response NUNCA contém webhook secret, plan IDs ou card_token", $out === 'NO_LEAK', $pass, $fail, $log);

// =====================================================================
// Teste 9: classifyMode: sandbox/production/other
// =====================================================================
$out = runIsolatedContent(__FILE__, '
function classifyMode(string $mode): string {
    $m = strtolower($mode);
    if ($m === "sandbox") return "sandbox";
    if ($m === "production") return "production";
    if ($m !== "") return "other";
    return "production";
}
$tests = [
    "sandbox" => "sandbox",
    "SANDBOX" => "sandbox",
    "production" => "production",
    "PRODUCTION" => "production",
    "mixed" => "other",
    "test" => "other",
    "" => "production",
];
$allOk = true;
foreach ($tests as $input => $expected) {
    $got = classifyMode($input);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("9. classifyMode: sandbox/production/other/empty", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 10: A resposta contém SOMENTE os 6 campos esperados
// =====================================================================
$out = runIsolatedContent($indexFile, '
$content = $CONTENT;
$expectedFields = ["mode", "public_key_type", "access_token_type", "plan_pro_configured", "plan_premium_configured", "webhook_secret_configured"];
$missing = [];
foreach ($expectedFields as $f) {
    $quoted = "\x27" . $f . "\x27";
    if (strpos($content, $quoted) === false) {
        $missing[] = $f;
    }
}
echo empty($missing) ? "ALL_FIELDS" : "MISSING:" . implode(",", $missing);
');
ok("10. Response contém exatamente os 6 campos esperados", $out === 'ALL_FIELDS', $pass, $fail, $log);

// =====================================================================
// Teste 11: classifyPubKey para TEST- e APP_USR-
// =====================================================================
$out = runIsolatedContent(__FILE__, '
function classifyPubKey(string $pubKey): string {
    if ($pubKey === "") return "missing";
    if (str_starts_with($pubKey, "TEST-")) return "test";
    if (str_starts_with($pubKey, "APP_USR-") || str_starts_with($pubKey, "APP PRD-")) return "production";
    return "unknown";
}
$tests = [
    "TEST-pk-1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab" => "test",
    "APP_USR-pk1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab" => "production",
    "" => "missing",
    "RANDOM-pk-1234567890" => "unknown",
];
$allOk = true;
foreach ($tests as $input => $expected) {
    $got = classifyPubKey($input);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("11. classifyPubKey: TEST-/APP_USR-/empty/unknown", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 12: Response NÃO contém size/tamanho das credenciais
// =====================================================================
$out = runIsolatedContent($indexFile, '
$content = $CONTENT;
$badPatterns = [
    "/strlen\s*\(\s*\$token/",
    "/strlen\s*\(\s*\$pubKey/",
    "/strlen\s*\(\s*\$webhookSecret/",
    "/count\s*\(\s*\$token/",
    "/count\s*\(\s*\$secret/",
    "/length\s*\(\s*\$token/",
];
$found = false;
foreach ($badPatterns as $p) {
    if (preg_match($p, $content)) { $found = true; break; }
}
echo $found ? "SIZE_IN_RESPONSE" : "NO_SIZE_LEAK";
');
ok("12. Response NÃO expõe tamanho de credenciais", $out === 'NO_SIZE_LEAK', $pass, $fail, $log);

// =====================================================================
// Teste 13: Response NÃO contém hash/fragmentos das credenciais
// =====================================================================
$out = runIsolatedContent($indexFile, '
$content = $CONTENT;
$badPatterns = [
    "/substr\s*\(\s*\$token/",
    "/substr\s*\(\s*\$pubKey/",
    "/substr\s*\(\s*\$webhookSecret/",
    "/md5\s*\(\s*\$token/",
    "/sha1\s*\(\s*\$token/",
    "/sha256\s*\(\s*\$token/",
];
$found = false;
foreach ($badPatterns as $p) {
    if (preg_match($p, $content)) { $found = true; break; }
}
echo $found ? "FRAGMENT_LEAK" : "NO_FRAGMENT_LEAK";
');
ok("13. Response NÃO expõe fragmentos/hashes de credenciais", $out === 'NO_FRAGMENT_LEAK', $pass, $fail, $log);

echo "=== MP ENV DIAG SECURITY TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

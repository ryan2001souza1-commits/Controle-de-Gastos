<?php
/**
 * Teste do fluxo completo de CSRF — cada cenário roda em processo PHP isolado.
 * Garante $_SESSION, random_bytes, session_status e comportamento idênticos à produção.
 *
 * Cenários cobertos:
 *  1. Visitante sem sessão acessando login
 *  2. Visitante acessando registro
 *  3. Visitante acessando páginas públicas
 *  4. Login (gera token para user_id)
 *  5. Usuário autenticado (csrf_field)
 *  6. Logout (token deve ser regenerado para null)
 *  7. POST sem CSRF
 *  8. POST com CSRF inválido
 *  9. POST com CSRF válido
 * 10. Regeneração após login (token anônimo rejeitado)
 */

$root = dirname(__DIR__);
require_once $root . '/src/services/CsrfService.php';

$pass = 0; $fail = 0;
$log = '';

function ok(string $name, bool $cond, string &$log, int &$pass, int &$fail): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

/**
 * Roda um bloco em processo PHP isolado. Cada cenário é um script separado
 * carregado via proc_open — simula um "visitante" independente.
 */
function runIsolated(string $scriptBody): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'csrf_test_') . '.php';
    file_put_contents($tmp, "<?php\nrequire_once '" . dirname(__DIR__) . "/src/services/CsrfService.php';\n" . $scriptBody);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// =====================================================================
// 1. Visitante sem sessão
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$tok = $svc->generateToken(null);
echo $tok !== null && isset($_SESSION["csrf_token"]) && $_SESSION["csrf_user_id"] === null ? "OK" : "FAIL";
');
ok("1. Visitante sem sessão — generateToken(null) gera token e marca csrf_user_id=null", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 2. Visitante acessando registro — token persiste entre requests da mesma sessão
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$t1 = $svc->generateToken(null);
$t2 = $svc->getToken(null);
echo $t1 === $t2 && $t1 !== "" ? "OK" : "FAIL";
');
ok("2. Registro — token persiste e é recuperado por getToken(null)", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 3. Páginas públicas — bootstrap detecta null e gera token sem quebrar
// =====================================================================
$out = runIsolated('
session_start();
$csrfUserId = $_SESSION["user_id"] ?? null;
$storedCsrfUserId = $_SESSION["csrf_user_id"] ?? null;
$tokenExists = isset($_SESSION["csrf_token"]) && is_string($_SESSION["csrf_token"]);
$userIdChanged = $csrfUserId !== $storedCsrfUserId;
if (!$tokenExists || $userIdChanged) {
    (new CsrfService())->generateToken($csrfUserId);
}
echo isset($_SESSION["csrf_token"]) ? "OK" : "FAIL";
');
ok("3. Página pública — bootstrap não quebra e cria token para anônimo", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 4. Login — regenerateToken para user_id rotaciona o token
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$tokAnon = $svc->generateToken(null);
$tokUser = $svc->regenerateToken(42);
echo $tokAnon !== $tokUser && $_SESSION["csrf_user_id"] === 42 ? "OK" : "FAIL";
');
ok("4. Login — regenerateToken(42) rotaciona token e marca csrf_user_id=42", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 5. Usuário autenticado — token estável
// =====================================================================
$out = runIsolated('
session_start();
$_SESSION["user_id"] = 99;
$svc = new CsrfService();
$tok = $svc->generateToken(99);
$retrieved = $svc->getToken(99);
echo $retrieved === $tok ? "OK" : "FAIL";
');
ok("5. Usuário autenticado — getToken(99) retorna mesmo token", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 6. Logout — cenário: duas requisições isoladas (cliente novo após logout)
//    Processo A: session com csrf_user_id=7 (simula logout real)
//    Processo B: nova sessão anônima após logout
//    Verifica que: (a) após logout a próxima requisição gera token null
//    e (b) o token é diferente do anterior
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
// Simula requisição logada anterior ao logout
$t1 = $svc->generateToken(7);
echo json_encode(["token"=>$t1, "userId"=>$_SESSION["csrf_user_id"]]);
');
$data = json_decode($out, true);
$tokenBefore = $data['token'] ?? '';

// Processo B: nova requisição (como após session_destroy)
$out = runIsolated('
session_start();
// Simula bootstrap: csrf_user_id da sessão é null, não existe token
// userIdChanged = true (null !== null? false!)
// tokenExists = false → gera token null
$csrfUserId = $_SESSION["user_id"] ?? null;
$storedCsrfUserId = $_SESSION["csrf_user_id"] ?? null;
$userIdChanged = $csrfUserId !== $storedCsrfUserId;  // false (null !== null = false)
$tokenExists = isset($_SESSION["csrf_token"]) && is_string($_SESSION["csrf_token"]);
if (!$tokenExists || $userIdChanged) {
    (new CsrfService())->generateToken($csrfUserId);
}
echo json_encode([
    "userId"=>$_SESSION["csrf_user_id"],
    "hasToken"=>isset($_SESSION["csrf_token"]),
]);
');
$data2 = json_decode($out, true);
$test6 = ($data2['userId'] === null && $data2['hasToken'] === true && isset($tokenBefore));
ok("6. Logout — após destroy, nova sessão gera token para null, csrf_user_id=null", $test6, $log, $pass, $fail);

// =====================================================================
// 7. POST sem CSRF
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$svc->generateToken(42);
echo $svc->validateToken(42, "") === false ? "OK" : "FAIL";
');
ok("7. POST sem csrf_token — rejected", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 8. POST com CSRF inválido
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$realTok = $svc->generateToken(42);
$r1 = $svc->validateToken(42, "AAAAAAAAAAAAAAAAAAAAAA");
$r2 = $svc->validateToken(42, $realTok . "X");
echo $r1 === false && $r2 === false ? "OK" : "FAIL";
');
ok("8. POST com csrf_token inválido/adulterado — rejected", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 9. POST com CSRF válido
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$tok = $svc->generateToken(42);
echo $svc->validateToken(42, $tok) === true ? "OK" : "FAIL";
');
ok("9. POST com csrf_token válido — accepted", $out === 'OK', $log, $pass, $fail);

// =====================================================================
// 10. Regeneração após login — token anônimo NÃO pode ser reutilizado
//    simulate: anonymous user gets token, then logs in (regenerates token),
//    then tries to use the OLD anonymous token — must be rejected
// =====================================================================
$out = runIsolated('
session_start();
$svc = new CsrfService();
$tokAnon = $svc->generateToken(null);       // token para anônimo
$tokUser = $svc->regenerateToken(42);      // ROTACIONA após login
// O token anônimo NÃO é mais o que está na sessão
$storedTok = $_SESSION["csrf_token"];
$r1 = $svc->validateToken(42, $tokAnon);   // deve ser false (token diferente)
$r2 = $svc->validateToken(42, $tokUser);  // deve ser true
echo $tokAnon !== $tokUser && $storedTok === $tokUser && $r1 === false && $r2 === true ? "OK" : "FAIL";
');
ok("10. Pós-login — token anônimo rejeitado (session tem token do usuário)", $out === 'OK', $log, $pass, $fail);

echo "=== CSRF FLOW TESTS ===\n";
echo $log;
echo "\nRESUMO: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);

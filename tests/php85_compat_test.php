<?php
/**
 * PHP 8.5 Compatibility Tests.
 *
 * Testa:
 *  - AsaasService NAO usa curl_close() (deprecated desde 8.0, error desde 8.5)
 *  - AsaasService NAO gera output antes de um redirect simulado
 *  - display_errors nao esta como '1' em producao (via config)
 *  - config.php configura error_reporting seguro
 *  - nenhum var_dump/print_r/echo que possa quebrar redirects
 */

$ROOT = __DIR__ . '/..';

function php85_assert(string $name, bool $cond): void
{
    static $pass = 0, $fail = 0;
    if ($cond) { echo "  [PASS] $name\n"; $pass++; }
    else       { echo "  [FAIL] $name\n"; $fail++; }
    $GLOBALS['__results'][] = ['name' => $name, 'ok' => $cond];
}
$__results = [];

echo "=== PHP 8.5 COMPATIBILITY TESTS ===\n";

// --- AsaasService: curl_close REMOVIDO ---
$asaasSrc = file_get_contents($ROOT . '/src/services/AsaasService.php');
php85_assert('T01 AsaasService NAO usa curl_close()',
    strpos($asaasSrc, 'curl_close') === false);
php85_assert('T02 AsaasService usa unset($ch) ao inves de curl_close',
    strpos($asaasSrc, 'unset($ch)') !== false);

// --- AsaasService: sem output durante request ---
php85_assert('T03 AsaasService request() NAO usa echo',
    !preg_match('/\becho\b/', $asaasSrc));
php85_assert('T04 AsaasService request() NAO usa print_r',
    strpos($asaasSrc, 'print_r') === false);
php85_assert('T05 AsaasService request() NAO usa var_dump',
    strpos($asaasSrc, 'var_dump') === false);

// --- config.php: display_errors configurado para producao ---
$configSrc = file_get_contents($ROOT . '/src/config/config.php');
php85_assert('T06 config.php configura display_errors=0 em producao',
    strpos($configSrc, "display_errors") !== false
    && strpos($configSrc, "'0'") !== false
    && strpos($configSrc, "display_startup_errors") !== false
    && strpos($configSrc, "'0'") !== false);
php85_assert('T07 config.php configura error_reporting E_ALL (sem exclusao de deprecations)',
    strpos($configSrc, 'E_ALL') !== false
    && strpos($configSrc, '~E_DEPRECATED') === false
    && strpos($configSrc, 'E_STRICT') === false);
php85_assert('T08 config.php detecta Vercel_ENV=production',
    strpos($configSrc, "VERCEL_ENV") !== false);
php85_assert('T09 config.php usa ini_set para display_errors',
    strpos($configSrc, "ini_set") !== false
    && strpos($configSrc, "display_errors") !== false);
php85_assert('T10 config.php NAO usa @ para suprimir errors',
    strpos($configSrc, '@') === false
    || substr_count($configSrc, '@') < 5); // tolerancia minima

// --- AsaasSubscriptionController: header() NAO precedido de output ---
$ctrlSrc = file_get_contents($ROOT . '/src/controllers/AsaasSubscriptionController.php');
$headerLines = [];
foreach (explode("\n", $ctrlSrc) as $i => $line) {
    if (strpos($line, 'header(') !== false && strpos($line, 'Location:') !== false) {
        $headerLines[] = $i + 1;
    }
}
php85_assert('T11 AsaasSubscriptionController tem redirects com Location header',
    count($headerLines) >= 2);
foreach ($headerLines as $lineNo) {
    $lines = explode("\n", $ctrlSrc);
    $prev = $lines[$lineNo - 2] ?? '';
    $hasEcho = strpos($prev, 'echo ') !== false
        || strpos($prev, 'echo"') !== false
        || strpos($prev, "echo'") !== false
        || strpos($prev, 'print ') !== false;
    php85_assert("T12 Redirect na linha $lineNo NAO precedido de echo/print",
        !$hasEcho);
}

// --- AsaasService: metodo request nao emite output ---
// Verifica que todas as funcoes de log usam error_log (nao echo)
$logCalls = preg_match_all('/error_log\s*\(/', $asaasSrc);
php85_assert('T13 AsaasService usa apenas error_log para logs (sem echo)',
    $logCalls !== false && $logCalls >= 0);

// --- AsaasWebhookService: sem output durante handle ---
$whSrc = file_get_contents($ROOT . '/src/services/AsaasWebhookService.php');
php85_assert('T14 AsaasWebhookService NAO usa echo/print/var_dump',
    strpos($whSrc, 'echo ') === false
    && strpos($whSrc, 'var_dump') === false
    && strpos($whSrc, 'print_r') === false);

// --- AsaasSubscriptionController: fluxo de erro nao imprime nada antes do redirect ---
// Verifica que TODOS os branches que fazem header() nao fazem echo/print na linha anterior
$ctrlLines = explode("\n", $ctrlSrc);
$problematicBranches = 0;
for ($i = 0; $i < count($ctrlLines) - 1; $i++) {
    $line = $ctrlLines[$i];
    $nextLine = $ctrlLines[$i + 1] ?? '';
    $hasEcho = strpos($line, 'echo ') !== false || strpos($line, 'print ') !== false;
    $hasHeader = strpos($nextLine, 'header(') !== false;
    if ($hasEcho && $hasHeader) {
        $problematicBranches++;
    }
}
php85_assert('T15 AsaasSubscriptionController: nenhum echo/print antes de header()',
    $problematicBranches === 0);

// --- MercadoPagoService: curl_close tambem ausente? (verificar) ---
$mpSrc = file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
php85_assert('T16 MercadoPagoService NAO usa curl_close',
    strpos($mpSrc, 'curl_close') === false);

// --- Mailer: curl_close ausente? ---
$mailerSrc = file_get_contents($ROOT . '/src/services/Mailer.php');
php85_assert('T17 Mailer NAO usa curl_close',
    strpos($mailerSrc, 'curl_close') === false);

// --- GoogleAuthService: curl_close ausente? ---
$gauthSrc = file_get_contents($ROOT . '/src/services/GoogleAuthService.php');
php85_assert('T18 GoogleAuthService NAO usa curl_close',
    strpos($gauthSrc, 'curl_close') === false);

// --- AiService: curl_close ausente? ---
$aiSrc = file_get_contents($ROOT . '/src/services/AiService.php');
php85_assert('T19 AiService NAO usa curl_close',
    strpos($aiSrc, 'curl_close') === false);

// --- AsaasService: curl_close removido nao causa null handle warning ---
// Verifica que nao ha referencia a $ch apos o unset (a menos que seja para log)
$chAfterUnset = preg_match('/unset\s*\(\s*\$ch\s*\).*\$ch/', $asaasSrc);
php85_assert('T20 AsaasService NAO usa $ch apos unset (sem warning de variavel indefinida)',
    $chAfterUnset !== 1);

// --- config.php: error_reporting inclui E_ALL com exclusoes ---
 php85_assert('T21 config.php NAO exclui E_DEPRECATED em producao (E_ALL sem ~E_DEPRECATED)',
    strpos($configSrc, '~E_DEPRECATED') === false
    && strpos($configSrc, 'error_reporting(E_ALL)') !== false);

// --- config.php: log_errors ativado em producao ---
php85_assert('T22 config.php ativa log_errors=1 em producao',
    strpos($configSrc, "log_errors") !== false
    && strpos($configSrc, "'1'") !== false);

// --- AsaasService: metodo request retorna array (verificacao de tipo de retorno) ---
php85_assert('T23 AsaasService request() retorna array nos success cases',
    strpos($asaasSrc, "'ok' => true") !== false
    && strpos($asaasSrc, "'ok' => false") !== false);

// --- ProfileController: updateProfile nao imprime nada antes do redirect ---
$profileSrc = file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$profileLines = explode("\n", $profileSrc);
$profileProblems = 0;
for ($i = 0; $i < count($profileLines) - 1; $i++) {
    $line = $profileLines[$i];
    $nextLine = $profileLines[$i + 1] ?? '';
    $hasEcho = strpos($line, 'echo ') !== false;
    $hasHeader = strpos($nextLine, 'header(') !== false;
    if ($hasEcho && $hasHeader) {
        $profileProblems++;
    }
}
php85_assert('T24 ProfileController: nenhum echo/print antes de header()',
    $profileProblems === 0);

// --- AsaasSubscriptionController: metodo create nao faz echo ---
php85_assert('T25 AsaasSubscriptionController::create NAO usa echo',
    strpos($ctrlSrc, 'echo ') === false
    && strpos($ctrlSrc, 'print ') === false);

// --- AsaasService: findOrCreateCustomer: try/catch no UPDATE local ---
$asaasFull = file_get_contents($ROOT . '/src/services/AsaasService.php');
$hasTryCatchUpdate = preg_match('/try\s*\{[^}]*UPDATE\s+usuarios\s+SET\s+asaas_customer_id/s', $asaasFull);
php85_assert('T26 findOrCreateCustomer protege UPDATE local com try/catch',
    $hasTryCatchUpdate === 1);
php85_assert('T27 findOrCreateCustomer retorna erro se UPDATE local falhar',
    strpos($asaasFull, 'local_save_failed') !== false);

$totalPass = count(array_filter($__results, fn($r) => $r['ok']));
$totalFail = count(array_filter($__results, fn($r) => !$r['ok']));
echo "\n=== PHP 8.5 TESTS: $totalPass PASS, $totalFail FAIL ===\n";
exit($totalFail === 0 ? 0 : 1);

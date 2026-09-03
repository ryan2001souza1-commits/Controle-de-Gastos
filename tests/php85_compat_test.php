<?php
/**
 * PHP 8.5 Compatibility Tests.
 *
 * Testa:
 *  - config.php: display_errors seguro, Vercel_ENV, error_reporting
 *  - Mailer, GoogleAuthService, AiService NAO usam curl_close()
 *  - ProfileController: nao imprime nada antes de redirect
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

// --- config.php: display_errors configurado para producao ---
$configSrc = file_get_contents($ROOT . '/src/config/config.php');
php85_assert('T01 config.php configura display_errors=0 em producao',
    strpos($configSrc, "display_errors") !== false
    && strpos($configSrc, "'0'") !== false
    && strpos($configSrc, "display_startup_errors") !== false
    && strpos($configSrc, "'0'") !== false);
php85_assert('T02 config.php configura error_reporting E_ALL (sem exclusao de deprecations)',
    strpos($configSrc, 'E_ALL') !== false
    && strpos($configSrc, '~E_DEPRECATED') === false
    && strpos($configSrc, 'E_STRICT') === false);
php85_assert('T03 config.php detecta Vercel_ENV=production',
    strpos($configSrc, "VERCEL_ENV") !== false);
php85_assert('T04 config.php usa ini_set para display_errors',
    strpos($configSrc, "ini_set") !== false
    && strpos($configSrc, "display_errors") !== false);
php85_assert('T05 config.php NAO usa @ para suprimir errors',
    strpos($configSrc, '@') === false
    || substr_count($configSrc, '@') < 5);

php85_assert('T06 config.php NAO exclui E_DEPRECATED em producao (E_ALL sem ~E_DEPRECATED)',
    strpos($configSrc, '~E_DEPRECATED') === false
    && strpos($configSrc, 'error_reporting(E_ALL)') !== false);
php85_assert('T07 config.php ativa log_errors=1 em producao',
    strpos($configSrc, "log_errors") !== false
    && strpos($configSrc, "'1'") !== false);

// --- curl_close ausente nos servicos de rede ---
$mailerSrc = file_get_contents($ROOT . '/src/services/Mailer.php');
php85_assert('T08 Mailer NAO usa curl_close',
    strpos($mailerSrc, 'curl_close') === false);

$gauthSrc = file_get_contents($ROOT . '/src/services/GoogleAuthService.php');
php85_assert('T09 GoogleAuthService NAO usa curl_close',
    strpos($gauthSrc, 'curl_close') === false);

$aiSrc = file_get_contents($ROOT . '/src/services/AiService.php');
php85_assert('T10 AiService NAO usa curl_close',
    strpos($aiSrc, 'curl_close') === false);

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
php85_assert('T12 ProfileController: nenhum echo/print antes de header()',
    $profileProblems === 0);

$totalPass = count(array_filter($__results, fn($r) => $r['ok']));
$totalFail = count(array_filter($__results, fn($r) => !$r['ok']));
echo "\n=== PHP 8.5 TESTS: $totalPass PASS, $totalFail FAIL ===\n";
exit($totalFail === 0 ? 0 : 1);

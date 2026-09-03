<?php
/**
 * CPF Flow Tests — CpfValidator + ProfileController.
 *
 * Testa:
 *  - CpfValidator: valido, invalido, mascara, digitos, format, mask
 *  - ProfileController: rejeita cpf invalido
 *  - nenhum log/exposicao de CPF
 */

$ROOT = __DIR__ . '/..';
require_once $ROOT . '/src/services/CpfValidator.php';

function cpf_assert(string $name, bool $cond): void
{
    static $pass = 0, $fail = 0;
    if ($cond) { echo "  [PASS] $name\n"; $pass++; }
    else       { echo "  [FAIL] $name\n"; $fail++; }
    $GLOBALS['__results'][] = ['name' => $name, 'ok' => $cond];
}
$__results = [];

echo "=== CPF VALIDATOR TESTS ===\n";

// --- CpfValidator::isValid ---
// CPF valido: 529.982.247-25 (DV confirmados)
cpf_assert('T01 CPF valido 52998224725', CpfValidator::isValid('52998224725'));
cpf_assert('T02 CPF valido com mascara', CpfValidator::isValid('529.982.247-25'));
cpf_assert('T03 CPF valido com espacos', CpfValidator::isValid('  529.982.247-25  '));
cpf_assert('T04 CPF valido null', !CpfValidator::isValid(null));

// CPF invalido: digitos verificadores errados
cpf_assert('T05 CPF invalido (DV errado)', !CpfValidator::isValid('52998224726'));
cpf_assert('T06 CPF todos 1', !CpfValidator::isValid('11111111111'));
cpf_assert('T07 CPF todos 0', !CpfValidator::isValid('00000000000'));
cpf_assert('T08 CPF todos 9', !CpfValidator::isValid('99999999999'));
cpf_assert('T09 CPF menos de 11 digitos', !CpfValidator::isValid('1234567890'));
cpf_assert('T10 CPF mais de 11 digitos', !CpfValidator::isValid('123456789012'));
cpf_assert('T11 CPF apenas letras', !CpfValidator::isValid('abcdefghijk'));
cpf_assert('T12 CPF vazio', !CpfValidator::isValid(''));
cpf_assert('T13 CPF espacos', !CpfValidator::isValid('   '));
cpf_assert('T14 CPF mascara invalida', !CpfValidator::isValid('123.456.789-10'));
cpf_assert('T15 CPF DV primeiro invalido', !CpfValidator::isValid('52998224735'));

// --- CpfValidator::digits ---
cpf_assert('T16 digits: 11 digitos', CpfValidator::digits('529.982.247-25') === '52998224725');
cpf_assert('T17 digits: ja limpo', CpfValidator::digits('52998224725') === '52998224725');
cpf_assert('T18 digits: espacos', trim(CpfValidator::digits('  123  ') ?? '') === '123');
cpf_assert('T19 digits: null', CpfValidator::digits(null) === null);
cpf_assert('T20 digits: vazio', CpfValidator::digits('') === null);

// --- CpfValidator::format ---
cpf_assert('T21 format: normal', CpfValidator::format('52998224725') === '529.982.247-25');
cpf_assert('T22 format: com mascara', CpfValidator::format('529.982.247-25') === '529.982.247-25');
cpf_assert('T23 format: null', CpfValidator::format(null) === null);
cpf_assert('T24 format: < 11 digitos', CpfValidator::format('12345') === null);
cpf_assert('T25 format: > 11 digitos', CpfValidator::format('1234567890123') === null);

// --- CpfValidator::mask ---
cpf_assert('T26 mask: normal', CpfValidator::mask('52998224725') === '***.982.247-**');
cpf_assert('T27 mask: null', CpfValidator::mask(null) === null);
cpf_assert('T28 mask: < 11', CpfValidator::mask('123') === null);

// --- Security: CPF NAO aparece em error_log ---
echo "\n=== CPF SECURITY TESTS ===\n";

$profileCtrl = file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$noLogCpfProfile = (
    preg_match('/error_log[^;]*\$cpf/', $profileCtrl) !== 1
    && preg_match('/error_log[^;]*cpf/', $profileCtrl) !== 1
);
cpf_assert('T29 ProfileController NAO loga CPF', $noLogCpfProfile);

// --- ProfileController: usa CpfValidator e rejeita invalido ---
echo "\n=== PROFILE CONTROLLER TESTS ===\n";

cpf_assert('T30 usa CpfValidator',
    strpos($profileCtrl, 'CpfValidator') !== false);
cpf_assert('T31 rejeita CPF invalido (invalid_cpf error)',
    strpos($profileCtrl, 'invalid_cpf') !== false);
cpf_assert('T32 usa CpfValidator::digits',
    strpos($profileCtrl, 'CpfValidator::digits') !== false);
cpf_assert('T33 usa CpfValidator::isValid',
    strpos($profileCtrl, 'CpfValidator::isValid') !== false);

// --- User::updateProfile: suporta cpf ---
$userModel = file_get_contents($ROOT . '/src/models/User.php');
cpf_assert('T34 User::updateProfile permite cpf',
    strpos($userModel, "'cpf'") !== false
    && strpos($userModel, "'nome','email','telefone','cpf'") !== false);

// --- configuracoes.php: campo CPF presente ---
$config = file_get_contents($ROOT . '/public/configuracoes.php');
cpf_assert('T35 tem campo cpf no formulario',
    strpos($config, 'name="cpf"') !== false);
cpf_assert('T36 placeholder cpf',
    strpos($config, '000.000.000-00') !== false);
cpf_assert('T37 inputmode numeric',
    strpos($config, 'inputmode="numeric"') !== false);
cpf_assert('T38 JS de formatacao cpf',
    strpos($config, 'cpf.value') !== false
    || strpos($config, "getElementById('cpf')") !== false);
cpf_assert('T39 importa CpfValidator',
    strpos($config, 'CpfValidator') !== false);
cpf_assert('T40 usa CpfValidator::format no value',
    strpos($config, 'CpfValidator::format') !== false);
cpf_assert('T41 mensagem invalid_cpf',
    strpos($config, 'invalid_cpf') !== false);

// --- meu_plano.php: mensagem invalid_cpf com link ---
$meuPlano = file_get_contents($ROOT . '/public/meu_plano.php');
cpf_assert('T42 missing_cpf REMOVIDO',
    strpos($meuPlano, 'missing_cpf') === false);
cpf_assert('T43 tem invalid_cpf com texto utile',
    strpos($meuPlano, 'invalid_cpf') !== false
    && (stripos($meuPlano, 'configura') !== false));
cpf_assert('T44 link para configuracoes',
    strpos($meuPlano, '/index.php?action=configuracoes') !== false);

// --- validador centralizado em arquivo proprio ---
$validatorFile = $ROOT . '/src/services/CpfValidator.php';
cpf_assert('T45 CpfValidator.php existe',
    is_file($validatorFile));
cpf_assert('T46 CpfValidator final class',
    strpos(file_get_contents($validatorFile), 'final class CpfValidator') !== false);
cpf_assert('T47 metodo isValid estatico',
    strpos(file_get_contents($validatorFile), 'public static function isValid') !== false);
cpf_assert('T48 metodo digits estatico',
    strpos(file_get_contents($validatorFile), 'public static function digits') !== false);
cpf_assert('T49 metodo format estatico',
    strpos(file_get_contents($validatorFile), 'public static function format') !== false);
cpf_assert('T50 metodo mask estatico',
    strpos(file_get_contents($validatorFile), 'public static function mask') !== false);

// --- ProfileController: importa CpfValidator ---
cpf_assert('T51 ProfileController importa CpfValidator',
    strpos($profileCtrl, 'CpfValidator.php') !== false);

// --- DV algorithm correctness ---
// Known valid CPF: 52998224725 -> d1=2, d2=5
// Manually verify with Python:
// d1: sum(5*10 + 2*9 + 9*8 + 9*7 + 8*6 + 2*5 + 2*4 + 4*3 + 7*2) = 50+18+72+63+48+10+8+12+14 = 295
// mod = 295 % 11 = 9, dv1 = 11 - 9 = 2 ✓
// d2: sum(prev * 11 + dv1 * 10) = 305+20 = 325, mod = 325 % 11 = 6, dv2 = 11 - 6 = 5 ✓
cpf_assert('T52 DV algorithm: 52998224725 valido', CpfValidator::isValid('52998224725'));
cpf_assert('T53 DV algorithm: 52998224724 invalido (d1 errado)', !CpfValidator::isValid('52998224724'));
cpf_assert('T54 DV algorithm: 52998224735 invalido (d2 errado)', !CpfValidator::isValid('52998224735'));

echo "\n=== CPF FLOW TESTS: " . count(array_filter($__results, fn($r) => $r['ok'])) . " PASS, " . count(array_filter($__results, fn($r) => !$r['ok'])) . " FAIL ===\n";
exit(count(array_filter($__results, fn($r) => !$r['ok'])) === 0 ? 0 : 1);

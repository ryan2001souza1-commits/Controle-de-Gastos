/**
 * Node harness: testa a logica pura de public/js/mp-card-utils.js.
 * Executado por tests/mp_card_tokenization_tests.php. Sem rede, sem DOM.
 */
'use strict';
const U = require('../public/js/mp-card-utils.js');

let pass = 0, fail = 0;
function ok(name, cond) {
    if (cond) { pass++; console.log('  [PASS] ' + name); }
    else { fail++; console.log('  [FAIL] ' + name); }
}

// --- normalizeMonth ---
ok('mes "1" -> "01"', U.normalizeMonth('1') === '01');
ok('mes "01" permanece', U.normalizeMonth('01') === '01');
ok('mes "12" permanece', U.normalizeMonth('12') === '12');
ok('mes "9" -> "09"', U.normalizeMonth('9') === '09');
ok('mes "0" invalido', U.normalizeMonth('0') === '');
ok('mes "13" invalido', U.normalizeMonth('13') === '');
ok('mes "" invalido', U.normalizeMonth('') === '');
ok('mes "ab" invalido', U.normalizeMonth('ab') === '');

// --- normalizeYear ---
ok('ano "26" -> "2026"', U.normalizeYear('26') === '2026');
ok('ano "2026" permanece', U.normalizeYear('2026') === '2026');
ok('ano "99" -> "2099"', U.normalizeYear('99') === '2099');
ok('ano "2" invalido', U.normalizeYear('2') === '');
ok('ano "202" invalido', U.normalizeYear('202') === '');
ok('ano "1999" invalido', U.normalizeYear('1999') === '');
ok('ano "" invalido', U.normalizeYear('') === '');

// --- extractSafeCode ---
ok('cause[].code usado', U.extractSafeCode({ cause: [{ code: 'invalid_card_number' }] }) === 'invalid_card_number');
ok('code direto', U.extractSafeCode({ code: 'invalid_security_code' }) === 'invalid_security_code');
ok('status vira http_*', U.extractSafeCode({ status: 503 }) === 'http_503');
ok('null -> fallback', U.extractSafeCode(null) === 'unknown_tokenization_error');
ok('string -> fallback (nunca ecoa conteudo)', U.extractSafeCode('4111111111111111') === 'unknown_tokenization_error');
ok('undefined -> fallback', U.extractSafeCode(undefined) === 'unknown_tokenization_error');
ok('{} -> fallback', U.extractSafeCode({}) === 'unknown_tokenization_error');
ok('hash 32hex rejeitado', U.extractSafeCode({ code: 'e3ed6f098462036dd2cbabe314b9de2a' }) === 'unknown_tokenization_error');
ok('mensagem longa rejeitada', U.extractSafeCode({ message: 'x'.repeat(200) }) === 'unknown_tokenization_error');
ok('cause sem code -> fallback', U.extractSafeCode({ cause: [{ description: 'alguma coisa' }] }) === 'unknown_tokenization_error');
ok('codigo com espaco rejeitado', U.extractSafeCode({ code: 'tem espaco' }) === 'unknown_tokenization_error');

console.log('TOTAL: ' + (pass + fail) + ' Passed: ' + pass + ' Failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);

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

// --- isValidDeviceId (defensiva, sem charset presumido) ---
ok('device simples valido', U.isValidDeviceId('MPDEVICESESSION1234567890abcdef') === true);
ok('device curto valido', U.isValidDeviceId('abcd') === true);
ok('device com simbolos NAO descartado', U.isValidDeviceId('abc+def/ghi=jklm|nop:qrs_tuv.wxy-z01') === true);
ok('device com espaco interno valido (trim externo)', U.isValidDeviceId('  abcdefgh  ') === true);
ok('device vazio invalido', U.isValidDeviceId('') === false);
ok('device curto demais invalido', U.isValidDeviceId('abc') === false);
ok('device com CR rejeitado', U.isValidDeviceId('abcdef\r\nghij') === false);
ok('device com LF rejeitado', U.isValidDeviceId('abcdef\nghij') === false);
ok('device gigante rejeitado', U.isValidDeviceId(new Array(600).join('x')) === false);
ok('device null rejeitado', U.isValidDeviceId(null) === false);
ok('device nao-string rejeitado', U.isValidDeviceId(12345) === false);

// --- inspectDevice (global -> fallback oficial -> ausente) ---
function scopeWith(globalVal, fallbackVal) {
    return {
        MP_DEVICE_SESSION_ID: globalVal,
        document: {
            getElementById: function (id) {
                if (id === 'deviceId' || id === 'deviceID') return { value: fallbackVal };
                return null;
            }
        }
    };
}
var r1 = U.inspectDevice(scopeWith('GLOBAL1234', 'FALLBACK12'));
ok('global tem prioridade', r1.value === 'GLOBAL1234' && r1.source === 'global');
var r2 = U.inspectDevice({ document: { getElementById: function () { return { value: 'FALLBACK12345' }; } } });
ok('fallback oficial usado sem global', r2.value === 'FALLBACK12345' && r2.source === 'fallback');
var r3 = U.inspectDevice({});
ok('ausente retorna vazio distinguivel', r3.value === '' && r3.source === null && r3.sawGlobal === false);
var r4 = U.inspectDevice({ MP_DEVICE_SESSION_ID: '  ' });
ok('global vazio = missing (nao invalid)', r4.value === '' && r4.sawGlobal === false);
var r5 = U.inspectDevice({ MP_DEVICE_SESSION_ID: 'ab' });
ok('global curto = invalid (sawGlobal true)', r5.value === '' && r5.sawGlobal === true);

// --- waitForDeviceId (poll limitado, resolve imediato quando presente) ---
async function waitTests() {
    var t0 = Date.now();
    var w1 = await U.waitForDeviceId(scopeWith('IMMEDIATE1234', ''), { intervalMs: 25, maxMs: 500 });
    var dt1 = Date.now() - t0;
    ok('resolve imediato sem delay', w1.value === 'IMMEDIATE1234' && dt1 < 150);

    var dyn = { v: '' };
    var scope2 = { get MP_DEVICE_SESSION_ID() { return dyn.v; }, document: { getElementById: function () { return null; } } };
    setTimeout(function () { dyn.v = 'APPEARED123456'; }, 80);
    var w2 = await U.waitForDeviceId(scope2, { intervalMs: 25, maxMs: 1000 });
    ok('resolve quando aparece no poll', w2.value === 'APPEARED123456');

    var t3 = Date.now();
    var w3 = await U.waitForDeviceId({}, { intervalMs: 25, maxMs: 120 });
    var dt3 = Date.now() - t3;
    ok('timeout resolve vazio sem travar', w3.value === '' && dt3 >= 100 && dt3 < 800);
}
waitTests().then(function () {
    console.log('TOTAL: ' + (pass + fail) + ' Passed: ' + pass + ' Failed: ' + fail);
    process.exit(fail > 0 ? 1 : 0);
});

/**
 * mp-card-utils.js — logica PURA (sem DOM) do formulario de cartao.
 *
 * Testavel via node (module.exports) e usada pelo subscribe.js no browser
 * (window.MpCardUtils). Nenhuma funcao aqui toca em dados sensiveis alem
 * de normalizar/validar formato; nada e logado aqui.
 */
(function (root, factory) {
    'use strict';
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = factory();
    } else {
        root.MpCardUtils = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function onlyDigits(v) {
        return String(v == null ? '' : v).replace(/\D/g, '');
    }

    /**
     * Normaliza mes: "1"->"01", "01"->"01", "12"->"12".
     * Retorna '' quando invalido (fora de 1..12).
     */
    function normalizeMonth(v) {
        var d = onlyDigits(v);
        if (d.length < 1 || d.length > 2) return '';
        var n = parseInt(d, 10);
        if (isNaN(n) || n < 1 || n > 12) return '';
        return (n < 10 ? '0' : '') + n;
    }

    /**
     * Normaliza ano para 4 digitos (doc oficial): "26"->"2026",
     * "2026"->"2026". Retorna '' quando invalido.
     */
    function normalizeYear(v) {
        var d = onlyDigits(v);
        if (d.length === 2) return '20' + d;
        if (d.length === 4) {
            var n = parseInt(d, 10);
            if (!isNaN(n) && n >= 2000 && n <= 2099) return d;
        }
        return '';
    }

    var SAFE_CODE_RE = /^[a-z0-9_.-]{1,64}$/;

    function pickCode(v) {
        if (typeof v !== 'string') return '';
        var s = v.trim().toLowerCase();
        if (!SAFE_CODE_RE.test(s)) return '';
        // Recusa valores com cara de dado (sequencias longas de digitos,
        // hashes) — codigo de erro e identificador curto.
        if (/[0-9]{8,}/.test(s.replace(/[^0-9]/g, '')) && /[0-9]/.test(s) && s.length > 24) return '';
        if (/^[0-9a-f]{32,}$/.test(s)) return '';
        return s;
    }

    /**
     * Extrai SOMENTE um codigo seguro do erro do SDK (code/type/
     * cause[].code/status). Nunca retorna mensagens, objetos ou valores
     * que possam conter dados do cartao. Fallback estavel quando o SDK
     * nao fornece codigo.
     */
    function extractSafeCode(err) {
        if (!err || typeof err !== 'object') return 'unknown_tokenization_error';
        var candidates = [];
        if (err.code) candidates.push(err.code);
        if (err.type) candidates.push(err.type);
        if (typeof err.status !== 'undefined') candidates.push('http_' + String(err.status));
        var cause = err.cause;
        if (Array.isArray(cause)) {
            for (var i = 0; i < cause.length && i < 5; i++) {
                if (cause[i] && cause[i].code) candidates.push(cause[i].code);
            }
        } else if (cause && cause.code) {
            candidates.push(cause.code);
        }
        for (var j = 0; j < candidates.length; j++) {
            var c = pickCode(candidates[j]);
            if (c) return c;
        }
        return 'unknown_tokenization_error';
    }

    return {
        normalizeMonth: normalizeMonth,
        normalizeYear: normalizeYear,
        extractSafeCode: extractSafeCode,
        isValidDeviceId: isValidDeviceId,
        inspectDevice: inspectDevice,
        waitForDeviceId: waitForDeviceId
    };

    /**
     * Validacao DEFENSIVA do Device ID, sem presumir charset (a documentacao
     * oficial nao define o formato exato): string, trim, tamanho razoavel,
     * sem CR/LF nem caracteres de controle. Nunca rejeita por simbolo.
     */
    function isValidDeviceId(v) {
        if (typeof v !== 'string') return false;
        var s = v.trim();
        if (s.length < 4 || s.length > 512) return false;
        if (/[\x00-\x1F\x7F]/.test(s)) return false;
        return true;
    }

    function readGlobal(scope) {
        try {
            if (scope && typeof scope.MP_DEVICE_SESSION_ID !== 'undefined' && scope.MP_DEVICE_SESSION_ID !== null) {
                return String(scope.MP_DEVICE_SESSION_ID);
            }
        } catch (e) { /* sem acesso: trata como ausente */ }
        return '';
    }

    function readFallback(scope) {
        // Fallback OFICIAL documentado: security.js preenche elemento com
        // id="deviceId" (ou "deviceID"). Somente leitura, sem escrita.
        try {
            var doc = scope && scope.document;
            if (!doc || typeof doc.getElementById !== 'function') return '';
            var ids = ['deviceId', 'deviceID'];
            for (var i = 0; i < ids.length; i++) {
                var el = doc.getElementById(ids[i]);
                if (el && typeof el.value !== 'undefined' && el.value !== null) {
                    var v = String(el.value);
                    if (v.trim() !== '') return v;
                }
            }
        } catch (e) { /* sem acesso: trata como ausente */ }
        return '';
    }

    /**
     * Inspeciona fontes oficiais SEM expor valores ao chamador além do
     * necessario. Retorna {value, source, sawGlobal, sawFallback}:
     *  - value: ID valido ou '' (unico valor propagado adiante);
     *  - source: 'global' | 'fallback' | null;
     *  - saw*: havia algo nao-vazio na fonte (mesmo que invalido),
     *    permitindo distinguir missing x invalid sem registrar valores.
     */
    function inspectDevice(scope) {
        var g = readGlobal(scope);
        var f = readFallback(scope);
        var sawGlobal = g.trim() !== '';
        var sawFallback = f.trim() !== '';
        if (isValidDeviceId(g)) return { value: g.trim(), source: 'global', sawGlobal: sawGlobal, sawFallback: sawFallback };
        if (isValidDeviceId(f)) return { value: f.trim(), source: 'fallback', sawGlobal: sawGlobal, sawFallback: sawFallback };
        return { value: '', source: null, sawGlobal: sawGlobal, sawFallback: sawFallback };
    }

    /**
     * Aguarda o Device ID por janela curta e limitada (poll 50-100ms,
     * teto ~1-2s). Resolve IMEDIATAMENTE se ja disponivel (sem delay).
     * Sempre resolve (nunca rejeita): {value, source, ...} ou vazio.
     */
    function waitForDeviceId(scope, options) {
        options = options || {};
        var interval = options.intervalMs || 75;
        var maxMs = options.maxMs || 1500;
        if (interval < 10) interval = 10;
        if (maxMs < 0) maxMs = 0;
        return new Promise(function (resolve) {
            var first = inspectDevice(scope);
            if (first.value || maxMs <= 0) { resolve(first); return; }
            var start = Date.now();
            function tick() {
                var r = inspectDevice(scope);
                if (r.value || (Date.now() - start) >= maxMs) { resolve(r); return; }
                setTimeout(tick, interval);
            }
            setTimeout(tick, interval);
        });
    }
}));

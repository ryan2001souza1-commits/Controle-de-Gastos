/**
 * Testes DOM do wiring REAL de public/js/mp_subscribe.js (sem pagamento).
 *
 * Monta um DOM falso mínimo + fetch falso + SDK falso, carrega o arquivo de
 * verdade, dispara onSubmit capturado e prova o comportamento da UI:
 *  D1. initial cancelled -> msg cancelamento, sem poll, botão habilitado
 *  D2. initial rejected  -> msg recusa, sem poll, botão habilitado
 *  D3. initial active    -> redirect, sem erro
 *  D4. pending -> poll -> cancelled -> msg + botão + loading off
 *  D5. pending -> poll -> active    -> redirect
 *  D6. timeout -> msg deadline + botão "Verificar novamente"; retry repolla
 *  D7. terminal tardio nunca ressuscita (stale ignorado na UI)
 *  D8. 3 falhas de transporte -> msg + retry + botão habilitado
 *  D9. [subscription-ui] sanitizado (sufixo; sem token/cartão/csrf)
 * D10. spinner/botão/erro em cada transição
 *
 * Rode: node tests/subscription_ui_dom_tests.js (exit 1 em falha).
 */
'use strict';
const assert = require('node:assert/strict');

const ATTEMPT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SUFFIX = 'aaaaaaaa';

// ---------- DOM falso ----------
function makeStyle() { return { display: '', opacity: '', cursor: '' }; }
function makeNode() {
    return {
        children: [],
        parentNode: null,
        style: makeStyle(),
        textContent: '',
        disabled: false,
        value: '',
        type: '',
        className: '',
        onclick: null,
    };
}
function makeEnv() {
    const nodes = {};
    for (const id of ['mp-checkout-panel', 'mp-diag-line', 'mp-card-form',
        'mp-pay-button', 'mp-checkout-error', 'mp-checkout-loading']) {
        nodes[id] = makeNode();
    }
    const panel = nodes['mp-checkout-panel'];
    const attrs = {
        'data-mp-public-key': 'TEST-00000000-0000-0000-0000-000000000000',
        'data-attempt-token': ATTEMPT,
        'data-mp-amount': '9.90',
    };
    panel.getAttribute = (k) => attrs[k] || '';
    panel.removeAttribute = (k) => { delete attrs[k]; };
    const form = nodes['mp-card-form'];
    form.querySelector = () => ({ value: 'csrf_test_value' });
    const errorBox = nodes['mp-checkout-error'];
    const parent = makeNode();
    parent.insertBefore = (btn, ref) => {
        btn.parentNode = parent;
        parent.children.push(btn);
    };
    parent.removeChild = (btn) => {
        parent.children = parent.children.filter((c) => c !== btn);
        btn.parentNode = null;
    };
    errorBox.parentNode = parent;

    const doc = {
        getElementById: (id) => nodes[id] || null,
        createElement: () => {
            const b = makeNode();
            b.parentNode = null;
            return b;
        },
    };
    return { doc, nodes, parent };
}

// ---------- timers falsos ----------
function makeTimers() {
    const timers = new Map();
    let nextId = 1;
    return {
        timers,
        set: (fn) => { const id = nextId++; timers.set(id, fn); return id; },
        clear: (id) => { timers.delete(id); },
        async drain(rounds = 500) {
            for (let r = 0; r < rounds; r++) {
                await Promise.resolve();
                await new Promise((res) => setImmediate(res));
                if (timers.size === 0) {
                    await Promise.resolve();
                    await new Promise((res) => setImmediate(res));
                    if (timers.size === 0) break;
                    continue;
                }
                for (const id of [...timers.keys()]) {
                    const fn = timers.get(id);
                    timers.delete(id);
                    fn();
                }
            }
        },
    };
}

let passed = 0;
function ok(cond, name) {
    if (!cond) { console.error('  ✗ ' + name); process.exitCode = 1; }
    else { passed++; console.log('  ✓ ' + name); }
}

// Carrega o arquivo UMA vez por cenário (IIFE faz bootstrap no require).
function loadApp({ subscribeResponses, pollScript, deviceId }) {
    delete require.cache[require.resolve('../public/js/mp_subscribe.js')];
    const { doc, nodes, parent } = makeEnv();
    const T = makeTimers();
    const uiLogs = [];
    const fetches = [];
    const postBodies = [];
    let pollIdx = 0;
    let capturedSubmit = null;

    global.document = doc;
    global.window = { location: { href: '' } };
    global.setTimeout = T.set;
    global.clearTimeout = T.clear;
    global.console.info = (...a) => uiLogs.push(a.join(' '));
    if (deviceId !== undefined) {
        global.MP_DEVICE_SESSION_ID = deviceId;
    } else {
        delete global.MP_DEVICE_SESSION_ID;
    }
    global.MercadoPago = function () {
        return {
            cardForm: (cfg) => {
                capturedSubmit = cfg.callbacks.onSubmit;
                return { getCardFormData: () => ({ token: 'tok_test_single_use' }) };
            },
        };
    };
    global.fetch = (url, opts) => {
        fetches.push(String(url));
        if (String(url).includes('subscribe_token')) {
            try { postBodies.push(JSON.parse((opts && opts.body) || '{}')); } catch (e) { postBodies.push({}); }
            const r = subscribeResponses.shift() || subscribeResponses[subscribeResponses.length - 1];
            if (r && r.throw) return Promise.reject(new Error('net down'));
            return Promise.resolve({ status: r.http, json: () => Promise.resolve(r.body) });
        }
        const step = pollScript[Math.min(pollIdx++, pollScript.length - 1)];
        if (step === 'THROW') return Promise.reject(new Error('net down'));
        if (step === 'HTML') return Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new Error('not json')) });
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(step) });
    };

    require('../public/js/mp_subscribe.js');
    const api = require('../public/js/mp_subscribe.js');
    return {
        nodes, parent, uiLogs, fetches, postBodies, T, api,
        submit: () => capturedSubmit({ preventDefault: () => {} }),
        polls: () => fetches.filter((u) => u.includes('subscription_status')).length,
        retryBtn: () => parent.children.find((c) => c.type === 'button'),
        cleanup: () => {
            delete global.document; delete global.window;
            delete global.MercadoPago; delete global.fetch;
            delete global.MP_DEVICE_SESSION_ID;
        },
    };
}

const CANCELLED = { ok: true, status: 'cancelled', outcome: 'cancelled' };
const REJECTED = { ok: true, status: 'rejected', outcome: 'rejected' };
const ACTIVE = { ok: true, status: 'active', outcome: 'active', redirect: '/index.php?action=meu_plano&subscribed=1' };
const PENDING = { ok: true, status: 'pending', outcome: 'processing', linked: true };

(async () => {
    // ---- D1: initial cancelled ----
    console.log('--- D1 initial cancelled ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: CANCELLED }], pollScript: [PENDING] });
        await app.submit();
        await app.T.drain(20);
        ok(app.nodes['mp-checkout-error'].textContent.includes('cancelado ou não autorizado'), 'msg cancelamento imediata');
        ok(app.polls() === 0, 'NENHUM poll após cancelled inicial');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado');
        ok(app.nodes['mp-checkout-loading'].style.display === 'none', 'spinner removido');
        app.cleanup();
    }

    // ---- D2: initial rejected ----
    console.log('--- D2 initial rejected ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: REJECTED }], pollScript: [PENDING] });
        await app.submit();
        await app.T.drain(20);
        ok(app.nodes['mp-checkout-error'].textContent.includes('outro cartão ou fale com o banco'), 'msg recusa imediata');
        ok(app.polls() === 0, 'NENHUM poll após rejected inicial');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado');
        app.cleanup();
    }

    // ---- D3: initial active ----
    console.log('--- D3 initial active ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: ACTIVE }], pollScript: [PENDING] });
        await app.submit();
        await app.T.drain(20);
        ok(global.window.location.href.includes('subscribed=1'), 'redirect de sucesso');
        ok(app.polls() === 0, 'NENHUM poll após active inicial');
        app.cleanup();
    }

    // ---- D4: pending -> poll -> cancelled ----
    console.log('--- D4 pending -> cancelled no poll ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING, CANCELLED] });
        await app.submit();
        await app.T.drain();
        const err = app.nodes['mp-checkout-error'].textContent;
        ok(err.includes('cancelado ou não autorizado'), 'poll cancelled encerra com msg (texto: ' + err.slice(0, 40) + ')');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado');
        ok(app.nodes['mp-checkout-loading'].style.display === 'none', 'spinner removido');
        ok(app.T.timers.size === 0, 'zero timers órfãos');
        app.cleanup();
    }

    // ---- D5: pending -> poll -> active ----
    console.log('--- D5 pending -> active no poll ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING, ACTIVE] });
        await app.submit();
        await app.T.drain();
        ok(global.window.location.href.includes('subscribed=1'), 'poll active redireciona');
        ok(app.T.timers.size === 0, 'zero timers órfãos');
        app.cleanup();
    }

    // ---- D6: timeout + retry ----
    console.log('--- D6 timeout + retry ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: new Array(40).fill(PENDING) });
        await app.submit();
        await app.T.drain();
        ok(app.nodes['mp-checkout-error'].textContent.includes('verificar novamente mais tarde'), 'msg deadline');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado no deadline');
        const btn = app.retryBtn();
        ok(!!btn && btn.textContent.includes('Verificar'), 'botão Verificar novamente presente');
        const pollsBefore = app.polls();
        btn.onclick();
        await app.T.drain(30);
        ok(app.polls() > pollsBefore, 'retry reinicia o poll (sem novo cartão)');
        app.cleanup();
    }

    // ---- D7: terminal não ressuscita ----
    console.log('--- D7 terminal estável ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [CANCELLED, PENDING, PENDING, ACTIVE] });
        await app.submit();
        await app.T.drain();
        const err = app.nodes['mp-checkout-error'].textContent;
        ok(err.includes('cancelado ou não autorizado'), 'primeiro terminal (cancelled) prevalece');
        ok(!global.window.location.href.includes('subscribed=1'), 'active tardio NUNCA executa');
        ok(app.T.timers.size === 0, 'zero timers');
        app.cleanup();
    }

    // ---- D8: transport abort ----
    console.log('--- D8 transport abort ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: ['THROW', 'THROW', 'THROW'] });
        await app.submit();
        await app.T.drain();
        ok(app.nodes['mp-checkout-error'].textContent.includes('conexão'), 'msg de transporte após 3 falhas');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado');
        ok(!!app.retryBtn(), 'retry oferecido');
        ok(app.T.timers.size === 0, 'zero timers');
        app.cleanup();
    }

    // ---- D9: observabilidade sanitizada ----
    console.log('--- D9 uiLog sanitizado ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [CANCELLED] });
        await app.submit();
        await app.T.drain();
        const lines = app.uiLogs.filter((l) => l.includes('[subscription-ui]'));
        ok(lines.length >= 2, 'linhas [subscription-ui] em initial+poll (' + lines.length + ')');
        const blob = lines.join('\n');
        ok(blob.includes('attempt_suffix=' + SUFFIX), 'sufixo presente');
        ok(!blob.includes(ATTEMPT), 'token completo NUNCA logado');
        ok(!blob.includes('tok_test_single_use'), 'card token NUNCA logado');
        ok(!blob.includes('csrf_test_value'), 'csrf NUNCA logado');
        app.cleanup();
    }

    // ---- D10: estados do spinner/botão ----
    console.log('--- D10 spinner/botão ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING, REJECTED] });
        await app.submit();
        await new Promise((r) => setImmediate(r));
        await new Promise((r) => setImmediate(r));
        ok(app.nodes['mp-pay-button'].disabled === true, 'durante poll: botão travado');
        await app.T.drain();
        ok(app.nodes['mp-pay-button'].disabled === false, 'após terminal: botão livre');
        ok(app.nodes['mp-checkout-loading'].style.display === 'none', 'após terminal: sem spinner');
        app.cleanup();
    }

    // ---- D11: sufixo hex preserva dígitos (regressão do "de") ----
    console.log('--- D11 sufixo com dígitos ---');
    {
        delete require.cache[require.resolve('../public/js/mp_subscribe.js')];
        const { doc } = makeEnv();
        // Reescreve o token do painel com dígitos antes do require.
        const panel = doc.getElementById('mp-checkout-panel');
        const tight = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        panel.getAttribute = (k) => (k === 'data-attempt-token' ? tight : (k === 'data-mp-amount' ? '9.90' : 'TEST-x'));
        panel.removeAttribute = () => {};
        const uiLogs = [];
        global.document = doc;
        global.window = { location: { href: '' } };
        global.console.info = (...a) => uiLogs.push(a.join(' '));
        global.MercadoPago = function () {
            return { cardForm: () => ({ getCardFormData: () => ({ token: 't' }) }) };
        };
        let submitFn = null;
        global.MercadoPago = function () {
            return {
                cardForm: (cfg) => {
                    submitFn = cfg.callbacks.onSubmit;
                    return { getCardFormData: () => ({ token: 't' }) };
                },
            };
        };
        global.fetch = () => Promise.resolve({ status: 200, json: () => Promise.resolve({ ok: true, status: 'pending', outcome: 'processing' }) });
        require('../public/js/mp_subscribe.js');
        await submitFn({ preventDefault: () => {} });
        await new Promise((r) => setImmediate(r));
        await new Promise((r) => setImmediate(r));
        const blob = uiLogs.join('\n');
        ok(blob.includes('attempt_suffix=6d7e8f90'), 'sufixo hex com dígitos preservado (não vira "de")');
        ok(!blob.includes(tight), 'token completo nunca logado');
        delete global.document; delete global.window;
        delete global.MercadoPago; delete global.fetch;
    }

    // ---- D12: duplo submit -> UM único POST (sem reuso de token) ----
    console.log('--- D12 double submit ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 400, body: { ok: false, error: 'invalid_card' } }], pollScript: [{ ok: true, status: 'pending', outcome: 'processing' }] });
        await app.submit();
        await app.submit(); // segundo clique imediato: guardado por `submitted`
        await app.T.drain(30);
        const posts = app.fetches.filter((u) => u.includes('subscribe_token')).length;
        ok(posts === 1, 'duplo clique = 1 POST subscribe_token (' + posts + ')');
        ok(app.polls() === 0, 'erro 400 invalid_card NÃO inicia poll');
        ok(app.nodes['mp-checkout-error'].textContent.includes('Verifique os dados do cartão'), 'msg orienta conferir dados');
        app.cleanup();
    }

    // ---- D13: initial invalid_card (CC_VAL_433 mapeado) ----
    console.log('--- D13 initial invalid_card ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 400, body: { ok: false, error: 'invalid_card' } }], pollScript: [] });
        await app.submit();
        await app.T.drain(20);
        ok(app.nodes['mp-checkout-error'].textContent.includes('Verifique os dados do cartão'), 'msg adequada, sem spinner infinito');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado para corrigir e tentar de novo');
        ok(app.T.timers.size === 0, 'zero timers');
        app.cleanup();
    }

    // ---- D12: abort no meio do voo + stale ignorado no wiring ----
    console.log('--- D12 abort mid-flight ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING] });
        await app.submit();
        await new Promise((r) => setImmediate(r));
        await new Promise((r) => setImmediate(r));
        // Poll #1 em voo (fetch pendente). Para tudo e resolve como ACTIVE:
        // deve ser ignorado (geração morta), sem redirect.
        ok(app.polls() === 1, 'poll em voo iniciado');
        app.api.__wiring.stopSubscriptionPolling();
        ok(app.T.timers.size === 0, 'stop limpa timers com fetch em voo');
        app.cleanup();
    }

    // ---- D13: renderTerminalError é atômico ----
    console.log('--- D13 terminal atômico ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING] });
        // Simula processing visível e então terminal direto:
        app.nodes['mp-checkout-loading'].style.display = 'block';
        app.nodes['mp-pay-button'].disabled = true;
        app.api.__wiring.renderTerminalError('Msg terminal X');
        ok(app.nodes['mp-checkout-loading'].style.display === 'none', 'spinner escondido');
        ok(app.nodes['mp-checkout-error'].style.display === 'block', 'erro visível');
        ok(app.nodes['mp-checkout-error'].textContent === 'Msg terminal X', 'texto terminal aplicado');
        ok(app.nodes['mp-pay-button'].disabled === false, 'botão reabilitado');
        ok(app.T.timers.size === 0, 'zero timers');
        app.cleanup();
    }

    // ---- D14: log inequívoco action/error separados ----
    console.log('--- D14 log inequívoco ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 400, body: { ok: false, error: 'invalid_card' } }], pollScript: [] });
        await app.submit();
        await app.T.drain(20);
        const line = app.uiLogs.find((l) => l.includes('source=initial'));
        ok(!!line && line.includes('action=show_error') && line.includes('error=invalid_card'), 'action e error em campos separados (linha: ' + (line || '?').slice(0, 120) + ')');
        ok(!/show_errorinvalid|remova/i.test(line || ''), 'sem fusão ambígua (regressão do "removalid_card")');
        app.cleanup();
    }

    // ---- DID1–DID4: Device ID oficial (sem MP real) ----
    console.log('--- DID frontend: device id ---');
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING], deviceId: 'dev-fingerprint-abc12345' });
        await app.submit();
        await app.T.drain(10);
        ok(app.postBodies.length >= 1 && app.postBodies[0].device_id === 'dev-fingerprint-abc12345', 'DID1: MP_DEVICE_SESSION_ID presente -> enviado ao backend (verbatim)');
        const blob = app.uiLogs.join('\n');
        ok(!blob.includes('dev-fingerprint-abc12345'), 'DID4: device nunca em log de console');
        ok(!app.fetches.some((u) => u.includes('dev-fingerprint')), 'DID4b: device nunca em URL');
        app.cleanup();
    }
    {
        const app = loadApp({ subscribeResponses: [{ http: 200, body: PENDING }], pollScript: [PENDING] });
        await app.submit();
        await app.T.drain(10);
        ok(app.postBodies.length >= 1 && !('device_id' in app.postBodies[0]), 'DID2: ausente -> chave omitida do POST');
        ok(app.nodes['mp-checkout-loading'].style.display === 'block' || app.polls() >= 1, 'DID3: script atrasado/ausente não quebra o fluxo');
        app.cleanup();
    }

    console.log(`\nTotal: ${passed} passed`);
})().catch((e) => {
    console.error('FATAL', e);
    process.exitCode = 1;
});

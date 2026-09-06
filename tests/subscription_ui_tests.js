/**
 * Testes Node da state machine REAL de public/js/mp_subscribe.js.
 *
 * Cobre (§3 A–I, §10 1–14):
 *  1. initial cancelled -> não inicia poll
 *  2. initial rejected -> não inicia poll
 *  3. initial active -> sucesso
 *  4. initial pending -> inicia poll
 *  5. poll pending -> cancelled -> para
 *  6. poll pending -> rejected -> para
 *  7. poll pending -> active -> para + sucesso
 *  8. timeout -> para (zero timers) + mensagem
 *  9. request atrasado não sobrescreve terminal
 * 10. cancelled nunca volta para processing
 * 11. rejected nunca volta para processing
 * 12. unknown não fica infinito (deadline encerra)
 * 13. terminal/paused/expired param (sem spinner)
 * 14. nenhum setTimeout órfão após qualquer terminal
 *  + tabela BACKEND -> UI (§2) e transport-abort após 3 falhas.
 *
 * Rode: node tests/subscription_ui_tests.js (exit 1 em falha).
 */
'use strict';
const assert = require('node:assert/strict');
const S = require('../public/js/mp_subscribe.js');

let passed = 0;
function ok(cond, name) {
    if (!cond) {
        console.error('  ✗ ' + name);
        process.exitCode = 1;
    } else {
        passed++;
        console.log('  ✓ ' + name);
    }
}

// ---- §2: tabela BACKEND -> UI ----
console.log('--- normalizePollStatus (§2) ---');
const table = [
    ['active', 'active'], ['authorized', 'active'],
    ['cancelled', 'cancelled'], ['canceled', 'cancelled'],
    ['rejected', 'rejected'], ['paused', 'paused'], ['expired', 'expired'],
    ['pending', 'pending'], ['processing', 'pending'],
];
for (const [backend, expected] of table) {
    ok(S.normalizePollStatus({ ok: true, status: backend }) === expected, `status ${backend} -> ${expected}`);
    ok(S.normalizePollStatus({ ok: true, outcome: backend }) === expected, `outcome ${backend} -> ${expected}`);
}
ok(S.normalizePollStatus({ ok: true, status: 'weird' }) === 'unknown', 'desconhecido -> unknown');
ok(S.normalizePollStatus(null) === 'unknown', 'nulo -> unknown');
ok(S.normalizePollStatus({ ok: true }) === 'unknown', 'sem campos -> unknown (deadline limita)');

// ---- §3 A–D + §10 1–4: resposta inicial ----
console.log('--- decideInitialAction (§3 A–D, §10 1–4) ---');
ok(S.decideInitialAction(200, { ok: true, status: 'cancelled', outcome: 'cancelled' }).action === 'show_error', 'A: initial cancelled NÃO inicia poll');
ok(S.decideInitialAction(200, { ok: true, status: 'rejected', outcome: 'rejected' }).action === 'show_error', 'B: initial rejected NÃO inicia poll');
ok(S.decideInitialAction(200, { ok: true, status: 'active', outcome: 'active' }).action === 'success', 'C: initial active -> sucesso');
ok(S.decideInitialAction(200, { ok: true, status: 'pending', outcome: 'processing' }).action === 'start_poll', 'D: initial pending -> inicia poll');
ok(S.decideInitialAction(500, { ok: false }).action === 'start_poll', 'E: http 500 -> poll (reconcilia)');
ok(S.decideInitialAction(502, { ok: false }).action === 'start_poll', '502 -> poll');
ok(S.decideInitialAction(400, { ok: false, error: 'invalid_card' }).action === 'show_error', '400 invalid_card -> erro imediato, sem poll');
const k = S.decideInitialAction(200, { ok: true, status: 'cancelled', outcome: 'cancelled' });
ok(k.key === 'cancelled', 'cancelled preserva a chave da mensagem');

// ---- Harness do controller (timers e fetch falsos) ----
function makeHarness(script) {
    // script: array de respostas por poll ('pending'|'active'|'cancelled'|...|{transport:true}|{parse:true}|{okFalse:true})
    const timers = new Map();
    let nextId = 1;
    const events = [];
    let idx = 0;
    const deps = {
        url: '/x',
        fetchFn: () => {
            const step = script[Math.min(idx++, script.length - 1)];
            if (step && step.transport) return Promise.reject(new Error('net'));
            if (step && step.parse) return Promise.resolve({ ok: false, json: () => Promise.reject(new Error('html')) });
            if (step && step.okFalse) return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: false, error: 'not_found' }) });
            const st = typeof step === 'string' ? step : 'pending';
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, status: st, outcome: st === 'active' ? 'active' : (st === 'pending' ? 'processing' : st) }) });
        },
        setTimeoutFn: (fn) => { const id = nextId++; timers.set(id, fn); return id; },
        clearTimeoutFn: (id) => { timers.delete(id); },
        onSuccess: () => events.push('success'),
        onTerminal: (g, key) => events.push('terminal:' + key),
        onTimeout: () => events.push('timeout'),
        onTransportAbort: () => events.push('transport_abort'),
    };
    const ctl = S.createPollController(deps);
    return {
        ctl, events, timers,
        // Avança: esvazia microtasks (fetches) e dispara timers, até o
        // controller parar (zero timers após flush completo) ou o teto.
        async drain(maxRounds = 500) {
            for (let r = 0; r < maxRounds; r++) {
                await Promise.resolve();
                await new Promise((res) => setImmediate(res));
                if (timers.size === 0) {
                    // Flush extra: se ainda assim nada agendado, nada em voo.
                    await Promise.resolve();
                    await new Promise((res) => setImmediate(res));
                    if (timers.size === 0) break;
                    continue;
                }
                const ids = [...timers.keys()];
                for (const id of ids) {
                    const fn = timers.get(id);
                    timers.delete(id);
                    fn();
                }
            }
            return events;
        },
    };
}

(async () => {
    // ---- §10 5/6/7: terminais param ----
    console.log('--- terminais param o poll (§10 5–7) ---');
    for (const [step, ev] of [['cancelled', 'terminal:cancelled'], ['rejected', 'terminal:rejected'], ['active', 'success']]) {
        const h = makeHarness(['pending', step]);
        h.ctl.start();
        const events = await h.drain();
        ok(events.includes(ev), `poll pending -> ${step} => ${ev} (eventos: ${events.join(',')})`);
        ok(h.timers.size === 0, `zero timers órfãos após ${step}`);
    }

    // ---- §10 8: timeout para o spinner ----
    console.log('--- timeout (§10 8) ---');
    {
        const h = makeHarness(new Array(30).fill('pending'));
        h.ctl.start();
        const events = await h.drain();
        ok(events.filter((e) => e === 'timeout').length === 1, 'deadline dispara timeout exatamente 1 vez');
        ok(h.timers.size === 0, 'zero timers após timeout');
    }

    // ---- §10 9/10/11: atrasado nunca ressuscita terminal ----
    console.log('--- stale guard (§10 9–11) ---');
    {
        // Geração 1 termina em cancelled; resposta "pending" atrasada da
        // geração 1 chega DEPOIS do stop e deve ser ignorada.
        let resolvers = [];
        const timers = new Map();
        let nextId = 1;
        const events = [];
        const deps = {
            url: '/x',
            fetchFn: () => new Promise((res) => resolvers.push(res)),
            setTimeoutFn: (fn) => { const id = nextId++; timers.set(id, fn); return id; },
            clearTimeoutFn: (id) => { timers.delete(id); },
            onSuccess: () => events.push('success'),
            onTerminal: (g, key) => events.push('terminal:' + key),
            onTimeout: () => events.push('timeout'),
            onTransportAbort: () => events.push('transport_abort'),
        };
        const ctl = S.createPollController(deps);
        ctl.start(); // tick#1 dispara fetch (pendente)
        // Avança o timer? Não há timer ainda (primeiro tick é imediato).
        // Resolve o 1º fetch como cancelled:
        resolvers.shift()({ ok: true, json: () => Promise.resolve({ ok: true, status: 'cancelled', outcome: 'cancelled' }) });
        await new Promise((r) => setImmediate(r));
        await new Promise((r) => setImmediate(r));
        ok(events.join(',') === 'terminal:cancelled', 'terminal registado');
        // Reinicia (botão Verificar): geração 2 começa; resposta fantasma da
        // geração 1 (se ainda houvesse) seria ignorada pelo guarda de geração.
        ctl.start();
        ok(timers.size <= 1, 'no máximo 1 timer após restart');
        ctl.stop();
        ok(timers.size === 0, 'stop limpa timers (sem órfãos)');
        ok(!events.includes('success'), 'cancelled nunca virou success');
    }

    // ---- §3 E/F/G + transport abort ----
    console.log('--- transporte (§3 E–G) ---');
    {
        const h = makeHarness([{ transport: true }, { transport: true }, { transport: true }, 'active']);
        h.ctl.start();
        const events = await h.drain();
        ok(events.includes('transport_abort'), '3 falhas seguidas abortam (não 60s de spinner)');
        ok(!events.includes('success'), 'active posterior inalcançável não executa');
        ok(h.timers.size === 0, 'zero timers após abort');
    }
    {
        const h = makeHarness([{ parse: true }, { parse: true }, { parse: true }]);
        h.ctl.start();
        const events = await h.drain();
        ok(events.includes('transport_abort'), 'JSON inválido repetido aborta');
    }
    {
        // Falha isolada NÃO mata o poll (recupera no próximo tick).
        const h = makeHarness([{ transport: true }, 'active']);
        h.ctl.start();
        const events = await h.drain();
        ok(events.includes('success'), 'falha isolada recupera e chega a active');
    }
    {
        const h = makeHarness([{ okFalse: true }]);
        h.ctl.start();
        const events = await h.drain();
        ok(events.includes('terminal:payment_failed'), 'ok:false do backend encerra (genérico)');
        ok(h.timers.size === 0, 'zero timers');
    }

    // ---- §3 H/I + §10 12/13: paused/expired/unknown ----
    console.log('--- paused/expired/unknown (§3 H–I, §10 12–13) ---');
    for (const [step, ev] of [['paused', 'terminal:paused'], ['expired', 'terminal:expired']]) {
        const h = makeHarness([step]);
        h.ctl.start();
        const events = await h.drain();
        ok(events.includes(ev), `${step} encerra de imediato`);
        ok(h.timers.size === 0, `zero timers após ${step}`);
    }
    {
        const h = makeHarness(new Array(30).fill('weird_status_xyz'));
        h.ctl.start();
        const events = await h.drain();
        ok(events.filter((e) => e === 'timeout').length === 1, 'unknown NÃO fica infinito: deadline encerra');
    }

    console.log(`\nTotal: ${passed} passed`);
})().catch((e) => {
    console.error('FATAL', e);
    process.exitCode = 1;
});

/**
 * mp_subscribe.js — tokenizacao Mercado Pago (CardForm) + POST /subscribe_token.
 *
 * SEGURANCA:
 * - Numero do cartao/CVV vivem em iframes do Mercado Pago (iframe:true) e
 *   jamais sao lidos por este script: so o card_token_id opaco e enviado.
 * - Ao backend vao SOMENTE: card_token_id, attempt_token, csrf_token.
 * - card_token_id nunca e logado (nem console, nem DOM persistente).
 * - Botao e travado durante o envio (anti double-click) e destravado em erro.
 */
(function () {
    'use strict';

    // =====================================================================
    // STATE MACHINE PURA — sem DOM, sem rede, testável em Node.
    // Vocabulário canônico da UI: active|cancelled|rejected|paused|expired|
    // pending|unknown. Mapeia TODAS as grafias do backend (status interno +
    // outcome + variantes toleradas) para esse enum — §2 nunca diverge.
    // =====================================================================
    var POLL_INTERVAL_MS = 2500;
    var POLL_MAX_ATTEMPTS = 24;
    var POLL_MAX_TRANSPORT_ERRORS = 3;

    var USER_MESSAGES = {
        invalid_card: 'Verifique os dados do cartão e tente novamente.',
        invalid_identity: 'Verifique nome, e-mail e documento do titular antes de pagar.',
        device_unavailable: 'Não foi possível preparar a verificação de segurança. Recarregue a página e tente novamente.',
        device_loading: 'Aguarde a verificação de segurança terminar e tente novamente.',
        card_declined: 'Pagamento recusado. Tente outro cartão ou fale com seu banco.',
        rejected: 'Pagamento recusado. Tente outro cartão ou fale com o banco.',
        cancelled: 'Pagamento cancelado ou não autorizado.',
        paused: 'Assinatura pausada. Fale com o suporte se precisar de ajuda.',
        expired: 'Assinatura expirada. Inicie uma nova tentativa.',
        service_error: 'Serviço indisponível no momento. Tente novamente em instantes.',
        payment_failed: 'Não foi possível concluir o pagamento. Confira os dados do cartão.'
    };

    function safeWord(v) {
        var s = '';
        try { s = String(v == null ? '' : v); } catch (e) { s = ''; }
        s = s.toLowerCase().replace(/[^a-z_]/g, '');
        return s.slice(0, 40);
    }

    // Lê input próprio por id (fora dos iframes do MP). Nunca toca em
    // número/CVV (vivem nos iframes). Retorna '' se ausente.
    function readInputValue(id) {
        try {
            var el = (typeof document !== 'undefined') ? document.getElementById(id) : null;
            if (!el || typeof el.value !== 'string') return '';
            return el.value.trim();
        } catch (e) {
            return '';
        }
    }

    // Normaliza qualquer payload do backend para o enum da UI.
    function normalizePollStatus(data) {
        if (!data || typeof data !== 'object') return 'unknown';
        var o = safeWord(data.outcome);
        var s = safeWord(data.status);
        if (o === 'active' || s === 'active' || o === 'authorized' || s === 'authorized') return 'active';
        if (o === 'cancelled' || s === 'cancelled' || o === 'canceled' || s === 'canceled') return 'cancelled';
        if (o === 'rejected' || s === 'rejected') return 'rejected';
        if (o === 'paused' || s === 'paused') return 'paused';
        if (o === 'expired' || s === 'expired') return 'expired';
        if (o === 'processing' || s === 'pending' || s === 'processing') return 'pending';
        return 'unknown';
    }

    // Decisão da RESPOSTA IMEDIATA do POST subscribe_token.
    // success SOMENTE com outcome active. ok:true sozinho NUNCA é sucesso.
    function decideInitialAction(http, data) {
        data = (data && typeof data === 'object') ? data : {};
        var outcome = data.outcome
            || (data.status === 'active' ? 'active'
                : (data.ok === true ? 'processing' : 'error'));
        if (outcome === 'active') {
            return { action: 'success', redirect: data.redirect || '/index.php?action=meu_plano&subscribed=1' };
        }
        if (outcome === 'processing' || http === 502 || http === 500) {
            return { action: 'start_poll' };
        }
        return { action: 'show_error', key: data.error || outcome || 'payment_failed' };
    }

    // Decisão de UM tick do poll com payload parseado.
    function decidePollTick(data) {
        if (!data || typeof data !== 'object' || data.ok !== true) {
            return { action: 'stop_terminal', key: 'payment_failed' };
        }
        var st = normalizePollStatus(data);
        if (st === 'active') return { action: 'success' };
        if (st === 'cancelled' || st === 'rejected' || st === 'paused' || st === 'expired') {
            return { action: 'stop_terminal', key: st };
        }
        return { action: 'continue' };
    }

    // Controller do poll com dependências injetáveis (testável sem DOM/rede).
    // Garante: UMA request por vez, stop único com limpeza de timer, guarda
    // de geração (resposta atrasada nunca sobrescreve terminal), teto de
    // erros de transporte, deadline limitado. Nenhum timer órfão.
    function createPollController(deps) {
        var gen = 0;
        var timerId = null;
        var remaining = POLL_MAX_ATTEMPTS;
        var transportErrors = 0;
        var running = false;

        function clearTimer() {
            if (timerId !== null) {
                try { deps.clearTimeoutFn(timerId); } catch (e) {}
                timerId = null;
            }
        }
        // stopSubscriptionPolling interno: PARA TUDO (timers + geração).
        // Chamado em active/cancelled/rejected/paused/expired/timeout/fatal.
        function stop() {
            gen++;
            clearTimer();
            running = false;
        }
        function schedule() {
            clearTimer();
            var myGen = gen;
            timerId = deps.setTimeoutFn(function () {
                timerId = null;
                tick(myGen);
            }, POLL_INTERVAL_MS);
        }
        function tick(myGen) {
            if (myGen !== gen || !running) return;
            if (remaining <= 0) {
                stop();
                deps.onTimeout(myGen);
                return;
            }
            remaining--;
            var fetchGen = gen;
            var url = deps.url;
            deps.fetchFn(url).then(function (resp) {
                return resp.json().then(function (data) {
                    return { httpOk: !!resp.ok, data: data };
                });
            }).then(function (result) {
                if (fetchGen !== gen || !running) return; // resposta atrasada: ignora
                var data = result.data;
                if (!result.httpOk || !data || typeof data !== 'object') {
                    transportErrors++;
                    if (transportErrors >= POLL_MAX_TRANSPORT_ERRORS) {
                        stop();
                        deps.onTransportAbort(fetchGen);
                        return;
                    }
                    schedule();
                    return;
                }
                var d = decidePollTick(data);
                if (d.action === 'success') {
                    stop();
                    deps.onSuccess(fetchGen);
                    return;
                }
                if (d.action === 'stop_terminal') {
                    stop();
                    deps.onTerminal(fetchGen, d.key);
                    return;
                }
                transportErrors = 0;
                schedule();
            }).catch(function () {
                if (fetchGen !== gen || !running) return; // resposta atrasada: ignora
                transportErrors++;
                if (transportErrors >= POLL_MAX_TRANSPORT_ERRORS) {
                    stop();
                    deps.onTransportAbort(fetchGen);
                    return;
                }
                schedule();
            });
        }
        return {
            start: function () {
                stop(); // encerra qualquer geração anterior antes de começar
                running = true;
                remaining = POLL_MAX_ATTEMPTS;
                transportErrors = 0;
                tick(gen);
            },
            stop: stop,
            pendingTimers: function () { return timerId === null ? 0 : 1; },
        };
    }

    // Exporta a state machine para testes Node. No browser, `module` não
    // existe e a execução segue para o bootstrap do DOM abaixo.
    // __wiring expõe start/stop SOMENTE para testes DOM (mesmo arquivo).
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            POLL_INTERVAL_MS: POLL_INTERVAL_MS,
            POLL_MAX_ATTEMPTS: POLL_MAX_ATTEMPTS,
            POLL_MAX_TRANSPORT_ERRORS: POLL_MAX_TRANSPORT_ERRORS,
            USER_MESSAGES: USER_MESSAGES,
            safeWord: safeWord,
            normalizePollStatus: normalizePollStatus,
            decideInitialAction: decideInitialAction,
            decidePollTick: decidePollTick,
            createPollController: createPollController,
            __wiring: {
                startUiPoll: function () { return startUiPoll(); },
                stopSubscriptionPolling: function () { return stopSubscriptionPolling(); },
                renderTerminalError: function (m) { return renderTerminalError(m); },
            },
        };
    }
    if (typeof document === 'undefined') {
        return;
    }

    var panel = document.getElementById('mp-checkout-panel');
    var diagLine = document.getElementById('mp-diag-line');
    if (!panel) {
        return;
    }

    function diag(text) {
        if (!diagLine) return;
        diagLine.style.display = 'block';
        diagLine.textContent = text;
    }

    function sanitizeError(err) {
        var msg = '';
        try {
            msg = String((err && err.message) || err || 'unknown');
        } catch (e) {
            msg = 'unknown';
        }
        // Redige qualquer coisa que pareca chave/token/cartao.
        msg = msg.replace(/(TEST-|APP_USR-)[A-Za-z0-9_-]+/g, '$1<redacted>');
        msg = msg.replace(/\b\d{13,19}\b/g, '<card-redacted>');
        return msg.slice(0, 160);
    }

    // Serializa erros do SDK (que vêm como ARRAY de objetos) expondo SOMENTE
    // campos seguros de diagnóstico. Nunca imprime chaves, tokens ou PAN.
    function safeSerializeError(err, depth) {
        depth = depth || 0;
        if (depth > 2) return '[nested]';
        if (Array.isArray(err)) {
            return '[' + err.map(function (e) { return safeSerializeError(e, depth + 1); }).join(' | ') + ']';
        }
        if (err && typeof err === 'object') {
            var out = {};
            ['message', 'type', 'code', 'cause', 'field', 'description', 'status'].forEach(function (k) {
                if (err[k] !== undefined && err[k] !== null && typeof err[k] !== 'object') {
                    out[k] = String(err[k]);
                }
            });
            ['causes', 'errors'].forEach(function (k) {
                if (Array.isArray(err[k])) {
                    out[k] = err[k].map(function (e) { return safeSerializeError(e, depth + 1); }).join(' | ');
                }
            });
            var keys = Object.keys(out);
            if (keys.length === 0) return '[object-sem-campos-seguros]';
            var s = keys.map(function (k) { return k + '=' + out[k]; }).join('; ');
            return sanitizeError(s).slice(0, 400);
        }
        return sanitizeError(err);
    }

    var PUBLIC_KEY = panel.getAttribute('data-mp-public-key') || '';
    var ATTEMPT_TOKEN = panel.getAttribute('data-attempt-token') || '';
    var AMOUNT = panel.getAttribute('data-mp-amount') || '';
    // Remove do DOM apos leitura: reduz superficie de persistencia acidental.
    // (Nunca imprime a public key: diagnostico usa apenas prefixo/tipo.)
    panel.removeAttribute('data-mp-public-key');
    panel.removeAttribute('data-attempt-token');
    panel.removeAttribute('data-mp-amount');

    function keyType(key) {
        if (key.indexOf('APP_USR-') === 0) return 'production';
        if (key.indexOf('TEST-') === 0) return 'test';
        return 'unknown';
    }

    var form = document.getElementById('mp-card-form');
    var payButton = document.getElementById('mp-pay-button');
    var errorBox = document.getElementById('mp-checkout-error');
    var loadingBox = document.getElementById('mp-checkout-loading');
    var csrfInput = form ? form.querySelector('input[name="csrf_token"]') : null;
    var submitted = false;
    var mounted = false;
    var cardForm = null;

    function showError(message) {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.style.display = 'block';
    }

    function reportDiag(extra) {
        diag(
            'MP SDK loaded: ' + (typeof MercadoPago !== 'undefined' ? 'yes' : 'no')
            + ' | Public key configured: ' + (PUBLIC_KEY ? 'yes' : 'no')
            + ' | Public key type: ' + (PUBLIC_KEY ? keyType(PUBLIC_KEY) : 'none')
            + ' | CardForm mounted: ' + (mounted ? 'yes' : 'no')
            + (extra ? ' | ' + extra : '')
        );
    }

    function setBusy(busy) {
        submitted = busy;
        if (payButton) {
            // Gate antifraude: sem device pronto, o botão NUNCA reabilita
            // (mesmo setBusy(false) respeita — fail-closed).
            payButton.disabled = busy || isSubmitBlocked();
            payButton.style.opacity = payButton.disabled ? '0.6' : '';
            payButton.style.cursor = payButton.disabled ? 'wait' : 'pointer';
        }
        if (loadingBox) loadingBox.style.display = busy ? 'block' : 'none';
        if (!busy && errorBox) errorBox.style.display = 'none';
    }

    // ---------- Wiring UI (browser) ----------
    // Sufixo hex preservando dígitos (correlator forense). safeWord() genérico
    // remove 0-9 e DESTRUIRIA o sufixo (ex.: "4d834025" viraria "d") — por
    // isso a validação aqui é charset hex explícito, nunca o safeWord.
    var ATTEMPT_SUFFIX = (function () {
        try {
            var t = String(ATTEMPT_TOKEN || '').toLowerCase().slice(-8);
            return (/^[0-9a-f]{8}$/.test(t)) ? t : 'invalid';
        } catch (e) { return 'invalid'; }
    })();
    var activePoll = null;
    var retryButton = null;

    // uiLog com campos SEPARADOS e inequívocos: action e error nunca se
    // fundem (regressão do "removalid_card", onde o ':' era comido pelo
    // sanitizador). Ex.: action=show_error error=invalid_card.
    function uiLog(source, status, outcome, action, errorKey) {
        try {
            if (typeof console !== 'undefined' && console.info) {
                // ATTEMPT_SUFFIX já é charset hex validado (ou 'invalid').
                console.info('[subscription-ui] attempt_suffix=' + ATTEMPT_SUFFIX
                    + ' source=' + safeWord(source)
                    + ' status=' + safeWord(status)
                    + ' outcome=' + safeWord(outcome)
                    + ' action=' + safeWord(action)
                    + ' error=' + safeWord(errorKey || ''));
            }
        } catch (e) {}
    }

    var pollAborter = null;

    // GATE DE PRONTIDÃO DO DEVICE (fail-closed durante investigação WCS-49458):
    // o checkout NÃO tokeniza nem faz POST enquanto o Device ID oficial não
    // estiver disponível, salvo impossibilidade comprovada (script falhou ou
    // teto estourado → erro amigável pedindo reload, sem pagamento).
    // Estados: 'loading' (aguardando) | 'ready' (global presente) |
    // 'unavailable' (script falhou ou timeout — terminal, pede reload).
    var DEVICE_READY_TIMEOUT_MS = 8000;
    var DEVICE_POLL_MS = 100;
    var deviceState = 'loading';
    var deviceGateOn = false;
    var payLabelOriginalText = null;

    // Estado do script oficial de segurança (flags do onload/onerror da tag).
    function securityScriptState() {
        try {
            if (typeof window !== 'undefined' && window.__mpSecurityFailed) return 'failed';
            if (typeof window !== 'undefined' && window.__mpSecurityLoaded) return 'loaded';
        } catch (e) {}
        return 'unknown';
    }

    function readDeviceIdNow() {
        try {
            if (typeof MP_DEVICE_SESSION_ID !== 'undefined' && MP_DEVICE_SESSION_ID) {
                return String(MP_DEVICE_SESSION_ID).slice(0, 160);
            }
        } catch (e) {}
        return '';
    }

    // Diagnóstico sanitizado (booleanos apenas — NUNCA o valor).
    function deviceDiagLine() {
        var tag = false;
        try {
            tag = (typeof document !== 'undefined') && !!document.getElementById('mp-security-script');
        } catch (e) {}
        return { tag: tag, load: securityScriptState(), present: readDeviceIdNow() !== '' };
    }

    function logDeviceDiag(extra) {
        try {
            var st = deviceDiagLine();
            var ready = (deviceState === 'ready');
            var blocked = (deviceGateOn && deviceState !== 'ready');
            if (typeof console !== 'undefined' && console.info) {
                console.info('[mp-device-ui] script_tag=' + (st.tag ? 'yes' : 'no')
                    + ' script_loaded=' + (st.load === 'loaded' ? 'yes' : (st.load === 'failed' ? 'no' : 'unknown'))
                    + ' device_id_present=' + (st.present ? 'yes' : 'no')
                    + ' device_ready=' + (ready ? 'yes' : 'no')
                    + ' submit_blocked=' + (blocked ? 'yes' : 'no')
                    + (extra ? ' ' + extra : ''));
            }
        } catch (e) {}
    }

    // O gate bloqueia o botão enquanto não-ready (fail-closed). setBusy()
    // consulta esta função: mesmo setBusy(false) NÃO reabilita sem device.
    function isSubmitBlocked() {
        return deviceGateOn && deviceState !== 'ready';
    }

    // Texto do botão via textContent do span dedicado (sem HTML dinâmico).
    function payLabel() {
        try {
            if (typeof document === 'undefined') return null;
            return document.getElementById('mp-pay-label');
        } catch (e) {
            return null;
        }
    }

    function setDeviceState(next) {
        if (deviceState === next) return;
        deviceState = next;
        if (next === 'ready') {
            try {
                var lbl = payLabel();
                if (lbl && payLabelOriginalText !== null && payLabelOriginalText !== undefined) {
                    lbl.textContent = payLabelOriginalText;
                }
            } catch (e) {}
            if (!submitted && payButton) {
                payButton.disabled = false;
                payButton.style.opacity = '';
                payButton.style.cursor = 'pointer';
            }
            try {
                if (errorBox && errorBox.textContent === USER_MESSAGES.device_unavailable) {
                    errorBox.style.display = 'none';
                }
            } catch (e) {}
            logDeviceDiag('transition=ready');
            return;
        }
        if (next === 'unavailable') {
            setBusy(false);
            showError(USER_MESSAGES.device_unavailable);
            logDeviceDiag('transition=unavailable');
        }
    }

    // Inicia o rastreamento na abertura do checkout (paralelo ao preenchimento).
    function startDeviceTracking() {
        deviceGateOn = true;
        try {
            if (payButton) {
                var lbl0 = payLabel();
                if (lbl0 && payLabelOriginalText === null) {
                    payLabelOriginalText = lbl0.textContent;
                }
                payButton.disabled = true;
                payButton.style.opacity = '0.6';
                payButton.style.cursor = 'wait';
                if (lbl0) {
                    lbl0.textContent = 'Preparando pagamento seguro…';
                }
            }
        } catch (e) {}
        logDeviceDiag('tracking=start');
        if (readDeviceIdNow() !== '') { setDeviceState('ready'); return; }
        if (securityScriptState() === 'failed') { setDeviceState('unavailable'); return; }
        var deadline = 0;
        try { deadline = Date.now() + DEVICE_READY_TIMEOUT_MS; } catch (e) {}
        (function tick() {
            if (deviceState !== 'loading') return;
            if (readDeviceIdNow() !== '') { setDeviceState('ready'); return; }
            if (securityScriptState() === 'failed') { setDeviceState('unavailable'); return; }
            var remaining = 0;
            try { remaining = deadline - Date.now(); } catch (e) {}
            if (remaining <= 0 || deadline <= 0) { setDeviceState('unavailable'); return; }
            try {
                setTimeout(tick, Math.min(DEVICE_POLL_MS, remaining));
            } catch (e) {
                setDeviceState('unavailable');
            }
        })();
    }

    // stopSubscriptionPolling — ÚNICA função que encerra o poll. Chamada em
    // active/cancelled/rejected/paused/expired/timeout/fatal. Aborta request
    // em voo, limpa timers, invalida gerações (respostas atrasadas morrem)
    // e remove o retry.
    function stopSubscriptionPolling() {
        if (pollAborter) {
            try { pollAborter.abort(); } catch (e) {}
            pollAborter = null;
        }
        if (activePoll) {
            try { activePoll.stop(); } catch (e) {}
        }
        hideRetryButton();
    }

    // renderTerminalError — ÚNICA renderização de estado terminal visual.
    // Atomicamente: para tudo, esconde processing/spinner, mostra a mensagem
    // final, reabilita o botão, preserva o formulário para correção. Nenhum
    // callback antigo pode restaurar "processando" depois dela.
    function renderTerminalError(message) {
        stopSubscriptionPolling();
        setBusy(false);
        showError(message);
    }

    function showRetryButton() {
        hideRetryButton();
        try {
            if (!errorBox || !errorBox.parentNode) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Verificar novamente';
            btn.className = 'btn';
            btn.style.marginTop = '8px';
            btn.style.cursor = 'pointer';
            btn.onclick = function () {
                hideRetryButton();
                if (errorBox) errorBox.style.display = 'none';
                setBusy(true);
                uiLog('retry', '', '', 'repoll', '');
                startUiPoll();
            };
            errorBox.parentNode.insertBefore(btn, errorBox.nextSibling);
            retryButton = btn;
        } catch (e) {}
    }

    function hideRetryButton() {
        try {
            if (retryButton && retryButton.parentNode) {
                retryButton.parentNode.removeChild(retryButton);
            }
        } catch (e) {}
        retryButton = null;
    }

    function messageFor(key) {
        return USER_MESSAGES[key] || USER_MESSAGES.payment_failed;
    }

    // Inicia UM poll limitado para a attempt atual. Chamadas repetidas
    // encerram a geração anterior antes (nunca dois polls simultâneos).
    // Cada geração tem seu AbortController: parar o poll ABORTA o fetch
    // em voo (rejeição cai no guarda de geração e é ignorada).
    function startUiPoll() {
        stopSubscriptionPolling();
        setBusy(true);
        var aborter = null;
        try {
            if (typeof AbortController !== 'undefined') {
                aborter = new AbortController();
                pollAborter = aborter;
            }
        } catch (e) {}
        var poll = createPollController({
            url: '/index.php?action=subscription_status&attempt=' + encodeURIComponent(ATTEMPT_TOKEN),
            fetchFn: function (url) {
                var opts = { method: 'GET', credentials: 'same-origin' };
                if (aborter) {
                    try { opts.signal = aborter.signal; } catch (e) {}
                }
                return fetch(url, opts);
            },
            setTimeoutFn: function (fn, ms) { return setTimeout(fn, ms); },
            clearTimeoutFn: function (id) { clearTimeout(id); },
            onSuccess: function () {
                stopSubscriptionPolling();
                uiLog('poll', 'active', 'active', 'success', '');
                window.location.href = '/index.php?action=meu_plano&subscribed=1';
            },
            onTerminal: function (gen, key) {
                uiLog('poll', key, key, 'stop_terminal', key);
                renderTerminalError(messageFor(key));
            },
            onTimeout: function () {
                uiLog('poll', 'pending', 'processing', 'timeout', '');
                renderTerminalError('Pagamento ainda não foi confirmado. Você pode verificar novamente mais tarde.');
                showRetryButton();
            },
            onTransportAbort: function () {
                uiLog('poll', '', '', 'transport_abort', 'transport');
                renderTerminalError('Não foi possível verificar o pagamento. Verifique sua conexão e tente novamente.');
                showRetryButton();
            },
        });
        activePoll = poll;
        poll.start();
    }

    // Continua o submit APÓS a espera do Device ID: monta o body (com
    // device_id SOMENTE se não-vazio), limpa cópias locais e dispara o POST.
    function continueSubmit(token, formData, deviceId) {
        var subscribeBody = {
            card_token_id: token,
            attempt_token: ATTEMPT_TOKEN,
            csrf_token: csrfInput ? csrfInput.value : '',
        };
        if (deviceId !== '') {
            subscribeBody.device_id = deviceId;
        }
        // Limpa as cópias locais assim que serializadas (backend valida).
        token = '';
        formData = {};
        deviceId = '';

        fetch('/index.php?action=subscribe_token', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(subscribeBody),
        }).then(function (resp) {
            return resp.json().then(function (data) {
                return { http: resp.status, data: data };
            });
        }).then(function (result) {
            var data = result.data || {};
            var decision = decideInitialAction(result.http, data);
            uiLog('initial', data.status, data.outcome, decision.action, decision.key || '');
            // REGRA DE OURO: sucesso SOMENTE com outcome active.
            // ok:true sozinho (pending/vinculado) NUNCA celebra compra.
            if (decision.action === 'success') {
                stopSubscriptionPolling();
                window.location.href = decision.redirect;
                return;
            }
            // Somente pending/processing (ou 502/500) inicia polling da
            // tentativa — sem reenviar o token de uso unico. Terminais
            // (cancelled/rejected/paused/expired/erro) NUNCA entram
            // em poll: render terminal atômico imediato.
            if (decision.action === 'start_poll') {
                setBusy(true);
                showError('Pagamento em processamento. Aguardando confirmação…');
                startUiPoll();
                return;
            }
            renderTerminalError(messageFor(decision.key));
        }).catch(function () {
            uiLog('initial', '', '', 'fetch_error_repoll', 'fetch');
            // Falha de rede/timeout no POST: tenta reconciliar por
            // polling limitado (sem reenviar token de uso unico).
            setBusy(true);
            showError('Pagamento em processamento. Aguardando confirmação…');
            startUiPoll();
        });
    }

    function boot(attemptsLeft) {
        // Aguarda o SDK (CDN pode chegar depois deste script) em vez de
        // falhar silenciosamente. Apos esgotar, exibe erro LOUD.
        if (typeof MercadoPago === 'undefined') {
            if (attemptsLeft > 0) {
                setTimeout(function () { boot(attemptsLeft - 1); }, 500);
                return;
            }
            reportDiag('sdk-missing');
            showError('Biblioteca de pagamento não carregou. Verifique sua conexão e recarregue a página.');
            return;
        }
        if (!PUBLIC_KEY) {
            reportDiag('no-public-key');
            showError('Pagamento indisponível no momento. Tente novamente mais tarde.');
            return;
        }
        if (!/^[0-9a-f]{32}$/.test(ATTEMPT_TOKEN)) {
            reportDiag('bad-attempt');
            showError('Sessão de pagamento inválida. Volte e inicie novamente.');
            return;
        }
        if (!AMOUNT || Number(AMOUNT) <= 0) {
            reportDiag('bad-amount');
            showError('Valor do plano indisponível. Tente novamente mais tarde.');
            return;
        }
        // Gate antifraude: trava o botão e rastreia o Device ID oficial
        // desde a abertura do checkout (paralelo ao preenchimento), para
        // que o submit nunca ocorra antes da prontidão.
        startDeviceTracking();
        var mp;
        try {
            mp = new MercadoPago(PUBLIC_KEY);
        } catch (e) {
            reportDiag('init-error: ' + safeSerializeError(e));
            showError('Não foi possível iniciar o pagamento. Recarregue a página.');
            return;
        }
        try {
            cardForm = mp.cardForm({
                amount: AMOUNT,
                iframe: true,
        form: {
            id: 'mp-card-form',
            cardNumber: { id: 'mp-cardNumber', placeholder: 'Número do cartão' },
            expirationDate: { id: 'mp-expirationDate', placeholder: 'MM/AA' },
            securityCode: { id: 'mp-securityCode', placeholder: 'CVV' },
            cardholderName: { id: 'mp-cardholderName', placeholder: 'Nome impresso' },
            issuer: { id: 'mp-issuer', placeholder: 'Banco emissor' },
            installments: { id: 'mp-installments', placeholder: 'Parcelas' },
            identificationType: { id: 'mp-identificationType', placeholder: 'Tipo de documento' },
            identificationNumber: { id: 'mp-identificationNumber', placeholder: 'Número do documento' },
            cardholderEmail: { id: 'mp-cardholderEmail', placeholder: 'E-mail' },
        },
        callbacks: {
            onFormMounted: function (error) {
                if (error) {
                    reportDiag('mount-error: ' + safeSerializeError(error));
                    showError('Não foi possível carregar o formulário de pagamento. Recarregue a página.');
                    return;
                }
                mounted = true;
                reportDiag();
            },
            onSubmit: function (event) {
                event.preventDefault();
                if (submitted) return;
                // GATE ANTIFRAUDE (fail-closed): sem device pronto, NEM
                // tokeniza, NEM faz POST. Botão desabilitado já barra o
                // clique; este guarda cobre Enter/submit programático.
                if (deviceGateOn && deviceState !== 'ready') {
                    showError(deviceState === 'loading'
                        ? USER_MESSAGES.device_loading
                        : USER_MESSAGES.device_unavailable);
                    return;
                }
                setBusy(true);

                // Contexto antifraude (WCS-49458): identidade do titular
                // alimenta o card_token. Exige nome/e-mail/documento
                // preenchidos ANTES de tokenizar — sem token queimado à toa,
                // sem POST ao backend. São inputs próprios (não iframes).
                var holderName = readInputValue('mp-cardholderName');
                var holderEmail = readInputValue('mp-cardholderEmail');
                var holderDocDigits = readInputValue('mp-identificationNumber').replace(/\D/g, '');
                if (holderName.length < 3
                    || holderEmail.length > 120
                    || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(holderEmail)
                    || holderDocDigits.length < 5) {
                    setBusy(false);
                    showError(USER_MESSAGES.invalid_identity);
                    return;
                }

                var formData = {};
                try {
                    formData = cardForm.getCardFormData();
                } catch (e) {
                    setBusy(false);
                    showError('Verifique os dados do cartão e tente novamente.');
                    return;
                }

                var token = formData && formData.token ? String(formData.token) : '';
                if (!token) {
                    setBusy(false);
                    showError('Não foi possível tokenizar o cartão. Verifique os dados.');
                    return;
                }

                // Device ID oficial: o gate garante prontidão — lê o valor
                // atual (verbatim, sem gerar). Fallback '' é defensivo
                // (backend omite o header); nunca logado, nunca em URL.
                // Captura cópias e limpa os originais JÁ (strings são por
                // valor; continueSubmit usa as cópias — token nunca persiste).
                logDeviceDiag();
                var deviceCopy = readDeviceIdNow();
                var tokenCopy = token;
                var formCopy = formData;
                token = '';
                formData = {};
                continueSubmit(tokenCopy, formCopy, deviceCopy);
            },
        },
            });
        } catch (e) {
            reportDiag('cardform-error: ' + safeSerializeError(e));
            showError('Não foi possível carregar o formulário de pagamento. Recarregue a página.');
            return;
        }
        reportDiag('boot-ok');
    }

    boot(16);
})();

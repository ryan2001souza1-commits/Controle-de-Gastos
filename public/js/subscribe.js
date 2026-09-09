/**
 * subscribe.js — assinatura com cartao via MercadoPago.js oficial (Core Methods).
 *
 * Fluxo:
 *  1. Usuario escolhe Pro/Premium -> abre o formulario de cartao;
 *  2. `mp.createCardToken()` tokeniza no navegador (numero/CVV vao DIRETO
 *     ao Mercado Pago e nunca ao nosso backend);
 *  3. POST /index.php?action=subscribe_start com SOMENTE
 *     {plan, card_token_id, csrf_token};
 *  4. backend cria o preapproval (status authorized);
 *  5. se houver checkout_url -> redireciona; senao exibe sucesso
 *     (a ativacao final ocorre via webhook).
 */
(function () {
    'use strict';

    var currentPlan = null;
    var currentPlanName = '';

    function $(id) { return document.getElementById(id); }

    function publicKey() {
        var el = $('mp-config');
        var pk = el ? (el.getAttribute('data-public-key') || '') : '';
        return pk.trim();
    }

    function csrfToken() {
        var el = document.querySelector('#subscribe-csrf input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function cardError(msg) {
        var box = $('mp-card-error');
        if (!box) return;
        if (!msg) { box.style.display = 'none'; box.textContent = ''; return; }
        box.textContent = msg;
        box.style.display = 'block';
    }

    function showError(btn, msg) {
        var card = btn ? btn.closest('[data-plan-card]') : null;
        var fb = card ? card.querySelector('.subscribe-feedback') : $('subscribe-feedback-global');
        if (fb) { fb.textContent = msg; fb.style.display = 'block'; }
    }

    function showSuccess(btn, msg) {
        var card = btn ? btn.closest('[data-plan-card]') : null;
        var fb = card ? card.querySelector('.subscribe-feedback') : $('subscribe-feedback-global');
        if (fb) {
            fb.textContent = msg;
            fb.style.display = 'block';
            fb.style.color = 'var(--color-success)';
        }
    }

    function setBusy(btn, busy, label) {
        if (!btn) return;
        if (busy) { btn.dataset.busy = '1'; btn.disabled = true; btn.style.opacity = '.6'; }
        else { delete btn.dataset.busy; btn.disabled = false; btn.style.opacity = ''; }
        if (label) btn.textContent = label;
    }

    function openModal(btn) {
        if (!publicKey()) {
            showError(btn, 'Pagamento temporariamente indisponível. Tente mais tarde.');
            return;
        }
        if (typeof window.MercadoPago === 'undefined') {
            showError(btn, 'Não foi possível carregar o pagamento seguro. Verifique sua conexão e recarregue.');
            return;
        }
        currentPlan = btn.getAttribute('data-plan');
        currentPlanName = btn.getAttribute('data-plan-name') || currentPlan;
        if (currentPlan !== 'pro' && currentPlan !== 'premium') return;
        var title = $('mp-card-title');
        if (title) title.textContent = 'Assinar ' + currentPlanName;
        cardError(null);
        var modal = $('mp-card-modal');
        if (modal) modal.style.display = 'flex';
    }

    function closeModal() {
        var modal = $('mp-card-modal');
        if (modal) modal.style.display = 'none';
        currentPlan = null;
    }

    function digits(v) { return (v || '').replace(/\D/g, ''); }

    /**
     * Leitura SINCRONA legada do Device ID (fallback quando MpCardUtils
     * indisponivel). Caminho principal usa waitForDeviceId (com polling).
     * O VALOR nunca e logado — apenas presente yes/no.
     */
    function deviceId() {
        var v = '';
        try {
            v = typeof window.MP_DEVICE_SESSION_ID !== 'undefined' ? String(window.MP_DEVICE_SESSION_ID) : '';
        } catch (e) { v = ''; }
        v = v.trim();
        if (v.length < 4 || v.length > 512) return '';
        if (/[\x00-\x1F\x7F]/.test(v)) return '';
        return v;
    }

    /**
     * Diagnostico de carregamento do security.js (listeners onload/onerror
     * registrados na inicializacao; nunca quebram o checkout).
     */
    function watchSecurityJs() {
        try {
            var scripts = document.querySelectorAll('script[src*="security.js"]');
            for (var i = 0; i < scripts.length; i++) {
                var src = scripts[i].getAttribute('src') || '';
                if (src.indexOf('mercadopago.com/v2/security.js') === -1) continue;
                scripts[i].addEventListener('load', function () { window.__mpSecurityJsLoaded = true; });
                scripts[i].addEventListener('error', function () { window.__mpSecurityJsFailed = true; });
            }
        } catch (e) { /* diagnostico e opcional */ }
    }

    function securityJsLoaded(deviceValue) {
        if (window.__mpSecurityJsFailed === true) return false;
        if (window.__mpSecurityJsLoaded === true) return true;
        // Prova de execucao: ID obtido por fonte oficial.
        return !!deviceValue;
    }

    function diagLine(suffix) {
        if (typeof console !== 'undefined' && console.info) console.info('[mp-device] ' + suffix);
    }

    function validCpfBasic(v) {
        var d = digits(v);
        if (d.length !== 11) return false;
        if (/^(\d)\1{10}$/.test(d)) return false;
        return true;
    }

    function normMonth(v) {
        if (window.MpCardUtils && window.MpCardUtils.normalizeMonth) return window.MpCardUtils.normalizeMonth(v);
        var d = digits(v);
        return d.length === 2 ? d : '';
    }

    function normYear(v) {
        if (window.MpCardUtils && window.MpCardUtils.normalizeYear) return window.MpCardUtils.normalizeYear(v);
        var d = digits(v);
        return d.length === 4 ? d : '';
    }

    function safeCode(err) {
        if (window.MpCardUtils && window.MpCardUtils.extractSafeCode) return window.MpCardUtils.extractSafeCode(err);
        return 'unknown_tokenization_error';
    }

    function friendlyMessage(code) {
        var map = {
            nao_autenticado: 'Sessão expirada. Recarregue a página e entre novamente.',
            plano_invalido: 'Plano inválido. Escolha Pro ou Premium.',
            card_token_ausente: 'Não foi possível gerar o token do cartão. Tente novamente.',
            card_token_invalido: 'Não foi possível gerar o token do cartão. Tente novamente.',
            catalogo_sem_preco: 'Plano temporariamente indisponível. Tente mais tarde.',
            mp_not_configured: 'Pagamento temporariamente indisponível. Tente mais tarde.',
            mp_plan_not_configured: 'Pagamento temporariamente indisponível. Tente mais tarde.',
            mp_timeout: 'O Mercado Pago demorou a responder. Tente novamente.',
            mp_connection: 'Falha de conexão com o pagamento. Tente novamente.',
            mp_http_400: 'Cartão recusado. Confira os dados e tente novamente.',
            mp_http_401: 'Pagamento indisponível no momento. Tente mais tarde.',
            mp_http_429: 'Muitas tentativas. Aguarde e tente novamente.',
            checkout_indisponivel: 'Não foi possível concluir. Tente novamente.',
            erro_banco: 'Erro interno. Tente novamente em instantes.'
        };
        return map[code] || 'Não foi possível concluir a assinatura. Tente novamente.';
    }

    function friendlySdkMessage(code) {
        var map = {
            invalid_card_number: 'Número do cartão inválido. Confira e tente novamente.',
            invalid_expiration_date: 'Data de validade inválida. Confira e tente novamente.',
            invalid_expiration_month: 'Mês de validade inválido. Confira e tente novamente.',
            invalid_expiration_year: 'Ano de validade inválido. Confira e tente novamente.',
            invalid_security_code: 'Código de segurança inválido. Confira e tente novamente.',
            invalid_cardholder_name: 'Nome do titular inválido. Confira e tente novamente.',
            invalid_identification_number: 'Documento inválido. Confira e tente novamente.',
            invalid_public_key: 'Pagamento indisponível no momento. Tente mais tarde.',
            public_key_error: 'Pagamento indisponível no momento. Tente mais tarde.',
            network_error: 'Falha de conexão com o pagamento. Tente novamente.'
        };
        if (map[code]) return map[code];
        return 'Não foi possível validar o cartão (' + code + '). Confira os dados e tente novamente.';
    }

    async function submitCard(event) {
        event.preventDefault();
        if (!currentPlan) return;
        var submitBtn = $('mp-card-submit');
        if (submitBtn && submitBtn.dataset.busy === '1') return;
        cardError(null);

        var number = digits($('mp-card-number') ? $('mp-card-number').value : '');
        var name = ($('mp-card-name') ? $('mp-card-name').value : '').trim();
        var month = normMonth($('mp-card-month') ? $('mp-card-month').value : '');
        var year = normYear($('mp-card-year') ? $('mp-card-year').value : '');
        var cvv = digits($('mp-card-cvv') ? $('mp-card-cvv').value : '');
        var doc = digits($('mp-card-doc') ? $('mp-card-doc').value : '');
        // Mensagem PROPRIA de validacao local — nunca confundida com erro do SDK.
        // CPF obrigatorio (BR): melhora a avaliacao antifraude; usado SOMENTE
        // na tokenizacao oficial, nunca enviado ao nosso backend.
        if (number.length < 13 || !name || !month || !year || cvv.length < 3) {
            cardError('Verifique os dados digitados no cartão.');
            return;
        }
        if (!validCpfBasic(doc)) {
            cardError('Informe um CPF válido do titular.');
            return;
        }
        var token = csrfToken();
        if (!token) {
            cardError('Sessão expirada. Recarregue a página e tente novamente.');
            return;
        }
        if (submitBtn) { submitBtn.dataset.busy = '1'; submitBtn.disabled = true; submitBtn.style.opacity = '.6'; }

        try {
            // Tokenizacao oficial: dados do cartao vao ao Mercado Pago e
            // retornam como card_token_id temporario. Nada de cartao sai daqui.
            var mp = new window.MercadoPago(publicKey(), { locale: 'pt-BR' });
            var cardData = {
                cardNumber: number,
                cardholderName: name,
                cardExpirationMonth: month,
                cardExpirationYear: year,
                securityCode: cvv
            };
            if (doc) { cardData.identificationType = 'CPF'; cardData.identificationNumber = doc; }
            var tokenResp = await mp.createCardToken(cardData);
            var cardTokenId = tokenResp && tokenResp.id ? String(tokenResp.id) : '';
            if (!cardTokenId) {
                if (typeof console !== 'undefined' && console.info) console.info('[mp-tokenization] code=empty_token_response');
                cardError('Não foi possível validar o cartão. Tente novamente.');
                if (submitBtn) { delete submitBtn.dataset.busy; submitBtn.disabled = false; submitBtn.style.opacity = ''; }
                return;
            }

            // Device ID oficial com espera limitada: tenta o global, aguarda
            // ate ~1.5s (poll 75ms) caso o security.js ainda esteja gerando,
            // depois usa o fallback oficial (#deviceId) ou segue sem ID.
            // Diagnostico: SOMENTE yes/no e reason — nunca o valor.
            var deviceInfo = { value: '', source: null, sawGlobal: false, sawFallback: false };
            if (window.MpCardUtils && window.MpCardUtils.waitForDeviceId) {
                try {
                    deviceInfo = await window.MpCardUtils.waitForDeviceId(window, { intervalMs: 75, maxMs: 1500 });
                } catch (e) { /* segue sem ID */ }
            } else {
                var legacy = deviceId();
                deviceInfo = { value: legacy, source: legacy ? 'global' : null, sawGlobal: !!legacy, sawFallback: false };
            }
            var deviceSessionId = deviceInfo.value || '';
            var deviceReason = deviceSessionId ? 'present' : ((deviceInfo.sawGlobal || deviceInfo.sawFallback) ? 'invalid' : 'missing');
            diagLine('security_js_loaded=' + (securityJsLoaded(deviceSessionId) ? 'yes' : 'no'));
            diagLine('global_present=' + (deviceInfo.sawGlobal ? 'yes' : 'no'));
            diagLine('fallback_present=' + (deviceInfo.sawFallback ? 'yes' : 'no'));
            diagLine('added_to_post=' + (deviceSessionId ? 'yes' : 'no'));
            diagLine('reason=' + deviceReason);
            diagLine('present=' + (deviceSessionId ? 'yes' : 'no'));
            // Ao backend: plan + card_token_id + CSRF (+ device_id quando valido).
            // NUNCA numero/CVV/CPF.
            var body = 'plan=' + encodeURIComponent(currentPlan)
                + '&card_token_id=' + encodeURIComponent(cardTokenId)
                + (deviceSessionId ? '&device_id=' + encodeURIComponent(deviceSessionId) : '')
                + '&csrf_token=' + encodeURIComponent(token);
            var resp = await fetch('/index.php?action=subscribe_start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            var data = await resp.json();
            if (resp.ok && data && data.success) {
                if (typeof data.checkout_url === 'string' && data.checkout_url.indexOf('https://') === 0) {
                    window.location.href = data.checkout_url;
                    return;
                }
                closeModal();
                var btn = document.querySelector('.subscribe-btn[data-plan="' + currentPlan + '"]');
                showSuccess(btn, 'Assinatura criada! Confirmaremos a ativação em instantes.');
                return;
            }
            cardError(friendlyMessage(data && data.error));
        } catch (e) {
            // Diagnostico seguro: SOMENTE o codigo. Nunca objeto, mensagem
            // bruta, token, chave ou dado do cartao.
            var code = safeCode(e);
            if (typeof console !== 'undefined' && console.info) console.info('[mp-tokenization] code=' + code);
            cardError(friendlySdkMessage(code));
        }
        if (submitBtn) { delete submitBtn.dataset.busy; submitBtn.disabled = false; submitBtn.style.opacity = ''; }
    }

    document.addEventListener('DOMContentLoaded', function () {
        watchSecurityJs();
        document.querySelectorAll('.subscribe-btn[data-plan]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setBusy(null, false);
                openModal(btn);
            });
        });
        var close = $('mp-card-close');
        if (close) close.addEventListener('click', closeModal);
        var modal = $('mp-card-modal');
        if (modal) modal.addEventListener('click', function (ev) { if (ev.target === modal) closeModal(); });
        var form = $('mp-card-form');
        if (form) form.addEventListener('submit', submitCard);
    });
})();

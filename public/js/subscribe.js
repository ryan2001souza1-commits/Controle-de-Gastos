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

    async function submitCard(event) {
        event.preventDefault();
        if (!currentPlan) return;
        var submitBtn = $('mp-card-submit');
        if (submitBtn && submitBtn.dataset.busy === '1') return;
        cardError(null);

        var number = digits($('mp-card-number') ? $('mp-card-number').value : '');
        var name = ($('mp-card-name') ? $('mp-card-name').value : '').trim();
        var month = digits($('mp-card-month') ? $('mp-card-month').value : '');
        var year = digits($('mp-card-year') ? $('mp-card-year').value : '');
        var cvv = digits($('mp-card-cvv') ? $('mp-card-cvv').value : '');
        var doc = digits($('mp-card-doc') ? $('mp-card-doc').value : '');
        if (number.length < 13 || !name || month.length !== 2 || year.length !== 4 || cvv.length < 3) {
            cardError('Confira os dados do cartão e tente novamente.');
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
            var mp = new window.MercadoPago(publicKey());
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
                cardError('Não foi possível validar o cartão. Tente novamente.');
                if (submitBtn) { delete submitBtn.dataset.busy; submitBtn.disabled = false; submitBtn.style.opacity = ''; }
                return;
            }

            // Ao backend: SOMENTE plan + card_token_id + CSRF.
            var body = 'plan=' + encodeURIComponent(currentPlan)
                + '&card_token_id=' + encodeURIComponent(cardTokenId)
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
            cardError('Não foi possível validar o cartão. Confira os dados e tente novamente.');
        }
        if (submitBtn) { delete submitBtn.dataset.busy; submitBtn.disabled = false; submitBtn.style.opacity = ''; }
    }

    document.addEventListener('DOMContentLoaded', function () {
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

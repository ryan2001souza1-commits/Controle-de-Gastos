/**
 * subscribe.js — inicia assinatura via backend (sem SDK, sem token no frontend).
 *
 * Fluxo: POST /index.php?action=subscribe_start {plan, csrf_token}
 *  -> backend valida tudo e chama a API oficial
 *  -> resposta {success, checkout_url} -> redireciona ao checkout oficial.
 */
(function () {
    'use strict';

    function csrfToken() {
        var el = document.querySelector('#subscribe-csrf input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function feedbackFor(btn) {
        var card = btn.closest('[data-plan-card]');
        if (card) {
            var fb = card.querySelector('.subscribe-feedback');
            if (fb) return fb;
        }
        return document.getElementById('subscribe-feedback-global');
    }

    function setBusy(btn, busy) {
        if (busy) {
            btn.dataset.busy = '1';
            btn.disabled = true;
            btn.style.opacity = '.6';
            btn.style.cursor = 'wait';
        } else {
            delete btn.dataset.busy;
            btn.disabled = false;
            btn.style.opacity = '';
            btn.style.cursor = '';
        }
    }

    function showError(btn, msg) {
        var fb = feedbackFor(btn);
        if (fb) {
            fb.textContent = msg;
            fb.style.display = 'block';
        } else {
            alert(msg);
        }
    }

    function friendlyMessage(data) {
        var map = {
            nao_autenticado: 'Sessão expirada. Recarregue a página e entre novamente.',
            plano_invalido: 'Plano inválido. Escolha Pro ou Premium.',
            catalogo_sem_preco: 'Plano temporariamente indisponível. Tente mais tarde.',
            mp_not_configured: 'Pagamento temporariamente indisponível. Tente mais tarde.',
            mp_plan_not_configured: 'Pagamento temporariamente indisponível. Tente mais tarde.',
            mp_timeout: 'O Mercado Pago demorou a responder. Tente novamente.',
            mp_connection: 'Falha de conexão com o pagamento. Tente novamente.',
            mp_http_429: 'Muitas tentativas. Aguarde e tente novamente.',
            checkout_indisponivel: 'Checkout indisponível no momento. Tente novamente.',
            erro_banco: 'Erro interno. Tente novamente em instantes.'
        };
        if (data && data.error && map[data.error]) return map[data.error];
        return 'Não foi possível iniciar a assinatura. Tente novamente.';
    }

    async function start(btn) {
        if (btn.dataset.busy === '1') return; // anti double-click
        var plan = btn.getAttribute('data-plan');
        if (plan !== 'pro' && plan !== 'premium') return;
        var token = csrfToken();
        if (!token) {
            showError(btn, 'Sessão expirada. Recarregue a página e tente novamente.');
            return;
        }
        setBusy(btn, true);
        try {
            var body = 'plan=' + encodeURIComponent(plan) + '&csrf_token=' + encodeURIComponent(token);
            var resp = await fetch('/index.php?action=subscribe_start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            var data = await resp.json();
            if (resp.ok && data && data.success && typeof data.checkout_url === 'string'
                && data.checkout_url.indexOf('https://') === 0) {
                window.location.href = data.checkout_url;
                return;
            }
            setBusy(btn, false);
            showError(btn, friendlyMessage(data));
        } catch (e) {
            setBusy(btn, false);
            showError(btn, 'Falha de conexão. Tente novamente.');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('.subscribe-btn[data-plan]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () { start(btn); });
        });
    });
})();

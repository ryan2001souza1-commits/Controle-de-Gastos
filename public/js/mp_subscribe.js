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

    var panel = document.getElementById('mp-checkout-panel');
    if (!panel || typeof MercadoPago === 'undefined') {
        return;
    }

    var PUBLIC_KEY = panel.getAttribute('data-mp-public-key') || '';
    var ATTEMPT_TOKEN = panel.getAttribute('data-attempt-token') || '';
    // Remove do DOM apos leitura: reduz superficie de persistencia acidental.
    panel.removeAttribute('data-mp-public-key');
    panel.removeAttribute('data-attempt-token');

    if (!PUBLIC_KEY || !/^[0-9a-f]{32}$/.test(ATTEMPT_TOKEN)) {
        return;
    }

    var form = document.getElementById('mp-card-form');
    var payButton = document.getElementById('mp-pay-button');
    var errorBox = document.getElementById('mp-checkout-error');
    var loadingBox = document.getElementById('mp-checkout-loading');
    var csrfInput = form ? form.querySelector('input[name="csrf_token"]') : null;
    var submitted = false;

    function showError(message) {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.style.display = 'block';
    }

    function setBusy(busy) {
        submitted = busy;
        if (payButton) {
            payButton.disabled = busy;
            payButton.style.opacity = busy ? '0.6' : '';
            payButton.style.cursor = busy ? 'wait' : 'pointer';
        }
        if (loadingBox) loadingBox.style.display = busy ? 'block' : 'none';
        if (!busy && errorBox) errorBox.style.display = 'none';
    }

    function pollStatus(done) {
        fetch('/index.php?action=subscription_status&attempt=' + encodeURIComponent(ATTEMPT_TOKEN), {
            method: 'GET',
            credentials: 'same-origin',
        }).then(function (resp) {
            return resp.json();
        }).then(function (data) {
            if (data && data.ok === true && (data.status === 'active' || data.linked === true)) {
                window.location.href = '/index.php?action=meu_plano&subscribed=1';
            } else {
                done();
            }
        }).catch(function () {
            done();
        });
    }

    var mp = new MercadoPago(PUBLIC_KEY);
    var cardForm = mp.cardForm({
        amount: '0',
        iframe: true,
        form: {
            id: 'mp-card-form',
            cardNumber: { id: 'mp-cardNumber', placeholder: 'Número do cartão' },
            expirationDate: { id: 'mp-expirationDate', placeholder: 'MM/AA' },
            securityCode: { id: 'mp-securityCode', placeholder: 'CVV' },
            cardholderName: { id: 'mp-cardholderName', placeholder: 'Nome impresso' },
            cardholderEmail: { id: 'mp-cardholderEmail', placeholder: 'E-mail' },
        },
        callbacks: {
            onFormMounted: function (error) {
                if (error) {
                    showError('Não foi possível carregar o formulário de pagamento. Recarregue a página.');
                }
            },
            onSubmit: function (event) {
                event.preventDefault();
                if (submitted) return;
                setBusy(true);

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

                fetch('/index.php?action=subscribe_token', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        card_token_id: token,
                        attempt_token: ATTEMPT_TOKEN,
                        csrf_token: csrfInput ? csrfInput.value : '',
                    }),
                }).then(function (resp) {
                    return resp.json().then(function (data) {
                        return { http: resp.status, data: data };
                    });
                }).then(function (result) {
                    // Limpa o token da memoria asim que possivel.
                    token = '';
                    formData = {};
                    var data = result.data || {};
                    if (data.ok === true) {
                        window.location.href = data.redirect || '/index.php?action=meu_plano&subscribed=1';
                        return;
                    }
                    // Timeout/rede apos criacao no MP: reconcilia por polling
                    // da tentativa (sem reenviar token de uso unico).
                    if (result.http === 502 || result.http === 500) {
                        pollStatus(function () {
                            setBusy(false);
                            showError('Pagamento em processamento. Aguarde alguns instantes e recarregue a página.');
                        });
                        return;
                    }
                    setBusy(false);
                    var userMessages = {
                        invalid_card: 'Verifique os dados do cartão e tente novamente.',
                        card_declined: 'Pagamento recusado. Tente outro cartão ou fale com seu banco.',
                        service_error: 'Serviço indisponível no momento. Tente novamente em instantes.',
                        payment_failed: 'Não foi possível concluir o pagamento. Confira os dados do cartão.'
                    };
                    showError(userMessages[data.error] || userMessages.payment_failed);
                }).catch(function () {
                    token = '';
                    pollStatus(function () {
                        setBusy(false);
                        showError('Falha de conexão. Verifique se a assinatura foi criada antes de tentar de novo.');
                    });
                });
            },
        },
    });
})();

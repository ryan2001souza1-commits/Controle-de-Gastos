<?php
require_once __DIR__ . '/MercadoPagoWebhookHandler.php';
require_once __DIR__ . '/MercadoPagoSubscriptionVerifier.php';

/**
 * MercadoPagoWebhookProcessor — orquestracao gate + verificacao (sem banco).
 *
 * Fluxo desta etapa para subscription_preapproval com x-signature valida:
 *   1. Gate (MercadoPagoWebhookHandler): metodo, JSON, tipo, HMAC.
 *   2. SOMENTE se validado: consulta GET /preapproval/{ID} via
 *      MercadoPagoSubscriptionVerifier (fonte de verdade = API oficial).
 *   3. Mapeia o resultado para a resposta HTTP — SEMPRE sem efeitos:
 *      - assinatura verificada (qualquer correlacao)  => 200 recebido;
 *      - falha permanente (plano/ref/status invalidos) => 200 ignorado;
 *      - falha temporaria (timeout/5xx/rede/config)    => 500 p/ retry.
 *
 * Correlacao (B) e separada da verificacao (A): sem external_reference
 * valida o user_id permanece null — nada e inventado (nem por e-mail).
 * NESTA ETAPA nenhum caminho escreve no banco ou ativa planos.
 */
class MercadoPagoWebhookProcessor
{
    /**
     * @param callable|null $apiHttpHandler repassado ao verificador (testes).
     * @return array{http_code:int, body:array, log:array} (sem segredos)
     */
    public static function processNotification(
        string $method,
        array $headers,
        string $queryString,
        string $rawBody,
        string $secret,
        ?MercadoPagoSubscriptionVerifier $verifier
    ): array {
        $gate = MercadoPagoWebhookHandler::process($method, $headers, $queryString, $rawBody, $secret);

        $validated = ($gate['log']['validated'] ?? false) === true;
        $isPreapproval = (($gate['log']['type'] ?? '') === 'subscription_preapproval');
        if (!$validated || !$isPreapproval || $verifier === null) {
            return $gate;
        }

        $data = json_decode($rawBody, true);
        $id = is_array($data) ? MercadoPagoWebhookHandler::extractId($data, $queryString) : null;
        if ($id === null) {
            $log = $gate['log'];
            $log['result'] = 'missing_id';
            return ['http_code' => 200, 'body' => ['ok' => true, 'handled' => false, 'verified_subscription' => false], 'log' => $log];
        }

        $v = $verifier->verify($id);
        $log = $gate['log'];
        $log['verified_subscription'] = $v['verified_subscription'];
        $log['correlated_user'] = $v['correlated_user'];
        $log['verify_reason'] = $v['reason'];
        if ($v['status'] !== null) {
            $log['mp_status'] = $v['status'];
        }

        if ($v['retryable']) {
            // Falha temporaria: 500 para o MP tentar novamente. Nada ativado.
            $log['result'] = 'temporal_retry';
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'upstream_temporarily_unavailable'], 'log' => $log];
        }

        if ($v['verified_subscription']) {
            $log['result'] = $v['correlated_user'] ? 'verified_received' : 'verified_uncorrelated';
            $body = ['ok' => true, 'handled' => false, 'verified_subscription' => true, 'correlated_user' => $v['correlated_user'], 'status' => $v['status']];
            if (!$v['correlated_user']) {
                $body['reason'] = $v['reason'];
            }
            return ['http_code' => 200, 'body' => $body, 'log' => $log];
        }

        $log['result'] = 'rejected_' . $v['reason'];
        return [
            'http_code' => 200,
            'body' => ['ok' => true, 'handled' => false, 'verified_subscription' => false, 'correlated_user' => false, 'reason' => $v['reason']],
            'log' => $log,
        ];
    }
}

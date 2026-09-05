<?php
/**
 * MercadoPagoService — integração com a API de Assinaturas do Mercado Pago.
 *
 * Fluxo de checkout hospedado (link de assinatura):
 * 1. Busca o plano via GET /preapproval_plan/{id} para obter o init_point;
 * 2. Redireciona o cliente para o init_point com external_reference e payer_email
 *    como query parameters;
 * 3. O Mercado Pago exibe a página de pagamento ao cliente;
 * 4. Após o pagamento, o Mercado Pago notifica via webhook.
 *
 * Nunca expõe o Access Token ao frontend ou em logs.
 */
class MercadoPagoService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private string $accessToken;

    public function __construct()
    {
        $token = getenv('MERCADOPAGO_ACCESS_TOKEN');
        if ($token === false || $token === '') {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN nao configurado');
        }
        $this->accessToken = (string)$token;
    }

    /**
     * Resolve o ID do plano do Mercado Pago (preapproval_plan_id) a partir do slug interno.
     * Retorna null se o plano não for válido ou se o ID não estiver configurado no .env.
     */
    public static function getPlanIdForSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        $envKey = match ($slug) {
            'pro'      => 'MERCADOPAGO_PLAN_ID_PRO',
            'premium'  => 'MERCADOPAGO_PLAN_ID_PREMIUM',
            default    => null,
        };

        if ($envKey === null) {
            return null;
        }
        $id = getenv($envKey);
        return ($id !== false && $id !== '') ? (string)$id : null;
    }

    /**
     * Busca o init_point de um plano de assinatura.
     *
     * @param string $planSlug  slug interno (pro|premium)
     * @param int    $userId    id interno do usuário
     * @param string $email     email do pagador
     * @return array{ok:bool, init_point?:string, plan_id?:string, status?:int, error?:string}
     */
    public function getInitPointForPlan(string $planSlug, int $userId, string $email): array
    {
        $planId = self::getPlanIdForSlug($planSlug);
        if ($planId === null || $planId === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'plan_not_found'];
        }
        if ($userId <= 0) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_user'];
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_email'];
        }

        $url = self::BASE_URL . '/preapproval_plan/' . urlencode($planId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'X-Integrator-Id: dev_controle_de_gastos',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log('[MercadoPagoService] curl error: ' . $curlErr);
            return ['ok' => false, 'status' => 0, 'error' => 'network_error'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            error_log('[MercadoPagoService] resposta não-JSON do MP (status=' . $httpStatus . ')');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_response'];
        }

        if ($httpStatus === 404) {
            return ['ok' => false, 'status' => 404, 'error' => 'plan_not_found'];
        }

        if ($httpStatus !== 200) {
            $msg = is_string($data['message'] ?? null) ? (string)$data['message'] : 'mp_error';
            error_log('[MercadoPagoService] erro HTTP ' . $httpStatus . ': ' . $msg);
            return ['ok' => false, 'status' => $httpStatus, 'error' => $msg];
        }

        $initPoint = $data['init_point'] ?? null;
        if (!is_string($initPoint) || $initPoint === '') {
            error_log('[MercadoPagoService] plano sem init_point (plan_id=' . $planId . ')');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'missing_init_point'];
        }

        $externalRef = 'user_' . $userId . '_' . $planSlug;
        $backUrl = rtrim((string)(getenv('APP_URL') ?: 'https://controle-de-gastos-one-silk.vercel.app'), '/')
            . '/mercadopago_return.php';
        $separator = (str_contains($initPoint, '?') ? '&' : '?');
        $initPointWithRef = $initPoint
            . $separator . 'external_reference=' . urlencode($externalRef)
            . '&payer_email=' . urlencode($email)
            . '&back_url=' . urlencode($backUrl);

        return [
            'ok'         => true,
            'status'     => $httpStatus,
            'init_point' => $initPointWithRef,
            'plan_id'    => $planId,
            'external_reference' => $externalRef,
        ];
    }

    /**
     * Consulta os dados oficiais de uma assinatura (preapproval) no Mercado Pago.
     * Esta é a fonte confiavel — nunca confiar apenas no payload do webhook.
     *
     * @param string $mpPreapprovalId ID da assinatura no Mercado Pago
     * @return array{ok:bool, status?:int, data?:array, error?:string}
     */
    public function getPreapproval(string $mpPreapprovalId): array
    {
        if ($mpPreapprovalId === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $mpPreapprovalId)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_id'];
        }

        $url = self::BASE_URL . '/preapproval/' . urlencode($mpPreapprovalId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'X-Integrator-Id: dev_controle_de_gastos',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log('[MercadoPagoService] getPreapproval curl error: ' . $curlErr);
            return ['ok' => false, 'status' => 0, 'error' => 'network_error'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            error_log('[MercadoPagoService] getPreapproval resposta nao-JSON (status=' . $httpStatus . ')');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_response'];
        }

        if ($httpStatus === 404) {
            return ['ok' => false, 'status' => 404, 'error' => 'not_found'];
        }

        if ($httpStatus !== 200) {
            $msg = is_string($data['message'] ?? null) ? (string)$data['message'] : 'mp_error';
            error_log('[MercadoPagoService] getPreapproval erro HTTP ' . $httpStatus . ': ' . $msg);
            return ['ok' => false, 'status' => $httpStatus, 'error' => $msg];
        }

        return ['ok' => true, 'status' => 200, 'data' => $data];
    }
}

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

    protected string $accessToken;

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
     * Cria uma assinatura (preapproval) via POST /preapproval na API do Mercado Pago.
     *
     * Diferenca critica do fluxo antigo (checkout hospedado por plano):
     * - O MP NAO persiste external_reference/payer_email/back_url enviados
     *   como query string no init_point do plano.
     * - Para que o webhook receba o external_reference, a subscription
     *   precisa ser criada na API com esses campos no CORPO do POST.
     * - A API devolve o init_point e o id (preapproval_id) que devem ser
     *   usados para o redirect do usuario.
     *
     * @param string $planSlug          'pro' ou 'premium' (validado contra .env)
     * @param int    $userId            id do usuario autenticado
     * @param string $email             email do pagador
     * @param string $externalReference ex: 'user_15_pro'
     * @return array{ok:bool, preapproval_id?:string, init_point?:string,
     *               external_reference?:string, plan_id?:string, status?:int, error?:string}
     */
    public function createPreapproval(
        string $planSlug,
        int $userId,
        string $email,
        string $externalReference
    ): array {
        $planSlug = strtolower(trim($planSlug));
        if (!in_array($planSlug, ['pro', 'premium'], true)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_plan'];
        }
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
        if (!preg_match('/^user_\d+_(pro|premium)$/', $externalReference)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_external_reference'];
        }

        $reason = $planSlug === 'pro'
            ? 'Controle de Gastos - Pro'
            : 'Controle de Gastos - Premium';

        $backUrl = rtrim((string)(getenv('APP_URL') ?: 'https://controle-de-gastos-one-silk.vercel.app'), '/')
            . '/mercadopago_return.php';

        $payload = [
            'preapproval_plan_id' => $planId,
            'reason'              => $reason,
            'external_reference'  => $externalReference,
            'payer_email'         => $email,
            'back_url'            => $backUrl,
            'auto_recurring'      => (object)[
                'frequency'         => 1,
                'frequency_type'    => 'months',
            ],
            'status'              => 'pending',
        ];

        $url = self::BASE_URL . '/preapproval';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
                'X-Integrator-Id: dev_controle_de_gastos',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($body === false) {
            error_log('[MercadoPagoService] createPreapproval curl error: ' . $curlErr);
            return ['ok' => false, 'status' => 0, 'error' => 'network_error'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            error_log('[MercadoPagoService] createPreapproval resposta nao-JSON (status=' . $httpStatus . ')');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_response'];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $msg = is_string($data['message'] ?? null) ? (string)$data['message'] : 'mp_error';
            error_log('[MercadoPagoService] createPreapproval erro HTTP ' . $httpStatus . ': ' . $msg);
            return ['ok' => false, 'status' => $httpStatus, 'error' => $msg];
        }

        $preapprovalId = $data['id'] ?? null;
        $initPoint     = $data['init_point'] ?? null;

        if (!is_string($preapprovalId) || $preapprovalId === '' ||
            !is_string($initPoint)     || $initPoint     === '') {
            error_log('[MercadoPagoService] createPreapproval sem id/init_point');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'missing_fields'];
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $preapprovalId)) {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_id'];
        }

        return [
            'ok'                 => true,
            'status'             => $httpStatus,
            'preapproval_id'     => $preapprovalId,
            'init_point'         => $initPoint,
            'external_reference' => $externalReference,
            'plan_id'            => $planId,
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

    /**
     * Cancela uma assinatura recorrente no Mercado Pago via PUT /preapproval/{id}.
     *
     * A API exige o corpo com { "status": "cancelled" }.
     * Idempotente: se a assinatura ja estiver cancelled, retorna ok=true.
     *
     * @param string $mpPreapprovalId ID da assinatura no Mercado Pago
     * @return array{ok:bool, status?:int, data?:array, error?:string}
     */
    public function cancelPreapproval(string $mpPreapprovalId): array
    {
        if ($mpPreapprovalId === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $mpPreapprovalId)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_id'];
        }

        $url = self::BASE_URL . '/preapproval/' . urlencode($mpPreapprovalId);
        $payload = json_encode(['status' => 'cancelled']);
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'X-Integrator-Id: dev_controle_de_gastos',
        ];

        $httpStatus = 0;
        $body = '';
        $curlErr = '';
        $startMs = (int)(microtime(true) * 1000);
        $this->curlPut($url, $headers, $payload, $body, $httpStatus, $curlErr, 8);
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        $mpIdTag = substr($mpPreapprovalId, -8);
        error_log(sprintf(
            '[cancel.mp_call_result] mp_id_suffix=%s http=%d elapsed_ms=%d body_len=%d curl_err=%s',
            $mpIdTag, $httpStatus, $elapsedMs, strlen($body), $curlErr === '' ? '-' : substr($curlErr, 0, 40)
        ));

        if ($body === '' && $curlErr !== '') {
            error_log('[MercadoPagoService] cancelPreapproval curl error: ' . $curlErr);
            return ['ok' => false, 'status' => 0, 'error' => 'network_error'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            error_log('[MercadoPagoService] cancelPreapproval resposta nao-JSON (status=' . $httpStatus . ')');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_response'];
        }

        if ($httpStatus === 404) {
            return ['ok' => false, 'status' => 404, 'error' => 'not_found'];
        }

        if ($httpStatus >= 400) {
            $msg = is_string($data['message'] ?? null) ? (string)$data['message'] : 'mp_error';
            if ($httpStatus === 400 && $this->isAlreadyCancelledMessage($msg, $data)) {
                error_log('[MercadoPagoService] cancelPreapproval already_cancelled via 400: ' . $msg);
                return [
                    'ok' => true,
                    'status' => $httpStatus,
                    'data' => ['status' => 'cancelled'],
                    'already_cancelled' => true,
                ];
            }
            error_log('[MercadoPagoService] cancelPreapproval erro HTTP ' . $httpStatus . ': ' . $msg);
            return ['ok' => false, 'status' => $httpStatus, 'error' => $msg];
        }

        $currentStatus = strtolower(trim((string)($data['status'] ?? '')));
        if ($currentStatus === 'cancelled') {
            return ['ok' => true, 'status' => 200, 'data' => $data, 'already_cancelled' => true];
        }

        return ['ok' => true, 'status' => $httpStatus, 'data' => $data];
    }

    private function isAlreadyCancelledMessage(string $msg, array $data): bool
    {
        $cancelledPatterns = [
            'cannot modify a cancelled',
            'cannot update a cancelled',
            'cannot change a cancelled',
            'cancelled preapproval',
            'preapproval already cancelled',
            'subscription already cancelled',
        ];
        $msgLower = strtolower($msg);
        foreach ($cancelledPatterns as $p) {
            if (str_contains($msgLower, strtolower($p))) {
                return true;
            }
        }
        if (isset($data['status']) && strtolower((string)$data['status']) === 'cancelled') {
            return true;
        }
        return false;
    }

    protected function curlPut(string $url, array $headers, string $payload, string &$body, int &$httpStatus, string &$curlErr, int $timeout = 30): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $body = is_string($resp) ? $resp : '';
    }

    /**
     * Status semanticos validos de preapproval no Mercado Pago.
     * Qualquer outro valor (inteiro, HTTP code, vazio, desconhecido)
     * NAO deve ser gravado em subscription.raw_status.
     */
    private const VALID_RAW_STATUSES = [
        'authorized', 'active', 'paused', 'cancelled', 'canceled',
        'expired', 'pending', 'in_process', 'rejected', 'failure',
    ];

    /**
     * Normaliza um valor bruto vindo do body da resposta MP para uso
     * em subscription.raw_status. Retorna:
     * - valor normalizado (lowercase, trim) se for status semantico conhecido;
     * - 'cancelled' se for codigo HTTP de sucesso (2xx) numa chamada de cancel;
     * - null se nao puder ser usado (int, codigo HTTP, vazio, desconhecido).
     *
     * @param mixed $rawValue  Valor bruto (pode ser string, int, null)
     * @param bool  $isCancelPath  Se true, normaliza HTTP 2xx para 'cancelled'
     *                             (chamada de cancelPreapproval com sucesso).
     */
    public static function sanitizeRawStatus($rawValue, bool $isCancelPath = false): ?string
    {
        if ($rawValue === null) return null;
        if (is_int($rawValue)) {
            if ($isCancelPath && $rawValue >= 200 && $rawValue < 300) return 'cancelled';
            return null;
        }
        if (!is_string($rawValue)) return null;
        $normalized = strtolower(trim($rawValue));
        if ($normalized === '') return null;
        if (is_numeric($normalized)) {
            $n = (int)$normalized;
            if ($isCancelPath && $n >= 200 && $n < 300) return 'cancelled';
            return null;
        }
        if (in_array($normalized, self::VALID_RAW_STATUSES, true)) return $normalized;
        if ($isCancelPath) {
            return 'cancelled';
        }
        return null;
    }
}

<?php
/**
 * MercadoPagoService — integração com a API de Assinaturas do Mercado Pago.
 *
 * Fluxo oficial de criação de assinatura:
 * 1. Cria uma preapproval via POST /preapproval com preapproval_plan_id,
 *    payer_email, external_reference e back_url no CORPO;
 * 2. O Mercado Pago persiste esses campos e devolve id + init_point;
 * 3. O caller redireciona o cliente para o init_point da preapproval criada;
 * 4. Apos o pagamento, o Mercado Pago notifica via webhook.
 *
 * Nunca expoe o Access Token ao frontend ou em logs.
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
     * Cria uma assinatura (preapproval) via POST /preapproval na API do Mercado Pago.
     *
     * FLUXO OFICIAL (tokenizacao via JS SDK + POST com card_token_id):
     * - O frontend tokeniza o cartao com MercadoPago.js (PUBLIC_KEY) e envia
     *   ao backend SOMENTE o card_token_id opaco (nunca numero/CVV).
     * - O backend monta external_reference a partir do attempt_token local,
     *   resolve plan_id/.env e payer_email do usuario autenticado, e chama
     *   este metodo com status=authorized.
     * - O MP persiste external_reference e o devolve no GET/webhook, o que
     *   permite correlacao deterministica attempt -> assinatura.
     *
     * Nunca criar preapproval sem card_token_id: a API responde HTTP 400.
     *
     * @param string $planId             preapproval_plan_id do MP (ex: 0d0a31c3...)
     * @param string $payerEmail         email do pagador (do usuario autenticado)
     * @param string $externalReference  attempt_token UUID hex (32 chars) ou
     *                                   legado user_<id>_<slug>
     * @param string $backUrl            URL de retorno apos checkout
     * @param string $cardTokenId        token opaco gerado pelo JS SDK (uso unico)
     * @param string $idempotencyKey     chave de idempotencia (UUID da tentativa).
     *                                   DECISAO (hardening): suporte oficial em
     *                                   POST /preapproval = PROVAVEL (spec da
     *                                   Subscriptions API menciona o header;
     *                                   sem pagina oficial dedicada). Header
     *                                   mantido por ser inofensivo e adotado
     *                                   pelos proprios SDKs do MP; a SEGURANCA
     *                                   NAO depende dele — a protecao primaria
     *                                   e a idempotencia local transacional.
     * @param mixed $deviceId           Device ID do navegador (MP_DEVICE_SESSION_ID
     *                                   do security.js oficial). Opcional: quando
     *                                   valido, vai como header X-meli-session-id
     *                                   (recomendacao oficial Subscriptions →
     *                                   Improve payment approval). Ausente/
     *                                   invalido = header omitido (fail-safe,
     *                                   checkout nunca bloqueia por isso).
     * @param string $reason           Descricao especifica da assinatura
     *                                   (ex.: "Controle de Gastos - Pro -
     *                                   Assinatura mensal"). Campo oficial
     *                                   aceito em POST /preapproval (com ou sem
     *                                   plano). Validado (sem CR/LF, <=128):
     *                                   invalido = omitido, sem bloquear.
     * @return array{ok:bool, preapproval_id?:string, init_point?:string,
     *               external_reference?:string, plan_id?:string, status?:int,
     *               mp_status?:string, error?:string}
     */
    public function createPreapproval(
        string $planId,
        string $payerEmail,
        string $externalReference,
        string $backUrl,
        string $cardTokenId,
        string $idempotencyKey = '',
        $deviceId = null,
        string $reason = ''
    ): array {
        $planId = trim($planId);
        if ($planId === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_plan_id'];
        }
        if ($payerEmail === '' || !filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_email'];
        }
        if (
            !preg_match('/^[0-9a-f]{32}$/', $externalReference)
            && !preg_match('/^user_\d+_(pro|premium)$/', $externalReference)
        ) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_external_reference'];
        }
        if ($backUrl === '' || !filter_var($backUrl, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_back_url'];
        }
        if (
            $cardTokenId === ''
            || strlen($cardTokenId) > 256
            || !preg_match('/^[A-Za-z0-9._\-]+$/', $cardTokenId)
        ) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_card_token'];
        }
        $idempotencyKey = trim($idempotencyKey);
        if (
            $idempotencyKey !== ''
            && (strlen($idempotencyKey) > 128 || preg_match('/[\r\n]/', $idempotencyKey) === 1)
        ) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_idempotency_key'];
        }

        $payload = [
            'preapproval_plan_id' => $planId,
            'external_reference'  => $externalReference,
            'payer_email'        => $payerEmail,
            'card_token_id'      => $cardTokenId,
            'back_url'           => $backUrl,
            'status'             => 'authorized',
        ];
        // Reason especifica e deterministica (contexto antifraude). Campo
        // oficial do endpoint; omitida se invalida. Sem PII (plano apenas).
        $reason = self::sanitizeReason($reason);
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        $url = self::BASE_URL . '/preapproval';
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'X-Integrator-Id: dev_controle_de_gastos',
        ];
        if ($idempotencyKey !== '') {
            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }
        // Device ID (header oficial anti-fraude). SOMENTE com valor validado;
        // jamais header vazio. Payload financeiro intacto.
        $deviceId = self::sanitizeDeviceId($deviceId);
        if ($deviceId !== null) {
            $headers[] = 'X-meli-session-id: ' . $deviceId;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            // Dentro da janela da function serverless (maxDuration 10s):
            // timeout total 8s + connect 5s evitam SIGKILL no meio do POST
            // (timeout pos-criacao gera preapproval orfa no MP).
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
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
            // status_detail do MP (ex.: cc_rejected_insufficient_amount): charset
            // restrito a codigos snake_case — nunca ecoa cartao/token (sao opacos
            // e nunca vao ao log em nenhum ponto deste fluxo).
            $detail = '';
            if (is_string($data['status_detail'] ?? null)) {
                $detail = strtolower(trim((string)$data['status_detail']));
                if (!preg_match('/^[a-z0-9_]{1,80}$/', $detail)) {
                    $detail = '';
                }
            }
            // Body sanitizado para forense de suporte (WCS-49458): preserva
            // estrutura/codigos/mensagens, redige PII/segredos. Capado.
            $safeBody = json_encode(
                self::sanitizeMpErrorBody($data),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (!is_string($safeBody) || strlen($safeBody) > 600) {
                $safeBody = substr(is_string($safeBody) ? $safeBody : '{}', 0, 600);
            }
            error_log('[MercadoPagoService] createPreapproval erro HTTP ' . $httpStatus . ': ' . $msg
                . ($detail !== '' ? ' detail=' . $detail : '')
                . ' body=' . $safeBody);
            return ['ok' => false, 'status' => $httpStatus, 'error' => $msg, 'mp_detail' => $detail];
        }

        $preapprovalId = $data['id'] ?? null;
        if (!is_string($preapprovalId) || $preapprovalId === '') {
            error_log('[MercadoPagoService] createPreapproval sem id');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'missing_id'];
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $preapprovalId)) {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_id'];
        }

        // Consistencia: o MP deve ecoar o external_reference e o plano enviados.
        // Divergencia indica resposta inesperada — nao persistir vinculo.
        // Nota (hardening): validar preapproval_plan_id contra .env e suficiente
        // aqui porque (a) o access token so enxerga objetos da propria conta
        // (id estrangeiro retorna 404, nunca dados de outro vendedor) e (b) em
        // assinatura com plano associado, valor/moeda/frequencia vêm DO PLANO
        // (este POST nao envia transaction_amount) — plano correto implica
        // valores corretos. application_id/collector_id nao tem valor esperado
        // configurado no servidor e nada agregariam a esse vinculo.
        if (isset($data['external_reference']) && (string)$data['external_reference'] !== $externalReference) {
            error_log('[MercadoPagoService] createPreapproval external_reference divergente');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'external_reference_mismatch'];
        }
        if (isset($data['preapproval_plan_id']) && (string)$data['preapproval_plan_id'] !== $planId) {
            error_log('[MercadoPagoService] createPreapproval preapproval_plan_id divergente');
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'plan_mismatch'];
        }

        // init_point e opcional no fluxo authorized (pode nao vir na resposta).
        $initPoint = $data['init_point'] ?? null;
        if ($initPoint !== null && !is_string($initPoint)) {
            $initPoint = null;
        }

        return [
            'ok'                 => true,
            'status'             => $httpStatus,
            'preapproval_id'     => $preapprovalId,
            'init_point'         => $initPoint,
            'external_reference' => $externalReference,
            'plan_id'            => $planId,
            'mp_status'          => is_string($data['status'] ?? null) ? (string)$data['status'] : null,
        ];
    }

    /**
     * Mapeia um resultado falho de createPreapproval() para um codigo seguro
     * exibivel ao usuario. Nunca expoe mensagem bruta do MP, token ou segredo.
     *
     * - 'invalid_card'  dados do cartao/token invalidos (tentar de novo com
     *                   dados corretos faz sentido);
     * - 'card_declined' emissor recusou (outro cartao/banco);
     * - 'processing'    falha de rede/timeout: a assinatura PODE ter sido criada
     *                   no MP — o frontend deve fazer polling, nao novo cartao;
     * - 'service_error' erro nosso/transitorio (tentar mais tarde).
     *
     * @param array{ok:bool, status?:int, error?:string, mp_detail?:string} $result
     */
    public static function mapErrorToUserCode(array $result): string
    {
        if (($result['ok'] ?? false) === true) {
            return 'ok';
        }
        $http = (int)($result['status'] ?? 0);
        $error = strtolower((string)($result['error'] ?? ''));
        $detail = strtolower((string)($result['mp_detail'] ?? ''));
        $blob = $error . ' ' . $detail;
        if (str_contains($error, 'invalid_card_token')) {
            return 'invalid_card';
        }
        if (
            str_contains($blob, 'declined') || str_contains($blob, 'insufficient')
            || str_contains($blob, 'rejected') || str_contains($blob, 'invalid_payment')
            || str_contains($blob, 'cc_rejected') || str_contains($blob, 'call_issuer')
        ) {
            return 'card_declined';
        }
        if ($http === 0) {
            return 'processing';
        }
        if (
            str_contains($blob, 'card_token') || str_contains($blob, 'invalid_card')
            || str_contains($blob, 'bad_request') || str_contains($blob, 'invalid_param')
            || str_contains($blob, 'cc_val_')
        ) {
            return 'invalid_card';
        }
        if ($http === 401 || $http === 403 || $http >= 500) {
            return 'service_error';
        }
        return 'payment_failed';
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
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
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
     * Busca preapprovals por external_reference EXATO (somente leitura).
     *
     * A API /preapproval/search NAO oferece filtro exato por
     * external_reference (filtros oficiais: q, payer_id, payer_email,
     * preapproval_plan_id, status). Por isso buscamos por `q` e filtramos
     * por igualdade EXATA no cliente, retornando apenas registros cujo
     * external_reference e identico ao informado.
     *
     * USO PERMITIDO: evitar POST duplicado de uma tentativa JA conhecida
     * (identidade fixada pela linha local) e varredura de orfas.
     * USO PROIBIDO: descobrir a qual usuario pertence uma preapproval
     * (identidade), ordenar por tempo ou pegar "a mais recente".
     *
     * Nunca lanca excecao: falha de transporte/resposta vira ok:false
     * (fail-open para a busca; a decisao de POST segue o fluxo normal).
     *
     * @return array{ok:bool, matches?:array, error?:string}
     */
    public function searchPreapprovalsByExternalReference(string $externalReference, int $limit = 10): array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $externalReference)) {
            return ['ok' => false, 'error' => 'invalid_external_reference', 'matches' => []];
        }
        $limit = max(1, min($limit, 50));
        $url = self::BASE_URL . '/preapproval/search?q=' . urlencode($externalReference)
            . '&limit=' . $limit;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'X-Integrator-Id: dev_controle_de_gastos',
            ],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            return ['ok' => false, 'error' => 'network_error', 'matches' => []];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'invalid_response', 'matches' => []];
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return ['ok' => false, 'error' => 'mp_error', 'matches' => []];
        }

        $matches = [];
        $results = $data['results'] ?? null;
        if (is_array($results)) {
            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string)($row['external_reference'] ?? '') !== $externalReference) {
                    continue;
                }
                $mpId = (string)($row['id'] ?? '');
                if ($mpId === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $mpId)) {
                    continue;
                }
                $matches[] = [
                    'id' => $mpId,
                    'status' => strtolower(trim((string)($row['status'] ?? ''))),
                    'preapproval_plan_id' => (string)($row['preapproval_plan_id'] ?? ''),
                    'external_reference' => $externalReference,
                ];
            }
        }
        return ['ok' => true, 'matches' => $matches];
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
     * Sanitiza o Device ID do navegador (MP_DEVICE_SESSION_ID do security.js
     * oficial) para uso como header X-meli-session-id.
     *
     * Contrato fail-safe: qualquer valor ausente/invalido retorna null e o
     * chamador OMITE o header (checkout nunca bloqueia por falta do Device ID).
     *
     * Regras (anti header-injection + anti-abuso):
     * - null/''/não-string → null;
     * - após trim: 8–128 chars, SOMENTE ASCII visível sem espaço
     *   ([\x21-\x7E]) — CR/LF/%0d/%0a e controles são impossíveis aqui,
     *   logo nenhum valor sanitizado pode quebrar linhas do header.
     *
     * @param mixed $deviceId valor não confiável vindo do frontend
     */
    public static function sanitizeDeviceId($deviceId): ?string
    {
        if (!is_string($deviceId)) {
            return null;
        }
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return null;
        }
        if (preg_match('/[\r\n]/', $deviceId) === 1) {
            return null;
        }
        if (!preg_match('/\A[\x21-\x7E]{8,128}\z/', $deviceId)) {
            return null;
        }
        return $deviceId;
    }

    /**
     * Sanitiza a descricao (reason) da assinatura: deterministica por plano,
     * sem PII. Rejeita CR/LF, controles e excesso (>128): invalido = omitir.
     */
    public static function sanitizeReason($reason): ?string
    {
        if (!is_string($reason)) {
            return null;
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 128) {
            return null;
        }
        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $reason) === 1) {
            return null;
        }
        return $reason;
    }

    /**
     * Sanitiza o body de erro do MP para logging forense (suporte WCS-49458).
     * Preserva estrutura/codigos/mensagens/tipos; redige PII e segredos:
     * emails, chaves (TEST-/APP_USR-), Bearer, PAN (13-19 digitos), hex
     * longo (tokens/ids opacos), com teto de tamanho. Causas aninhadas
     * (cause/causes) sanitizadas um nivel. Nunca inclui Device ID, card
     * token, Access Token ou Public Key (esses nunca entram no body lido).
     *
     * @param mixed $data body decodificado (ou qualquer valor)
     */
    public static function sanitizeMpErrorBody($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count >= 20) {
                break;
            }
            $count++;
            $safeKey = is_string($key) ? substr(preg_replace('/[^\w\-]/', '_', $key) ?? 'k', 0, 40) : 'k';
            if (is_string($value)) {
                $out[$safeKey] = self::redactSecretText(substr($value, 0, 200));
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $out[$safeKey] = $value;
            } elseif (is_array($value) && ($safeKey === 'cause' || $safeKey === 'causes')) {
                $nested = [];
                $n = 0;
                foreach ($value as $ck => $cv) {
                    if ($n >= 10) {
                        break;
                    }
                    $n++;
                    $nkey = is_string($ck) ? substr($ck, 0, 40) : (int)$ck;
                    $nested[$nkey] = is_string($cv)
                        ? self::redactSecretText(substr($cv, 0, 200))
                        : (is_scalar($cv) || $cv === null ? $cv : '[nested]');
                }
                $out[$safeKey] = $nested;
            } else {
                $out[$safeKey] = '[omitted]';
            }
        }
        return $out;
    }

    /**
     * Redige segredos/PII de um texto livre (mesma politica de
     * Subscription::describeDbError, aplicada a bodies do MP).
     */
    public static function redactSecretText(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '<email>', $text) ?? $text;
        $text = preg_replace('/(TEST-|APP_USR-)[A-Za-z0-9_-]+/', '$1<redacted>', $text) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/', 'Bearer <redacted>', $text) ?? $text;
        $text = preg_replace('/\b\d{13,19}\b/', '<card-redacted>', $text) ?? $text;
        $text = preg_replace('/\b[0-9a-fA-F]{16,}\b/', '<hex-redacted>', $text) ?? $text;
        return $text;
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

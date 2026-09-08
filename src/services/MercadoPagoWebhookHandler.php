<?php
/**
 * MercadoPagoWebhookHandler — infraestrutura MINIMA de webhook (etapa webhook-1).
 *
 * Escopo desta etapa (preparacao, sem efeitos):
 * - Recebe notificacoes do Mercado Pago, valida a assinatura HMAC oficial
 *   (x-signature/x-request-id) quando MERCADOPAGO_WEBHOOK_SECRET estiver
 *   configurada e responde com o codigo adequado — SEM alterar nenhum dado.
 * - NAO consulta a API do Mercado Pago (sem MERCADOPAGO_ACCESS_TOKEN aqui).
 * - NAO executa INSERT/UPDATE/DELETE; NAO ativa planos; NAO sincroniza nada.
 * - Sem secret configurado: modo diagnostico seguro — apenas registra o
 *   recebimento e responde 200, sem nenhum efeito. NAO e bypass: nao existe
 *   neste codigo nenhum caminho que ative planos.
 *
 * Validacao oficial (docs Mercado Pago):
 *   manifest = "id:{data.id};request-id:{x-request-id};ts:{ts};"
 *   v1 = HMAC-SHA256(manifest, MERCADOPAGO_WEBHOOK_SECRET) em hex
 *   x-signature: "ts={ts},v1={v1}"
 *
 * Logs minimos e seguros: tipo do evento, presenca/ausencia de ID,
 * resultado da validacao e request id (UUID opaco). NUNCA Access Token,
 * webhook secret, cartao, e-mail ou dados sensiveis.
 */

class MercadoPagoWebhookHandler
{
    /** Topicos aceitos nesta etapa (recebidos, logados, sem efeitos). */
    public const ALLOWED_TYPES = [
        'subscription_preapproval',
        'subscription_authorized_payment',
        'subscription_preapproval_plan',
        'payment',
    ];

    /**
     * Processa uma notificacao de forma pura (sem I/O alem do retorno).
     *
     * @param string $method       Metodo HTTP (ex: 'POST').
     * @param array  $headers      Headers normalizados (chaves em minusculas).
     * @param string $queryString  Query string bruta (ex: 'data.id=123').
     * @param string $rawBody      Corpo bruto da requisicao.
     * @param string $secret       Valor de MERCADOPAGO_WEBHOOK_SECRET ('' = ausente).
     *
     * @return array{http_code:int, body:array, log:array}
     *   - body: payload JSON de resposta (sem dados sensiveis).
     *   - log: campos seguros para error_log (sem segredos).
     */
    public static function process(string $method, array $headers, string $queryString, string $rawBody, string $secret): array
    {
        $method = strtoupper(trim($method));
        if ($method !== 'POST') {
            return [
                'http_code' => 405,
                'body' => ['ok' => false, 'error' => 'method_not_allowed'],
                'log' => ['mp_webhook' => true, 'method' => $method, 'result' => 'method_not_allowed'],
            ];
        }

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || $data === []) {
            return [
                'http_code' => 400,
                'body' => ['ok' => false, 'error' => 'invalid_payload'],
                'log' => ['mp_webhook' => true, 'result' => 'invalid_json'],
            ];
        }

        $type = strtolower(trim((string)($data['type'] ?? $data['topic'] ?? '')));
        $known = in_array($type, self::ALLOWED_TYPES, true);
        $id = self::extractId($data, $queryString);

        $requestId = trim((string)($headers['x-request-id'] ?? ''));
        if ($requestId !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/', $requestId)) {
            $requestId = '';
        }

        $log = [
            'mp_webhook' => true,
            'type' => $type !== '' ? $type : 'unknown',
            'known_type' => $known,
            'has_id' => $id !== null,
            'request_id' => $requestId !== '' ? $requestId : null,
        ];

        if (!$known) {
            $log['result'] = 'ignored_unknown_type';
            return ['http_code' => 200, 'body' => ['ok' => true, 'handled' => false], 'log' => $log];
        }

        if ($secret === '') {
            // Modo diagnostico: secret ainda nao configurado — nenhum efeito,
            // apenas registra o recebimento. Nao e bypass (nada aqui ativa planos).
            $log['result'] = 'received_unconfigured';
            $log['validated'] = false;
            return ['http_code' => 200, 'body' => ['ok' => true, 'handled' => false], 'log' => $log];
        }

        $signature = trim((string)($headers['x-signature'] ?? ''));
        if ($signature === '' || $requestId === '') {
            $log['result'] = 'missing_signature';
            $log['validated'] = false;
            return ['http_code' => 401, 'body' => ['ok' => false, 'error' => 'missing_signature'], 'log' => $log];
        }

        if (!self::isSignatureValid($signature, $requestId, self::manifestId($queryString, $id), $secret)) {
            $log['result'] = 'invalid_signature';
            $log['validated'] = false;
            return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'invalid_signature'], 'log' => $log];
        }

        $log['result'] = 'received_validated';
        $log['validated'] = true;
        return ['http_code' => 200, 'body' => ['ok' => true, 'handled' => false], 'log' => $log];
    }

    /**
     * Extrai ID de forma defensiva, sem assumir uma unica forma.
     * Retorna null quando ausente. Nunca loga o valor aqui (so presenca).
     */
    public static function extractId(array $data, string $queryString): ?string
    {
        $candidates = [];
        if (isset($data['data']) && is_array($data['data']) && isset($data['data']['id'])) {
            $candidates[] = $data['data']['id'];
        }
        parse_str($queryString, $qs);
        if (isset($qs['data_id'])) {
            // PHP converte '.' em '_' — cobre ?data.id=...
            $candidates[] = $qs['data_id'];
        }
        if (isset($data['data_id'])) {
            $candidates[] = $data['data_id'];
        }
        if (isset($data['id'])) {
            $candidates[] = $data['id'];
        }
        foreach ($candidates as $c) {
            if (is_string($c) || is_int($c)) {
                $v = trim((string)$c);
                if ($v !== '' && strlen($v) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $v)) {
                    return $v;
                }
            }
        }
        return null;
    }

    /**
     * ID canonico para o manifest HMAC: o da query (?data.id=), conforme a
     * documentacao oficial; fallback para o ID extraido do body.
     */
    public static function manifestId(string $queryString, ?string $bodyId): ?string
    {
        parse_str($queryString, $qs);
        if (isset($qs['data_id'])) {
            $v = trim((string)$qs['data_id']);
            if ($v !== '' && strlen($v) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $v)) {
                return $v;
            }
        }
        return $bodyId;
    }

    /**
     * Valida a assinatura HMAC oficial. O segredo NUNCA e logado/retornado.
     */
    public static function isSignatureValid(string $signature, string $requestId, ?string $dataId, string $secret): bool
    {
        $ts = null;
        $v1 = null;
        foreach (explode(',', $signature) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            $k = strtolower(trim($kv[0]));
            $v = trim($kv[1]);
            if ($k === 'ts' && $v !== '') {
                $ts = $v;
            } elseif ($k === 'v1' && $v !== '') {
                $v1 = strtolower($v);
            }
        }
        if ($ts === null || $v1 === null || $dataId === null || $secret === '') {
            return false;
        }
        if (!preg_match('/^[0-9]{1,20}$/', $ts) || !preg_match('/^[0-9a-f]{1,128}$/', $v1)) {
            return false;
        }
        $manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);
        return hash_equals($expected, $v1);
    }

    /**
     * Constroi manifest/doc-teste: gera assinatura valida para um cenario.
     * Uso exclusivo em testes (o segredo de teste nunca e o de producao).
     */
    public static function buildTestSignature(string $dataId, string $requestId, string $ts, string $secret): string
    {
        $manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
        return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', $manifest, $secret);
    }
}

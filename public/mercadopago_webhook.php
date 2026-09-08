<?php
/**
 * public/mercadopago_webhook.php — endpoint público de notificações MP.
 *
 * URL esperada: https://<APP_URL>/mercadopago_webhook.php
 * (via api/index.php, que inclui este arquivo para *.php — sem rewrite extra)
 *
 * Server-to-server: SEM login, SEM sessão, SEM CSRF.
 * Autenticidade via x-signature HMAC (SubscriptionWebhookService).
 * Respostas pequenas, sem HTML/redirect. 2xx = recebido (MP retenta
 * não-2xx a cada ~15min); 401 = forjado; 500 = transitório/misconfig.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/MercadoPagoClient.php';
require_once __DIR__ . '/../src/services/SubscriptionService.php';
require_once __DIR__ . '/../src/services/SubscriptionWebhookService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'code' => 'method_not_allowed']);
    exit;
}

/** Headers case-insensitive (getallheaders nem sempre existe no SAPI). */
function mp_webhook_headers(): array
{
    if (function_exists('getallheaders')) {
        $out = [];
        foreach ((array)getallheaders() as $k => $v) {
            $out[strtolower((string)$k)] = is_string($v) ? $v : '';
        }
        return $out;
    }
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_') && is_string($v)) {
            $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
    }
    return $out;
}

try {
    $headers = mp_webhook_headers();
    $xSignature = $headers['x-signature'] ?? '';
    $xRequestId = $headers['x-request-id'] ?? '';
    $rawBody = (string)file_get_contents('php://input');

    $db = getDBConnection();
    $svc = new SubscriptionWebhookService(new SubscriptionService($db));
    $res = $svc->handle($xSignature, $xRequestId, $_GET, $rawBody);

    http_response_code((int)$res['http']);
    echo json_encode(['ok' => $res['http'] === 200, 'code' => $res['code'], 'event' => $res['event']]);
} catch (Throwable $e) {
    error_log('[mp-webhook] erro interno: ' . substr($e->getMessage(), 0, 120));
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'internal_error']);
}

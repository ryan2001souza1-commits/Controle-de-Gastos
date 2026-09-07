<?php
// =============================================================================
// public/mercadopago_webhook.php — Webhook de Assinaturas (Mercado Pago)
// =============================================================================
// URL pública: https://controle-de-gastos-one-silk.vercel.app/mercadopago_webhook.php
// Servido diretamente pela Vercel (api/index.php delega /public/*.php).
//
// - Endpoint PÚBLICO, sem sessão e SEM CSRF (usa x-signature HMAC).
// - GET retorna 405. POST sem assinatura válida é recusado (401/503).
// - Nunca expõe token, secret ou stack trace nas respostas.
// =============================================================================

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/services/MercadoPagoClient.php';
require_once $ROOT . '/src/services/SubscriptionService.php';
require_once $ROOT . '/src/services/SubscriptionWebhookService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Headers (case-insensitive via $_SERVER).
$xSignature = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
$xRequestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');

// Query params (o MP pode enviar data.id / data_id / id / topic na URL).
$query = [];
foreach (['data.id', 'data_id', 'id', 'topic', 'type', 'action'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k])) $query[$k] = $_GET[$k];
}

$rawBody = (string)file_get_contents('php://input');
if (strlen($rawBody) > 65536) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
    exit;
}

try {
    $db = getDBConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
    exit;
}

try {
    $svc = new SubscriptionWebhookService($db);
    $result = $svc->handle($xSignature, $xRequestId, $query, $rawBody);
} catch (Throwable $e) {
    error_log('[mp-webhook] exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
    exit;
}

http_response_code($result['http']);
// Resposta mínima: sem ids reais, sem detalhes internos.
echo json_encode(['ok' => $result['http'] === 200, 'code' => $result['code']]);

<?php
// =============================================================================
// public/mercadopago_webhook.php — Webhook oficial Mercado Pago (backend-only)
// =============================================================================
// Endpoint publico HTTPS dedicado. PROPOSITALMENTE sem sessao, sem login e
// sem CSRF: a autenticacao e feita via x-signature (HMAC) + consulta oficial
// a API. Nunca expoe stack trace, segredos ou detalhes internos.
//
// Servido pelo api/index.php (fallback de arquivos publicos) e localmente
// via php -S -t public.
// =============================================================================

declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    require_once __DIR__ . '/../src/config/config.php';
    require_once __DIR__ . '/../src/models/User.php';
    require_once __DIR__ . '/../src/models/Plan.php';
    require_once __DIR__ . '/../src/services/PlanService.php';
    require_once __DIR__ . '/../src/services/BillingSyncService.php';
    require_once __DIR__ . '/../src/services/WebhookLedger.php';
    require_once __DIR__ . '/../src/services/MercadoPagoClient.php';
    require_once __DIR__ . '/../src/controllers/MpWebhookController.php';

    $db = getDBConnection();
    $controller = new MpWebhookController($db, new User($db), new PlanService($db));
    $controller->handle([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'headers' => [
            'x-signature' => (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
            'x-request-id' => (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),
        ],
        'query' => $_GET,
        'rawBody' => (string)file_get_contents('php://input'),
    ]);
} catch (Throwable $e) {
    error_log('[mp_webhook] fatal ' . substr($e->getMessage(), 0, 200));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'erro_interno'], JSON_UNESCAPED_UNICODE);
}

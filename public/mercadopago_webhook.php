<?php
/**
 * mercadopago_webhook.php — Endpoint público de webhook do Mercado Pago.
 *
 * Seguranca:
 *   - NAO exige sessao (servidor-servidor)
 *   - Valida X-Signature via HMAC-SHA256
 *   - Idempotente via payment_webhooks.event_id UNIQUE
 *   - Responde 200/401 rapido (sem chamadas externas ao MP)
 *   - Nao expoe tokens, senhas ou dados de cartao nos logs
 *
 * Importante: o processamento real dos dados do MP e feito apos o registro
 * do evento (dentro da mesma requisicao para evitar loops).
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);

$envFile = $ROOT . '/.env';
if (is_file($envFile) && getenv('VERCEL_ENV') === false) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'empty_body']);
    exit;
}

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
$xDataId    = $_SERVER['HTTP_X_DATA_ID'] ?? null;
$sourceIp   = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    require_once $ROOT . '/src/config/config.php';
    require_once $ROOT . '/src/models/User.php';
    require_once $ROOT . '/src/services/PlanService.php';
    require_once $ROOT . '/src/models/Subscription.php';
    require_once $ROOT . '/src/services/MercadoPagoService.php';
    require_once $ROOT . '/src/services/WebhookService.php';

    $db = getDBConnection();

    $userModel = new User($db);
    $planService = new PlanService($db);
    $subscriptionModel = new Subscription($db);
    $mpService = new MercadoPagoService();
    $webhookService = new WebhookService($db, $subscriptionModel, $mpService);

    $result = $webhookService->handle($rawBody, $xSignature, $xRequestId, $xDataId, $sourceIp);

    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo json_encode([
        'received' => true,
        'duplicate' => $result['duplicate'] ?? false,
        'processed' => $result['processed'] ?? false,
    ]);
    exit;
} catch (Throwable $e) {
    error_log('[mercadopago_webhook] exception: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'internal_error']);
    exit;
}

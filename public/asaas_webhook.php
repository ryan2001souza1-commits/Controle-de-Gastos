<?php
/**
 * asaas_webhook.php — Endpoint público de webhook do Asaas.
 *
 * SEGURANCA:
 *   - NAO exige login, sessao ou CSRF
 *   - Autenticacao via header "asaas-access-token" comparado com
 *     ASAAS_WEBHOOK_TOKEN via hash_equals
 *   - Aceita apenas POST
 *   - Resposta rapida: 200 mesmo para eventos ja processados (idempotencia)
 *   - NUNCA loga o token, o payload bruto, dados de cartao, CPF completo
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
        }
    }
}

require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/AsaasService.php';
require_once $ROOT . '/src/services/AsaasWebhookService.php';
require_once $ROOT . '/src/migrations.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

if (!AsaasService::isConfigured()) {
    http_response_code(503);
    echo json_encode(['error' => 'asaas_not_configured']);
    exit;
}

$accessToken = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? null;
$rawBody = (string)file_get_contents('php://input');
$sourceIp = $_SERVER['REMOTE_ADDR'] ?? null;

$webhookSecret = (string)(getenv('ASAAS_WEBHOOK_TOKEN') ?: '');
if ($webhookSecret === '') {
    http_response_code(503);
    echo json_encode(['error' => 'webhook_token_not_configured']);
    exit;
}

if ($accessToken === null || $accessToken === '' || !hash_equals($webhookSecret, $accessToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

try {
    $db = getDBConnection();
    runMigrations($db);

    $subModel = new Subscription($db);
    $webhook  = new AsaasWebhookService($db, $subModel);
    $result   = $webhook->handle($rawBody, $accessToken, $sourceIp);

    http_response_code((int)($result['status'] ?? 200));
    echo json_encode([
        'received'  => true,
        'duplicate' => (bool)($result['duplicate'] ?? false),
        'processed' => (bool)($result['processed'] ?? false),
    ]);
    exit;
} catch (Throwable $e) {
    $safe = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $e->getMessage()), 0, 200);
    error_log('[asaas_webhook] exception: ' . $safe);
    http_response_code(500);
    echo json_encode(['error' => 'internal_error']);
    exit;
}

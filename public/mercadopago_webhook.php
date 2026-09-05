<?php
/**
 * Mercado Pago Webhook — endpoint para notificações de assinaturas.
 *
 * URL de produção: https://controle-de-gastos-one-silk.vercel.app/mercadopago_webhook.php
 *
 * Segurança:
 * - Não aceita GET.
 * - Não expõe Access Token.
 * - Sempre consulta GET /preapproval/{id} como fonte confiavel.
 * - Idempotente:webhooks podem chegar multiplas vezes com o mesmo ID.
 * - Valida external_reference no formato estrito user_{ID}_{pro|premium}.
 * - Valida preapproval_plan_id contra os IDs do .env.
 * - Logs sanitizados — nunca expõe token ou dados sensiveis.
 *
 * Configuração no painel do Mercado Pago:
 * - URL de notificação: https://controle-de-gastos-one-silk.vercel.app/mercadopago_webhook.php
 * - Notificações: Assinaturas (preapprovals)
 */

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

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

require_once $ROOT . '/src/config/config.php';

try {
    $db = getDBConnection();
} catch (Throwable $e) {
    $hasUrl = getenv('DATABASE_URL') !== false && getenv('DATABASE_URL') !== '';
    $parsed = $hasUrl ? @parse_url(getenv('DATABASE_URL')) : false;
    $hasHost = is_array($parsed) && !empty($parsed['host']);
    $hasScheme = is_array($parsed) && !empty($parsed['scheme']);
    $sslMode = (is_array($parsed) && !empty($parsed['query']))
        ? (function (string $q): ?string {
            parse_str($q, $r);
            return $r['sslmode'] ?? null;
        })($parsed['query'])
        : null;
    $pdoAvailable = in_array('pgsql', PDO::getAvailableDrivers(), true);

    error_log(sprintf(
        '[MPWebhook] DB fail: class=%s code=%s msg_safe=defined url=%s scheme=%s host=%s sslmode=%s pdo_pgsql=%s',
        get_class($e),
        (string)$e->getCode(),
        $hasUrl ? 'yes' : 'no',
        $hasScheme ? 'yes' : 'no',
        $hasHost ? 'yes' : 'no',
        $sslMode ?? 'none',
        $pdoAvailable ? 'yes' : 'no'
    ));
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';

$rawBody = file_get_contents('php://input');
$body = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$headers = [];
if (function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    if (is_array($allHeaders)) {
        foreach ($allHeaders as $key => $value) {
            $headers[$key] = $value;
            $normalizedKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $headers[$normalizedKey] = $value;
        }
    }
}

$mpPreapprovalId = MercadoPagoWebhookService::extractPreapprovalId($_GET, $body, $headers);

if ($mpPreapprovalId === null) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['received' => true, 'note' => 'no preapproval_id found']);
    exit;
}

$webhookSecret = (string)getenv('MERCADOPAGO_WEBHOOK_SECRET');
if ($webhookSecret !== '') {
    $sigHeader = '';
    if (isset($headers['x-signature']) && is_string($headers['x-signature'])) {
        $sigHeader = $headers['x-signature'];
    } elseif (isset($headers['HTTP_X_SIGNATURE']) && is_string($headers['HTTP_X_SIGNATURE'])) {
        $sigHeader = $headers['HTTP_X_SIGNATURE'];
    }

    $requestId = '';
    if (isset($headers['x-request-id']) && is_string($headers['x-request-id'])) {
        $requestId = $headers['x-request-id'];
    } elseif (isset($headers['HTTP_X_REQUEST_ID']) && is_string($headers['HTTP_X_REQUEST_ID'])) {
        $requestId = $headers['HTTP_X_REQUEST_ID'];
    }

    [$sigTs, $sigV1] = MercadoPagoWebhookService::parseSignatureHeader($sigHeader);

    $sigDiag = sprintf(
        '[MPWebhook] sigdiag: x_signature_present=%s x_request_id_present=%s data_id_present=%s ts_present=%s v1_present=%s data_id_source=%s',
        $sigHeader !== '' ? 'yes' : 'no',
        $requestId !== '' ? 'yes' : 'no',
        $mpPreapprovalId !== null ? 'yes' : 'no',
        $sigTs !== null ? 'yes' : 'no',
        $sigV1 !== null ? 'yes' : 'no',
        $mpPreapprovalId !== null ? 'query' : 'none'
    );
    error_log($sigDiag);

    $valid = ($sigTs !== null && $sigV1 !== null)
        && MercadoPagoWebhookService::validateSignature($webhookSecret, $mpPreapprovalId, $sigTs, $sigV1, $requestId);

    if (!$valid) {
        error_log('[MPWebhook] assinatura invalida para preapproval_id');
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_signature']);
        exit;
    }
}

try {
    $mpService = new MercadoPagoService();
} catch (Throwable $e) {
    error_log('[MPWebhook] MercadoPagoService init failed: ' . get_class($e));
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Service unavailable']);
    exit;
}

try {
    $webhookService = new MercadoPagoWebhookService($db, $mpService);
    $result = $webhookService->process($mpPreapprovalId);
} catch (Throwable $e) {
    error_log('[MPWebhook] processamento falhou: ' . get_class($e));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['received' => false, 'action' => 'internal_error']);
    exit;
}

http_response_code($result['http_status']);
header('Content-Type: application/json');
echo json_encode([
    'received' => $result['ok'],
    'action' => $result['action'],
]);
exit;

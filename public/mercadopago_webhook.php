<?php
// =============================================================================
// public/mercadopago_webhook.php — endpoint MINIMO de webhook (etapa webhook-1)
// =============================================================================
// URL: https://controle-de-gastos-one-silk.vercel.app/mercadopago_webhook.php
//
// - Servido diretamente por api/index.php (ramo generico, sem DB/sessao).
// - Arquivo standalone: NAO requer config, banco ou sessao.
// - Aceita SOMENTE POST para processamento; outros metodos => 405.
// - Delega o gate a MercadoPagoWebhookHandler e, SOMENTE para
//   subscription_preapproval com x-signature valida, verifica a assinatura
//   real via GET /preapproval/{ID} (MercadoPagoSubscriptionVerifier).
// - NESTA ETAPA: nenhum efeito colateral — sem INSERT/UPDATE/DELETE,
//   sem ativacao de planos. Falha temporaria da API => 500 (retry do MP).
// =============================================================================

declare(strict_types=1);

$__mpRoot = dirname(__DIR__);
require_once $__mpRoot . '/src/services/MercadoPagoWebhookHandler.php';
require_once $__mpRoot . '/src/services/MercadoPagoSubscriptionVerifier.php';
require_once $__mpRoot . '/src/services/MercadoPagoWebhookProcessor.php';

$__mpMethod = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));

// Headers normalizados (chaves minusculas). getallheaders() nem sempre existe.
$__mpHeaders = [];
if (function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $__k => $__v) {
        $__mpHeaders[strtolower(trim((string)$__k))] = trim((string)$__v);
    }
}
foreach ($_SERVER as $__k => $__v) {
    if (str_starts_with((string)$__k, 'HTTP_')) {
        $__name = strtolower(str_replace('_', '-', substr((string)$__k, 5)));
        if (!isset($__mpHeaders[$__name])) {
            $__mpHeaders[$__name] = trim((string)$__v);
        }
    }
}
if (isset($_SERVER['HTTP_X_SIGNATURE']) && !isset($__mpHeaders['x-signature'])) {
    $__mpHeaders['x-signature'] = trim((string)$_SERVER['HTTP_X_SIGNATURE']);
}
if (isset($_SERVER['HTTP_X_REQUEST_ID']) && !isset($__mpHeaders['x-request-id'])) {
    $__mpHeaders['x-request-id'] = trim((string)$_SERVER['HTTP_X_REQUEST_ID']);
}

$__mpQuery = (string)($_SERVER['QUERY_STRING'] ?? '');
$__mpRaw = (string)@file_get_contents('php://input');

$__mpSecret = getenv('MERCADOPAGO_WEBHOOK_SECRET');
if ($__mpSecret === false) {
    $__mpSecret = '';
}
if ($__mpSecret === '' && isset($_ENV['MERCADOPAGO_WEBHOOK_SECRET'])) {
    $__mpSecret = (string)$_ENV['MERCADOPAGO_WEBHOOK_SECRET'];
}
$__mpSecret = trim($__mpSecret);

$__mpVerifier = MercadoPagoSubscriptionVerifier::fromEnv();
$__mpResult = MercadoPagoWebhookProcessor::processNotification(
    $__mpMethod,
    $__mpHeaders,
    $__mpQuery,
    $__mpRaw,
    $__mpSecret,
    $__mpVerifier
);

// Log minimo e seguro (o handler garante: sem segredos ou dados sensiveis).
error_log('[mp_webhook] ' . json_encode($__mpResult['log'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

http_response_code((int)$__mpResult['http_code']);
header('Content-Type: application/json');
header('Allow: POST');
echo json_encode($__mpResult['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
/**
 * Testes do MercadoPagoClient (ETAPA 1) — SEM rede, SEM credenciais reais.
 *
 * Referência oficial:
 *   https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/overview
 *
 * O transporte HTTP é substituído por stub (MercadoPagoClient::$transport).
 * Token usado aqui é fictício (TEST-...), nunca credencial real.
 */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoClient.php';

$passed = 0; $failed = 0;
function mp_client_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

echo "\n=== TESTES: MercadoPagoClient (ETAPA 1, sem rede) ===\n\n";
putenv('MERCADOPAGO_ACCESS_TOKEN=TEST-ACCESS-TOKEN-FAKE-0001');
$_ENV['MERCADOPAGO_ACCESS_TOKEN'] = 'TEST-ACCESS-TOKEN-FAKE-0001';

// ---- C01: endpoint + Authorization + payload no create ----
$captured = [];
MercadoPagoClient::$transport = function (string $method, string $url, ?array $body, string $token) use (&$captured) {
    $captured = ['method' => $method, 'url' => $url, 'body' => $body, 'token' => $token];
    return ['ok' => true, 'http' => 201, 'data' => ['id' => 'abc123', 'status' => 'pending', 'init_point' => 'https://www.mercadopago.com.br/x'], 'error' => ''];
};
$client = new MercadoPagoClient();
$res = $client->createPreapproval(['preapproval_plan_id' => 'plan-1', 'payer_email' => 'a@b.c']);
mp_client_assert($captured['method'] === 'POST', 'C01a create usa POST');
mp_client_assert($captured['url'] === 'https://api.mercadopago.com/preapproval', 'C01b endpoint oficial POST /preapproval', $captured['url']);
mp_client_assert($captured['token'] === 'TEST-ACCESS-TOKEN-FAKE-0001', 'C01c token entregue ao transporte (header Bearer)');
mp_client_assert(($captured['body']['preapproval_plan_id'] ?? '') === 'plan-1', 'C01d payload preservado');
mp_client_assert($res['ok'] && $res['http'] === 201 && ($res['data']['id'] ?? '') === 'abc123', 'C01e sucesso 201 repassado');

// ---- C02: get usa GET + path com id ----
$captured = [];
$res = $client->getPreapproval('xyz-99');
mp_client_assert($captured['method'] === 'GET' && $captured['url'] === 'https://api.mercadopago.com/preapproval/xyz-99', 'C02 get endpoint oficial');
mp_client_assert($captured['body'] === null, 'C02b GET sem body');

// ---- C03: update usa PUT ----
$captured = [];
$res = $client->updatePreapproval('xyz-99', ['status' => 'canceled']);
mp_client_assert($captured['method'] === 'PUT' && $captured['url'] === 'https://api.mercadopago.com/preapproval/xyz-99', 'C03 update endpoint oficial');
mp_client_assert(($captured['body']['status'] ?? '') === 'canceled', 'C03b cancelamento oficial status=canceled');

// ---- C04: id inválido nem chama transporte ----
$calls = 0;
MercadoPagoClient::$transport = function () use (&$calls) { $calls++; return ['ok' => true, 'http' => 200, 'data' => [], 'error' => '']; };
$res = $client->getPreapproval('../evil?q=1');
mp_client_assert(!$res['ok'] && $res['error'] === 'invalid_id' && $calls === 0, 'C04 path injection rejeitado sem HTTP');
$res = $client->updatePreapproval('', ['status' => 'canceled']);
mp_client_assert(!$res['ok'] && $res['error'] === 'invalid_id' && $calls === 0, 'C04b id vazio rejeitado');

// ---- C05: sem token = missing_access_token, sem HTTP ----
putenv('MERCADOPAGO_ACCESS_TOKEN'); unset($_ENV['MERCADOPAGO_ACCESS_TOKEN'], $_SERVER['MERCADOPAGO_ACCESS_TOKEN']);
$noToken = new MercadoPagoClient();
$calls = 0;
$res = $noToken->createPreapproval(['a' => 1]);
mp_client_assert(!$res['ok'] && $res['error'] === 'missing_access_token' && $calls === 0, 'C05 fail-closed sem token');
putenv('MERCADOPAGO_ACCESS_TOKEN=TEST-ACCESS-TOKEN-FAKE-0001');
$_ENV['MERCADOPAGO_ACCESS_TOKEN'] = 'TEST-ACCESS-TOKEN-FAKE-0001';

// ---- C06: mapeamento de erros HTTP ----
foreach ([
    [400, 'http_400'], [401, 'http_401'], [403, 'http_403'], [404, 'http_404'],
    [409, 'http_409'], [429, 'http_429'], [500, 'http_5xx'], [503, 'http_5xx'], [302, 'http_error'],
] as [$http, $expected]) {
    MercadoPagoClient::$transport = fn() => ['ok' => false, 'http' => $http, 'data' => [], 'error' => $expected];
    $res = (new MercadoPagoClient())->getPreapproval('abc');
    mp_client_assert(!$res['ok'] && $res['error'] === $expected && $res['http'] === $http, "C06 HTTP $http -> $expected");
}

// ---- C07: transport quebrado / exceção ----
MercadoPagoClient::$transport = function () { throw new RuntimeException('boom'); };
$res = (new MercadoPagoClient())->getPreapproval('abc');
mp_client_assert(!$res['ok'] && $res['error'] === 'connection_error', 'C07 exceção do transporte -> connection_error');
MercadoPagoClient::$transport = fn() => 'nao-array';
$res = (new MercadoPagoClient())->getPreapproval('abc');
mp_client_assert(!$res['ok'] && $res['error'] === 'connection_error', 'C07b retorno inválido -> connection_error');

// ---- C08: token NUNCA vaza no resultado/erro ----
MercadoPagoClient::$transport = fn() => ['ok' => false, 'http' => 401, 'data' => [], 'error' => 'http_401'];
$res = (new MercadoPagoClient())->getPreapproval('abc');
mp_client_assert(strpos(json_encode($res), 'TEST-ACCESS-TOKEN-FAKE') === false, 'C08 token fora do retorno de erro');

// ---- C09: sem curl_close (compat PHP 8.5) + sem output ----
$src = (string)file_get_contents($ROOT . '/src/services/MercadoPagoClient.php');
mp_client_assert(strpos($src, 'curl_close') === false, 'C09 sem curl_close (PHP 8.5 compat)');
mp_client_assert(strpos($src, 'api.mercadopago.com') !== false, 'C09b base oficial presente');
mp_client_assert(preg_match('/BASE_URL\s*=\s*\'https:\/\/api\.mercadopago\.com\'/', $src) === 1, 'C09c BASE_URL exata oficial');

// ---- C10: HTTP 400 preserva erro sanitizado (diagnóstico, sem segredos) ----
$parse = new ReflectionMethod(MercadoPagoClient::class, 'parseResponse');
$parse->setAccessible(true);
$body400 = json_encode([
    'message' => 'Invalid payer_email for this collector',
    'error' => 'bad_request',
    'status' => 400,
    'access_token' => 'APP_USR-TRAP-SECRET-999',
    'cause' => [['code' => 'PA001', 'description' => 'payer must differ from collector']],
]);
$res400 = $parse->invoke(null, 400, (string)$body400, 'POST', 'https://api.mercadopago.com/preapproval');
mp_client_assert(!$res400['ok'] && $res400['error'] === 'http_400' && $res400['http'] === 400, 'C10 código http_400 preservado');
mp_client_assert(strpos((string)($res400['detail'] ?? ''), 'payer_email') !== false
    && strpos((string)($res400['detail'] ?? ''), 'PA001') !== false, 'C10b detalhe com message/cause');
mp_client_assert(strpos((string)($res400['detail'] ?? ''), 'TRAP-SECRET') === false
    && strpos(json_encode($res400), 'TRAP-SECRET') === false, 'C10c segredo redactado do detalhe');
$resTxt = $parse->invoke(null, 500, 'Internal Server Error', 'GET', 'https://api.mercadopago.com/x');
mp_client_assert($resTxt['error'] === 'http_5xx' && ($resTxt['detail'] ?? '') !== '', 'C10d body não-JSON preservado truncado');
mp_client_assert(MercadoPagoClient::sanitizedErrorDetail('') === '', 'C10e body vazio sem detalhe');

MercadoPagoClient::$transport = null;
putenv('MERCADOPAGO_ACCESS_TOKEN'); unset($_ENV['MERCADOPAGO_ACCESS_TOKEN']);

echo "\n=== RESUMO CLIENT ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

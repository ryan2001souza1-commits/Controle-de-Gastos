<?php
/**
 * Testes — checkout hospedado do plano Mercado Pago (SEM rede, SEM credenciais).
 *
 * Novo fluxo: o site NAO faz POST /preapproval (a API atual exige
 * card_token_id e o site nao possui CardForm). O backend consulta o plano
 * via GET /preapproval_plan/{PLAN_ID} e redireciona para o init_point
 * oficial retornado.
 *
 * Cobre:
 * - pro seleciona MERCADOPAGO_PLAN_ID_PRO
 * - premium seleciona MERCADOPAGO_PLAN_ID_PREMIUM
 * - GET do plano feito corretamente (metodo, URL, Authorization)
 * - plano inexistente falha (HTTP nao-2xx)
 * - plano inactive/cancelled falha
 * - id divergente falha
 * - JSON invalido falha
 * - init_point ausente falha
 * - URL sem HTTPS falha
 * - host externo/arbitrario falha
 * - Access Token nao vaza (mensagens, redirects, view)
 * - GET do navegador nao inicia acao
 * - POST sem CSRF falha
 * - POST com CSRF invalido falha
 * - usuario nao autenticado falha
 * - plano invalido falha
 * - Access Token ausente falha
 * - timeout tratado
 * - retorno nao ativa plano
 * - nenhuma escrita no banco
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoCheckoutStarter.php';
require_once $ROOT . '/src/services/CsrfService.php';
require_once $ROOT . '/src/controllers/SubscribeController.php';

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }
}

$passed = 0;
$failed = 0;
function mp_start_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } else {
        echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n";
        $failed++;
    }
}

function mp_backup_env(array $keys): array
{
    $bak = [];
    foreach ($keys as $k) {
        $bak[$k] = ['getenv' => getenv($k), 'env' => $_ENV[$k] ?? null, 'server' => $_SERVER[$k] ?? null];
    }
    return $bak;
}
function mp_set_env(string $k, ?string $v): void
{
    if ($v === null) {
        putenv($k);
        unset($_ENV[$k], $_SERVER[$k]);
    } else {
        putenv("$k=$v");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}
function mp_restore_env(array $bak): void
{
    foreach ($bak as $k => $v) {
        if ($v['getenv'] === false) {
            putenv($k);
        } else {
            putenv("$k=" . $v['getenv']);
        }
        if ($v['env'] === null) {
            unset($_ENV[$k]);
        } else {
            $_ENV[$k] = $v['env'];
        }
        if ($v['server'] === null) {
            unset($_SERVER[$k]);
        } else {
            $_SERVER[$k] = $v['server'];
        }
    }
}

class MockPDOMpCheckout extends PDO
{
    public function __construct()
    {
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new MockStmtMpCheckout();
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new MockStmtMpCheckout();
    }
}
class MockStmtMpCheckout extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }
}

const MP_TEST_CSRF = 'TEST_CSRF_TOKEN_123';

function mp_valid_session(): array
{
    return ['user_id' => 5, 'user_email' => 'user@teste.com', 'csrf_token' => MP_TEST_CSRF, 'csrf_user_id' => 5];
}

/**
 * Executa o controller com metodo/POST controlados; captura redirect.
 */
function mp_run_controller(array $session, array $post, string $method, MercadoPagoCheckoutStarter $starter, array $get = ['action' => 'subscribe']): ?string
{
    $_SESSION = $session;
    $_POST = $post;
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = $method;
    $captured = null;
    $ctrl = new SubscribeController(new MockPDOMpCheckout(), $starter, function (string $url) use (&$captured): void {
        $captured = $url;
        throw new RuntimeException('redirect:' . $url);
    });
    try {
        $ctrl->start();
    } catch (RuntimeException $e) {
    }
    return $captured;
}

function mp_plan_body(string $id, string $status = 'active', string $initPoint = 'https://www.mercadopago.com/checkout/PLAN123'): string
{
    return json_encode(['id' => $id, 'status' => $status, 'init_point' => $initPoint]);
}

/** Starter que captura a chamada HTTP e responde com o plano informado. */
function mp_plan_starter(?array &$capturedCall, string $planId, string $status = 'active', string $initPoint = 'https://www.mercadopago.com/checkout/PLAN123', int $httpCode = 200): MercadoPagoCheckoutStarter
{
    return new MercadoPagoCheckoutStarter(function (string $method, string $url, array $headers) use (&$capturedCall, $planId, $status, $initPoint, $httpCode) {
        $capturedCall = ['method' => $method, 'url' => $url, 'headers' => $headers];
        return ['http_code' => $httpCode, 'body' => mp_plan_body($planId, $status, $initPoint), 'error' => ''];
    });
}

$ENV_KEYS = ['MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_PLAN_ID_PRO', 'MERCADOPAGO_PLAN_ID_PREMIUM'];

echo "\n=== Checkout hospedado do plano MP ===\n\n";

// ------------------------------------------------------------------
// P01: pro/premium selecionam o plan ID correto
// ------------------------------------------------------------------
echo "--- P01: selecao do plan ID ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'TEST_TOKEN_FAKE');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');
mp_start_assert(MercadoPagoCheckoutStarter::getPlanId('pro') === 'PLAN_PRO_123', 'P01a pro usa MERCADOPAGO_PLAN_ID_PRO');
mp_start_assert(MercadoPagoCheckoutStarter::getPlanId('premium') === 'PLAN_PREM_456', 'P01b premium usa MERCADOPAGO_PLAN_ID_PREMIUM');
mp_start_assert(MercadoPagoCheckoutStarter::getPlanId('gold') === '', 'P01c plano desconhecido retorna vazio');
mp_start_assert(MercadoPagoCheckoutStarter::buildPlanUrl('PLAN_PRO_123') === 'https://api.mercadopago.com/preapproval_plan/PLAN_PRO_123', 'P01d URL do plano montada corretamente');

// ------------------------------------------------------------------
// P02: GET do plano feito corretamente
// ------------------------------------------------------------------
echo "\n--- P02: requisicao GET ao plano ---\n";
$call = null;
$starter = mp_plan_starter($call, 'PLAN_PRO_123');
$url = $starter->resolveCheckoutUrl(5, 'pro');
mp_start_assert($call !== null, 'P02a chamada HTTP realizada');
mp_start_assert(($call['method'] ?? '') === 'GET', 'P02b metodo GET', json_encode($call));
mp_start_assert(($call['url'] ?? '') === 'https://api.mercadopago.com/preapproval_plan/PLAN_PRO_123', 'P02c URL = preapproval_plan/{PLAN_ID_PRO}', json_encode($call));
$hasAuth = false;
foreach ($call['headers'] ?? [] as $h) {
    if (stripos($h, 'Authorization: Bearer ') === 0 && !str_contains($h, 'TEST_TOKEN_FAKE')) {
        $hasAuth = true;
    }
}
mp_start_assert($hasAuth, 'P02d header Authorization presente (token real nao exposto ao mock)');
mp_start_assert($url === 'https://www.mercadopago.com/checkout/PLAN123', 'P02e retorna init_point oficial');

$call = null;
$starter = mp_plan_starter($call, 'PLAN_PREM_456', 'active', 'https://www.mercadopago.com.br/checkout/PREM');
$starter->resolveCheckoutUrl(5, 'premium');
mp_start_assert(($call['url'] ?? '') === 'https://api.mercadopago.com/preapproval_plan/PLAN_PREM_456', 'P02f premium consulta PLAN_ID_PREMIUM', json_encode($call));

// POST /preapproval removido: starter nao faz POST nem envia card_token
$svcSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoCheckoutStarter.php');
mp_start_assert(strpos($svcSrc, 'CURLOPT_POSTFIELDS') === false, 'P02g sem POSTFIELDS (sem POST /preapproval)');
mp_start_assert(strpos($svcSrc, "'POST'") === false && strpos($svcSrc, '"POST"') === false, 'P02h sem metodo POST no starter');
$svcCodeLines = '';
foreach (explode("\n", $svcSrc) as $line) {
    $t = ltrim($line);
    if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '/*')) {
        continue;
    }
    $svcCodeLines .= $line . "\n";
}
mp_start_assert(stripos($svcCodeLines, 'card_token') === false, 'P02i sem card_token no codigo (fora comentarios)');
mp_start_assert(strpos($svcSrc, 'payer_email') === false, 'P02j sem payer_email (fluxo sem preapproval)');
mp_restore_env($bak);

// ------------------------------------------------------------------
// P03: plano inexistente / inativo / id divergente falham
// ------------------------------------------------------------------
echo "\n--- P03: validacao da resposta do plano ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'FAKE_TOKEN_ABC123');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');

function mp_expect_fail(string $name, $httpRes, string $expectReason): void
{
    $starter = new MercadoPagoCheckoutStarter(function () use ($httpRes) {
        if (is_callable($httpRes)) {
            return $httpRes();
        }
        return $httpRes;
    });
    $threw = false;
    $reason = '';
    $msg = '';
    try {
        $starter->resolveCheckoutUrl(5, 'pro');
    } catch (MpCheckoutException $e) {
        $threw = true;
        $reason = $e->reason;
        $msg = $e->getMessage();
    }
    mp_start_assert($threw, "$name lanca excecao");
    mp_start_assert($reason === $expectReason, "$name reason=$expectReason (veio $reason)");
    mp_start_assert(!str_contains($msg, 'FAKE_TOKEN_ABC123'), "$name mensagem sem token");
    mp_start_assert(!str_contains($msg, 'PLAN_PRO_123'), "$name mensagem sem plan ID");
}

mp_expect_fail('P03a plano inexistente (404)', ['http_code' => 404, 'body' => '{"message":"not found"}', 'error' => ''], 'http_error');
mp_expect_fail('P03b plano inactive', ['http_code' => 200, 'body' => mp_plan_body('PLAN_PRO_123', 'inactive'), 'error' => ''], 'plan_inactive');
mp_expect_fail('P03c plano cancelled', ['http_code' => 200, 'body' => mp_plan_body('PLAN_PRO_123', 'cancelled'), 'error' => ''], 'plan_inactive');
mp_expect_fail('P03d id divergente', ['http_code' => 200, 'body' => mp_plan_body('OUTRO_PLAN_999', 'active'), 'error' => ''], 'plan_mismatch');
mp_expect_fail('P03e sem id', ['http_code' => 200, 'body' => '{"status":"active","init_point":"https://www.mercadopago.com/x"}', 'error' => ''], 'plan_mismatch');
mp_expect_fail('P03f JSON invalido', ['http_code' => 200, 'body' => 'NAO-JSON{{{', 'error' => ''], 'invalid_json');
mp_expect_fail('P03g corpo vazio', ['http_code' => 200, 'body' => '', 'error' => ''], 'invalid_response');
mp_expect_fail('P03h init_point ausente', ['http_code' => 200, 'body' => '{"id":"PLAN_PRO_123","status":"active"}', 'error' => ''], 'missing_checkout_url');
mp_expect_fail('P03i URL sem HTTPS', ['http_code' => 200, 'body' => mp_plan_body('PLAN_PRO_123', 'active', 'http://www.mercadopago.com/x'), 'error' => ''], 'missing_checkout_url');
mp_expect_fail('P03j host arbitrario', ['http_code' => 200, 'body' => mp_plan_body('PLAN_PRO_123', 'active', 'https://evil.com/roubo'), 'error' => ''], 'missing_checkout_url');
mp_expect_fail('P03k HTTP 500', ['http_code' => 500, 'body' => '{"message":"err"}', 'error' => ''], 'http_error');
mp_expect_fail('P03l timeout', ['http_code' => 0, 'body' => '', 'error' => 'Operation timed out'], 'timeout');
mp_expect_fail('P03m indisponivel', ['http_code' => 0, 'body' => '', 'error' => 'Could not resolve host'], 'api_unavailable');

// token ausente / plan ID ausente
$bak2 = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', null);
$threw = false;
try {
    (new MercadoPagoCheckoutStarter())->resolveCheckoutUrl(5, 'pro');
} catch (MpCheckoutException $e) {
    $threw = $e->reason === 'missing_token';
}
mp_start_assert($threw, 'P03n token ausente -> missing_token');
mp_restore_env($bak2);
mp_restore_env($bak);

// ------------------------------------------------------------------
// P04: host oficial (unitario)
// ------------------------------------------------------------------
echo "\n--- P04: host oficial ---\n";
mp_start_assert(MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://www.mercadopago.com/checkout/ABC'), 'P04a www.mercadopago.com ok');
mp_start_assert(MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://www.mercadopago.com.br/subscriptions/checkout?x=1'), 'P04b .com.br ok');
mp_start_assert(MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://sandbox.mercadopago.com/checkout/X'), 'P04c sandbox subdomain ok');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('http://www.mercadopago.com/x'), 'P04d http rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://evil.com/mercadopago'), 'P04e dominio arbitrario rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://mercadopago.evil.com/x'), 'P04f lookalike rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('javascript:alert(1)'), 'P04g javascript rejeitado');

// ------------------------------------------------------------------
// P05: controller — GET nao inicia, CSRF, auth, plano invalido
// ------------------------------------------------------------------
echo "\n--- P05: controller ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'TEST_TOKEN_FAKE');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');

$apiCalled = false;
$starter = new MercadoPagoCheckoutStarter(function () use (&$apiCalled) {
    $apiCalled = true;
    return ['http_code' => 200, 'body' => mp_plan_body('PLAN_PRO_123'), 'error' => ''];
});
$url = mp_run_controller(mp_valid_session(), [], 'GET', $starter, ['action' => 'subscribe', 'plan' => 'pro']);
mp_start_assert($apiCalled === false, 'P05a GET do navegador NAO chama API MP');
mp_start_assert($url !== null && str_contains($url, 'invalid_method'), 'P05b GET redireciona invalid_method', (string)$url);

$apiCalled = false;
$url = mp_run_controller(mp_valid_session(), ['plan' => 'pro'], 'POST', $starter);
mp_start_assert($apiCalled === false, 'P05c POST sem CSRF NAO chama API');
mp_start_assert($url !== null && str_contains($url, 'invalid_csrf'), 'P05d POST sem CSRF -> invalid_csrf', (string)$url);

$apiCalled = false;
$url = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => 'ERRADO'], 'POST', $starter);
mp_start_assert($apiCalled === false, 'P05e POST com CSRF invalido NAO chama API');
mp_start_assert($url !== null && str_contains($url, 'invalid_csrf'), 'P05f CSRF invalido -> invalid_csrf', (string)$url);

$apiCalled = false;
$url = mp_run_controller([], ['plan' => 'pro', 'csrf_token' => 'x'], 'POST', $starter);
mp_start_assert($apiCalled === false, 'P05g nao autenticado NAO chama API');
mp_start_assert($url !== null && str_contains($url, 'action=login'), 'P05h nao autenticado -> login', (string)$url);

$apiCalled = false;
$url = mp_run_controller(mp_valid_session(), ['plan' => 'gold', 'csrf_token' => MP_TEST_CSRF], 'POST', $starter);
mp_start_assert($apiCalled === false, 'P05i plano invalido NAO chama API');
mp_start_assert($url !== null && str_contains($url, 'invalid_plan'), 'P05j plano invalido -> invalid_plan', (string)$url);

// POST valido resolve e redireciona para o checkout hospedado
$call = null;
$starter = mp_plan_starter($call, 'PLAN_PRO_123');
$url = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => MP_TEST_CSRF], 'POST', $starter);
mp_start_assert($url === 'https://www.mercadopago.com/checkout/PLAN123', 'P05k POST valido redireciona para init_point', (string)$url);
mp_start_assert(($call['url'] ?? '') === 'https://api.mercadopago.com/preapproval_plan/PLAN_PRO_123', 'P05l POST valido consulta plano pro');

// erro da API vira redirect generico sem vazar segredo
$starter500 = new MercadoPagoCheckoutStarter(function () {
    return ['http_code' => 404, 'body' => '{"message":"not found"}', 'error' => ''];
});
$url = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => MP_TEST_CSRF], 'POST', $starter500);
mp_start_assert($url !== null && str_contains($url, 'checkout_unavailable'), 'P05m plano inexistente -> checkout_unavailable', (string)$url);
mp_start_assert(!str_contains((string)$url, 'TEST_TOKEN_FAKE'), 'P05n redirect sem token');
mp_restore_env($bak);

// ------------------------------------------------------------------
// P06: seguranca, view, banco, retorno
// ------------------------------------------------------------------
echo "\n--- P06: seguranca + view + banco ---\n";
$svcSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoCheckoutStarter.php');
$ctrlSrc = (string)@file_get_contents($ROOT . '/src/controllers/SubscribeController.php');
$viewSrc = (string)@file_get_contents($ROOT . '/public/meu_plano.php');
$routerSrc = (string)@file_get_contents($ROOT . '/public/index.php');

mp_start_assert(strpos($viewSrc, 'MERCADOPAGO_ACCESS_TOKEN') === false, 'P06a view sem token');
mp_start_assert(strpos($viewSrc, 'MERCADOPAGO_PLAN_ID') === false, 'P06b view sem plan ID');
mp_start_assert(strpos($svcSrc, 'MERCADOPAGO_PUBLIC_KEY') === false, 'P06c sem Public Key');
mp_start_assert(preg_match('/sdk\.mercadopago\.com/i', $svcSrc . $ctrlSrc . $viewSrc) !== 1, 'P06d sem SDK MP.js');
mp_start_assert(preg_match('/CardForm\s*\(|createCardToken/i', $svcSrc . $ctrlSrc . $viewSrc) !== 1, 'P06e sem CardForm');
mp_start_assert(preg_match('/<form[^>]*method="POST"[^>]*action="\/index\.php\?action=subscribe"/i', $viewSrc) === 1, 'P06f view usa form POST p/ subscribe');
mp_start_assert(strpos($viewSrc, 'csrf_field()') !== false, 'P06g view inclui csrf_field()');
mp_start_assert(strpos($viewSrc, 'name="plan"') !== false, 'P06h view envia somente slug do plano');
mp_start_assert(strpos($viewSrc, 'name="user_id"') === false, 'P06i view NAO envia user_id');
mp_start_assert(strpos($viewSrc, 'action=subscribe&amp;plan=') === false && strpos($viewSrc, 'action=subscribe&plan=') === false, 'P06j view sem link GET criador');
mp_start_assert(strpos($routerSrc, "'subscribe'") !== false, 'P06k rota subscribe existe');
mp_start_assert(strpos($svcSrc, 'UPDATE usuarios') === false && strpos($svcSrc, 'INSERT INTO subscriptions') === false, 'P06l starter sem escrita');
mp_start_assert(strpos($ctrlSrc, 'UPDATE') === false && strpos($ctrlSrc, 'INSERT INTO') === false && strpos($ctrlSrc, 'DELETE FROM') === false, 'P06m controller sem INSERT/UPDATE/DELETE');
mp_start_assert(stripos($svcSrc . $ctrlSrc, 'CREATE TABLE') === false, 'P06n sem DDL/migration');
$profileSrc = (string)@file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$fn = '';
if (preg_match('/function meuPlano\(\).*?^    \}/ms', $profileSrc, $m)) {
    $fn = $m[0];
}
mp_start_assert(stripos($fn, 'UPDATE usuarios SET plano') === false, 'P06o retorno NAO ativa plano');
mp_start_assert(strpos($svcSrc, '9.90') === false && strpos($ctrlSrc, '19.90') === false, 'P06p sem alterar precos');

echo "\n=== RESUMO CHECKOUT HOSPEDADO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

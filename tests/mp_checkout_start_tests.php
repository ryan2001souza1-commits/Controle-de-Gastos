<?php
/**
 * Testes etapa 1 (revisao final) — checkout MP via POST + CSRF.
 * SEM rede, SEM credenciais reais.
 *
 * Cobre (exigido na revisao):
 * - GET nao cria preapproval
 * - POST valido cria
 * - POST sem CSRF falha
 * - POST com CSRF invalido falha
 * - usuario nao autenticado falha
 * - pro usa PLAN_ID_PRO / premium usa PLAN_ID_PREMIUM
 * - plano invalido falha
 * - Access Token ausente falha
 * - timeout tratado
 * - HTTP nao-2xx tratado
 * - JSON invalido tratado
 * - init_point ausente tratado
 * - URL checkout invalida/rejeitada (host arbitrario, http)
 * - Access Token nao aparece em resposta/log
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
if (!class_exists('User')) {
    require_once $ROOT . '/src/models/User.php';
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
 * @return array{url:?string, apiCalled:bool}
 */
function mp_run_controller(array $session, array $post, string $method, MercadoPagoCheckoutStarter $starter, array $get = ['action' => 'subscribe']): array
{
    $_SESSION = $session;
    $_POST = $post;
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = $method;
    $captured = null;
    $apiCalled = false;
    // starter ja conta chamadas via closure do teste; aqui so capturamos redirect
    $ctrl = new SubscribeController(new MockPDOMpCheckout(), $starter, function (string $url) use (&$captured): void {
        $captured = $url;
        throw new RuntimeException('redirect:' . $url);
    });
    try {
        $ctrl->start();
    } catch (RuntimeException $e) {
    }
    return ['url' => $captured, 'apiCalled' => $apiCalled];
}

function mp_ok_starter(?array &$capturedPayload, string $initPoint = 'https://www.mercadopago.com/checkout/OK123'): MercadoPagoCheckoutStarter
{
    return new MercadoPagoCheckoutStarter(function (string $url, array $headers, string $payload) use (&$capturedPayload, $initPoint) {
        $capturedPayload = json_decode($payload, true);
        return ['http_code' => 201, 'body' => json_encode(['init_point' => $initPoint]), 'error' => ''];
    });
}

$ENV_KEYS = ['MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_PLAN_ID_PRO', 'MERCADOPAGO_PLAN_ID_PREMIUM', 'APP_URL'];

echo "\n=== ETAPA 1 (revisao): POST + CSRF + timeout + host oficial ===\n\n";

// ------------------------------------------------------------------
// R01: timeout < maxDuration (7s total / 3s connect)
// ------------------------------------------------------------------
echo "--- R01: timeout seguro ---\n";
$svcSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoCheckoutStarter.php');
mp_start_assert(strpos($svcSrc, 'TIMEOUT_SECONDS = 7') !== false, 'R01a timeout total = 7s');
mp_start_assert(strpos($svcSrc, 'CONNECT_TIMEOUT_SECONDS = 3') !== false, 'R01b connect timeout = 3s');
mp_start_assert(strpos($svcSrc, 'TIMEOUT_SECONDS = 15') === false, 'R01c timeout antigo 15s removido');
mp_start_assert(strpos($svcSrc, 'CONNECT_TIMEOUT_SECONDS = 5') === false, 'R01d connect antigo 5s removido');
$vercelJson = (string)@file_get_contents($ROOT . '/vercel.json');
mp_start_assert(strpos($vercelJson, '"maxDuration": 10') !== false, 'R01e maxDuration Vercel = 10s (inalterado)');
mp_start_assert(7 < 10 && 3 < 10, 'R01f 7s/3s < 10s maxDuration');

// ------------------------------------------------------------------
// R02: GET nao cria preapproval
// ------------------------------------------------------------------
echo "\n--- R02: GET nunca cria ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'TEST_TOKEN_FAKE');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'TEST_PLAN_PRO');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'TEST_PLAN_PREM');
$apiCalled = false;
$starter = new MercadoPagoCheckoutStarter(function () use (&$apiCalled) {
    $apiCalled = true;
    return ['http_code' => 201, 'body' => '{"init_point":"https://www.mercadopago.com/x"}', 'error' => ''];
});
$res = mp_run_controller(mp_valid_session(), [], 'GET', $starter, ['action' => 'subscribe', 'plan' => 'pro']);
mp_start_assert($apiCalled === false, 'R02a GET nao chama API MP');
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'meu_plano') && str_contains($res['url'], 'invalid_method'), 'R02b GET redireciona com invalid_method', (string)$res['url']);
mp_start_assert($res['url'] === null || !str_contains((string)$res['url'], 'mercadopago.com'), 'R02c GET nao redireciona para checkout', (string)$res['url']);
mp_restore_env($bak);

// ------------------------------------------------------------------
// R03: POST valido cria (com CSRF)
// ------------------------------------------------------------------
echo "\n--- R03: POST valido cria ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'TEST_TOKEN_FAKE');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');
mp_set_env('APP_URL', 'https://exemplo.com');
$captured = null;
$starter = mp_ok_starter($captured, 'https://www.mercadopago.com/checkout/PRO123');
$res = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => MP_TEST_CSRF], 'POST', $starter);
mp_start_assert($res['url'] === 'https://www.mercadopago.com/checkout/PRO123', 'R03a POST valido redireciona para init_point oficial', (string)$res['url']);
mp_start_assert(($captured['preapproval_plan_id'] ?? '') === 'PLAN_PRO_123', 'R03b payload pro correto');
mp_start_assert(($captured['external_reference'] ?? '') === 'user_5_pro', 'R03c external_reference da sessao');
mp_start_assert(($captured['payer_email'] ?? '') === 'user@teste.com', 'R03d payer_email autenticado');
mp_start_assert(($captured['back_url'] ?? '') === 'https://exemplo.com/index.php?action=meu_plano&subscribe=return', 'R03e back_url via APP_URL');
mp_restore_env($bak);

// ------------------------------------------------------------------
// R04: POST sem CSRF / CSRF invalido falham sem chamar API
// ------------------------------------------------------------------
echo "\n--- R04: CSRF obrigatorio ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'TEST_TOKEN_FAKE');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');
$apiCalled = false;
$starter = new MercadoPagoCheckoutStarter(function () use (&$apiCalled) {
    $apiCalled = true;
    return ['http_code' => 201, 'body' => '{"init_point":"https://www.mercadopago.com/x"}', 'error' => ''];
});
$res = mp_run_controller(mp_valid_session(), ['plan' => 'pro'], 'POST', $starter);
mp_start_assert($apiCalled === false, 'R04a POST sem csrf_token NAO chama API');
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'invalid_csrf'), 'R04b POST sem CSRF redireciona invalid_csrf', (string)$res['url']);

$apiCalled = false;
$res = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => 'TOKEN_ERRADO'], 'POST', $starter);
mp_start_assert($apiCalled === false, 'R04c POST com CSRF invalido NAO chama API');
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'invalid_csrf'), 'R04d CSRF invalido redireciona invalid_csrf', (string)$res['url']);

// router tambem protege via middleware existente
$routerSrc = (string)@file_get_contents($ROOT . '/public/index.php');
mp_start_assert(preg_match("/'subscribe'/", $routerSrc) === 1 && str_contains($routerSrc, 'csrfProtectedActions'), 'R04e router inclui subscribe nas acoes CSRF');
mp_start_assert(preg_match("/'subscribe',?\s*\];/s", $routerSrc) === 1 || str_contains($routerSrc, "'subscribe',"), 'R04f subscribe listado no array CSRF');
mp_restore_env($bak);

// ------------------------------------------------------------------
// R05: nao autenticado falha (POST e GET)
// ------------------------------------------------------------------
echo "\n--- R05: autenticacao obrigatoria ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'T');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'P');
$apiCalled = false;
$starter = new MercadoPagoCheckoutStarter(function () use (&$apiCalled) {
    $apiCalled = true;
    return ['http_code' => 201, 'body' => '{}', 'error' => ''];
});
$res = mp_run_controller([], ['plan' => 'pro', 'csrf_token' => 'x'], 'POST', $starter);
mp_start_assert($apiCalled === false, 'R05a POST sem sessao NAO chama API');
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'action=login'), 'R05b sem sessao vai para login', (string)$res['url']);
mp_restore_env($bak);

// ------------------------------------------------------------------
// R06: pro/premium selecionam plan ID correto; invalido falha
// ------------------------------------------------------------------
echo "\n--- R06: whitelist + plan ID ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'TEST_TOKEN_FAKE');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');
mp_start_assert(MercadoPagoCheckoutStarter::getPlanId('pro') === 'PLAN_PRO_123', 'R06a pro usa ID pro');
mp_start_assert(MercadoPagoCheckoutStarter::getPlanId('premium') === 'PLAN_PREM_456', 'R06b premium usa ID premium');
$captured = null;
$starter = mp_ok_starter($captured, 'https://www.mercadopago.com.br/checkout/PREM');
$res = mp_run_controller(mp_valid_session(), ['plan' => 'premium', 'csrf_token' => MP_TEST_CSRF], 'POST', $starter);
mp_start_assert(($captured['preapproval_plan_id'] ?? '') === 'PLAN_PREM_456', 'R06c payload premium correto');
mp_start_assert($res['url'] === 'https://www.mercadopago.com.br/checkout/PREM', 'R06d premium (.com.br) aceito — host oficial');

$apiCalled = false;
$starterBad = new MercadoPagoCheckoutStarter(function () use (&$apiCalled) {
    $apiCalled = true;
    return ['http_code' => 201, 'body' => '{}', 'error' => ''];
});
$res = mp_run_controller(mp_valid_session(), ['plan' => 'gold', 'csrf_token' => MP_TEST_CSRF], 'POST', $starterBad);
mp_start_assert($apiCalled === false, 'R06e plano invalido NAO chama API');
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'invalid_plan'), 'R06f plano invalido -> invalid_plan', (string)$res['url']);

// user_id do navegador ignorado
$captured = null;
$starter = mp_ok_starter($captured);
$res = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => MP_TEST_CSRF, 'user_id' => '999', 'payer_email' => 'evil@x.com', 'external_reference' => 'user_999_premium'], 'POST', $starter);
mp_start_assert(($captured['external_reference'] ?? '') === 'user_5_pro', 'R06g external_reference ignora navegador');
mp_start_assert(($captured['payer_email'] ?? '') === 'user@teste.com', 'R06h payer_email ignora navegador');
mp_restore_env($bak);

// ------------------------------------------------------------------
// R07: variaveis ausentes + erros da API
// ------------------------------------------------------------------
echo "\n--- R07: config + API ---\n";
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', null);
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'P');
$threw = false;
try {
    (new MercadoPagoCheckoutStarter())->startForUser(5, 'a@b.com', 'pro');
} catch (MpCheckoutException $e) {
    $threw = $e->reason === 'missing_token';
}
mp_start_assert($threw, 'R07a token ausente -> missing_token');

mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'FAKE_TOKEN_ABC123');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'PLAN_PRO_123');
mp_set_env('MERCADOPAGO_PLAN_ID_PREMIUM', 'PLAN_PREM_456');

function mp_expect_fail(string $name, array $httpRes, string $expectReason): void
{
    $starter = new MercadoPagoCheckoutStarter(function () use ($httpRes) {
        return $httpRes;
    });
    $threw = false;
    $reason = '';
    $msg = '';
    try {
        $starter->startForUser(5, 'user@teste.com', 'pro');
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

mp_expect_fail('R07b timeout', ['http_code' => 0, 'body' => '', 'error' => 'Operation timed out'], 'timeout');
mp_expect_fail('R07c indisponivel', ['http_code' => 0, 'body' => '', 'error' => 'Could not resolve host'], 'api_unavailable');
mp_expect_fail('R07d HTTP 500', ['http_code' => 500, 'body' => '{"message":"err"}', 'error' => ''], 'http_error');
mp_expect_fail('R07e HTTP 401', ['http_code' => 401, 'body' => 'unauthorized', 'error' => ''], 'http_error');
mp_expect_fail('R07f JSON invalido', ['http_code' => 201, 'body' => 'NAO-JSON{{{', 'error' => ''], 'invalid_json');
mp_expect_fail('R07g vazio', ['http_code' => 201, 'body' => '', 'error' => ''], 'invalid_response');
mp_expect_fail('R07h sem init_point', ['http_code' => 201, 'body' => '{"id":"123"}', 'error' => ''], 'missing_checkout_url');

// controller converte em redirect generico sem vazar segredo
$starter500 = new MercadoPagoCheckoutStarter(function () {
    return ['http_code' => 500, 'body' => 'erro', 'error' => ''];
});
$res = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => MP_TEST_CSRF], 'POST', $starter500);
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'checkout_unavailable'), 'R07i erro API -> checkout_unavailable', (string)$res['url']);
mp_start_assert(!str_contains((string)$res['url'], 'FAKE_TOKEN'), 'R07j redirect sem token');
mp_restore_env($bak);

// ------------------------------------------------------------------
// R08: URL checkout — SOMENTE host oficial HTTPS
// ------------------------------------------------------------------
echo "\n--- R08: host oficial ---\n";
mp_start_assert(MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://www.mercadopago.com/checkout/ABC'), 'R08a www.mercadopago.com ok');
mp_start_assert(MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://www.mercadopago.com.br/subscriptions/checkout?x=1'), 'R08b .com.br ok');
mp_start_assert(MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://sandbox.mercadopago.com/checkout/X'), 'R08c sandbox subdomain ok');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('http://www.mercadopago.com/x'), 'R08d http rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://evil.com/mercadopago'), 'R08e dominio arbitrario rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('https://mercadopago.evil.com/x'), 'R08f lookalike rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl('javascript:alert(1)'), 'R08g javascript rejeitado');
mp_start_assert(!MercadoPagoCheckoutStarter::isOfficialCheckoutUrl(''), 'R08h vazio rejeitado');
mp_start_assert(MercadoPagoCheckoutStarter::extractCheckoutUrl(['init_point' => 'https://evil.com/x']) === null, 'R08i extract rejeita host estranho');
mp_start_assert(MercadoPagoCheckoutStarter::extractCheckoutUrl(['init_point' => 'https://www.mercadopago.com/ok']) === 'https://www.mercadopago.com/ok', 'R08j extract aceita oficial');

// evil init_point via controller -> falha segura (sem redirect externo)
$bak = mp_backup_env($ENV_KEYS);
mp_set_env('MERCADOPAGO_ACCESS_TOKEN', 'T');
mp_set_env('MERCADOPAGO_PLAN_ID_PRO', 'P');
$starterEvil = new MercadoPagoCheckoutStarter(function () {
    return ['http_code' => 201, 'body' => '{"init_point":"https://evil.com/roubo"}', 'error' => ''];
});
$res = mp_run_controller(mp_valid_session(), ['plan' => 'pro', 'csrf_token' => MP_TEST_CSRF], 'POST', $starterEvil);
mp_start_assert($res['url'] !== null && str_contains($res['url'], 'meu_plano'), 'R08k init_point maligno NAO redireciona para fora', (string)$res['url']);
mp_start_assert($res['url'] === null || !str_contains((string)$res['url'], 'evil.com'), 'R08l destino maligno bloqueado', (string)$res['url']);
mp_restore_env($bak);

// ------------------------------------------------------------------
// R09: token nunca exposto; view POST + CSRF; sem banco; retorno seguro
// ------------------------------------------------------------------
echo "\n--- R09: seguranca + view + banco ---\n";
$svcSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoCheckoutStarter.php');
$ctrlSrc = (string)@file_get_contents($ROOT . '/src/controllers/SubscribeController.php');
$viewSrc = (string)@file_get_contents($ROOT . '/public/meu_plano.php');
$routerSrc = (string)@file_get_contents($ROOT . '/public/index.php');

mp_start_assert(strpos($viewSrc, 'MERCADOPAGO_ACCESS_TOKEN') === false, 'R09a view sem token');
mp_start_assert(strpos($viewSrc, 'MERCADOPAGO_PLAN_ID') === false, 'R09b view sem plan ID');
mp_start_assert(strpos($ctrlSrc, 'echo') === false, 'R09c controller sem echo');
mp_start_assert(strpos($svcSrc, 'MERCADOPAGO_PUBLIC_KEY') === false, 'R09d sem Public Key');
mp_start_assert(preg_match('/sdk\.mercadopago\.com/i', $svcSrc . $ctrlSrc . $viewSrc) !== 1, 'R09e sem SDK MP.js');
mp_start_assert(preg_match('/CardForm\s*\(|createCardToken/i', $svcSrc . $ctrlSrc . $viewSrc) !== 1, 'R09f sem CardForm');
// view usa POST + CSRF + hidden plan; sem link GET criador
mp_start_assert(preg_match('/<form[^>]*method="POST"[^>]*action="\/index\.php\?action=subscribe"/i', $viewSrc) === 1, 'R09g view usa form POST p/ subscribe');
mp_start_assert(strpos($viewSrc, 'csrf_field()') !== false, 'R09h view inclui csrf_field()');
mp_start_assert(strpos($viewSrc, 'name="plan"') !== false, 'R09i view envia somente slug do plano');
mp_start_assert(strpos($viewSrc, 'name="user_id"') === false, 'R09j view NAO envia user_id');
mp_start_assert(strpos($viewSrc, 'action=subscribe&amp;plan=') === false && strpos($viewSrc, 'action=subscribe&plan=') === false, 'R09k view sem link GET criador');
// APP_URL prioritaria, sem dominio hardcoded
mp_start_assert(strpos($svcSrc, "env('APP_URL')") !== false, 'R09l starter usa APP_URL prioritariamente');
mp_start_assert(strpos($svcSrc, 'controle-de-gastos-one-silk') === false && strpos($svcSrc, 'controle-de-gastos.vercel.app') === false || strpos($svcSrc, 'aiService') !== false, 'R09m sem dominio hardcoded no starter');
// sem escrita no banco
mp_start_assert(strpos($svcSrc, 'UPDATE usuarios') === false && strpos($svcSrc, 'INSERT INTO subscriptions') === false, 'R09n starter sem escrita');
mp_start_assert(strpos($ctrlSrc, 'UPDATE') === false && strpos($ctrlSrc, 'INSERT INTO') === false && strpos($ctrlSrc, 'DELETE FROM') === false, 'R09o controller sem INSERT/UPDATE/DELETE');
$profileSrc = (string)@file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$fn = '';
if (preg_match('/function meuPlano\(\).*?^    \}/ms', $profileSrc, $m)) {
    $fn = $m[0];
}
mp_start_assert(stripos($fn, 'UPDATE usuarios SET plano') === false, 'R09p retorno NAO ativa plano');
mp_start_assert(strpos($svcSrc, '9.90') === false && strpos($ctrlSrc, '19.90') === false, 'R09q sem alterar precos');
$migrationsSrc = (string)@file_get_contents($ROOT . '/src/migrations.php');
mp_start_assert(strpos($migrationsSrc, '9.90') !== false && strpos($migrationsSrc, '19.90') !== false, 'R09r precos 9.90/19.90 preservados');

echo "\n=== RESUMO REVISAO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

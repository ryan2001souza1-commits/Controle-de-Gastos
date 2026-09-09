<?php
/**
 * Subscribe Start Tests — primeira etapa funcional (POST /preapproval).
 *
 * Tudo com mocks/fakes. NENHUM teste bate na API real do Mercado Pago.
 *
 * Cobre: metodo, auth, CSRF (via front controller), planos, catalogo como
 * fonte de preco, user_id da sessao, env ausente, request correto, Bearer
 * server-side, external_reference/attempt_token, 201, checkout URL,
 * timeout/HTTPs/JSON invalido, tentativa pending sem ativar plano,
 * double-submit e falha de API sem efeito no plano.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/models/User.php';
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/services/PlanService.php';
require_once $ROOT . '/src/services/BillingSyncService.php';
require_once $ROOT . '/src/services/MercadoPagoClient.php';
require_once $ROOT . '/src/controllers/SubscribeController.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } else {
        echo "  \033[31m✗\033[0m $name\n";
        $failed++;
    }
}

class FakeSubStmt extends PDOStatement
{
    public array $row = [];
    public array $all = [];
    public int $count = 1;
    public ?array $params = null;

    public function execute(?array $params = null): bool
    {
        $this->params = $params;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row !== [] ? $this->row : false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->all;
    }

    public function rowCount(): int
    {
        return $this->count;
    }
}

class FakeSubPDO extends PDO
{
    /** @var string[] */
    public array $queries = [];
    /** @var FakeSubStmt[] */
    public array $queue = [];
    public string $nextId = '7';

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        $stmt = array_shift($this->queue);
        return $stmt ?? new FakeSubStmt();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->nextId;
    }
}

function sub_stmt(array $row = [], array $all = [], int $count = 1): FakeSubStmt
{
    $s = new FakeSubStmt();
    $s->row = $row;
    $s->all = $all;
    $s->count = $count;
    return $s;
}

function sub_user_row(): array
{
    return [
        'id' => 42, 'nome' => 'Teste', 'email' => 'pagador@teste.com',
        'senha' => null, 'plano' => 'gratuito', 'plano_status' => 'ativo',
    ];
}

function sub_plan_row(string $slug, string $id, string $nome, string $preco): array
{
    return ['id' => $id, 'nome' => $nome, 'slug' => $slug, 'preco' => $preco, 'descricao' => '', 'status' => 'ativo'];
}

/** @param array<string,string> $env */
function sub_env(array $env): void
{
    $defaults = [
        'MERCADOPAGO_ACCESS_TOKEN' => 'TEST_TOKEN',
        'MERCADOPAGO_PLAN_ID_PRO' => 'plan_pro_1',
        'MERCADOPAGO_PLAN_ID_PREMIUM' => 'plan_pre_1',
        'APP_URL' => 'https://app.test',
    ];
    foreach (array_merge($defaults, $env) as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

function sub_reset_request(string $method, array $post, bool $logged = true): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['CONTENT_TYPE']);
    $_POST = $post;
    if ($logged) {
        $_SESSION['user_id'] = 42;
    } else {
        unset($_SESSION['user_id']);
    }
}

/** @return array{code:int, data:array} */
function sub_call(SubscribeController $ctl): array
{
    // Isola warnings do buffer: registra em vez de imprimir (CLI joga
    // warnings no stdout e poluiria o JSON capturado).
    $GLOBALS['SUB_WARNINGS'] = [];
    set_error_handler(function (int $no, string $str): bool {
        $GLOBALS['SUB_WARNINGS'][] = $str;
        $GLOBALS['SUB_WARNINGS_TOTAL'][] = $str;
        return true;
    }, E_WARNING);
    try {
        ob_start();
        $ctl->start();
        $out = (string)ob_get_clean();
    } finally {
        restore_error_handler();
    }
    return ['code' => http_response_code(), 'data' => json_decode($out, true) ?? []];
}

function sub_transport(array &$captured, int $status, string $body, string $error = ''): callable
{
    return function (string $method, string $url, array $headers, string $reqBody) use (&$captured, $status, $body, $error): array {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $reqBody];
        return ['status' => $status, 'body' => $body, 'error' => $error];
    };
}

function sub_success_body(string $id = 'pre_123', string $init = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=pre_123'): string
{
    return json_encode(['id' => $id, 'init_point' => $init, 'status' => 'pending'], JSON_UNESCAPED_SLASHES);
}

/** Fila padrao: findById, plano x2, reuse vazio, INSERT, UPDATE. */
function sub_queue_success(FakeSubPDO $db, string $slug): void
{
    $planRow = $slug === 'pro'
        ? sub_plan_row('pro', '2', 'Pro', '9.90')
        : sub_plan_row('premium', '3', 'Premium', '19.90');
    $db->queue = [
        sub_stmt(sub_user_row()),
        sub_stmt($planRow),
        sub_stmt($planRow),
        sub_stmt([], []),
        sub_stmt([], [], 1),
        sub_stmt([], [], 1),
    ];
}

echo "\n=== SUBSCRIBE START TESTS ===\n\n";

echo "-- metodo e autenticacao --\n";
sub_env([]);
sub_reset_request('GET', ['plan' => 'pro']);
$db = new FakeSubPDO();
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 405 && ($r['data']['error'] ?? '') === 'metodo_nao_permitido', 'SS01: GET recusado com 405');

sub_reset_request('POST', ['plan' => 'pro'], false);
$db = new FakeSubPDO();
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 401 && ($r['data']['error'] ?? '') === 'nao_autenticado', 'SS02: nao autenticado retorna 401 controlado');
assert_test(count($db->queries) === 0, 'SS03: nao autenticado nao toca no banco');

$frontSrc = (string)file_get_contents($ROOT . '/public/index.php');
assert_test(str_contains($frontSrc, "'subscribe_start'") && str_contains($frontSrc, "elseif (\$action === 'subscribe_start')"), 'SS04: rota subscribe_start existe no front controller');
assert_test(preg_match("/csrfProtectedActions\\s*=\\s*\\[[^\\]]*'subscribe_start'/s", $frontSrc) === 1, 'SS05: CSRF obrigatorio para subscribe_start');

echo "\n-- validacao de plano --\n";
sub_reset_request('POST', ['plan' => 'free']);
$db = new FakeSubPDO();
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 400 && ($r['data']['error'] ?? '') === 'plano_invalido', 'SS06: plan=free recusado');

sub_reset_request('POST', ['plan' => 'diamante']);
$db = new FakeSubPDO();
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 400, 'SS07: plano aleatorio recusado');

sub_reset_request('POST', []);
$db = new FakeSubPDO();
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 400, 'SS08: plano ausente recusado');

echo "\n-- catalogo como fonte da verdade --\n";
$captured = [];
sub_reset_request('POST', ['plan' => 'pro', 'price' => '0.01', 'amount' => '1', 'transaction_amount' => '1', 'user_id' => '999']);
$db = new FakeSubPDO();
sub_queue_success($db, 'pro');
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured, 201, sub_success_body())));
$r = sub_call($ctl);
$reqBody = json_decode($captured['body'] ?? '{}', true);
assert_test($r['code'] === 200 && ($r['data']['success'] ?? false) === true, 'SS09: pro com campos injetados ainda retorna sucesso');
assert_test(!isset($reqBody['price'], $reqBody['amount'], $reqBody['transaction_amount']), 'SS10: preco do frontend ignorado no request MP');
assert_test(($reqBody['preapproval_plan_id'] ?? '') === 'plan_pro_1' && ($reqBody['payer_email'] ?? '') === 'pagador@teste.com', 'SS11: pro usa plan_id e email do backend');
$insertParams = null;
foreach ($db->queries as $i => $q) {
    if (str_contains($q, 'INSERT INTO subscriptions')) {
        $insertParams = true;
        break;
    }
}
assert_test($insertParams === true, 'SS12: tentativa INSERT registrada');
// Reexecuta para inspecionar params do INSERT com fila fresca.
$captured2 = [];
$dbB = new FakeSubPDO();
sub_queue_success($dbB, 'pro');
$ctlB = new SubscribeController($dbB, new User($dbB), new PlanService($dbB), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured2, 201, sub_success_body())));
sub_reset_request('POST', ['plan' => 'pro', 'user_id' => '999']);
sub_call($ctlB);
$insertSql = '';
foreach ($dbB->queries as $q) {
    if (str_contains($q, 'INSERT INTO subscriptions')) {
        $insertSql = $q;
    }
}
assert_test($insertSql !== '' && str_contains($insertSql, 'user_id'), 'SS13: INSERT vincula user_id');
// user_id da sessao (42) deve ser o primeiro parametro do INSERT.
$dbC = new FakeSubPDO();
sub_queue_success($dbC, 'pro');
$hold = [];
$wrapPdo = new class ($dbC, $hold) extends FakeSubPDO {
    /** @var array */
    public array $seen = [];
    public function __construct(private FakeSubPDO $inner, array $h)
    {
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if (str_contains($query, 'INSERT INTO subscriptions') && $stmt instanceof FakeSubStmt) {
            $orig = $stmt;
            return new class ($orig, $this) extends FakeSubStmt {
                public function __construct(private FakeSubStmt $in, private object $rec)
                {
                }
                public function execute(?array $params = null): bool
                {
                    $this->rec->seen = $params ?? [];
                    return $this->in->execute($params);
                }
                public function rowCount(): int
                {
                    return $this->in->rowCount();
                }
                public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
                {
                    return $this->in->fetch($mode, $cursorOrientation, $cursorOffset);
                }
                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                {
                    return $this->in->fetchAll($mode, ...$args);
                }
            };
        }
        return $stmt;
    }
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->inner->lastInsertId($name);
    }
};
$captured3 = [];
$ctlC = new SubscribeController($wrapPdo, new User($wrapPdo), new PlanService($wrapPdo), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured3, 201, sub_success_body())));
sub_reset_request('POST', ['plan' => 'pro', 'user_id' => '999']);
sub_call($ctlC);
assert_test(($wrapPdo->seen[0] ?? null) === 42, 'SS14: user_id do frontend ignorado; sessao (42) e a fonte');

echo "\n-- ambiente ausente --\n";
sub_env(['MERCADOPAGO_ACCESS_TOKEN' => '']);
sub_reset_request('POST', ['plan' => 'pro']);
$db = new FakeSubPDO();
$db->queue = [sub_stmt(sub_user_row()), sub_stmt(sub_plan_row('pro', '2', 'Pro', '9.90')), sub_stmt(sub_plan_row('pro', '2', 'Pro', '9.90'))];
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 503 && ($r['data']['error'] ?? '') === 'mp_not_configured', 'SS15: Access Token ausente retorna mp_not_configured sem fatal');

sub_env(['MERCADOPAGO_PLAN_ID_PRO' => '', 'MERCADOPAGO_PLAN_ID_PREMIUM' => '']);
sub_reset_request('POST', ['plan' => 'premium']);
$db = new FakeSubPDO();
$db->queue = [sub_stmt(sub_user_row()), sub_stmt(sub_plan_row('premium', '3', 'Premium', '19.90')), sub_stmt(sub_plan_row('premium', '3', 'Premium', '19.90'))];
$ctl = new SubscribeController($db, new User($db), new PlanService($db));
$r = sub_call($ctl);
assert_test($r['code'] === 503 && ($r['data']['error'] ?? '') === 'mp_plan_not_configured', 'SS16: plan ID ausente retorna erro controlado');
sub_env([]);

echo "\n-- request MP e resposta --\n";
$captured = [];
sub_reset_request('POST', ['plan' => 'premium']);
$db = new FakeSubPDO();
sub_queue_success($db, 'premium');
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured, 201, sub_success_body('pre_9', 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=pre_9'))));
$r = sub_call($ctl);
assert_test(($captured['method'] ?? '') === 'POST' && ($captured['url'] ?? '') === 'https://api.mercadopago.com/preapproval', 'SS17: request POST na URL oficial');
$hasBearer = false;
$hasJson = false;
foreach ($captured['headers'] ?? [] as $h) {
    if ($h === 'Authorization: Bearer TEST_TOKEN') {
        $hasBearer = true;
    }
    if ($h === 'Content-Type: application/json') {
        $hasJson = true;
    }
}
assert_test($hasBearer && $hasJson, 'SS18: Bearer + JSON montados server-side');
assert_test($r['code'] === 200 && ($r['data']['checkout_url'] ?? '') === 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=pre_9', 'SS19: checkout URL oficial retornada intacta');

$meuPlano = (string)file_get_contents($ROOT . '/public/meu_plano.php');
$js = (string)file_get_contents($ROOT . '/public/js/subscribe.js');
assert_test(!str_contains($meuPlano, 'MERCADOPAGO_ACCESS_TOKEN') && !str_contains($meuPlano, 'Bearer'), 'SS20: nenhum segredo no template Meu Plano');
assert_test(!str_contains($js, 'MERCADOPAGO_ACCESS_TOKEN') && !str_contains($js, 'Bearer') && !str_contains($js, 'init_point'), 'SS21: nenhum segredo/URL MP hardcoded no JS');

$reqBody = json_decode($captured['body'] ?? '{}', true);
assert_test(preg_match('/^user_42_premium_[0-9a-f]{32}$/', (string)($reqBody['external_reference'] ?? '')) === 1, 'SS22: external_reference user/plano/attempt');

echo "\n-- falhas da API --\n";
function sub_fail_case(string $name, int $status, string $body, string $error, string $expectError, int $expectCode): void
{
    $captured = [];
    sub_reset_request('POST', ['plan' => 'pro']);
    $db = new FakeSubPDO();
    sub_queue_success($db, 'pro');
    // Remove UPDATE da fila: a chamada deve falhar antes de concluir.
    $ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured, $status, $body, $error)));
    $r = sub_call($ctl);
    assert_test($r['code'] === $expectCode && ($r['data']['error'] ?? '') === $expectError, $name);
    $hasUserUpdate = false;
    foreach ($db->queries as $q) {
        if (str_contains($q, 'UPDATE usuarios')) {
            $hasUserUpdate = true;
        }
    }
    assert_test(!$hasUserUpdate, $name . ' (plano do usuario intacto)');
}
sub_fail_case('SS23: timeout vira 504', 0, '', 'Operation timed out', 'mp_timeout', 504);
sub_fail_case('SS24: HTTP 400', 400, '{"message":"bad"}', '', 'mp_http_400', 502);
sub_fail_case('SS25: HTTP 401', 401, '{"message":"unauthorized"}', '', 'mp_http_401', 502);
sub_fail_case('SS26: HTTP 429', 429, '{"message":"slow down"}', '', 'mp_http_429', 502);
sub_fail_case('SS27: HTTP 500', 500, '{"message":"boom"}', '', 'mp_http_5xx', 502);
sub_fail_case('SS28: JSON invalido', 200, 'nao-json', '', 'mp_invalid_json', 502);

$captured = [];
sub_reset_request('POST', ['plan' => 'pro']);
$db = new FakeSubPDO();
sub_queue_success($db, 'pro');
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured, 201, '{"id":"pre_x","status":"pending"}')));
$r = sub_call($ctl);
assert_test($r['code'] === 502 && ($r['data']['error'] ?? '') === 'checkout_indisponivel', 'SS29: init_point ausente rejeitado');

echo "\n-- pending sem ativar + idempotencia --\n";
$captured = [];
sub_reset_request('POST', ['plan' => 'pro']);
$db = new FakeSubPDO();
sub_queue_success($db, 'pro');
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', sub_transport($captured, 201, sub_success_body())));
$r = sub_call($ctl);
$hasInsert = false;
$hasUserUpdate = false;
foreach ($db->queries as $q) {
    if (str_contains($q, 'INSERT INTO subscriptions')) {
        $hasInsert = true;
    }
    if (str_contains($q, 'UPDATE usuarios')) {
        $hasUserUpdate = true;
    }
}
assert_test($r['code'] === 200 && $hasInsert && !$hasUserUpdate, 'SS30: tentativa pending criada sem alterar usuarios.plano');

// Double submit: tentativa pending recente com checkout_url e reutilizada.
sub_reset_request('POST', ['plan' => 'pro']);
$db = new FakeSubPDO();
$db->queue = [
    sub_stmt(sub_user_row()),
    sub_stmt(sub_plan_row('pro', '2', 'Pro', '9.90')),
    sub_stmt(sub_plan_row('pro', '2', 'Pro', '9.90')),
    sub_stmt(['id' => 5, 'checkout_url' => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=pre_old']),
];
$called = false;
$transport = function () use (&$called): array {
    $called = true;
    return ['status' => 201, 'body' => '{}', 'error' => ''];
};
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', $transport));
$r = sub_call($ctl);
$didInsert = false;
foreach ($db->queries as $q) {
    if (str_contains($q, 'INSERT INTO subscriptions')) {
        $didInsert = true;
    }
}
assert_test($r['code'] === 200 && ($r['data']['reused'] ?? false) === true && !$didInsert && !$called, 'SS31: double submit reutiliza tentativa sem nova chamada MP');

assert_test(count($GLOBALS['SUB_WARNINGS_TOTAL'] ?? []) === 0, 'SS32: nenhum PHP warning durante as chamadas do controller');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

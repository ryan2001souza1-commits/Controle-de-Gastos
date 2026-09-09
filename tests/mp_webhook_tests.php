<?php
/**
 * MP Webhook Tests — webhook oficial com x-signature, idempotencia,
 * consulta oficial e sincronizacao transacional.
 *
 * Tudo com mocks/fakes. NENHUM teste bate na API real do Mercado Pago.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/models/User.php';
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/services/PlanService.php';
require_once $ROOT . '/src/services/BillingSyncService.php';
require_once $ROOT . '/src/services/WebhookLedger.php';
require_once $ROOT . '/src/services/MercadoPagoClient.php';
require_once $ROOT . '/src/controllers/MpWebhookController.php';

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

class FakeMpStmt extends PDOStatement
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

class FakeMpPDO extends PDO
{
    /** @var string[] */
    public array $queries = [];
    /** @var FakeMpStmt[] */
    public array $queue = [];
    /** @var FakeMpStmt[] */
    public array $created = [];
    public bool $inTx = false;
    public bool $committed = false;
    public bool $rolledBack = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->inTx = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        $this->committed = true;
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTx = false;
        $this->rolledBack = true;
        return true;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        $stmt = array_shift($this->queue) ?? new FakeMpStmt();
        $this->created[] = $stmt;
        return $stmt;
    }
}

function wh_stmt(array $row = [], array $all = [], int $count = 1): FakeMpStmt
{
    $s = new FakeMpStmt();
    $s->row = $row;
    $s->all = $all;
    $s->count = $count;
    return $s;
}

const WH_SECRET = 'whsec_test_123';

function wh_env(?string $secret): void
{
    $v = $secret ?? '';
    putenv("MERCADOPAGO_WEBHOOK_SECRET={$v}");
    $_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = $v;
    $_SERVER['MERCADOPAGO_WEBHOOK_SECRET'] = $v;
}

/** Manifest oficial: id:{qid};request-id:{rid};ts:{ts}; (partes ausentes removidas). */
function wh_manifest(?string $qid, string $rid, string $ts): string
{
    $m = '';
    if ($qid !== null && $qid !== '') {
        $m .= 'id:' . strtolower($qid) . ';';
    }
    return $m . 'request-id:' . $rid . ';ts:' . $ts . ';';
}

function wh_sig(?string $qid, string $rid, string $ts): string
{
    return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', wh_manifest($qid, $rid, $ts), WH_SECRET);
}

/** @return array{method:string, headers:array, query:array, rawBody:string} */
function wh_req(string $sig, string $rid, array $query, array $body, string $method = 'POST'): array
{
    return [
        'method' => $method,
        'headers' => ['x-signature' => $sig, 'x-request-id' => $rid],
        'query' => $query,
        'rawBody' => json_encode($body, JSON_UNESCAPED_SLASHES),
    ];
}

function wh_body(string $resId, string $type = 'subscription_preapproval'): array
{
    return ['id' => 999, 'live_mode' => false, 'type' => $type, 'action' => 'created', 'data' => ['id' => $resId]];
}

function wh_api(string $id, string $status, string $extRef = '', ?string $init = null): string
{
    $d = ['id' => $id, 'status' => $status, 'external_reference' => $extRef];
    if ($init !== null) {
        $d['init_point'] = $init;
    }
    return json_encode($d, JSON_UNESCAPED_SLASHES);
}

function wh_transport(array &$captured, int $status, string $body, string $error = ''): callable
{
    return function (string $method, string $url, array $headers, string $reqBody) use (&$captured, $status, $body, $error): array {
        $captured[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $reqBody];
        return ['status' => $status, 'body' => $body, 'error' => $error];
    };
}

/** @return array{code:int, data:array} */
function wh_call(MpWebhookController $ctl, array $req): array
{
    $GLOBALS['WH_WARNINGS_TOTAL'] = $GLOBALS['WH_WARNINGS_TOTAL'] ?? [];
    set_error_handler(function (int $no, string $str): bool {
        $GLOBALS['WH_WARNINGS_TOTAL'][] = $str;
        return true;
    }, E_WARNING);
    try {
        ob_start();
        $ctl->handle($req);
        $out = (string)ob_get_clean();
    } finally {
        restore_error_handler();
    }
    return ['code' => http_response_code(), 'data' => json_decode($out, true) ?? []];
}

function wh_local_row(int $id = 7, int $user = 42, string $slug = 'pro', string $provider = 'mercadopago'): array
{
    return ['id' => $id, 'user_id' => $user, 'plan_slug' => $slug, 'provider' => $provider];
}

/** Fila p/ sucesso completo authorized->active (claim + link + sync tx). */
function wh_queue_active(FakeMpPDO $db, array $local): void
{
    $db->queue = [
        wh_stmt([], [], 1),          // claim INSERT
        wh_stmt($local),             // findLocalByMpId
        wh_stmt($local),             // SELECT FOR UPDATE
        wh_stmt([], [], 1),          // UPDATE subscriptions ids
        wh_stmt([], [], 0),          // SELECT outras ativas: nenhuma
        wh_stmt([], [], 1),          // UPDATE usuarios
        wh_stmt([], [], 1),          // UPDATE subscriptions status
        wh_stmt([], [], 1),          // markProcessed
    ];
}

/** Fila p/ pending (sem coreApply): claim + link + lock + upd ids + mark. */
function wh_queue_pending(FakeMpPDO $db, array $local): void
{
    $db->queue = [
        wh_stmt([], [], 1),
        wh_stmt($local),
        wh_stmt($local),
        wh_stmt([], [], 1),
        wh_stmt([], [], 1),
    ];
}

function wh_user_update_params(FakeMpPDO $db): ?array
{
    foreach ($db->created as $s) {
        // encontra o UPDATE usuarios pelo historico de queries na mesma ordem
    }
    foreach ($db->queries as $i => $q) {
        if (str_contains($q, 'UPDATE usuarios')) {
            return $db->created[$i]->params;
        }
    }
    return null;
}

function wh_has_query(FakeMpPDO $db, string $needle): bool
{
    foreach ($db->queries as $q) {
        if (str_contains($q, $needle)) {
            return true;
        }
    }
    return false;
}

echo "\n=== MP WEBHOOK TESTS ===\n\n";
wh_env(WH_SECRET);

echo "-- metodo, parse e validacao de origem --\n";
$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req('', 'r1', [], [], 'GET'));
assert_test($r['code'] === 405, 'WH01: metodo invalido retorna 405');

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, ['method' => 'POST', 'headers' => ['x-signature' => 'ts=1,v1=aa', 'x-request-id' => 'r1'], 'query' => [], 'rawBody' => 'nao-json']);
assert_test($r['code'] === 400 && ($r['data']['error'] ?? '') === 'payload_invalido', 'WH02: payload invalido retorna 400');

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, ['method' => 'POST', 'headers' => ['x-request-id' => 'r1'], 'query' => ['data.id' => 'pre_1'], 'rawBody' => json_encode(wh_body('pre_1'))]);
assert_test($r['code'] === 401 && ($r['data']['error'] ?? '') === 'assinatura_ausente', 'WH03: x-signature ausente retorna 401');

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, ['method' => 'POST', 'headers' => ['x-signature' => 'ts=1,v1=aa'], 'query' => ['data.id' => 'pre_1'], 'rawBody' => json_encode(wh_body('pre_1'))]);
assert_test($r['code'] === 401 && ($r['data']['error'] ?? '') === 'request_id_ausente', 'WH04: x-request-id ausente retorna 401');

wh_env(null);
$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req('ts=1,v1=aa', 'r1', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 500 && ($r['data']['error'] ?? '') === 'webhook_nao_configurado', 'WH05: secret ausente nao processa (500 controlado)');
wh_env(WH_SECRET);

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req('v1=' . str_repeat('a', 64), 'r1', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 401 && ($r['data']['error'] ?? '') === 'ts_ausente', 'WH06: ts ausente retorna 401');

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req('ts=1704908010000', 'r1', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 401 && ($r['data']['error'] ?? '') === 'v1_ausente', 'WH07: v1 ausente retorna 401');

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req('ts=1704908010000,v1=' . str_repeat('0', 64), 'r1', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 401 && ($r['data']['error'] ?? '') === 'assinatura_invalida', 'WH08: HMAC invalido retorna 401');
assert_test(count($db->queries) === 0, 'WH09: assinatura invalida nao toca no banco nem na API');

$ctlSrc = (string)file_get_contents($ROOT . '/src/controllers/MpWebhookController.php');
assert_test(str_contains($ctlSrc, 'hash_equals(') && str_contains($ctlSrc, "hash_hmac('sha256'"), 'WH10: hash_equals + HMAC-SHA256 usados');

$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'r1', '1704908010000'), 'r1', ['data.id' => '!!!'], ['data' => ['id' => '???']]));
assert_test($r['code'] === 400, 'WH11: resource ID invalido retorna 400');

echo "\n-- consulta oficial como fonte da verdade --\n";
$captured = [];
$db = new FakeMpPDO();
wh_queue_active($db, wh_local_row());
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_123', 'authorized', 'user_42_pro_' . str_repeat('b', 32), 'https://mp.test/c'))));
$r = wh_call($ctl, wh_req(wh_sig('pre_123', 'req-1', '1704908010000'), 'req-1', ['data.id' => 'pre_123'], wh_body('pre_123')));
assert_test(count($captured) === 1 && ($captured[0]['method'] ?? '') === 'GET' && ($captured[0]['url'] ?? '') === 'https://api.mercadopago.com/preapproval/pre_123', 'WH12: webhook valido consulta GET /preapproval/{id}');
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'active', 'WH13: assinatura valida retorna assinatura valida');

// Body mente, API manda: body com status authorized ignorado, API pending vence.
$captured = [];
$db = new FakeMpPDO();
wh_queue_pending($db, wh_local_row());
$lyingBody = wh_body('pre_123');
$lyingBody['status'] = 'authorized';
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_123', 'pending', 'user_42_pro_' . str_repeat('b', 32)))));
$r = wh_call($ctl, wh_req(wh_sig('pre_123', 'req-2', '1704908010000'), 'req-2', ['data.id' => 'pre_123'], $lyingBody));
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'pending', 'WH14: payload sozinho nunca ativa; API pending vence');
assert_test(!wh_has_query($db, 'UPDATE usuarios'), 'WH15: sem confirmacao oficial, plano intacto');

function wh_api_fail(string $name, int $http, string $body, string $error, string $expectError, int $expectCode): void
{
    $captured = [];
    $db = new FakeMpPDO();
    $db->queue = [wh_stmt([], [], 1)]; // claim INSERT ok; GET falha depois
    $ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, $http, $body, $error)));
    $r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-f', '1704908010000'), 'req-f', ['data.id' => 'pre_1'], wh_body('pre_1')));
    assert_test($r['code'] === $expectCode && ($r['data']['error'] ?? '') === $expectError, $name);
    assert_test(!wh_has_query($db, 'UPDATE usuarios'), $name . ' (plano intacto)');
    // falha apos reserva deve marcar failed (permite retry), nao processed
    $markedFailed = false;
    foreach ($db->queries as $q) {
        if (str_contains($q, "SET status = 'failed'")) {
            $markedFailed = true;
        }
    }
    assert_test($markedFailed, $name . ' (ledger marked failed p/ retry)');
}
wh_api_fail('WH16: API 401 nao ativa', 401, '{"message":"unauth"}', '', 'mp_http_401', 500);
wh_api_fail('WH17: API 404 nao ativa', 404, '{"message":"not found"}', '', 'mp_http_404', 500);
wh_api_fail('WH18: API 429 nao ativa', 429, '{"message":"slow"}', '', 'mp_http_429', 503);
wh_api_fail('WH19: API 500 nao ativa', 500, '{"message":"boom"}', '', 'mp_http_5xx', 503);
wh_api_fail('WH20: timeout nao ativa', 0, '', 'Operation timed out', 'mp_timeout', 504);
wh_api_fail('WH21: JSON invalido nao ativa', 200, 'lixo', '', 'mp_invalid_json', 500);

echo "\n-- vinculacao segura --\n";
$captured = [];
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 1), wh_stmt([]), wh_stmt([])]; // claim + 2 lookups vazios
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_x', 'authorized', 'user_999_pro_' . str_repeat('c', 32)))));
$r = wh_call($ctl, wh_req(wh_sig('pre_x', 'req-u', '1704908010000'), 'req-u', ['data.id' => 'pre_x'], wh_body('pre_x')));
assert_test($r['code'] === 200 && ($r['data']['ignored'] ?? false) === true, 'WH22: external_reference desconhecida ignorada sem ativar');

$captured = [];
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 1), wh_stmt([])]; // claim + lookup vazio (sem external_reference)
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_y', 'authorized', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_y', 'req-v', '1704908010000'), 'req-v', ['data.id' => 'pre_y'], wh_body('pre_y')));
assert_test($r['code'] === 200 && ($r['data']['ignored'] ?? false) === true, 'WH23: subscription inexistente ignorada sem ativar');

$captured = [];
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 1), wh_stmt(wh_local_row(7, 42, 'pro', 'outro_gateway'))];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'authorized', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-w', '1704908010000'), 'req-w', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 200 && ($r['data']['reason'] ?? '') === 'provider_divergente' && !wh_has_query($db, 'UPDATE usuarios'), 'WH24: provider incorreto nunca atualizado');

echo "\n-- status e ativacao --\n";
// pending: sem UPDATE usuarios, mas ids oficiais gravados + processed.
$captured = [];
$db = new FakeMpPDO();
wh_queue_pending($db, wh_local_row());
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'pending', 'user_42_pro_' . str_repeat('b', 32)))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-p', '1704908010000'), 'req-p', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'pending' && !wh_has_query($db, 'UPDATE usuarios'), 'WH25: pending nao ativa plano');

// authorized: ativa pro + active_subscription_id=7 + user 42.
$captured = [];
$db = new FakeMpPDO();
wh_queue_active($db, wh_local_row());
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'authorized', 'user_42_pro_' . str_repeat('b', 32), 'https://mp.test/c'))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-a', '1704908010000'), 'req-a', ['data.id' => 'pre_1'], wh_body('pre_1')));
$params = wh_user_update_params($db);
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'active', 'WH26: authorized ativa');
assert_test($params === ['pro', 'ativo', 7, 42], 'WH27: UPDATE usuarios correto (plano, status, active_subscription_id, user)');
assert_test($db->committed && !$db->rolledBack, 'WH28: sync em transacao com commit');

// paused: sem mudanca de plano.
$captured = [];
$db = new FakeMpPDO();
wh_queue_pending($db, wh_local_row());
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'paused', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-s', '1704908010000'), 'req-s', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'paused' && !wh_has_query($db, 'UPDATE usuarios'), 'WH29: paused tratado sem ativar');

// canceled (API, 1 L) -> cancelled interno + downgrade.
$captured = [];
$db = new FakeMpPDO();
$db->queue = [
    wh_stmt([], [], 1),
    wh_stmt(wh_local_row()),
    wh_stmt(wh_local_row()),
    wh_stmt([], [], 1),
    wh_stmt([], [], 1), // UPDATE usuarios downgrade
    wh_stmt([], [], 1), // UPDATE subscriptions
    wh_stmt([], [], 1), // markProcessed
];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'canceled', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-c', '1704908010000'), 'req-c', ['data.id' => 'pre_1'], wh_body('pre_1')));
$params = wh_user_update_params($db);
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'cancelled', 'WH30: canceled oficial mapeado p/ cancelled');
assert_test($params === ['gratuito', 'ativo', null, 42], 'WH31: cancelled rebaixa p/ gratuito com active_subscription_id NULL');

// status desconhecido da API: ignora sem quebrar.
$captured = [];
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 1), wh_stmt(wh_local_row()), wh_stmt([], [], 1)];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'weird_status', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-z', '1704908010000'), 'req-z', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 200 && ($r['data']['reason'] ?? '') === 'status_desconhecido' && !wh_has_query($db, 'UPDATE usuarios'), 'WH32: status API desconhecido ignorado com seguranca');

echo "\n-- idempotencia, retry e ordem --\n";
// Duplicado: claim encontra processed -> 200 sem GET nem sync.
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 0), wh_stmt(['status' => 'processed', 'attempts' => 1, 'updated_at' => date('Y-m-d H:i:s')])];
$called = false;
$transport = function () use (&$called): array {
    $called = true;
    return ['status' => 200, 'body' => '{}', 'error' => ''];
};
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', $transport));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-d', '1704908010000'), 'req-d', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 200 && ($r['data']['duplicate'] ?? false) === true && !$called, 'WH33: evento duplicado responde sem repetir efeitos');

// 10 redeliveries: todos duplicate, zero efeitos.
$allDup = true;
for ($i = 0; $i < 10; $i++) {
    $db = new FakeMpPDO();
    $db->queue = [wh_stmt([], [], 0), wh_stmt(['status' => 'processed', 'attempts' => 2, 'updated_at' => date('Y-m-d H:i:s')])];
    $ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', $transport));
    $r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-d', '1704908010000'), 'req-d', ['data.id' => 'pre_1'], wh_body('pre_1')));
    if (!(($r['data']['duplicate'] ?? false) === true) || $called) {
        $allDup = false;
    }
}
assert_test($allDup, 'WH34: 10 redeliveries geram o mesmo estado, sem efeitos');

// Rollback: UPDATE usuarios falha -> 500 + rollback, plano intacto.
$db = new FakeMpPDO();
$db->queue = [
    wh_stmt([], [], 1),
    wh_stmt(wh_local_row()),
    wh_stmt(wh_local_row()),
    wh_stmt([], [], 1),
    wh_stmt([], [], 0), // SELECT outras: nenhuma
    wh_stmt([], [], 0), // UPDATE usuarios: 0 linhas -> throw
];
$captured = [];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'authorized', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-r', '1704908010000'), 'req-r', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 500 && $db->rolledBack && !$db->committed, 'WH35: falha no sync faz rollback total');

// Ledger nao trava apos falha: failed -> retry retoma e conclui.
$db = new FakeMpPDO();
$db->queue = [
    wh_stmt([], [], 0), // INSERT conflita
    wh_stmt(['status' => 'failed', 'attempts' => 1, 'updated_at' => date('Y-m-d H:i:s', time() - 3600)]),
    wh_stmt([], [], 1), // UPDATE claim ok -> retry
    wh_stmt(wh_local_row()),
    wh_stmt(wh_local_row()),
    wh_stmt([], [], 1),
    wh_stmt([], [], 0),
    wh_stmt([], [], 1),
    wh_stmt([], [], 1),
    wh_stmt([], [], 1),
];
$captured = [];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'authorized', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-t', '1704908010000'), 'req-t', ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 200 && ($r['data']['status'] ?? '') === 'active' && $db->committed, 'WH36: redelivery apos falha retoma e conclui');

// Fora de ordem: evento antigo reprocessado usa estado ATUAL da API.
$db = new FakeMpPDO();
$db->queue = [
    wh_stmt([], [], 1),
    wh_stmt(wh_local_row()),
    wh_stmt(wh_local_row()),
    wh_stmt([], [], 1),
    wh_stmt([], [], 1),
    wh_stmt([], [], 1),
    wh_stmt([], [], 1),
];
$captured = [];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'canceled', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-old', '1704908010000'), 'req-old', ['data.id' => 'pre_1'], wh_body('pre_1')));
$params = wh_user_update_params($db);
assert_test($r['code'] === 200 && $params === ['gratuito', 'ativo', null, 42], 'WH37: evento antigo nao sobrescreve estado vigente (API manda)');

// Duas ativas concorrentes: segunda ativacao bloqueada com rollback.
$db = new FakeMpPDO();
$db->queue = [
    wh_stmt([], [], 1),
    wh_stmt(wh_local_row(8, 42)),
    wh_stmt(wh_local_row(8, 42)),
    wh_stmt([], [], 1),
    wh_stmt([], [['id' => 7]], 1), // outra ativa existe (fetchAll)
];
$captured = [];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_8', 'authorized', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_8', 'req-2a', '1704908010000'), 'req-2a', ['data.id' => 'pre_8'], wh_body('pre_8')));
assert_test($r['code'] === 500 && $db->rolledBack && !wh_has_query($db, 'UPDATE usuarios'), 'WH38: segunda ativa concorrente bloqueada sem corromper');

echo "\n-- segredos e higiene --\n";
$captured = [];
$db = new FakeMpPDO();
wh_queue_active($db, wh_local_row());
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'authorized', ''))));
$r = wh_call($ctl, wh_req(wh_sig('pre_1', 'req-h', '1704908010000'), 'req-h', ['data.id' => 'pre_1'], wh_body('pre_1')));
$respJson = json_encode($r['data']);
assert_test(!str_contains($respJson, WH_SECRET) && !str_contains($respJson, 'TEST_TOKEN') && !str_contains($respJson, 'APP_USR'), 'WH39: segredo/token nunca na resposta');
$entrySrc = (string)file_get_contents($ROOT . '/public/mercadopago_webhook.php');
assert_test(
    !preg_match('/\b(var_dump|print_r|debug_backtrace|getTraceAsString)\b/', $entrySrc)
    && !preg_match('/echo[^;]*\$e\b/', $entrySrc)
    && str_contains($entrySrc, "'erro_interno'"),
    'WH40: entry sem stack trace (resposta generica fixa)'
);
assert_test(count($GLOBALS['WH_WARNINGS_TOTAL'] ?? []) === 0, 'WH41: nenhum warning PHP');
$apiSrc = (string)file_get_contents($ROOT . '/api/index.php');
assert_test(
    !str_contains($apiSrc, 'mercadopago_webhook')
    && str_contains($apiSrc, "include \$real;"),
    'WH42: roteador serve o webhook como arquivo publico, sem sessao/CSRF'
);

// data.id MAIUSCULO na query: manifest usa lowercase (doc oficial) e o
// GET/lookup usam o mesmo identificador normalizado.
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 1), wh_stmt([]), wh_stmt([])];
$captured = [];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('preabc123', 'pending', ''))));
$r = wh_call($ctl, wh_req(wh_sig('PREABC123', 'req-up', '1704908010000'), 'req-up', ['data.id' => 'PREABC123'], wh_body('PREABC123')));
assert_test(count($captured) === 1 && ($captured[0]['url'] ?? '') === 'https://api.mercadopago.com/preapproval/preabc123', 'WH43: id maiusculo normalizado igual ao manifest');

// x-request-id gigante: 401, sem tocar no banco.
$db = new FakeMpPDO();
$ctl = new MpWebhookController($db, new User($db), new PlanService($db));
$r = wh_call($ctl, wh_req('ts=1,v1=aa', str_repeat('x', 121), ['data.id' => 'pre_1'], wh_body('pre_1')));
assert_test($r['code'] === 401 && count($db->queries) === 0, 'WH44: request-id invalido rejeitado antes de tudo');

// Manifest estrito (so query): assinatura sem parte id valida quando a
// query nao trouxe data.id; lookup usa o body com seguranca (pos-validacao).
$db = new FakeMpPDO();
$db->queue = [wh_stmt([], [], 1), wh_stmt([]), wh_stmt([])];
$captured = [];
$ctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', wh_transport($captured, 200, wh_api('pre_1', 'pending', ''))));
$noIdSig = 'ts=1704908010000,v1=' . hash_hmac('sha256', 'request-id:req-noid;ts:1704908010000;', WH_SECRET);
$r = wh_call($ctl, wh_req($noIdSig, 'req-noid', [], wh_body('pre_1')));
assert_test(count($captured) === 1 && wh_has_query($db, 'INSERT INTO webhook_events'), 'WH45: manifest sem id valida; lookup pelo body apos assinatura ok');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

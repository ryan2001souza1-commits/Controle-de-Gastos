<?php
/**
 * MP Device + Diagnostic Tests — Device ID oficial, diagnostico seguro
 * do /preapproval e diagnostico staged de todos os 500 do webhook.
 *
 * Sem rede real, sem cartao real. error_log capturado via ini para /tmp.
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
require_once $ROOT . '/src/controllers/SubscribeController.php';
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

class FakeDvcStmt extends PDOStatement
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

class FakeDvcPDO extends PDO
{
    /** @var string[] */
    public array $queries = [];
    /** @var FakeDvcStmt[] */
    public array $queue = [];
    /** @var FakeDvcStmt[] */
    public array $created = [];
    public string $throwOn = '';
    public string $nextId = '9';
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
        if ($this->throwOn !== '' && str_contains($query, $this->throwOn)) {
            throw new RuntimeException('falha simulada');
        }
        $stmt = array_shift($this->queue) ?? new FakeDvcStmt();
        $this->created[] = $stmt;
        return $stmt;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->nextId;
    }
}

function dvc_stmt(array $row = [], array $all = [], int $count = 1): FakeDvcStmt
{
    $s = new FakeDvcStmt();
    $s->row = $row;
    $s->all = $all;
    $s->count = $count;
    return $s;
}

function dvc_transport(array &$captured, int $status, string $body, string $error = ''): callable
{
    return function (string $method, string $url, array $headers, string $reqBody) use (&$captured, $status, $body, $error): array {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $reqBody];
        return ['status' => $status, 'body' => $body, 'error' => $error];
    };
}

function dvc_env(array $env): void
{
    foreach ($env as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

/** Captura error_log em arquivo temporario durante $fn. */
function dvc_capture_log(callable $fn): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mp_log_');
    $prev = ini_get('error_log');
    ini_set('error_log', (string)$tmp);
    try {
        $fn();
    } finally {
        ini_set('error_log', $prev !== false ? $prev : '');
    }
    $out = (string)@file_get_contents((string)$tmp);
    @unlink((string)$tmp);
    return $out;
}

function dvc_user_row(): array
{
    return ['id' => 42, 'nome' => 'T', 'email' => 't@t.com', 'senha' => null, 'plano' => 'gratuito', 'plano_status' => 'ativo'];
}

function dvc_plan_row(): array
{
    return ['id' => '2', 'nome' => 'Pro', 'slug' => 'pro', 'preco' => '9.90', 'descricao' => '', 'status' => 'ativo'];
}

function dvc_ok_body(): string
{
    return '{"id":"pre_d","status":"authorized"}';
}

echo "\n=== MP DEVICE + DIAGNOSTIC TESTS ===\n\n";
dvc_env([
    'MERCADOPAGO_ACCESS_TOKEN' => 'TEST_TOKEN',
    'MERCADOPAGO_PLAN_ID_PRO' => 'plan_pro_1',
    'MERCADOPAGO_PLAN_ID_PREMIUM' => 'plan_pre_1',
    'MERCADOPAGO_WEBHOOK_SECRET' => 'whsec_test',
    'APP_URL' => 'https://app.test',
]);

echo "-- device_id chega ao backend e vira header --\n";
$device = 'MPDEVICESESSION1234567890abcdef';
$captured = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
unset($_SERVER['CONTENT_TYPE']);
$_POST = ['plan' => 'pro', 'card_token_id' => 'tokentest1234567890abcdef1234567890', 'device_id' => $device];
$_SESSION['user_id'] = 42;
$db = new FakeDvcPDO();
$db->queue = [dvc_stmt(dvc_user_row()), dvc_stmt(dvc_plan_row()), dvc_stmt(dvc_plan_row()), dvc_stmt([], []), dvc_stmt([], [], 1), dvc_stmt([], [], 1)];
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', dvc_transport($captured, 201, dvc_ok_body())));
ob_start();
$ctl->start();
$out = (string)ob_get_clean();
$code = http_response_code();
assert_test($code === 200, 'DV01: fluxo com device_id retorna 200');
$hasHeader = false;
foreach ($captured['headers'] ?? [] as $h) {
    if ($h === 'X-meli-session-id: ' . $device) {
        $hasHeader = true;
    }
}
assert_test($hasHeader, 'DV02: header X-meli-session-id enviado no POST /preapproval');

// Ausente: header omitido, fluxo continua.
$captured = [];
$_POST = ['plan' => 'pro', 'card_token_id' => 'tokentest1234567890abcdef1234567890'];
$db = new FakeDvcPDO();
$db->queue = [dvc_stmt(dvc_user_row()), dvc_stmt(dvc_plan_row()), dvc_stmt(dvc_plan_row()), dvc_stmt([], []), dvc_stmt([], [], 1), dvc_stmt([], [], 1)];
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', dvc_transport($captured, 201, dvc_ok_body())));
ob_start();
$ctl->start();
$out2 = (string)ob_get_clean();
assert_test(http_response_code() === 200, 'DV03: device ausente nao quebra o fluxo');
$hasHeader = false;
foreach ($captured['headers'] ?? [] as $h) {
    if (str_starts_with($h, 'X-meli-session-id:')) {
        $hasHeader = true;
    }
}
assert_test(!$hasHeader, 'DV04: header omitido quando device ausente');

// Malformado: tratado como ausente.
$captured = [];
$_POST = ['plan' => 'pro', 'card_token_id' => 'tokentest1234567890abcdef1234567890', 'device_id' => '!!!'];
$db = new FakeDvcPDO();
$db->queue = [dvc_stmt(dvc_user_row()), dvc_stmt(dvc_plan_row()), dvc_stmt(dvc_plan_row()), dvc_stmt([], []), dvc_stmt([], [], 1), dvc_stmt([], [], 1)];
$ctl = new SubscribeController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', dvc_transport($captured, 201, dvc_ok_body())));
ob_start();
$ctl->start();
ob_get_clean();
$hasHeader = false;
foreach ($captured['headers'] ?? [] as $h) {
    if (str_starts_with($h, 'X-meli-session-id:')) {
        $hasHeader = true;
    }
}
assert_test(http_response_code() === 200 && !$hasHeader, 'DV05: device malformado tratado como ausente');
assert_test(MercadoPagoClient::isValidDeviceSessionId($device) && !MercadoPagoClient::isValidDeviceSessionId('!!!') && !MercadoPagoClient::isValidDeviceSessionId(null), 'DV06: validador de formato do device');

// Device nunca persistido nem retornado.
$leaked = false;
foreach ($db->created as $s) {
    foreach ($s->params ?? [] as $p) {
        if ($p === '!!!') {
            $leaked = true;
        }
    }
}
assert_test(!$leaked, 'DV07: device_id nunca persistido');
assert_test(!str_contains($out2, 'MPDEVICESESSION') && !str_contains($out, $device), 'DV08: device_id nunca retornado');

echo "\n-- diagnostico seguro do /preapproval --\n";
$errBody = json_encode([
    'message' => 'CC_VAL_433 Credit card validation has failed',
    'status_detail' => 'cc_rejected_high_risk',
    'cause' => [['code' => 'CC_VAL_433', 'description' => 'Credit card validation has failed']],
]);
$log = dvc_capture_log(function () use ($errBody) {
    $c = new MercadoPagoClient('TEST_TOKEN', function () use ($errBody): array {
        return ['status' => 400, 'body' => $errBody, 'error' => ''];
    });
    try {
        $c->createPreapproval(['preapproval_plan_id' => 'p', 'reason' => 'r', 'external_reference' => 'e', 'payer_email' => 'e@e.com', 'card_token_id' => 'SECRETTOKENVALUE1234567890']);
    } catch (MercadoPagoException $e) {
    }
});
assert_test(str_contains($log, 'cause_code=CC_VAL_433'), 'DV09: cause_code registrado');
assert_test(str_contains($log, 'status_detail=cc_rejected_high_risk'), 'DV10: status_detail registrado');
assert_test(str_contains($log, 'msg=CC_VAL_433'), 'DV11: message registrada');
assert_test(!str_contains($log, 'SECRETTOKENVALUE1234567890') && !str_contains($log, 'TEST_TOKEN'), 'DV12: token/secret nunca no log');

echo "\n-- nunca logar/persistir sensiveis (estatico) --\n";
$subSrc = (string)file_get_contents($ROOT . '/src/controllers/SubscribeController.php');
$badLog = false;
foreach (explode("\n", $subSrc) as $line) {
    if (str_contains($line, 'error_log') && (str_contains($line, '$deviceId') || str_contains($line, '$cardToken'))) {
        $badLog = true;
    }
}
assert_test(!$badLog, 'DV13: device/card_token nunca em error_log do subscribe');
assert_test(!preg_match('/\$_POST\[.(cpf|document|cardNumber|cvv)/', $subSrc), 'DV14: backend nunca recebe CPF/cartao/CVV');
assert_test(str_contains($subSrc, 'mp_device='), 'DV15: log registra apenas presente yes/no');
$cliSrc = (string)file_get_contents($ROOT . '/src/services/MercadoPagoClient.php');
$cliLogClean = true;
foreach (explode("\n", $cliSrc) as $line) {
    if (str_contains($line, 'error_log') && (str_contains($line, 'accessToken') || str_contains($line, 'extraHeaders') || str_contains($line, '$body'))) {
        $cliLogClean = false;
    }
}
assert_test($cliLogClean, 'DV16: client nunca loga token/headers/body');

echo "\n-- frontend: CPF obrigatorio + security.js + CSP --\n";
$js = (string)file_get_contents($ROOT . '/public/js/subscribe.js');
$meuPlano = (string)file_get_contents($ROOT . '/public/meu_plano.php');
assert_test(str_contains($js, 'MP_DEVICE_SESSION_ID') && str_contains($js, "'&device_id='"), 'DV17: frontend coleta e envia device_id');
assert_test(str_contains($js, '[mp-device] present='), 'DV18: console registra apenas presente yes/no');
assert_test(str_contains($js, 'validCpfBasic') && str_contains($js, 'Informe um CPF'), 'DV19: CPF obrigatorio com validacao basica no form');
assert_test(str_contains($meuPlano, 'security.js') && str_contains($meuPlano, 'view="checkout"'), 'DV20: security.js oficial no checkout');
assert_test(str_contains($meuPlano, 'CPF do titular') && !str_contains($meuPlano, 'CPF do titular (opcional)'), 'DV21: CPF marcado obrigatorio');
$vercel = str_replace('\\/', '/', (string)file_get_contents($ROOT . '/vercel.json'));
assert_test(str_contains($vercel, 'https://www.mercadopago.com') && !str_contains($vercel, 'api.mercadolibre.com'), 'DV22: CSP com host oficial do security.js, sem tracks');
preg_match('/"Content-Security-Policy", "value": "([^"]+)"/', $vercel, $cspm);
$cspOnly = str_replace('\\/', '/', (string)($cspm[1] ?? ''));
assert_test($cspOnly !== '' && !str_contains($cspOnly, '*') && preg_match('/\bhttps:(?!\/\/)/', $cspOnly) !== 1, 'DV23: CSP sem wildcard');

echo "\n-- webhook: diagnostico staged em todos os 500 --\n";
$whSrc = (string)file_get_contents($ROOT . '/src/controllers/MpWebhookController.php');
foreach (['config', 'signature', 'ledger', 'api', 'link', 'sync', 'db'] as $stage) {
    assert_test(str_contains($whSrc, "diag('{$stage}'"), "DV24: stage={$stage} presente");
}
assert_test(str_contains($whSrc, 'markProcessedSafe'), 'DV25: markProcessed com try/catch nos paths ignored');
$entrySrc = (string)file_get_contents($ROOT . '/public/mercadopago_webhook.php');
assert_test(str_contains($entrySrc, 'stage=fatal') && str_contains($entrySrc, 'new MercadoPagoClient(null, null, 5, 3)'), 'DV26: entry com stage=fatal e timeout enxuto (5s/3s)');

// markProcessed falhando em path ignored => 500 (retryavel), nao 200 silencioso.
$db = new FakeDvcPDO();
$db->queue = [dvc_stmt([], [], 1), dvc_stmt([])];
$db->throwOn = 'UPDATE webhook_events SET status';
$wctl = new MpWebhookController($db, new User($db), new PlanService($db), new MercadoPagoClient('TEST_TOKEN', function (): array {
    return ['status' => 200, 'body' => '{"id":"pre_z","status":"pending","external_reference":""}', 'error' => ''];
}));
$_SESSION['user_id'] = 42;
$secret = 'whsec_test';
putenv("MERCADOPAGO_WEBHOOK_SECRET={$secret}");
$_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = $secret;
$_SERVER['MERCADOPAGO_WEBHOOK_SECRET'] = $secret;
$ts = '1704908010000';
$rid = 'req-mark';
$manifest = 'id:pre_z;request-id:' . $rid . ';ts:' . $ts . ';';
$sig = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', $manifest, $secret);
ob_start();
$wctl->handle(['method' => 'POST', 'headers' => ['x-signature' => $sig, 'x-request-id' => $rid], 'query' => ['data.id' => 'pre_z'], 'rawBody' => json_encode(['type' => 'subscription_preapproval', 'data' => ['id' => 'pre_z']])]);
ob_get_clean();
assert_test(http_response_code() === 500, 'DV27: falha no markProcessed retorna 500 (mantem retry)');

// Source-of-truth preservado: suite do webhook cobre; aqui so fumaça.
assert_test(str_contains($whSrc, 'syncProviderStatus') && str_contains($whSrc, 'getPreapproval'), 'DV28: webhook ainda consulta API e sincroniza via BillingSyncService');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

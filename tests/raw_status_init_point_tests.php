<?php
/**
 * Testes para storeInitPoint com raw_status NULL/vazio.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name\n"; $failed++; }
}

class TDB_RS
{
    public array $tables = [];
    public function prepare(string $s) { return new TS_RS($this, $s); }
    public function exec(string $s): int { return 0; }
    public function lastInsertId(): string { return '1'; }
    public function setAttribute(int $a, $v): bool { return true; }
}

class TS_RS
{
    private TDB_RS $pdo;
    private string $sql;
    public array $params = [];

    public function __construct(TDB_RS $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = strtolower($sql);
    }

    public function execute(array $params = []): bool
    {
        $this->params = $params;
        $s = $this->sql;

        if (str_starts_with(trim($s), 'update') && str_contains($s, 'subscriptions')) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as &$sub) {
                if ((int)$sub['id'] === $id) {
                    if (str_contains($s, 'raw_status') && isset($params[':init'])) {
                        $current = $sub['raw_status'] ?? '';
                        $hasInit = strpos($current, '|init:') !== false;
                        if (!$hasInit) {
                            $sub['raw_status'] = $current . '|init:' . $params[':init'];
                        }
                    }
                    if (isset($params[':status'])) $sub['status'] = $params[':status'];
                }
            }
            unset($sub);
        }
        return true;
    }

    public function fetch($mode = 0): mixed
    {
        $s = $this->sql;
        if (str_contains($s, 'from subscriptions') && isset($this->params[':id'])) {
            $id = (int)$this->params[':id'];
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['id'] === $id) return $sub;
            }
        }
        return false;
    }

    public function fetchColumn()
    {
        $s = $this->sql;
        if (str_contains($s, 'raw_status from subscriptions') && isset($this->params[':id'])) {
            $id = (int)$this->params[':id'];
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['id'] === $id) {
                    return $sub['raw_status'] ?? null;
                }
            }
        }
        return false;
    }

    public function rowCount(): int { return 1; }
}

echo "\n=== TESTES: raw_status + storeInitPoint ===\n\n";

echo "--- RS01: raw_status NULL -> init_point armazenado corretamente ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$ok = $sm->storeInitPoint(1, 'https://mp.com/checkout/pro-abc123');
assert_test($ok === true, 'RS01a: storeInitPoint retorna true com raw_status NULL');
$raw = $db->tables['subscriptions'][0]['raw_status'] ?? '';
assert_test(strpos($raw, '|init:') !== false, 'RS01b: raw_status contem |init:');
assert_test(strpos($raw, 'pro-abc123') !== false, 'RS01c: raw_status contem URL correta');
assert_test($raw === '|init:https://mp.com/checkout/pro-abc123', 'RS01d: raw_status formato = |init:URL');

echo "\n--- RS02: raw_status vazio '' -> init_point armazenado ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => '', 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$sm->storeInitPoint(1, 'https://mp.com/checkout/premium-xyz');
$raw = $db->tables['subscriptions'][0]['raw_status'] ?? '';
assert_test($raw === '|init:https://mp.com/checkout/premium-xyz', 'RS02a: raw_status=|init:URL com vazio original');

echo "\n--- RS03: raw_status='authorized' -> init_point anexado ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'active', 'raw_status' => 'authorized', 'mp_preapproval_id' => 'mp_abc', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$sm->storeInitPoint(1, 'https://mp.com/new-checkout');
$raw = $db->tables['subscriptions'][0]['raw_status'] ?? '';
assert_test($raw === 'authorized|init:https://mp.com/new-checkout', 'RS03a: raw_status=authorized|init:URL');

echo "\n--- RS04: chamada repetida -> idempotente ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$sm->storeInitPoint(1, 'https://mp.com/first');
$sm->storeInitPoint(1, 'https://mp.com/second');
$sm->storeInitPoint(1, 'https://mp.com/third');
$raw = $db->tables['subscriptions'][0]['raw_status'] ?? '';
assert_test(substr_count($raw, '|init:') === 1, 'RS04a: apenas uma ocorrencia de |init:');
assert_test(strpos($raw, 'first') !== false, 'RS04b: primeira URL preservada');
assert_test(strpos($raw, 'second') === false, 'RS04c: segunda URL NAO duplicada');
assert_test(strpos($raw, 'third') === false, 'RS04d: terceira URL NAO duplicada');

echo "\n--- RS05: getStoredInitPoint com raw_status NULL ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$stored = $sm->getStoredInitPoint(1);
assert_test($stored === null, 'RS05a: getStoredInitPoint retorna null com raw_status NULL (nenhum init point)');

echo "\n--- RS06: getStoredInitPoint com URL armazenada ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => 'pending|init:https://mp.com/checkout/abc123', 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$stored = $sm->getStoredInitPoint(1);
assert_test($stored === 'https://mp.com/checkout/abc123', 'RS06a: getStoredInitPoint retorna URL correta');

echo "\n--- RS07: storeInitPoint com raw_status contendo pipe (edge case) ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => 'pending|old-data', 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$sm->storeInitPoint(1, 'https://mp.com/new-checkout');
$raw = $db->tables['subscriptions'][0]['raw_status'] ?? '';
assert_test(strpos($raw, 'old-data') !== false, 'RS07a: dado original preservado');
assert_test(strpos($raw, '|init:') !== false, 'RS07b: |init: adicionado');
assert_test(strpos($raw, 'new-checkout') !== false, 'RS07c: nova URL presente');

echo "\n--- RS08: COALESCE no CONCAT — verifica SQL fonte ---\n";
$sourceFile = file_get_contents($ROOT . '/src/models/Subscription.php');
$needleFix = "CONCAT(COALESCE(raw_status, ''), '|init:'";
$needleBug  = "CONCAT(raw_status, '|init:'";
$fixFound   = strpos($sourceFile, $needleFix) !== false;
$bugRemoved = strpos($sourceFile, $needleBug) === false;
assert_test($fixFound, "RS08a: source contem CONCAT(COALESCE) para evitar NULL");
assert_test($bugRemoved, "RS08b: source NAO contem CONCAT(raw_status) desnudo");

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

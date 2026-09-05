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
    private int $rowCountReturn = 1;

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
            $changed = 0;
            foreach ($this->pdo->tables['subscriptions'] as &$sub) {
                if ((int)$sub['id'] === $id) {
                    if (str_contains($s, 'checkout_url') && isset($params[':init'])) {
                        if (($sub['checkout_url'] ?? null) === null) {
                            $sub['checkout_url'] = $params[':init'];
                            $changed = 1;
                        }
                    }
                    if (isset($params[':raw_status'])) $sub['raw_status'] = $params[':raw_status'];
                    if (isset($params[':status'])) $sub['status'] = $params[':status'];
                }
            }
            unset($sub);
            $this->rowCountReturn = $changed;
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
        if (str_contains($s, 'checkout_url') && isset($this->params[':id'])) {
            $id = (int)$this->params[':id'];
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['id'] === $id) {
                    return $sub['checkout_url'] ?? null;
                }
            }
        }
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

    public function rowCount(): int { return $this->rowCountReturn; }
}

echo "\n=== TESTES: checkout_url + storeInitPoint ===\n\n";

echo "--- RS01: checkout_url NULL -> init_point armazenado corretamente ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'checkout_url' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$ok = $sm->storeInitPoint(1, 'https://mp.com/checkout/pro-abc123');
assert_test($ok === true, 'RS01a: storeInitPoint retorna true com checkout_url NULL');
$checkout = $db->tables['subscriptions'][0]['checkout_url'] ?? '';
assert_test($checkout === 'https://mp.com/checkout/pro-abc123', 'RS01b: checkout_url = URL completa');
$raw = $db->tables['subscriptions'][0]['raw_status'] ?? null;
assert_test($raw === null, 'RS01c: raw_status NAO modificado (preserva NULL)');

echo "\n--- RS02: checkout_url ja preenchido -> no-op (idempotente) ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'checkout_url' => 'https://mp.com/old', 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$ok = $sm->storeInitPoint(1, 'https://mp.com/new');
assert_test($ok === false, 'RS02a: storeInitPoint retorna false (no-op)');
assert_test($db->tables['subscriptions'][0]['checkout_url'] === 'https://mp.com/old', 'RS02b: checkout_url original preservado');

echo "\n--- RS03: raw_status preservado apos storeInitPoint ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'active', 'raw_status' => 'authorized', 'checkout_url' => null, 'mp_preapproval_id' => 'mp_abc', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$sm->storeInitPoint(1, 'https://mp.com/new-checkout');
assert_test($db->tables['subscriptions'][0]['raw_status'] === 'authorized', 'RS03a: raw_status=authorized preservado');
assert_test($db->tables['subscriptions'][0]['checkout_url'] === 'https://mp.com/new-checkout', 'RS03b: checkout_url = URL');

echo "\n--- RS04: chamada repetida -> idempotente (segunda nao sobrescreve) ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'checkout_url' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$ok1 = $sm->storeInitPoint(1, 'https://mp.com/first');
$ok2 = $sm->storeInitPoint(1, 'https://mp.com/second');
$ok3 = $sm->storeInitPoint(1, 'https://mp.com/third');
assert_test($ok1 === true, 'RS04a: 1a chamada gravou');
assert_test($ok2 === false, 'RS04b: 2a chamada no-op');
assert_test($ok3 === false, 'RS04c: 3a chamada no-op');
assert_test($db->tables['subscriptions'][0]['checkout_url'] === 'https://mp.com/first', 'RS04d: checkout_url = URL da 1a');

echo "\n--- RS05: getStoredInitPoint com ambos NULL ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'checkout_url' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$stored = $sm->getStoredInitPoint(1);
assert_test($stored === null, 'RS05a: getStoredInitPoint retorna null com ambos NULL');

echo "\n--- RS06: getStoredInitPoint com checkout_url populado ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => null, 'checkout_url' => 'https://mp.com/checkout/abc123', 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$stored = $sm->getStoredInitPoint(1);
assert_test($stored === 'https://mp.com/checkout/abc123', 'RS06a: getStoredInitPoint retorna checkout_url');

echo "\n--- RS07: getStoredInitPoint com fallback legacy (raw_status |init:) ---\n";
$db = new TDB_RS();
$db->tables['subscriptions'] = [
    ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
     'status' => 'pending', 'raw_status' => 'authorized|init:https://mp.com/legacy', 'checkout_url' => null, 'mp_preapproval_id' => '', 'external_reference' => 'user_1_pro'],
];
$sm = new Subscription($db);
$stored = $sm->getStoredInitPoint(1);
assert_test($stored === 'https://mp.com/legacy', 'RS07a: fallback extrai URL de raw_status legacy');

echo "\n--- RS08: checkout_url — verifica SQL fonte ---\n";
$sourceFile = file_get_contents($ROOT . '/src/models/Subscription.php');
preg_match('/public function storeInitPoint.*?^\s{4}\}/sm', $sourceFile, $storeMethod);
$methodSrc = $storeMethod[0] ?? '';
assert_test(
    strpos($sourceFile, 'checkout_url') !== false,
    "RS08a: storeInitPoint usa coluna checkout_url"
);
assert_test(
    strpos($sourceFile, 'SET checkout_url = :init') !== false,
    "RS08b: storeInitPoint usa SET checkout_url = :init"
);
assert_test(
    strpos($methodSrc, '|init:') === false,
    "RS08c: storeInitPoint NAO usa |init:"
);
assert_test(
    strpos($methodSrc, 'raw_status') === false,
    "RS08d: storeInitPoint NAO modifica raw_status"
);

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

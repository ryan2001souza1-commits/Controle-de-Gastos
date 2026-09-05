<?php
/**
 * TESTES DE REGRESSAO PARA Subscription::storeInitPoint() + checkout_url
 *
 * Corrigido: storeInitPoint agora usa checkout_url (TEXT) em vez de raw_status (VARCHAR(40)).
 * getStoredInitPoint prioriza checkout_url com fallback para raw_status legado.
 *
 * T1-T14: storeInitPoint/getStoredInitPoint isolados
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/models/User.php';
require_once $ROOT . '/src/models/Plan.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

$passed = 0; $failed = 0;
function pass(string $n): void { global $passed; $passed++; echo "  \033[32m✓\033[0m $n\n"; }
function fail(string $n, string $d = ''): void { global $failed; $failed++; echo "  \033[31m✗\033[0m $n" . ($d ? " [$d]" : "") . "\n"; }

function emptySub(int $id, int $uid, string $slug, ?string $raw = null, ?string $checkout = null): array {
    return [
        'id'=>$id,'user_id'=>$uid,'plan_slug'=>$slug,'status'=>'pending',
        'raw_status'=>$raw,'checkout_url'=>$checkout,'mp_preapproval_id'=>'','external_reference'=>"user_{$uid}_{$slug}",
        'next_billing_date'=>null,'grace_period_end'=>null,'start_date'=>null,
        'cancelled_at'=>null,'paused_at'=>null,'expired_at'=>null,
    ];
}

/** Cria mock PDO que retorna rowCount real baseado em mudanca de estado */
function makeMockDb(array $subscriptions = [], array $usuarios = []): object {
    return new class($subscriptions, $usuarios) {
        public array $queries = [];
        public array $params = [];
        public array $tables;
        public function __construct(array $subs, array $us) {
            $this->tables = ['subscriptions' => $subs, 'usuarios' => $us];
        }
        public function prepare(string $sql) {
            return new class($this, $sql) {
                private $pdo; private $sql; private $result = null; private int $rc = 1;
                public function __construct($p, $s) { $this->pdo = $p; $this->sql = $s; }
                public function execute(array $params = []): bool {
                    $this->pdo->queries[] = $this->sql;
                    $this->pdo->params[] = $params;
                    $s = strtolower(trim($this->sql));

                    if (str_starts_with($s, 'update') && str_contains($s, 'subscriptions') && str_contains($s, 'checkout_url')) {
                        $id = (int)($params[':id'] ?? 0);
                        if (!isset($this->pdo->tables['subscriptions'][$id])) { $this->rc = 0; return true; }
                        $cur = $this->pdo->tables['subscriptions'][$id]['checkout_url'] ?? null;
                        if ($cur !== null) { $this->rc = 0; return true; }
                        $this->pdo->tables['subscriptions'][$id]['checkout_url'] = $params[':init'] ?? '';
                        return true;
                    }
                    if (str_starts_with($s, 'update') && str_contains($s, 'subscriptions') && str_contains($s, 'raw_status') && !str_contains($s, 'checkout_url')) {
                        $id = (int)($params[':id'] ?? 0);
                        if (isset($this->pdo->tables['subscriptions'][$id])) {
                            $changed = 0;
                            if (isset($params[':raw_status']) && $this->pdo->tables['subscriptions'][$id]['raw_status'] !== $params[':raw_status']) { $this->pdo->tables['subscriptions'][$id]['raw_status'] = $params[':raw_status']; $changed = 1; }
                            if (isset($params[':status']) && $this->pdo->tables['subscriptions'][$id]['status'] !== $params[':status']) { $this->pdo->tables['subscriptions'][$id]['status'] = $params[':status']; $changed = 1; }
                            $this->rc = $changed;
                        } else { $this->rc = 0; }
                        return true;
                    }
                    if (str_starts_with($s, 'update') && str_contains($s, 'usuarios')) {
                        $uid = (int)($params[':uid'] ?? $params[':user_id'] ?? 0);
                        foreach ($this->pdo->tables['usuarios'] as &$u) {
                            if ((int)$u['id'] === $uid) {
                                if (isset($params[':plan'])) $u['plano'] = $params[':plan'];
                                if (isset($params[':plano'])) $u['plano'] = $params[':plano'];
                                if (str_contains($s, "'ativo'")) $u['plano_status'] = 'ativo';
                                if (str_contains($s, 'active_subscription_id')) {
                                    $u['active_subscription_id'] = isset($params[':sub_id']) ? (int)$params[':sub_id'] : null;
                                }
                            }
                        } unset($u);
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'checkout_url') && isset($params[':id']) && !str_contains($s, 'from usuarios')) {
                        $id = (int)$params[':id'];
                        $url = $this->pdo->tables['subscriptions'][$id]['checkout_url'] ?? null;
                        $this->result = new class($url) { private $v; public function __construct($v){$this->v=$v;} public function fetch(){return $this->v;} public function fetchColumn(){return $this->v;} public function fetchAll(){return [$this->v];} public function rowCount(){return $this->v===null?0:1;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'raw_status') && isset($params[':id']) && !str_contains($s, 'active_subscription_id') && !str_contains($s, 'from usuarios')) {
                        $id = (int)$params[':id'];
                        $raw = $this->pdo->tables['subscriptions'][$id]['raw_status'] ?? null;
                        $this->result = new class($raw) { private $v; public function __construct($v){$this->v=$v;} public function fetch(){return $this->v;} public function fetchColumn(){return $this->v;} public function fetchAll(){return [$this->v];} public function rowCount(){return $this->v===null?0:1;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'from subscriptions') && isset($params[':uid']) && !isset($params[':id']) && !isset($params[':slug'])) {
                        $row = null;
                        foreach ($this->pdo->tables['subscriptions'] as $sub) {
                            if ((int)$sub['user_id'] === (int)$params[':uid'] && in_array($sub['status'], ['pending','active','paused'], true)) { $row = $sub; break; }
                        }
                        $this->result = new class($row) { private $r; public function __construct($r){$this->r=$r;} public function fetch(){return $this->r;} public function fetchColumn(){return $this->r['id']??null;} public function fetchAll(){return $this->r?[$this->r]:[];} public function rowCount(){return $this->r?1:0;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'from subscriptions') && isset($params[':id'])) {
                        $id = (int)$params[':id'];
                        $row = $this->pdo->tables['subscriptions'][$id] ?? null;
                        $this->result = new class($row) { private $r; public function __construct($r){$this->r=$r;} public function fetch(){return $this->r;} public function fetchColumn(){return $this->r['id']??null;} public function fetchAll(){return $this->r?[$this->r]:[];} public function rowCount(){return $this->r?1:0;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'from subscriptions') && isset($params[':uid']) && isset($params[':slug'])) {
                        $row = null;
                        foreach ($this->pdo->tables['subscriptions'] as $sub) {
                            if ((int)$sub['user_id'] === (int)$params[':uid'] && $sub['plan_slug'] === $params[':slug'] && in_array($sub['status'], ['pending','active','paused'], true)) { $row = $sub; break; }
                        }
                        $this->result = new class($row) { private $r; public function __construct($r){$this->r=$r;} public function fetch(){return $this->r;} public function fetchColumn(){return $this->r['id']??null;} public function fetchAll(){return $this->r?[$this->r]:[];} public function rowCount(){return $this->r?1:0;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'from subscriptions') && isset($params[':uid']) && !isset($params[':slug'])) {
                        $row = null;
                        foreach ($this->pdo->tables['subscriptions'] as $sub) {
                            if ((int)$sub['user_id'] === (int)$params[':uid'] && in_array($sub['status'], ['active','paused'], true) && ($sub['mp_preapproval_id'] ?? '') !== '') { $row = $sub; break; }
                        }
                        $this->result = new class($row) { private $r; public function __construct($r){$this->r=$r;} public function fetch(){return $this->r;} public function fetchColumn(){return $this->r['id']??null;} public function fetchAll(){return $this->r?[$this->r]:[];} public function rowCount(){return $this->r?1:0;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'active_subscription_id') && isset($params[':uid'])) {
                        $u = $this->pdo->tables['usuarios'][(int)$params[':uid']] ?? null;
                        $val = $u['active_subscription_id'] ?? null;
                        $this->result = new class($val) { private $v; public function __construct($v){$this->v=$v;} public function fetch(){return $this->v;} public function fetchColumn(){return $this->v;} public function fetchAll(){return [$this->v];} public function rowCount(){return $this->v===null?0:1;} };
                        return true;
                    }
                    if (str_starts_with($s, 'insert') && str_contains($s, 'subscriptions')) {
                        $uid = (int)($params[':user_id'] ?? 0);
                        $slug = $params[':plan_slug'] ?? '';
                        $status = $params[':status'] ?? 'pending';
                        $raw = $params[':raw_status'] ?? '';
                        $extRef = $params[':external_reference'] ?? '';
                        $nextId = count($this->pdo->tables['subscriptions']) > 0 ? max(array_keys($this->pdo->tables['subscriptions'])) + 1 : 1;
                        $this->pdo->tables['subscriptions'][$nextId] = [
                            'id'=>$nextId,'user_id'=>$uid,'plan_slug'=>$slug,'status'=>$status,
                            'raw_status'=>$raw,'checkout_url'=>null,'mp_preapproval_id'=>'','external_reference'=>$extRef,
                            'next_billing_date'=>null,'grace_period_end'=>null,'start_date'=>null,
                            'cancelled_at'=>null,'paused_at'=>null,'expired_at'=>null,
                        ];
                        $this->result = new class($nextId) { private $v; public function __construct($v){$this->v=$v;} public function fetch(){return ['id'=>$this->v];} public function fetchColumn(){return $this->v;} public function fetchAll(){return [['id'=>$this->v]];} public function rowCount(){return 1;} };
                        return true;
                    }
                    $this->result = new class(null) { public function fetch(){return null;} public function fetchColumn(){return null;} public function fetchAll(){return [];} public function rowCount(){return 0;} };
                    return true;
                }
                public function fetchColumn() { return $this->result ? $this->result->fetchColumn() : null; }
                public function fetch(int $mode = 5) { return $this->result ? $this->result->fetch() : null; }
                public function fetchAll(): array { return $this->result ? $this->result->fetchAll() : []; }
                public function rowCount(): int { return $this->rc; }
                public function bindValue($k, $v, $t = null) {}
                public function bindParam($k, &$v, $t = null) {}
                public function setAttribute(int $a, $v) { return true; }
            };
        }
        public function exec(string $s) { return 0; }
        public function lastInsertId() { return '0'; }
        public function setAttribute(int $a, $v) { return true; }
        public function query(string $s) { return $this->prepare($s); }
    };
}

// =============================================================================
// T1: storeInitPoint com checkout_url=NULL — URL pequena
// =============================================================================
echo "\n=== T1: storeInitPoint checkout_url=NULL, URL pequena ===\n";
$db1 = makeMockDb([4 => emptySub(4, 1, 'pro', null, null)]);
$url1 = 'https://mp.com/checkout/abc123';

$sub1 = new Subscription($db1);
$ok1 = $sub1->storeInitPoint(4, $url1);

$sql1 = $db1->queries[0] ?? '';
$params1 = $db1->params[0] ?? [];
$checkout1 = $db1->tables['subscriptions'][4]['checkout_url'] ?? null;
$raw1 = $db1->tables['subscriptions'][4]['raw_status'] ?? null;

(str_contains($sql1, 'checkout_url')) ? pass("T1a: SQL usa checkout_url") : fail("T1a", $sql1);
(!str_contains($sql1, 'raw_status')) ? pass("T1b: SQL NAO toca raw_status") : fail("T1b");
(!str_contains($sql1, 'CONCAT')) ? pass("T1c: SQL NAO usa CONCAT") : fail("T1c");
($checkout1 === $url1) ? pass("T1d: checkout_url = URL") : fail("T1d", $checkout1);
($raw1 === null) ? pass("T1e: raw_status NAO modificado") : fail("T1e", $raw1);
($ok1 === true) ? pass("T1f: rowCount > 0") : fail("T1f", var_export($ok1,true));

// =============================================================================
// T2: storeInitPoint com checkout_url ja preenchido
// =============================================================================
echo "\n=== T2: storeInitPoint checkout_url ja existe — idempotente ===\n";
$db2 = makeMockDb([5 => emptySub(5, 1, 'premium', 'authorized', 'https://mp.com/old')]);
$url2 = 'https://mp.com/new';

$sub2 = new Subscription($db2);
$ok2 = $sub2->storeInitPoint(5, $url2);
$checkout2 = $db2->tables['subscriptions'][5]['checkout_url'] ?? null;

($ok2 === false) ? pass("T2a: rowCount=0 (no-op)") : fail("T2a", var_export($ok2,true));
($checkout2 === 'https://mp.com/old') ? pass("T2b: checkout_url original preservado") : fail("T2b", $checkout2);

// =============================================================================
// T3: URL > 50 chars
// =============================================================================
echo "\n=== T3: URL > 50 chars ===\n";
$db3 = makeMockDb([6 => emptySub(6, 2, 'pro', null, null)]);
$url3 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=abc123def456';

$sub3 = new Subscription($db3);
$sub3->storeInitPoint(6, $url3);
$checkout3 = $db3->tables['subscriptions'][6]['checkout_url'] ?? null;

(strlen($url3) > 50) ? pass("T3a: URL > 50 chars") : fail("T3a", strlen($url3));
($checkout3 === $url3) ? pass("T3b: URL longa armazenada integralmente") : fail("T3b", $checkout3);

// =============================================================================
// T4: URL > 500 chars
// =============================================================================
echo "\n=== T4: URL > 500 chars ===\n";
$db4 = makeMockDb([7 => emptySub(7, 3, 'premium', null, null)]);
$url4 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=abc123def456ghi789jkl012mno345pqr678stu901vwx234yz567abc890def123ghi456jkl789mno012pqr345stu678vwx901yz234abc567def890ghi123jkl456mno789pqr012stu345vwx678yz901abc234def567ghi890jkl123mno456pqr789stu012vwx345yz678abc901def234ghi567jkl890mno123pqr456stu789vwx012yz345abc678def901ghi234jkl567mno890pqr123stu456vwx789yz012abc345def678ghi901jkl234mno567pqr890stu123vwx456yz789abc012def345ghi678jkl901mno234pqr567stu890vwx123yz456abc789def012ghi345jkl678mno901pqr234stu567vwx890yz123abc456def789ghi012jkl345mno678pqr901stu234vwx567yz890abc123def456ghi789jkl012mno345pqr678stu901vwx234yz567abc890def123ghi456jkl789mno012pqr345stu678vwx901yz234abc567def890ghi123jkl456mno789pqr012stu345vwx678yz901abc234def567ghi890jkl123mno456pqr789stu012vwx345yz678abc901def234ghi567jkl890mno123pqr456stu789vwx012yz345abc678def901ghi234jkl567mno890pqr123stu456vwx789yz012abc345def678ghi901jkl234mno567pqr890stu123vwx456yz789abc012def345ghi678jkl901mno234pqr567stu890vwx123yz456&back_url=https%3A%2F%2Fmyapp.com%2Freturn%3Fid%3D123%26ref%3Duser_3_premium%26plan%3Dpremium%26ts%3D1700000000';

$sub4 = new Subscription($db4);
$sub4->storeInitPoint(7, $url4);
$checkout4 = $db4->tables['subscriptions'][7]['checkout_url'] ?? null;

(strlen($url4) > 500) ? pass("T4a: URL > 500 chars (" . strlen($url4) . ")") : fail("T4a", strlen($url4));
($checkout4 === $url4) ? pass("T4b: URL > 500 chars armazenada integralmente") : fail("T4b", substr($checkout4 ?? '',0,80));

// =============================================================================
// T5: URL com ?, &, = e percent-encoding
// =============================================================================
echo "\n=== T5: URL com caracteres especiais ===\n";
$db5 = makeMockDb([8 => emptySub(8, 4, 'pro', null, null)]);
$url5 = 'https://mp.com/checkout?a=1&b=2&c=%2F%3A%20&d=%C3%A9%C3%A0';

$sub5 = new Subscription($db5);
$sub5->storeInitPoint(8, $url5);
$checkout5 = $db5->tables['subscriptions'][8]['checkout_url'] ?? null;

($checkout5 === $url5) ? pass("T5a: URL especial preservada") : fail("T5a", $checkout5);
(strpos($checkout5, '%2F') !== false) ? pass("T5b: %2F preservado") : fail("T5b");
(strpos($checkout5, '%3A') !== false) ? pass("T5c: %3A preservado") : fail("T5c");

// =============================================================================
// T6: 3 chamadas — apenas 1a grava
// =============================================================================
echo "\n=== T6: 3 chamadas — idempotencia ===\n";
$db6 = makeMockDb([9 => emptySub(9, 5, 'premium', null, null)]);
$url6a = 'https://mp.com/first';
$url6b = 'https://mp.com/second';
$url6c = 'https://mp.com/third';

$sub6 = new Subscription($db6);
$ok6a = $sub6->storeInitPoint(9, $url6a);
$ok6b = $sub6->storeInitPoint(9, $url6b);
$ok6c = $sub6->storeInitPoint(9, $url6c);
$checkout6 = $db6->tables['subscriptions'][9]['checkout_url'] ?? null;

($ok6a === true) ? pass("T6a: 1a chamada gravou") : fail("T6a", var_export($ok6a,true));
($ok6b === false) ? pass("T6b: 2a chamada no-op") : fail("T6b", var_export($ok6b,true));
($ok6c === false) ? pass("T6c: 3a chamada no-op") : fail("T6c", var_export($ok6c,true));
($checkout6 === $url6a) ? pass("T6d: checkout_url = URL da 1a chamada") : fail("T6d", $checkout6);

// =============================================================================
// T7: getStoredInitPoint com checkout_url populated
// =============================================================================
echo "\n=== T7: getStoredInitPoint — checkout_url populated ===\n";
$db7 = makeMockDb([10 => emptySub(10, 6, 'pro', 'authorized', 'https://mp.com/stored')]);
$sub7 = new Subscription($db7);
$stored7 = $sub7->getStoredInitPoint(10);

($stored7 === 'https://mp.com/stored') ? pass("T7a: getStoredInitPoint retorna checkout_url") : fail("T7a", $stored7 ?? 'null');
(count($db7->queries) >= 1) ? pass("T7b: query executada") : fail("T7b");

// =============================================================================
// T8: getStoredInitPoint — fallback legacy em raw_status
// =============================================================================
echo "\n=== T8: getStoredInitPoint — fallback legacy ===\n";
$db8 = makeMockDb([11 => emptySub(11, 7, 'premium', 'authorized|init:https://mp.com/legacy', null)]);
$sub8 = new Subscription($db8);
$stored8 = $sub8->getStoredInitPoint(11);

($stored8 === 'https://mp.com/legacy') ? pass("T8a: fallback extrai URL de raw_status") : fail("T8a", $stored8 ?? 'null');
(count($db8->queries) >= 2) ? pass("T8b: fallback query executada (checkout_url null)") : fail("T8b", count($db8->queries));

// =============================================================================
// T9: getStoredInitPoint — raw_status sem |init:
// =============================================================================
echo "\n=== T9: getStoredInitPoint — raw_status sem |init: ===\n";
$db9 = makeMockDb([12 => emptySub(12, 8, 'pro', 'authorized', null)]);
$sub9 = new Subscription($db9);
$stored9 = $sub9->getStoredInitPoint(12);

($stored9 === null) ? pass("T9a: retorna null quando sem checkout_url e sem |init:") : fail("T9a", $stored9 ?? 'null');

// =============================================================================
// T10: getStoredInitPoint — ambos NULL
// =============================================================================
echo "\n=== T10: getStoredInitPoint — checkout_url NULL, raw_status NULL ===\n";
$db10 = makeMockDb([13 => emptySub(13, 9, 'premium', null, null)]);
$sub10 = new Subscription($db10);
$stored10 = $sub10->getStoredInitPoint(13);

($stored10 === null) ? pass("T10a: retorna null quando ambos NULL") : fail("T10a", $stored10 ?? 'null');

// =============================================================================
// T11: storeInitPoint NAO escreve em raw_status
// =============================================================================
echo "\n=== T11: storeInitPoint nunca escreve |init: em raw_status ===\n";
$db11 = makeMockDb([14 => emptySub(14, 10, 'pro', 'authorized', null)]);
$url11 = 'https://mp.com/new-checkout';

$sub11 = new Subscription($db11);
$sub11->storeInitPoint(14, $url11);
$raw11 = $db11->tables['subscriptions'][14]['raw_status'] ?? null;

($raw11 === 'authorized') ? pass("T11a: raw_status preservado (nao contem |init:)") : fail("T11a", $raw11 ?? 'null');
(strpos($raw11 ?? '', '|init:') === false) ? pass("T11b: raw_status NAO contem |init:") : fail("T11b", $raw11);

// =============================================================================
// T12: subscribe fluxo
// =============================================================================
echo "\n=== T12: action=subscribe — createPending + storeInitPoint + getStoredInitPoint ===\n";
$db12 = makeMockDb([]);
$sub12 = new Subscription($db12);
$sub12->createPending(20, 'premium', 2, 'user_20_premium');

$url12 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=sub_new_20';
$sub12->storeInitPoint(1, $url12);
$checkout12 = $db12->tables['subscriptions'][1]['checkout_url'] ?? null;
$stored12 = $sub12->getStoredInitPoint(1);

($checkout12 === $url12) ? pass("T12a: checkout_url armazenado") : fail("T12a", $checkout12);
($stored12 === $url12) ? pass("T12b: getStoredInitPoint retorna URL") : fail("T12b", $stored12);

// =============================================================================
// T13: clique duplo
// =============================================================================
echo "\n=== T13: clique duplo reutiliza checkout_url ===\n";
$db13 = makeMockDb([15 => emptySub(15, 11, 'pro', 'pending', null)]);
$url13a = 'https://mp.com/first';
$url13b = 'https://mp.com/second';

$sub13 = new Subscription($db13);
$ok13a = $sub13->storeInitPoint(15, $url13a);
$stored13a = $sub13->getStoredInitPoint(15);
$ok13b = $sub13->storeInitPoint(15, $url13b);
$stored13b = $sub13->getStoredInitPoint(15);

($ok13a === true) ? pass("T13a: 1o clique gravou") : fail("T13a", var_export($ok13a,true));
($ok13b === false) ? pass("T13b: 2o clique no-op") : fail("T13b", var_export($ok13b,true));
($stored13a === $url13a) ? pass("T13c: 1o getStoredInitPoint retorna URL correta") : fail("T13c", $stored13a);
($stored13b === $url13a) ? pass("T13d: 2o getStoredInitPoint retorna URL do 1o clique") : fail("T13d", $stored13b);

// =============================================================================
// T14: checkout_url novo > fallback legacy
// =============================================================================
echo "\n=== T14: checkout_url novo > fallback legacy ===\n";
$db14 = makeMockDb([16 => emptySub(16, 12, 'premium', 'authorized|init:https://mp.com/legacy', 'https://mp.com/new')]);
$sub14 = new Subscription($db14);
$stored14 = $sub14->getStoredInitPoint(16);

($stored14 === 'https://mp.com/new') ? pass("T14a: checkout_url novo tem prioridade") : fail("T14a", $stored14);

// =============================================================================
// RESUMO
// =============================================================================
echo "\n=== RESUMO FINAL ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

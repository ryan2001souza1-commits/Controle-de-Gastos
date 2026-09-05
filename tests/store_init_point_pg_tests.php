<?php
/**
 * TESTES DE REGRESSÃO PARA Subscription::storeInitPoint()
 *
 * SQLSTATE[42P18] em produção: CONCAT(COALESCE(raw_status,''),'|init:',:init)
 * causava "could not determine data type of parameter $1" no PostgreSQL
 * quando :init era enviado como unknown em posição variádica de concat().
 *
 * Correção: COALESCE(raw_status,'') || '|init:' || CAST(:init AS text)
 * Elimina o unknown do placeholder e tipa explicitamente como text.
 *
 * Estes testes verificam:
 * 1. S1-S8: storeInitPoint isolado (diversos raw_status e URLs)
 * 2. F1-F4: action=subscribe completo (criação + storeInitPoint)
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/models/User.php';
require_once $ROOT . '/src/models/Plan.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

/** Cria um mock PDO com tabela subscriptions inicializável */
function makeMockDb(array $subscriptions = [], array $usuarios = []): object {
    return new class($subscriptions, $usuarios) {
        public array $queries = [];
        public array $params = [];
        public array $tables;
        public function __construct(array $subs, array $us) {
            $this->tables = [
                'subscriptions' => $subs,
                'usuarios' => $us,
            ];
        }
        public function prepare(string $sql) {
            return new class($this, $sql) {
                private $pdo;
                private $sql;
                private $result = null;
                public function __construct($p, $s) { $this->pdo = $p; $this->sql = $s; }
                public function execute(array $params = []): bool {
                    $this->pdo->queries[] = $this->sql;
                    $this->pdo->params[] = $params;
                    $s = strtolower(trim($this->sql));

                    if (str_starts_with($s, 'update') && str_contains($s, 'subscriptions') && str_contains($s, 'raw_status') && !str_contains($s, '|init:')) {
                        $id = (int)($params[':id'] ?? 0);
                        if (!isset($this->pdo->tables['subscriptions'][$id])) return true;
                        if (isset($params[':raw_status'])) {
                            $this->pdo->tables['subscriptions'][$id]['raw_status'] = $params[':raw_status'];
                        }
                        if (isset($params[':status'])) {
                            $this->pdo->tables['subscriptions'][$id]['status'] = $params[':status'];
                        }
                        return true;
                    }
                    if (str_starts_with($s, 'update') && str_contains($s, 'subscriptions') && str_contains($s, 'raw_status') && str_contains($s, '|init:')) {
                        $id = (int)($params[':id'] ?? 0);
                        if (!isset($this->pdo->tables['subscriptions'][$id])) return true;
                        $raw = $params[':init'] ?? '';
                        $current = $this->pdo->tables['subscriptions'][$id]['raw_status'] ?? '';
                        $current = $current === null ? '' : $current;
                        if (strpos($current, '|init:') !== false) return true;
                        $this->pdo->tables['subscriptions'][$id]['raw_status'] = $current . '|init:' . $raw;
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
                    if (str_starts_with($s, 'select') && str_contains($s, 'raw_status') && isset($params[':id']) && !str_contains($s, 'active_subscription_id') && !str_contains($s, 'from usuarios')) {
                        $id = (int)$params[':id'];
                        $raw = $this->pdo->tables['subscriptions'][$id]['raw_status'] ?? null;
                        $this->result = new class($raw) { private $v; public function __construct($v){$this->v=$v;} public function fetch(){return $this->v;} public function fetchColumn(){return $this->v;} public function fetchAll(){return [$this->v];} public function rowCount(){return $this->v===null?0:1;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'from subscriptions') && isset($params[':uid']) && !isset($params[':id']) && !isset($params[':slug'])) {
                        $row = null;
                        foreach ($this->pdo->tables['subscriptions'] as $sub) {
                            if ((int)$sub['user_id'] === (int)$params[':uid'] && in_array($sub['status'], ['pending','active','paused'], true)) {
                                $row = $sub; break;
                            }
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
                            if ((int)$sub['user_id'] === (int)$params[':uid'] && $sub['plan_slug'] === $params[':slug'] && in_array($sub['status'], ['pending','active','paused'], true)) {
                                $row = $sub; break;
                            }
                        }
                        $this->result = new class($row) { private $r; public function __construct($r){$this->r=$r;} public function fetch(){return $this->r;} public function fetchColumn(){return $this->r['id']??null;} public function fetchAll(){return $this->r?[$this->r]:[];} public function rowCount(){return $this->r?1:0;} };
                        return true;
                    }
                    if (str_starts_with($s, 'select') && str_contains($s, 'from subscriptions') && isset($params[':uid']) && !isset($params[':slug'])) {
                        $row = null;
                        foreach ($this->pdo->tables['subscriptions'] as $sub) {
                            if ((int)$sub['user_id'] === (int)$params[':uid'] && in_array($sub['status'], ['active','paused'], true) && ($sub['mp_preapproval_id'] ?? '') !== '') {
                                $row = $sub; break;
                            }
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
                            'raw_status'=>$raw,'mp_preapproval_id'=>'','external_reference'=>$extRef,
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
                public function rowCount(): int { return $this->result ? $this->result->rowCount() : 0; }
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

$passed = 0; $failed = 0;
function pass(string $n): void { global $passed; $passed++; echo "  \033[32m✓\033[0m $n\n"; }
function fail(string $n, string $d = ''): void { global $failed; $failed++; echo "  \033[31m✗\033[0m $n" . ($d ? " [$d]" : "") . "\n"; }

function wouldFail42P18(string $sql): bool {
    if (str_contains($sql, 'CONCAT(') && preg_match('/CONCAT\([^)]+:\w+/', $sql)) return true;
    return false;
}

function emptySub(int $id, int $uid, string $slug, ?string $raw = null): array {
    return [
        'id'=>$id,'user_id'=>$uid,'plan_slug'=>$slug,'status'=>'pending',
        'raw_status'=>$raw,'mp_preapproval_id'=>'','external_reference'=>"user_{$uid}_{$slug}",
        'next_billing_date'=>null,'grace_period_end'=>null,'start_date'=>null,
        'cancelled_at'=>null,'paused_at'=>null,'expired_at'=>null,
    ];
}

// =============================================================================
// S1: storeInitPoint com raw_status NULL — REPRODUTOR DO 42P18
// =============================================================================
echo "\n=== S1: storeInitPoint raw_status=NULL (reprodutor 42P18) ===\n";
$db1 = makeMockDb([4 => emptySub(4, 1, 'pro', null)]);
$url1 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=abc123xyz&back_url=https%3A%2F%2Fapp.com%2Freturn';

$sub1 = new Subscription($db1);
$sub1->storeInitPoint(4, $url1);

$sql1 = $db1->queries[0] ?? '';
$params1 = $db1->params[0] ?? [];
$rawAfter1 = $db1->tables['subscriptions'][4]['raw_status'] ?? null;

(!wouldFail42P18($sql1)) ? pass("S1a: SQL NAO contem CONCAT problemático (42P18 corrigido)") : fail("S1a", $sql1);
(str_contains($sql1, 'CAST(:init AS text)')) ? pass("S1b: SQL contem CAST(:init AS text)") : fail("S1b", $sql1);
($params1[':init'] ?? '') === $url1 ? pass("S1c: :init recebeu URL completa") : fail("S1c", $params1[':init'] ?? 'MISSING');
(strpos($rawAfter1 ?? '', '|init:') !== false) ? pass("S1d: raw_status contém |init:") : fail("S1d", $rawAfter1 ?? 'null');
(strpos($rawAfter1 ?? '', $url1) !== false) ? pass("S1e: raw_status contém URL completa") : fail("S1e", substr($rawAfter1 ?? '', 0, 80));

// =============================================================================
// S2: storeInitPoint com raw_status vazio
// =============================================================================
echo "\n=== S2: storeInitPoint raw_status='' (vazio) ===\n";
$db2 = makeMockDb([5 => emptySub(5, 1, 'premium', '')]);
$url2 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=def456';

$sub2 = new Subscription($db2);
$sub2->storeInitPoint(5, $url2);
$rawAfter2 = $db2->tables['subscriptions'][5]['raw_status'] ?? null;

($rawAfter2 === '|init:' . $url2) ? pass("S2a: raw_status='|init:URL'") : fail("S2a", $rawAfter2);

// =============================================================================
// S3: storeInitPoint com raw_status='authorized'
// =============================================================================
echo "\n=== S3: storeInitPoint raw_status='authorized' ===\n";
$db3 = makeMockDb([6 => emptySub(6, 2, 'pro', 'authorized')]);
$url3 = 'https://mp.com/checkout/pro-long-url-abc123def456ghi789jkl012mno345pq';

$sub3 = new Subscription($db3);
$sub3->storeInitPoint(6, $url3);
$rawAfter3 = $db3->tables['subscriptions'][6]['raw_status'] ?? null;

($rawAfter3 === 'authorized|init:' . $url3) ? pass("S3a: raw_status='authorized|init:URL'") : fail("S3a", $rawAfter3);

// =============================================================================
// S4: URL longa com caracteres especiais
// =============================================================================
echo "\n=== S4: URL longa com ?, &, =, percent-encoding ===\n";
$db4 = makeMockDb([7 => emptySub(7, 3, 'premium', null)]);
$longUrl = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=abc123def456ghi789jkl012mno&back_url=https%3A%2F%2Fmyapp.com%2Freturn%3Fid%3D123%26ref%3Duser_3_premium';

$sub4 = new Subscription($db4);
$sub4->storeInitPoint(7, $longUrl);
$rawAfter4 = $db4->tables['subscriptions'][7]['raw_status'] ?? null;

(strpos($rawAfter4 ?? '', $longUrl) !== false) ? pass("S4a: URL longa preservada integralmente") : fail("S4a", substr($rawAfter4 ?? '', 0, 100));

// =============================================================================
// S5: URL com percent-encoding (%2F, %3A, %20)
// =============================================================================
echo "\n=== S5: URL com percent-encoding (%2F, %3A, %20) ===\n";
$db5 = makeMockDb([8 => emptySub(8, 4, 'pro', null)]);
$encodedUrl = 'https://mp.com/return?ref=user_4_pro&back=https%3A%2F%2Fapp.com%2Fpath%20one';

$sub5 = new Subscription($db5);
$sub5->storeInitPoint(8, $encodedUrl);
$rawAfter5 = $db5->tables['subscriptions'][8]['raw_status'] ?? null;

(strpos($rawAfter5 ?? '', $encodedUrl) !== false) ? pass("S5a: URL encoded preservada") : fail("S5a", $rawAfter5);
(strpos($rawAfter5 ?? '', '%2F') !== false) ? pass("S5b: %2F preservado") : fail("S5b", $rawAfter5);
(strpos($rawAfter5 ?? '', '%3A') !== false) ? pass("S5c: %3A preservado") : fail("S5c", $rawAfter5);

// =============================================================================
// S6: segunda chamada não duplica |init:
// =============================================================================
echo "\n=== S6: idempotência — 2ª chamada não duplica |init: ===\n";
$db6 = makeMockDb([9 => emptySub(9, 5, 'premium', null)]);
$url6a = 'https://mp.com/first';
$url6b = 'https://mp.com/second';

$sub6 = new Subscription($db6);
$sub6->storeInitPoint(9, $url6a);
$sub6->storeInitPoint(9, $url6b);
$rawAfter6 = $db6->tables['subscriptions'][9]['raw_status'] ?? null;
$countInit6 = substr_count($rawAfter6 ?? '', '|init:');

($rawAfter6 === '|init:' . $url6a) ? pass("S6a: raw_status = |init:URL da 1ª chamada") : fail("S6a", $rawAfter6);
($countInit6 === 1) ? pass("S6b: apenas 1 |init: (2ª chamada não duplicou)") : fail("S6b", "count=$countInit6");

// =============================================================================
// S7: getStoredInitPoint após storeInitPoint
// =============================================================================
echo "\n=== S7: getStoredInitPoint retorna URL exata ===\n";
$db7 = makeMockDb([10 => emptySub(10, 6, 'pro', null)]);
$url7 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=stored123';

$sub7 = new Subscription($db7);
$sub7->storeInitPoint(10, $url7);
$stored = $sub7->getStoredInitPoint(10);

($stored === $url7) ? pass("S7a: getStoredInitPoint retorna URL exata") : fail("S7a", $stored ?? 'null');

// =============================================================================
// S8: código-fonte contém CAST(:init AS text)
// =============================================================================
echo "\n=== S8: fonte contém CAST(:init AS text) ===\n";
$src = file_get_contents($ROOT . '/src/models/Subscription.php');
(str_contains($src, 'CAST(:init AS text)')) ? pass("S8a: fonte contém CAST(:init AS text)") : fail("S8a");
(!str_contains($src, 'CONCAT(COALESCE(raw_status, \'\'), \'|init:\', :init)')) ? pass("S8b: CONCAT problemático removido") : fail("S8b", "ainda presente no fonte");

// =============================================================================
// F1-F4: action=subscribe — fluxo completo
// =============================================================================
echo "\n=== F1: action=subscribe — novo usuário (sem assinatura ativa) ===\n";
$dbF1 = makeMockDb([]);
$subF1 = new Subscription($dbF1);
$resultF1 = $subF1->createPending(10, 'pro', 1, 'user_10_pro');

(substr_count(implode(' ', $dbF1->queries), '|init:') === 0) ? pass("F1a: createPending não grava |init:") : fail("F1a");
($dbF1->tables['subscriptions'][1]['raw_status'] ?? '') === 'pending' ? pass("F1b: raw_status='pending' após createPending") : fail("F1b", $dbF1->tables['subscriptions'][1]['raw_status'] ?? '');

echo "\n=== F2: action=subscribe — storeInitPoint após createPending ===\n";
$urlF2 = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=sub_new_10';
$subF2 = new Subscription($dbF1);
$subF2->storeInitPoint(1, $urlF2);
$rawF2 = $dbF1->tables['subscriptions'][1]['raw_status'] ?? null;

(!wouldFail42P18($dbF1->queries[1] ?? '')) ? pass("F2a: storeInitPoint SQL sem CONCAT problemático") : fail("F2a");
(strpos($rawF2 ?? '', $urlF2) !== false) ? pass("F2b: raw_status contém URL do Mercado Pago") : fail("F2b", $rawF2);

echo "\n=== F3: action=subscribe — upgrade (pro→premium) com cancelamento da assinatura antiga ===\n";
$dbF3 = makeMockDb([3 => emptySub(3, 5, 'pro', 'authorized')]);
$dbF3->tables['subscriptions'][3]['status'] = 'active';
$subF3 = new Subscription($dbF3);
$subF3->updateStatusById(3, Subscription::STATUS_CANCELLED, 'cancelled', null, null);
$rawF3 = $dbF3->tables['subscriptions'][3]['raw_status'] ?? null;

($rawF3 === 'cancelled') ? pass("F3a: assinatura antiga com raw_status='cancelled'") : fail("F3a", $rawF3);
(!wouldFail42P18($dbF3->queries[0] ?? '')) ? pass("F3b: updateStatusById SQL sem CONCAT problemático") : fail("F3b");

echo "\n=== F4: storeInitPoint + getStoredInitPoint integrados ===\n";
$dbF4 = makeMockDb([11 => emptySub(11, 7, 'premium', null)]);
$urlF4 = 'https://mp.com/checkout/premium-xyz-abc-123';

$subF4 = new Subscription($dbF4);
$subF4->storeInitPoint(11, $urlF4);
$retrieved = $subF4->getStoredInitPoint(11);

($retrieved === $urlF4) ? pass("F4a: store+get roundtrip preserva URL") : fail("F4a", $retrieved ?? 'null');
(strpos($dbF4->queries[0] ?? '', 'CAST(:init AS text)') !== false) ? pass("F4b: SQL corrigido com CAST(:init AS text)") : fail("F4b", $dbF4->queries[0] ?? '');

echo "\n=== RESUMO FINAL ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

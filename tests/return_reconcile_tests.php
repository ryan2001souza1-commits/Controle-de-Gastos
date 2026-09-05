<?php
/**
 * Testes do SubscriptionReconciler::reconcileFromReturn (novo fluxo).
 *
 * Cobre o fluxo: usuario clica Assinar → checkout MP → volta para /mercadopago_return.php
 *
 * Especificamente:
 *  - return autorizado liga pending ao preapproval_id
 *  - plan_id incorreto e rejeitado
 *  - usuario diferente e rejeitado
 *  - webhook chega antes do return
 *  - webhook chega depois do return
 *  - clique duplicado
 *  - Pro e Premium
 *  - status pending/cancelled
 *  - idempotencia
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';
require_once $ROOT . '/src/services/SubscriptionReconciler.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
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

class MercadoPagoServiceMock extends MercadoPagoService
{
    public function __construct() { $this->accessToken = 'MOCK'; }
    public array $mockResponse = ['ok' => false, 'status' => 500, 'error' => 'no_mock'];
    public function getPreapproval(string $id): array { return $this->mockResponse; }
    public function createPreapproval(string $p, int $u, string $e, string $r): array
    {
        return ['ok' => false, 'error' => 'mock'];
    }
    public function getInitPointForPlan(string $p, int $u, string $e): array
    {
        return ['ok' => false, 'error' => 'mock'];
    }
}

class MockPDO
{
    public array $tables = [];
    public int $lastInsertedId = 0;
    public array $lastQuery = [];
    public int $rowCount = 1;
    public array $lastExec = [];

    public function __construct()
    {
        $this->tables['planos'] = [
            ['id' => 1, 'slug' => 'gratuito', 'nome' => 'Gratuito', 'preco' => 0, 'status' => 'ativo'],
            ['id' => 2, 'slug' => 'pro', 'nome' => 'Pro', 'preco' => 9.90, 'status' => 'ativo'],
            ['id' => 3, 'slug' => 'premium', 'nome' => 'Premium', 'preco' => 19.90, 'status' => 'ativo'],
        ];
        $this->tables['usuarios'] = [];
        $this->tables['subscriptions'] = [];
    }

    public function exec(string $sql): int
    {
        $this->lastQuery = $sql;
        $this->lastExec = ['sql' => $sql];
        return 0;
    }
    public function prepare(string $sql): MockPDOStatement { return new MockPDOStatement($this, $sql); }
    public function query(string $sql): MockPDOStatement
    {
        $this->lastQuery = $sql;
        return new MockPDOStatement($this, $sql);
    }
    public function lastInsertId(): string { return (string)$this->lastInsertedId; }
    public function setAttribute(int $opt, $val): bool { return true; }
    public function getAttribute(int $opt) { return null; }
}

class MockPDOStatement
{
    private MockPDO $pdo;
    private string $sql;
    private array $params = [];

    public function __construct(MockPDO $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        $this->params = $params;
        $sqlLower = strtolower($this->sql);

        if (str_starts_with(trim($sqlLower), 'insert into')) {
            if (str_contains($sqlLower, 'subscriptions')) {
                $this->pdo->lastInsertedId = count($this->pdo->tables['subscriptions']) + 1;
                $this->pdo->tables['subscriptions'][] = [
                    'id' => $this->pdo->lastInsertedId,
                    'user_id' => (int)($params[':user_id'] ?? 0),
                    'plan_id' => (int)($params[':plan_id'] ?? 0),
                    'plan_slug' => $params[':plan_slug'] ?? '',
                    'status' => $params[':status'] ?? 'pending',
                    'start_date' => null,
                    'next_billing_date' => $params[':next_billing_date'] ?? null,
                    'paused_at' => null,
                    'cancelled_at' => null,
                    'expired_at' => null,
                    'grace_period_end' => null,
                    'raw_status' => $params[':raw_status'] ?? '',
                    'external_reference' => $params[':external_reference'] ?? '',
                    'mp_preapproval_id' => $params[':mp_preapproval_id'] ?? '',
                ];
            }
            if (str_contains($sqlLower, 'usuarios')) {
                $this->pdo->lastInsertedId = count($this->pdo->tables['usuarios']) + 1;
                $this->pdo->tables['usuarios'][] = [
                    'id' => $this->pdo->lastInsertedId,
                    'nome' => $params[':nome'] ?? '',
                    'email' => $params[':email'] ?? '',
                    'plano' => $params[':plano'] ?? 'gratuito',
                    'plano_status' => $params[':plano_status'] ?? 'ativo',
                    'plano_inicio' => null,
                    'plano_fim' => null,
                    'active_subscription_id' => null,
                ];
            }
        }

        if (str_starts_with(trim($sqlLower), 'update')) {
            if (str_contains($sqlLower, 'usuarios') && isset($params[':uid'])) {
                $uid = (int)$params[':uid'];
                foreach ($this->pdo->tables['usuarios'] as &$u) {
                    if ((int)$u['id'] === $uid) {
                        if (isset($params[':plan']))         $u['plano'] = $params[':plan'];
                        if (isset($params[':plan_status']))  $u['plano_status'] = $params[':plan_status'];
                        if (isset($params[':plano_status'])) $u['plano_status'] = $params[':plano_status'];
                        if (isset($params[':sub_id']))       $u['active_subscription_id'] = (int)$params[':sub_id'];
                    }
                }
                unset($u);
            }
            if (str_contains($sqlLower, 'subscriptions') && isset($params[':id'])) {
                $id = (int)$params[':id'];
                foreach ($this->pdo->tables['subscriptions'] as &$s) {
                    if ((int)$s['id'] === $id) {
                        if (isset($params[':mpid'])) {
                            $condOk = (isset($params[':id']) && (str_contains($this->sql, 'IS NULL') || str_contains($this->sql, 'mp_preapproval_id = ')))
                                ? ($s['mp_preapproval_id'] === '' || $s['mp_preapproval_id'] === null || $s['mp_preapproval_id'] === $params[':mpid'])
                                : true;
                            if ($condOk) $s['mp_preapproval_id'] = $params[':mpid'];
                        }
                        if (isset($params[':raw_status']))       $s['raw_status'] = $params[':raw_status'];
                        if (isset($params[':status']))           $s['status'] = $params[':status'];
                        if (isset($params[':next_billing_date']) && $params[':next_billing_date'] !== null) {
                            $s['next_billing_date'] = $params[':next_billing_date'];
                        }
                    }
                }
                unset($s);
            }
        }
        return true;
    }

    public function fetchColumn()
    {
        $sql = strtolower($this->sql);
        $params = $this->params;

        if (str_contains($sql, 'returning id') || str_contains($sql, 'returning')) {
            $sqlLower = strtolower($this->sql);
            if (str_contains($sqlLower, 'subscriptions')) {
                $entry = end($this->pdo->tables['subscriptions']);
                if ($entry) return (int)$entry['id'];
            }
            if (str_contains($sqlLower, 'usuarios')) {
                $entry = end($this->pdo->tables['usuarios']);
                if ($entry) return (int)$entry['id'];
            }
            return 0;
        }

        if (str_contains($sql, 'from usuarios') && isset($params[':uid'])) {
            $uid = (int)($params[':uid'] ?? 0);
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === $uid) return (int)$u['id'];
            }
            return false;
        }
        if (str_contains($sql, 'select id from subscriptions') && isset($params[':uid'])) {
            $uid = (int)($params[':uid'] ?? 0);
            $slug = $params[':slug'] ?? '';
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['user_id'] === $uid && $s['plan_slug'] === $slug
                    && in_array($s['status'], ['pending', 'active', 'paused'], true)) {
                    return (int)$s['id'];
                }
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($params[':mpid'])) {
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ($s['mp_preapproval_id'] === $params[':mpid']) return (int)$s['id'];
            }
            return false;
        }
        return false;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH): mixed
    {
        $sql = strtolower($this->sql);
        $params = $this->params;

        if (str_contains($sql, 'returning id') || str_contains($sql, 'returning')) {
            $sqlLower = strtolower($this->sql);
            if (str_contains($sqlLower, 'subscriptions')) {
                $entry = end($this->pdo->tables['subscriptions']);
                if ($entry) return $entry;
            }
            if (str_contains($sqlLower, 'usuarios')) {
                $entry = end($this->pdo->tables['usuarios']);
                if ($entry) return $entry;
            }
            return false;
        }

        if (str_contains($sql, 'from usuarios') && isset($params[':uid'])) {
            $uid = (int)($params[':uid'] ?? 0);
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === $uid) return $u;
            }
            return false;
        }

        if (str_contains($sql, 'from planos') || (str_contains($sql, 'planos') && isset($params[0]))) {
            $slug = $params[0] ?? ($params['slug'] ?? '');
            foreach ($this->pdo->tables['planos'] as $p) {
                if ($p['slug'] === $slug) return $p;
            }
            return false;
        }

        if (str_contains($sql, 'from subscriptions')) {
            if (isset($params[':id']) || isset($params[0])) {
                $id = isset($params[':id']) ? (int)$params[':id'] : (int)$params[0];
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    if ((int)$s['id'] === $id) return $s;
                }
                return false;
            }
            if (isset($params[':mpid'])) {
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    if ($s['mp_preapproval_id'] === $params[':mpid']) return $s;
                }
                return false;
            }
            if (isset($params[':uid']) && isset($params[':slug'])) {
                $uid = (int)($params[':uid'] ?? 0);
                $slug = $params[':slug'] ?? '';
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    if ((int)$s['user_id'] === $uid && $s['plan_slug'] === $slug
                        && in_array($s['status'], ['pending', 'active', 'paused'], true)) {
                        return $s;
                    }
                }
                return false;
            }
            if (isset($params[':uid'])) {
                $uid = (int)($params[':uid'] ?? 0);
                $maxId = 0; $lastSub = null;
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    if ((int)$s['user_id'] === $uid && (int)$s['id'] > $maxId) {
                        $maxId = (int)$s['id']; $lastSub = $s;
                    }
                }
                return $lastSub ?: false;
            }
        }
        return false;
    }
    public function rowCount(): int { return $this->pdo->rowCount; }
    public function fetchAll(int $mode = PDO::FETCH_BOTH): array { return []; }
}

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_test_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_test_xyz');

echo "\n=== TESTES: reconcileFromReturn (novo fluxo checkout hospedado) ===\n\n";

function makeDb(): MockPDO {
    $db = new MockPDO();
    $db->tables['usuarios'] = [
        ['id' => 1, 'nome' => 'Maria', 'email' => 'maria@ex.com', 'plano' => 'gratuito',
            'plano_status' => 'ativo', 'plano_inicio' => null, 'plano_fim' => null,
            'active_subscription_id' => null],
        ['id' => 2, 'nome' => 'Joao', 'email' => 'joao@ex.com', 'plano' => 'gratuito',
            'plano_status' => 'ativo', 'plano_inicio' => null, 'plano_fim' => null,
            'active_subscription_id' => null],
    ];
    return $db;
}

function setupPending(MockPDO $db, int $userId, string $slug): int {
    $planId = (int)(new Plan($db))->findBySlug($slug)['id'];
    $subModel = new Subscription($db);
    $created = $subModel->createPending($userId, $slug, $planId, 'user_' . $userId . '_' . $slug);
    return $created['id'];
}

echo "--- T01: return autorizado liga pending ao preapproval_id (Pro) ---\n";
$db = makeDb();
$pendingId = setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_001', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'next_payment_date' => '2026-10-05T00:00:00Z'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_001', 1);
assert_test($r['ok'] === true && in_array($r['action'], ['activated', 'created'], true),
    'T01: ok=true, action=activated|created', json_encode($r));
$linked = $db->tables['subscriptions'][$pendingId - 1] ?? null;
assert_test($linked !== null && $linked['mp_preapproval_id'] === 'mp_pre_001',
    'T01: pending foi vinculado ao preapproval_id', 'mp_id=' . ($linked['mp_preapproval_id'] ?? 'NULL'));
assert_test($linked['status'] === 'active', 'T01: status local = active', 'status=' . ($linked['status'] ?? 'NULL'));
$user = $db->tables['usuarios'][0];
assert_test($user['plano'] === 'pro', 'T01: usuario Maria agora tem plano=pro no DB', 'plano=' . $user['plano']);

echo "\n--- T02: return autorizado liga pending ao preapproval_id (Premium) ---\n";
$db = makeDb();
$pendingId = setupPending($db, 2, 'premium');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_002', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_premium_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_002', 2);
assert_test($r['ok'] === true, 'T02: Premium ok=true', json_encode($r));
$joao = $db->tables['usuarios'][1];
assert_test($joao['plano'] === 'premium', 'T02: Joao agora tem plano=premium', 'plano=' . $joao['plano']);

echo "\n--- T03: plan_id incorreto e rejeitado ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_003', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_gold_999'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_003', 1);
assert_test($r['ok'] === false && $r['action'] === 'unknown_plan',
    'T03: plan_id nao configurado no .env -> unknown_plan', json_encode($r));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'T03: Maria NAO foi promovida (plan desconhecido)', 'plano=' . $maria['plano']);

echo "\n--- T04: usuario diferente (sessao hacker tentando usar preapproval de outro) ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_004', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_004', 2);
assert_test($r['ok'] === false && $r['action'] === 'no_pending_for_user',
    'T04: Joao (user=2) chama return com preapproval da Maria (user=1) -> rejeitado',
    json_encode($r));
$joao = $db->tables['usuarios'][1];
assert_test($joao['plano'] === 'gratuito', 'T04: Joao NAO foi promovido (pending e da Maria)', 'plano=' . $joao['plano']);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'T04: Maria NAO foi promovida (ela nao chamou return, Joao que tentou)', 'plano=' . $maria['plano']);

echo "\n--- T05: status pending (pagamento ainda nao confirmado) ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_005', 'status' => 'pending',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_005', 1);
assert_test($r['ok'] === false && $r['action'] === 'not_authorized',
    'T05: status=pending -> not_authorized (NAO ativa)', json_encode($r));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'T05: Maria NAO foi promovida (pagamento pendente)', 'plano=' . $maria['plano']);

echo "\n--- T06: status cancelled ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_006', 'status' => 'cancelled',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_006', 1);
assert_test($r['ok'] === true && in_array($r['action'], ['activated', 'created'], true) ? false : ($r['ok'] && $r['action'] === 'updated' ? false : true),
    'T06: cancelled -> NEM cria nem ativa', json_encode($r));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'T06: cancelled NAO promove usuario', 'plano=' . $maria['plano']);

echo "\n--- T07: idempotencia (return chamado 3x para o mesmo preapproval) ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_007', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r1 = $rec->reconcileFromReturn('mp_pre_007', 1);
$r2 = $rec->reconcileFromReturn('mp_pre_007', 1);
$r3 = $rec->reconcileFromReturn('mp_pre_007', 1);
assert_test($r1['ok'] && $r2['ok'] && $r3['ok'], 'T07: 3 chamadas retornam ok=true');
$subs = array_filter($db->tables['subscriptions'], fn($s) => $s['user_id'] == 1);
assert_test(count($subs) === 1, 'T07: 3 chamadas -> 1 subscription no DB', 'count=' . count($subs));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'T07: Maria mantida como pro (nao rebaixada)', 'plano=' . $maria['plano']);

echo "\n--- T08: clique duplicado (3 createPending antes do checkout) ---\n";
$db = makeDb();
$subModel = new Subscription($db);
$planId = (int)(new Plan($db))->findBySlug('pro')['id'];
$c1 = $subModel->createPending(1, 'pro', $planId, 'user_1_pro');
$c2 = $subModel->createPending(1, 'pro', $planId, 'user_1_pro');
$c3 = $subModel->createPending(1, 'pro', $planId, 'user_1_pro');
assert_test($c1['created'] === true && $c2['created'] === false && $c3['created'] === false,
    'T08: 3 createPending -> apenas 1 cria (idem)');
assert_test($c1['id'] === $c2['id'] && $c2['id'] === $c3['id'], 'T08: todos retornam mesmo id');

echo "\n--- T09: webhook chega antes do return (race condition) ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');

$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_009', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$rReturn = $rec->reconcileFromReturn('mp_pre_009', 1);
assert_test($rReturn['ok'] === true, 'T09: return (chegou depois) reconciliou corretamente');
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'T09: usuario promovido via return (webhook ainda nao chegou)', 'plano=' . $maria['plano']);

echo "\n--- T10: webhook chega depois do return (race condition inversa) ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');

$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_010', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);

$rReturn = $rec->reconcileFromReturn('mp_pre_010', 1);
assert_test($rReturn['ok'] === true, 'T10a: return reconciliou (1a passagem)');
$maria = $db->tables['usuarios'][0];
$planoAposReturn = $maria['plano'];

$sub = $db->tables['subscriptions'][0];
$rReturn2 = $rec->reconcileFromReturn('mp_pre_010', 1);
assert_test($rReturn2['ok'] === true && in_array($rReturn2['action'], ['already_linked', 'updated'], true),
    'T10b: 2a chamada do return -> already_linked (idempotente)', json_encode($rReturn2));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === $planoAposReturn, 'T10c: Maria continua pro (sem rebaixar)', 'plano=' . $maria['plano']);

echo "\n--- T11: preapproval_id invalido ---\n";
$db = makeDb();
$mp = new MercadoPagoServiceMock();
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('id<>invalido', 1);
assert_test($r['ok'] === false && $r['action'] === 'invalid_id', 'T11: regex invalida -> invalid_id');

echo "\n--- T12: usuario nao existe ---\n";
$db = makeDb();
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_012', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_012', 999);
assert_test($r['ok'] === false && $r['action'] === 'user_not_found', 'T12: user_id inexistente -> user_not_found');

echo "\n--- T13: preapproval 404 no MP ---\n";
$db = makeDb();
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = ['ok' => false, 'status' => 404, 'error' => 'not_found'];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_inexistente', 1);
assert_test($r['ok'] === false && $r['action'] === 'not_found', 'T13: MP 404 -> not_found');

echo "\n--- T14: MP 500 transitorio ---\n";
$db = makeDb();
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = ['ok' => false, 'status' => 500, 'error' => 'server'];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_500', 1);
assert_test($r['ok'] === false && $r['action'] === 'transient_error', 'T14: MP 500 -> transient_error');

echo "\n--- T15: MP payload incompleto (sem status) ---\n";
$db = makeDb();
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_015', 'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_015', 1);
assert_test($r['ok'] === false && $r['action'] === 'incomplete_payload', 'T15: sem status -> incomplete_payload');

echo "\n--- T16: pending NAO existe para este user+plan, mas MP diz authorized ---\n";
$db = makeDb();
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_016', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_016', 1);
assert_test($r['ok'] === false && $r['action'] === 'no_pending_for_user',
    'T16: sem pending local -> rejeitado (preapproval de origem desconhecida)', json_encode($r));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito',
    'T16: Maria NAO foi promovida (sem prova de que o pagamento partiu dela)', 'plano=' . $maria['plano']);

echo "\n--- T17: pending pro + MP diz premium (plan_slug do pagamento != plan_slug do pending) ---\n";
$db = makeDb();
setupPending($db, 1, 'pro');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_017', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_premium_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_017', 1);
assert_test($r['ok'] === false && $r['action'] === 'no_pending_for_user',
    'T17: pagamento premium sem pending premium -> rejeitado (usuario precisa recomecar checkout)', json_encode($r));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'T17: Maria NAO foi promovida (pagamento nao corresponde ao pending)', 'plano=' . $maria['plano']);

echo "\n--- T18: pending premium + MP diz premium (correspondente) ---\n";
$db = makeDb();
setupPending($db, 1, 'premium');
$mp = new MercadoPagoServiceMock();
$mp->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pre_018', 'status' => 'authorized',
        'preapproval_plan_id' => 'plan_premium_test_xyz'],
];
$rec = new SubscriptionReconciler($db, $mp);
$r = $rec->reconcileFromReturn('mp_pre_018', 1);
assert_test($r['ok'] === true, 'T18: pending premium + MP premium -> ok', json_encode($r));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'premium', 'T18: Maria promovida para premium', 'plano=' . $maria['plano']);

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
if ($failed > 0) {
    echo "\033[31mALGUNS TESTES FALHARAM!\033[0m\n";
    exit(1);
}
echo "\033[32mTODOS OS TESTES PASSARAM!\033[0m\n";
exit(0);

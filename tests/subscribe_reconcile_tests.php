<?php
/**
 * Testes do SubscriptionReconciler + MercadoPagoService::createPreapproval.
 *
 * Cobre:
 *  - external_reference correto (Pro e Premium)
 *  - usuario errado (ataque)
 *  - plan_id inconsistente
 *  - clique duplicado
 *  - webhook autorizado atualizando Free -> Pro/Premium
 *
 * Usa mocks em memoria para nao depender de rede, banco de dados real
 * ou credenciais. A unica dependencia e o codigo PHP real (classes existentes).
 *
 * Para teste de ponta-a-ponta com a API real:
 *   php tests/subscribe_reconcile_tests.php --live
 */

$ROOT = dirname(__DIR__);

$envFile = $ROOT . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (getenv($k) === false || getenv($k) === '') {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';
require_once $ROOT . '/src/services/SubscriptionReconciler.php';

$passed = 0;
$failed = 0;
$skipped = 0;

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

function skip(string $name, string $reason): void
{
    global $skipped;
    echo "  \033[33m⊘\033[0m $name (SKIP: $reason)\n";
    $skipped++;
}

/**
 * Mock de PDO que implementa apenas os metodos usados pelo reconciler.
 * Usa arrays em memoria para os dados.
 */
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
        $this->tables['usuarios'] = [
            ['id' => 1, 'nome' => 'Maria', 'email' => 'maria@ex.com', 'plano' => 'gratuito',
                'plano_status' => 'ativo', 'plano_inicio' => null, 'plano_fim' => null,
                'active_subscription_id' => null],
            ['id' => 2, 'nome' => 'Joao', 'email' => 'joao@ex.com', 'plano' => 'gratuito',
                'plano_status' => 'ativo', 'plano_inicio' => null, 'plano_fim' => null,
                'active_subscription_id' => null],
        ];
        $this->tables['subscriptions'] = [];
    }

    public function exec(string $sql): int
    {
        $this->lastQuery = $sql;
        $this->lastExec = ['sql' => $sql];
        return 0;
    }

    public function prepare(string $sql): MockPDOStatement
    {
        return new MockPDOStatement($this, $sql);
    }
    public function query(string $sql): MockPDOStatement
    {
        $this->lastQuery = $sql;
        return new MockPDOStatement($this, $sql);
    }

    public function lastInsertId(): string
    {
        return (string)$this->lastInsertedId;
    }

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
                $newId = (int)$this->pdo->lastInsertId();
                $this->pdo->tables['subscriptions'][] = [
                    'id' => $newId,
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
                $newId = (int)$this->pdo->lastInsertId();
                $this->pdo->tables['usuarios'][] = [
                    'id' => $newId,
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
                        if (isset($params[':plan']))           $u['plano'] = $params[':plan'];
                        if (isset($params[':plan_status']))    $u['plano_status'] = $params[':plan_status'];
                        if (isset($params[':plano_status']))   $u['plano_status'] = $params[':plano_status'];
                        if (isset($params[':sub_id']))         $u['active_subscription_id'] = (int)$params[':sub_id'];
                    }
                }
                unset($u);
            }
            if (str_contains($sqlLower, 'subscriptions') && isset($params[':id'])) {
                $id = (int)$params[':id'];
                foreach ($this->pdo->tables['subscriptions'] as &$s) {
                    if ((int)$s['id'] === $id) {
                        if (isset($params[':mpid']))        $s['mp_preapproval_id'] = $params[':mpid'];
                        if (isset($params[':raw_status']))  $s['raw_status'] = $params[':raw_status'];
                        if (isset($params[':status']))      $s['status'] = $params[':status'];
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
            if (str_contains($sqlLower = strtolower($this->sql), 'subscriptions')) {
                $entry = end($this->pdo->tables['subscriptions']);
                if ($entry) return (int)$entry['id'];
            }
            if (str_contains($sqlLower, 'usuarios')) {
                $entry = end($this->pdo->tables['usuarios']);
                if ($entry) return (int)$entry['id'];
            }
            return $this->pdo->lastInsertedId;
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

    public function fetch(int $mode = PDO::FETCH_BOTH)
    {
        $sql = strtolower($this->sql);
        $params = $this->params;

        if (str_contains($sql, 'returning id') || str_contains($sql, 'returning')) {
            return ['id' => $this->pdo->lastInsertId()];
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
            if (isset($params[':id'])) {
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    if ((int)$s['id'] === (int)$params[':id']) return $s;
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
                $maxId = 0;
                $lastSub = null;
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    if ((int)$s['user_id'] === $uid && (int)$s['id'] > $maxId) {
                        $maxId = (int)$s['id'];
                        $lastSub = $s;
                    }
                }
                return $lastSub ?: false;
            }
        }

        return false;
    }

    public function rowCount(): int
    {
        return $this->pdo->rowCount;
    }

    public function fetchAll(int $mode = PDO::FETCH_BOTH): array { return []; }
}

/**
 * Mock de MercadoPagoService que permite definir resposta de getPreapproval in-memory.
 */
class MercadoPagoServiceMock extends MercadoPagoService
{
    public function __construct() { $this->accessToken = 'MOCK'; }

    public array $mockResponse = ['ok' => false, 'status' => 500, 'error' => 'not_configured'];
    public ?string $lastCalledId = null;

    public function getPreapproval(string $mpPreapprovalId): array
    {
        $this->lastCalledId = $mpPreapprovalId;
        return $this->mockResponse;
    }

    public function createPreapproval(
        string $planSlug, int $userId, string $email, string $externalReference
    ): array {
        return ['ok' => false, 'error' => 'mock_no_real_api'];
    }
}

echo "\n=== TESTES: Subscribe + Reconcile ===\n\n";

echo "--- external_reference: regex user_<id>_<slug> ---\n";

assert_test(
    preg_match('/^user_(\d+)_(pro|premium)$/', 'user_15_pro') === 1,
    'user_15_pro casa com regex'
);
assert_test(
    preg_match('/^user_(\d+)_(pro|premium)$/', 'user_999_premium') === 1,
    'user_999_premium casa com regex'
);
assert_test(
    preg_match('/^user_(\d+)_(pro|premium)$/', 'user_15_enterprise') === 0,
    'user_15_enterprise rejeitado (slug invalido)'
);
assert_test(
    preg_match('/^user_(\d+)_(pro|premium)$/', 'admin_pro') === 0,
    'admin_pro rejeitado (sem user_N)'
);
assert_test(
    preg_match('/^user_(\d+)_(pro|premium)$/', 'user_-1_pro') === 0,
    'user_-1_pro rejeitado (id negativo)'
);
assert_test(
    MercadoPagoWebhookService::parseExternalReference('user_0_pro') === null,
    'user_0_pro parseExternalReference -> null (id 0)'
);
assert_test(
    MercadoPagoWebhookService::parseExternalReference('user_15_pro') !== null,
    'user_15_pro parseExternalReference -> [15, pro]'
);
assert_test(
    MercadoPagoWebhookService::parseExternalReference('user_15_pro')[1] === 'pro',
    'user_15_pro -> plan_slug=pro'
);

echo "\n--- MercadoPagoService::getPlanIdForSlug ---\n";

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');
assert_test(
    MercadoPagoService::getPlanIdForSlug('pro') === null,
    'sem env -> null para pro'
);
assert_test(
    MercadoPagoService::getPlanIdForSlug('gold') === null,
    'slug invalido -> null'
);

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_test_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_test_xyz');
assert_test(
    MercadoPagoService::getPlanIdForSlug('pro') === 'plan_pro_test_xyz',
    'com env -> plan_pro_test_xyz para pro'
);
assert_test(
    MercadoPagoService::getPlanIdForSlug('premium') === 'plan_premium_test_xyz',
    'com env -> plan_premium_test_xyz para premium'
);

echo "\n--- MercadoPagoService::createPreapproval: validacoes de entrada ---\n";

$s = new MercadoPagoService();
$r = $s->createPreapproval('gold', 1, 'a@b.com', 'user_1_pro');
assert_test(($r['error'] ?? '') === 'invalid_plan', 'slug invalido (gold) -> invalid_plan');

$r = $s->createPreapproval('pro', 0, 'a@b.com', 'user_0_pro');
assert_test(($r['error'] ?? '') === 'invalid_user', 'user_id=0 -> invalid_user');

$r = $s->createPreapproval('pro', 1, 'not-email', 'user_1_pro');
assert_test(($r['error'] ?? '') === 'invalid_email', 'email invalido -> invalid_email');

$r = $s->createPreapproval('pro', 1, 'a@b.com', 'hacker_attempt');
assert_test(($r['error'] ?? '') === 'invalid_external_reference', 'external_reference invalido -> invalid_external_reference');

putenv('MERCADOPAGO_PLAN_ID_PRO');
$r = $s->createPreapproval('pro', 1, 'a@b.com', 'user_1_pro');
assert_test(($r['error'] ?? '') === 'plan_not_found', 'sem plan_id no .env -> plan_not_found');

echo "\n--- SubscriptionReconciler: Pro authorized (Free->Pro) ---\n";

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_test_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_test_xyz');

$dbMock = new MockPDO();
$mpMock = new MercadoPagoServiceMock();
$rec    = new SubscriptionReconciler($dbMock, $mpMock);

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'user_1_pro',
        'next_payment_date' => '2026-10-05T00:00:00Z',
    ],
];
$r = $rec->reconcile('mp_pre_001', 1, true);
assert_test($r['ok'] === true && $r['action'] === 'created', 'authorized -> reconciliacao OK (action=created)', json_encode($r));
assert_test(isset($r['details']['subscription_id']), 'detalhes incluem subscription_id');
assert_test(($r['details']['plan_slug'] ?? '') === 'pro', 'plan_slug=pro nos detalhes');

echo "\n--- SubscriptionReconciler: Premium authorized (Free->Premium) ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_002',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_premium_test_xyz',
        'external_reference' => 'user_2_premium',
    ],
];
$r2 = $rec->reconcile('mp_pre_002', 2, true);
assert_test($r2['ok'] === true && $r2['action'] === 'created', 'Premium authorized -> reconciliacao OK', json_encode($r2));

echo "\n--- SubscriptionReconciler: idempotencia (mesmo preapproval_id) ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'user_1_pro',
    ],
];
$rDup = $rec->reconcile('mp_pre_001', 1, true);
assert_test($rDup['ok'] === true && $rDup['action'] === 'updated', 'mesmo preapproval_id -> action=updated (idempotente)');
$subCount = count(array_filter($dbMock->tables['subscriptions'], fn($s) => $s['user_id'] == 1));
assert_test($subCount <= 1, 'mesmo preapproval_id -> no max 1 subscription para Maria', "count=$subCount");

echo "\n--- SubscriptionReconciler: usuario errado (ataque) ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_attack_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'user_2_pro',
    ],
];
$rAttack = $rec->reconcile('mp_pre_attack_001', 1, true);
assert_test(
    $rAttack['ok'] === false && $rAttack['action'] === 'user_mismatch',
    'external_reference user_2_pro mas esperado user_1 -> bloqueado (user_mismatch)',
    json_encode($rAttack)
);

echo "\n--- SubscriptionReconciler: plan_id inconsistente ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_mismatch_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_premium_test_xyz',
        'external_reference' => 'user_1_pro',
    ],
];
$rMismatch = $rec->reconcile('mp_pre_mismatch_001', 1, true);
assert_test(
    $rMismatch['ok'] === false && $rMismatch['action'] === 'plan_mismatch',
    'mp_plan_id=premium ext_ref=pro -> bloqueado (plan_mismatch)',
    json_encode($rMismatch)
);

echo "\n--- SubscriptionReconciler: plan desconhecido (nao no .env) ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_unknown_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_gold_999',
        'external_reference' => 'user_1_pro',
    ],
];
$rUnknown = $rec->reconcile('mp_pre_unknown_001', 1, true);
assert_test(
    $rUnknown['ok'] === false && $rUnknown['action'] === 'unknown_plan',
    'plan_id nao configurado no .env -> bloqueado (unknown_plan)',
    json_encode($rUnknown)
);

echo "\n--- SubscriptionReconciler: status pending (NAO ativa plano) ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_pending_001',
        'status' => 'pending',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'user_1_pro',
    ],
];
$rPending = $rec->reconcile('mp_pre_pending_001', 1, true);
assert_test(
    $rPending['ok'] === false && $rPending['action'] === 'not_authorized',
    'status=pending -> NAO ativa plano (not_authorized)',
    json_encode($rPending)
);

echo "\n--- SubscriptionReconciler: preapproval inexistente (MP 404) ---\n";

$mpMock->mockResponse = ['ok' => false, 'status' => 404, 'error' => 'not_found'];
$r404 = $rec->reconcile('mp_pre_notfound', 1, true);
assert_test(
    $r404['ok'] === false && $r404['action'] === 'not_found',
    'MP retorna 404 -> bloqueado (not_found)',
    json_encode($r404)
);

echo "\n--- SubscriptionReconciler: erro transitente (MP 500) ---\n";

$mpMock->mockResponse = ['ok' => false, 'status' => 500, 'error' => 'server_error'];
$r500 = $rec->reconcile('mp_pre_500', 1, true);
assert_test(
    $r500['ok'] === false && $r500['action'] === 'transient_error',
    'MP retorna 500 -> transient_error (permite retry do MP)',
    json_encode($r500)
);

echo "\n--- SubscriptionReconciler: external_reference vazio (caso antigo) ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_empty_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
    ],
];
$rEmpty = $rec->reconcile('mp_pre_empty_001', 1, true);
assert_test(
    $rEmpty['ok'] === false && $rEmpty['action'] === 'incomplete_payload',
    'external_reference ausente (caso do bug) -> bloqueado (incomplete_payload)',
    json_encode($rEmpty)
);

echo "\n--- SubscriptionReconciler: external_reference malformado ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_malformed_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'attacker_injection_123',
    ],
];
$rMalformed = $rec->reconcile('mp_pre_malformed_001', 1, true);
assert_test(
    $rMalformed['ok'] === false && $rMalformed['action'] === 'invalid_external_reference',
    'external_reference malformado -> bloqueado',
    json_encode($rMalformed)
);

echo "\n--- SubscriptionReconciler: user_id diferente na ext_ref ---\n";

$mpMock->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_other_user_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'user_999_pro',
    ],
];
$rOtherUser = $rec->reconcile('mp_pre_other_user_001', 1, true);
assert_test(
    $rOtherUser['ok'] === false && $rOtherUser['action'] === 'user_mismatch',
    'ext_ref=user_999 mas esperado=1 -> bloqueado (user_mismatch)',
    json_encode($rOtherUser)
);

echo "\n--- SubscriptionReconciler: click duplicado (3 chamadas, mesmo preapproval) ---\n";

$dbMock3 = new MockPDO();
$dbMock3->tables['usuarios'] = [
    ['id' => 10, 'nome' => 'Carlos', 'email' => 'carlos@ex.com', 'plano' => 'gratuito',
        'plano_status' => 'ativo', 'plano_inicio' => null, 'plano_fim' => null,
        'active_subscription_id' => null],
];
$mpMock3 = new MercadoPagoServiceMock();
$mpMock3->mockResponse = [
    'ok' => true, 'status' => 200,
    'data' => [
        'id' => 'mp_pre_carlos_001',
        'status' => 'authorized',
        'preapproval_plan_id' => 'plan_pro_test_xyz',
        'external_reference' => 'user_10_pro',
    ],
];
$rec3 = new SubscriptionReconciler($dbMock3, $mpMock3);
$rec3->reconcile('mp_pre_carlos_001', 10, true);
$rec3->reconcile('mp_pre_carlos_001', 10, true);
$rec3->reconcile('mp_pre_carlos_001', 10, true);
$subCount3 = count(array_filter($dbMock3->tables['subscriptions'], fn($s) => $s['user_id'] == 10));
assert_test($subCount3 === 1, '3 reconciliations com mesmo preapproval_id -> 1 subscription', "count=$subCount3");

echo "\n--- createPreapproval: teste live (opcional) ---\n";

$liveMode = in_array('--live', $argv, true);
if (!$liveMode) {
    skip('Pro/Premium (live API)', 'rodar com --live');
} else {
    $envFile = $ROOT . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if (getenv($k) === false) putenv("$k=$v");
        }
    }
    $svcLive = new MercadoPagoService();
    $uidLive = 777777;
    $liveRef = 'user_' . $uidLive . '_pro';
    $rLive = $svcLive->createPreapproval('pro', $uidLive, 'live_test@example.com', $liveRef);
    assert_test(
        ($rLive['ok'] ?? false) === true
            && is_string($rLive['preapproval_id'] ?? '')
            && is_string($rLive['init_point'] ?? ''),
        'Live API: Pro -> preapproval criada com init_point',
        json_encode($rLive)
    );
}

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed + $skipped;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m | \033[33mSkipped: $skipped\033[0m\n";
if ($failed > 0) {
    echo "\033[31mALGUNS TESTES FALHARAM!\033[0m\n";
    exit(1);
}
echo "\033[32mTODOS OS TESTES PASSARAM!\033[0m\n";
exit(0);

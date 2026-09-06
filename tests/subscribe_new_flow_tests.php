<?php
/**
 * Testes de regressao do fluxo de NOVA ASSINATURA (action=subscribe).
 *
 * Garante que:
 * - storedInitPoint preenchido NAO impede createPreapproval em nova assinatura
 * - checkout_url antigo NAO e reutilizado
 * - createPreapproval e chamado exatamente 1 vez
 * - redirect final usa init_point retornado pela nova preapproval
 * - assinatura ativa nao cria duplicata
 * - assinatura cancelled nao bloqueia nova tentativa
 * - Pro e Premium funcionam
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

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

/**
 * Simula o action=subscribe em /public/index.php (somente a logica
 * que decide redirect vs createPreapproval).
 * Retorna o "redirect" final (URL ou erro).
 */
function simulateSubscribeAction(
    MockPDOSubs $db,
    MockMPService $mp,
    int $userId,
    string $slug,
    string $email
): array {
    $userRow = $db->findUserById($userId);
    if ($userRow === null) {
        return ['redirect' => '/index.php?action=login'];
    }

    $externalReference = 'user_' . $userId . '_' . $slug;

    $planRow = $db->findPlanBySlug($slug);
    if ($planRow === null) {
        return ['redirect' => '/index.php?action=meu_plano&error=plan_not_found'];
    }
    $planId = (int)$planRow['id'];

    $subscriptionModel = new Subscription($db);

    // A) Assinatura ja existente para o mesmo plano
    $existing = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $slug);
    if ($existing !== null) {
        $existingMpId = (string)($existing['mp_preapproval_id'] ?? '');
        if ($existingMpId !== '') {
            $reuse = $mp->getPreapproval($existingMpId);
            if ($reuse['ok'] === true && is_array($reuse['data'])) {
                $status = strtolower((string)($reuse['data']['status'] ?? ''));
                if ($status === 'authorized') {
                    return ['redirect' => '/index.php?action=meu_plano&subscribed=1'];
                }
            }
        }
        return ['redirect' => '/mercadopago_return.php'];
    }

    // upgrade cancela antiga (Pro<->Premium)
    if ($slug === 'premium' || $slug === 'pro') {
        $activeSub = $subscriptionModel->findActiveByUser($userId);
        if ($activeSub !== null) {
            $oldMpId = (string)($activeSub['mp_preapproval_id'] ?? '');
            $oldPlanSlug = (string)($activeSub['plan_slug'] ?? '');
            $shouldCancel = $oldMpId !== ''
                && (
                    ($slug === 'premium' && $oldPlanSlug === 'pro')
                    || ($slug === 'pro' && $oldPlanSlug === 'premium')
                );
            if ($shouldCancel) {
                $mp->cancelPreapproval($oldMpId);
            }
        }
    }

    $subscriptionModel->createPending($userId, $slug, $planId, $externalReference);

    // B) NOVA ASSINATURA: createPreapproval sempre
    $planIdMp = MercadoPagoService::getPlanIdForSlug($slug);
    if ($planIdMp === null || $planIdMp === '') {
        return ['redirect' => '/index.php?action=meu_plano&error=plan_not_found'];
    }
    $backUrl = 'https://controle-de-gastos-one-silk.vercel.app/mercadopago_return.php';
    $result = $mp->createPreapproval($planIdMp, $email, $externalReference, $backUrl);

    if (($result['ok'] ?? false) === false) {
        return ['redirect' => '/index.php?action=meu_plano&error=service_error', 'created' => 0];
    }

    $initPoint = (string)($result['init_point'] ?? '');
    $preapprovalId = (string)($result['preapproval_id'] ?? '');
    if ($initPoint === '' || $preapprovalId === '') {
        return ['redirect' => '/index.php?action=meu_plano&error=service_error'];
    }

    $pendingSub = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $slug);
    if ($pendingSub !== null) {
        $subscriptionModel->storeInitPoint((int)$pendingSub['id'], $initPoint);
    }

    return [
        'redirect' => $initPoint,
        'preapproval_id' => $preapprovalId,
        'created' => 1,
    ];
}

class MockPDOSubs
{
    public array $tables = [];
    public int $lastInsertedId = 100;
    public array $queries = [];

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
        ];
        $this->tables['subscriptions'] = [];
    }

    public function findUserById(int $id): ?array
    {
        foreach ($this->tables['usuarios'] as $u) {
            if ((int)$u['id'] === $id) return $u;
        }
        return null;
    }

    public function findPlanBySlug(string $slug): ?array
    {
        foreach ($this->tables['planos'] as $p) {
            if ($p['slug'] === $slug) return $p;
        }
        return null;
    }

    public function prepare(string $sql): MockStmtSubs
    {
        $this->queries[] = $sql;
        return new MockStmtSubs($this, $sql);
    }
    public function exec(string $sql): int
    {
        $this->queries[] = $sql;
        return 0;
    }
    public function lastInsertId(): string { return (string)$this->lastInsertedId; }
    public function setAttribute(int $opt, $val): bool { return true; }
    public function getAttribute(int $opt) { return null; }
}

class MockStmtSubs
{
    private MockPDOSubs $pdo;
    private string $sql;
    private array $params = [];

    public function __construct(MockPDOSubs $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = strtolower($sql);
    }

    public function execute(array $params = []): bool
    {
        $this->params = $params;
        $sqlLower = trim($this->sql);

        if (str_starts_with($sqlLower, 'insert into subscriptions')) {
            $newId = (int)$this->pdo->lastInsertedId + 1;
            $this->pdo->lastInsertedId = $newId;
            $this->pdo->tables['subscriptions'][] = [
                'id' => $newId,
                'user_id' => (int)($params[':user_id'] ?? 0),
                'plan_id' => (int)($params[':plan_id'] ?? 0),
                'plan_slug' => $params[':plan_slug'] ?? '',
                'status' => $params[':status'] ?? 'pending',
                'start_date' => null,
                'next_billing_date' => null,
                'paused_at' => null,
                'cancelled_at' => null,
                'expired_at' => null,
                'grace_period_end' => null,
                'raw_status' => $params[':raw_status'] ?? '',
                'external_reference' => $params[':external_reference'] ?? '',
                'mp_preapproval_id' => $params[':mp_preapproval_id'] ?? '',
                'checkout_url' => null,
            ];
        }

        if (str_starts_with($sqlLower, 'update')) {
            if (str_contains($sqlLower, 'checkout_url')) {
                $id = (int)($params[':id'] ?? 0);
                foreach ($this->pdo->tables['subscriptions'] as &$s) {
                    if ((int)$s['id'] === $id) {
                        $s['checkout_url'] = $params[':init'] ?? null;
                    }
                }
                unset($s);
            }
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH)
    {
        $sqlLower = $this->sql;
        $params = $this->params;

        if (str_contains($sqlLower, "status in ('pending','active','paused')")
            || (str_contains($sqlLower, 'from subscriptions') && str_contains($sqlLower, 'order by id desc limit 1'))) {
            $uid = (int)($params[':uid'] ?? 0);
            $slug = (string)($params[':slug'] ?? '');
            $matches = [];
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['user_id'] === $uid
                    && (string)$s['plan_slug'] === $slug
                    && in_array($s['status'], ['pending', 'active', 'paused'], true)) {
                    $matches[] = $s;
                }
            }
            usort($matches, fn($a, $b) => (int)$b['id'] - (int)$a['id']);
            if (!empty($matches)) {
                return $mode === PDO::FETCH_ASSOC ? $matches[0] : (object)$matches[0];
            }
            return false;
        }
        if (str_contains($sqlLower, "status = 'active'") && str_contains($sqlLower, 'from subscriptions')
            && !str_contains($sqlLower, 'in (')) {
            $uid = (int)($params[':uid'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['user_id'] === $uid && (string)$s['status'] === 'active') {
                    return $mode === PDO::FETCH_ASSOC ? $s : (object)$s;
                }
            }
            return false;
        }
        if (str_contains($sqlLower, 'from subscriptions') && isset($params[':id'])) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === $id) return $s;
            }
            return false;
        }
        if (str_contains($sqlLower, 'checkout_url') && str_contains($sqlLower, 'from subscriptions')) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === $id) return $s;
            }
            return false;
        }
        if (str_contains($sqlLower, 'select checkout_url')) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === $id) {
                    return $s['checkout_url'] ?? null;
                }
            }
            return null;
        }
        if (str_contains($sqlLower, 'select raw_status')) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === $id) {
                    return $s['raw_status'] ?? null;
                }
            }
            return null;
        }

        return false;
    }

    public function fetchColumn()
    {
        $sqlLower = $this->sql;
        $params = $this->params;

        if (str_contains($sqlLower, 'returning id')) {
            $entry = end($this->pdo->tables['subscriptions']);
            if ($entry) return (int)$entry['id'];
            return false;
        }
        if (str_contains($sqlLower, 'select checkout_url') && !str_contains($sqlLower, 'from usuarios')) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === $id) return $s['checkout_url'] ?? null;
            }
            return null;
        }
        if (str_contains($sqlLower, 'select raw_status')) {
            $id = (int)($params[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === $id) return $s['raw_status'] ?? null;
            }
            return null;
        }

        return false;
    }

    public function rowCount(): int { return 1; }
    public function fetchAll(int $mode = PDO::FETCH_BOTH): array { return []; }
}

class MockMPService extends MercadoPagoService
{
    public function __construct() { $this->accessToken = 'MOCK'; }
    public int $createCallCount = 0;
    public array $createCalls = [];
    public ?array $createMockResponse = null;
    public ?string $lastCreatedPlanId = null;
    public ?string $lastCreatedEmail = null;
    public ?string $lastCreatedExternalRef = null;

    public function getPreapproval(string $id): array
    {
        if (str_starts_with($id, 'mp_auth')) {
            return ['ok' => true, 'status' => 200, 'data' => ['id' => $id, 'status' => 'authorized']];
        }
        if (str_starts_with($id, 'mp_pend')) {
            return ['ok' => true, 'status' => 200, 'data' => ['id' => $id, 'status' => 'pending']];
        }
        if (str_starts_with($id, 'mp_canc')) {
            return ['ok' => true, 'status' => 200, 'data' => ['id' => $id, 'status' => 'cancelled']];
        }
        return ['ok' => false, 'status' => 404];
    }

    public function cancelPreapproval(string $id): array
    {
        return ['ok' => true, 'status' => 200, 'data' => ['id' => $id, 'status' => 'cancelled']];
    }

    public function createPreapproval(
        string $planId,
        string $payerEmail,
        string $externalReference,
        string $backUrl
    ): array {
        $this->createCallCount++;
        $this->createCalls[] = [
            'plan_id' => $planId,
            'payer_email' => $payerEmail,
            'external_reference' => $externalReference,
            'back_url' => $backUrl,
        ];
        $this->lastCreatedPlanId = $planId;
        $this->lastCreatedEmail = $payerEmail;
        $this->lastCreatedExternalRef = $externalReference;

        if ($this->createMockResponse !== null) return $this->createMockResponse;

        return [
            'ok' => true,
            'status' => 201,
            'preapproval_id' => 'mp_new_' . $this->createCallCount,
            'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_new_' . $this->createCallCount,
            'external_reference' => $externalReference,
            'plan_id' => $planId,
        ];
    }
}

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_xyz');

echo "\n=== TESTES: action=subscribe NAO reutiliza storedInitPoint ===\n\n";

// ---------- CENARIO 1: NOVA assinatura com checkout_url antigo ja gravado ----------
echo "--- NS01: storedInitPoint preenchido NAO impede createPreapproval ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'cancelled',
        'raw_status' => 'cancelled',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_canc_old',
        'checkout_url' => 'https://mp.com/OLD_INIT_POINT_LEGADO',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS01a: createPreapproval foi chamado 1 vez', "count={$mp->createCallCount}");
assert_test(str_contains($result['redirect'], 'mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_new_1'), 'NS01b: redirect usa init_point da NOVA preapproval', $result['redirect']);
assert_test(!str_contains($result['redirect'], 'OLD_INIT_POINT_LEGADO'), 'NS01c: redirect NAO usa init_point legado', $result['redirect']);

// ---------- CENARIO 2: storedInitPoint preenchido + nova assinatura em outro plano ----------
echo "\n--- NS02: assinatura pending antiga para mesmo plano redireciona para return ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'pending',
        'raw_status' => 'pending',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_pend_old',
        'checkout_url' => 'https://mp.com/OLD_INIT_POINT',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 0, 'NS02a: createPreapproval NAO foi chamado (pending existe)', "count={$mp->createCallCount}");
assert_test(str_contains($result['redirect'], '/mercadopago_return.php'), 'NS02b: redirect para return.php', $result['redirect']);

// ---------- CENARIO 3: assinatura authorized redireciona para subscribed=1 ----------
echo "\n--- NS03: assinatura authorized -> redirect subscribed=1 ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'active',
        'raw_status' => 'authorized',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_auth_existente',
        'checkout_url' => 'https://mp.com/OLD',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 0, 'NS03a: createPreapproval NAO chamado (ja authorized)', "count={$mp->createCallCount}");
assert_test(str_contains($result['redirect'], 'subscribed=1'), 'NS03b: redirect subscribed=1', $result['redirect']);

// ---------- CENARIO 4: NOVA assinatura Premium ----------
echo "\n--- NS04: NOVA assinatura Premium com storedInitPoint antigo NAO bloqueia ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'cancelled',
        'raw_status' => 'cancelled',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_canc_pro',
        'checkout_url' => 'https://mp.com/OLD_PRO',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'premium', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS04a: createPreapproval chamado 1 vez para Premium', "count={$mp->createCallCount}");
assert_test($mp->lastCreatedPlanId === 'plan_premium_xyz', 'NS04b: plan_id do Premium', $mp->lastCreatedPlanId);
assert_test(str_contains($result['redirect'], 'mercadopago.com.br'), 'NS04c: redirect para nova preapproval', $result['redirect']);

// ---------- CENARIO 5: NOVA assinatura sem nenhuma pendencia ----------
echo "\n--- NS05: NOVA assinatura limpa (sem subscriptions) ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS05a: createPreapproval chamado 1 vez', "count={$mp->createCallCount}");
assert_test($mp->lastCreatedExternalRef === 'user_1_pro', 'NS05b: external_reference = user_1_pro', $mp->lastCreatedExternalRef);
assert_test($mp->lastCreatedEmail === 'maria@ex.com', 'NS05c: payer_email = email do user', $mp->lastCreatedEmail);
assert_test(str_contains($result['redirect'], 'mp_new_1'), 'NS05d: redirect usa init_point novo', $result['redirect']);

// ---------- CENARIO 6: assinatura cancelled NAO bloqueia nova ----------
echo "\n--- NS06: assinatura cancelled NAO bloqueia nova tentativa ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'cancelled',
        'raw_status' => 'cancelled',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_canc_123',
        'checkout_url' => 'https://mp.com/OLD',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS06a: createPreapproval chamado 1 vez (cancelled NAO bloqueia)', "count={$mp->createCallCount}");
assert_test(str_contains($result['redirect'], 'mp_new_1'), 'NS06b: redirect usa init_point novo', $result['redirect']);

// ---------- CENARIO 7: API falha ----------
echo "\n--- NS07: createPreapproval retorna erro -> redirect service_error ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$mp->createMockResponse = ['ok' => false, 'status' => 500, 'error' => 'mp_error'];

$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS07a: createPreapproval chamado 1 vez', "count={$mp->createCallCount}");
assert_test(str_contains($result['redirect'], 'service_error'), 'NS07b: redirect para service_error', $result['redirect']);

// ---------- CENARIO 8: createPreapproval retorna init_point vazio ----------
echo "\n--- NS08: createPreapproval sem init_point -> redirect service_error ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$mp->createMockResponse = ['ok' => true, 'status' => 201, 'preapproval_id' => 'mp_x', 'init_point' => ''];

$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test(str_contains($result['redirect'], 'service_error'), 'NS08: redirect service_error (init_point vazio)', $result['redirect']);

// ---------- CENARIO 9: redirect usa init_point retornado pela NOVA preapproval ----------
echo "\n--- NS09: redirect usa exatamente o init_point retornado ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$mp->createMockResponse = [
    'ok' => true, 'status' => 201,
    'preapproval_id' => 'mp_especifico_99',
    'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_especifico_99',
    'external_reference' => 'user_1_premium',
    'plan_id' => 'plan_premium_xyz',
];

$result = simulateSubscribeAction($db, $mp, 1, 'premium', 'maria@ex.com');

assert_test(
    $result['redirect'] === 'https://www.mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_especifico_99',
    'NS09a: redirect EXATO retornado pela preapproval',
    $result['redirect']
);
assert_test($result['preapproval_id'] === 'mp_especifico_99', 'NS09b: preapproval_id correto', $result['preapproval_id'] ?? '');

// ---------- Resumo ----------
echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
if ($failed > 0) {
    echo "\033[31mALGUNS TESTES FALHARAM!\033[0m\n";
    exit(1);
}
echo "\033[32mTODOS OS TESTES PASSARAM!\033[0m\n";
exit(0);

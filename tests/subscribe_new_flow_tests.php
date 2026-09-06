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

    // A) Bloqueio de DUPLICATA: apenas active/authorized PARA O MESMO PLANO
    $existing = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $slug);
    if ($existing !== null) {
        $existingStatus = strtolower((string)($existing['status'] ?? ''));
        $isAuthorized = ($existingStatus === 'active');
        if ($isAuthorized) {
            $existingMpId = (string)($existing['mp_preapproval_id'] ?? '');
            if ($existingMpId !== '') {
                $reuseMp = new MockMPService();
                $reuse = $reuseMp->getPreapproval($existingMpId);
                if ($reuse['ok'] === true && is_array($reuse['data'])) {
                    $status = strtolower((string)($reuse['data']['status'] ?? ''));
                    if ($status === 'authorized') {
                        return ['redirect' => '/index.php?action=meu_plano&subscribed=1'];
                    }
                }
            }
        }
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

    // Forca criacao de novo registro pending (cada clique = 1 preapproval)
    $stmtInsert = $db->prepare(
        'INSERT INTO subscriptions (user_id, plan_id, plan_slug, status, raw_status, external_reference)
         VALUES (:uid, :pid, :slug, :status, :raw, :ext_ref)
         RETURNING id'
    );
    $stmtInsert->execute([
        ':uid'    => $userId,
        ':pid'    => $planId,
        ':slug'   => $slug,
        ':status' => 'pending',
        ':raw'    => 'pending',
        ':ext_ref'=> $externalReference,
    ]);
    $newPendingId = (int)$stmtInsert->fetchColumn();

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
                'user_id' => (int)($params[':user_id'] ?? $params[':uid'] ?? 0),
                'plan_id' => (int)($params[':plan_id'] ?? $params[':pid'] ?? 0),
                'plan_slug' => $params[':plan_slug'] ?? $params[':slug'] ?? '',
                'status' => $params[':status'] ?? 'pending',
                'start_date' => null,
                'next_billing_date' => null,
                'paused_at' => null,
                'cancelled_at' => null,
                'expired_at' => null,
                'grace_period_end' => null,
                'raw_status' => $params[':raw_status'] ?? $params[':raw'] ?? '',
                'external_reference' => $params[':external_reference'] ?? $params[':ext_ref'] ?? '',
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

        $hasInClause = str_contains($sqlLower, "in (");
        if (str_contains($sqlLower, "from subscriptions")
            && str_contains($sqlLower, "status")
            && str_contains($sqlLower, "user_id")) {

            $uid = (int)($params[':uid'] ?? 0);

            if ($hasInClause && str_contains($sqlLower, "'pending'")) {
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

            if ($hasInClause && !str_contains($sqlLower, "'pending'")) {
                $matches = [];
                foreach ($this->pdo->tables['subscriptions'] as $s) {
                    $mpId = (string)($s['mp_preapproval_id'] ?? '');
                    if ((int)$s['user_id'] === $uid
                        && in_array($s['status'], ['active', 'paused'], true)
                        && $mpId !== '') {
                        $matches[] = $s;
                    }
                }
                usort($matches, fn($a, $b) => (int)$b['id'] - (int)$a['id']);
                if (!empty($matches)) {
                    return $mode === PDO::FETCH_ASSOC ? $matches[0] : (object)$matches[0];
                }
                return false;
            }
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

// ---------- CENARIO 1: assinatura cancelled NAO bloqueia ----------
echo "\n--- NS01: assinatura cancelled NAO bloqueia nova preapproval ---\n";

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
assert_test(
    strpos($result['redirect'], 'mercadopago.com.br/checkout/v1/redirect') !== false,
    'NS01b: redirect para checkout MP',
    $result['redirect']
);
assert_test(
    strpos($result['redirect'], 'OLD_INIT_POINT_LEGADO') === false,
    'NS01c: redirect NAO usa init_point legado',
    $result['redirect']
);

// ---------- CENARIO 2: pending existente para outro plano (Pro bloqueia Premium?) ----------
echo "\n--- NS02: pending Pro NAO bloqueia assinatura Premium ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'pending',
        'raw_status' => 'pending',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_pend_pro',
        'checkout_url' => null,
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'premium', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS02a: createPreapproval Premium chamado 1 vez', "count={$mp->createCallCount}");
assert_test($mp->lastCreatedPlanId === 'plan_premium_xyz', 'NS02b: plan_id e Premium', $mp->lastCreatedPlanId);
assert_test(
    strpos($result['redirect'], 'mercadopago.com.br') !== false,
    'NS02c: redirect para checkout MP Premium',
    $result['redirect']
);

// ---------- CENARIO 3: pending existente PARA O MESMO PLANO ----------
echo "\n--- NS03: pending Pro NAO bloqueia nova preapproval Pro ---\n";

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

assert_test($mp->createCallCount === 1, 'NS03a: createPreapproval chamado 1 vez (pending NAO bloqueia)', "count={$mp->createCallCount}");
assert_test(
    strpos($result['redirect'], 'mercadopago.com.br') !== false,
    'NS03b: redirect para checkout MP',
    $result['redirect']
);
assert_test(
    strpos($result['redirect'], 'OLD_INIT_POINT') === false,
    'NS03c: redirect NAO reutiliza init_point legado',
    $result['redirect']
);

// ---------- CENARIO 4: active/authorized para O MESMO PLANO ----------
echo "\n--- NS04: active/authorized Pro -> redirect subscribed=1 (NAO cria duplicata) ---\n";

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

assert_test($mp->createCallCount === 0, 'NS04a: createPreapproval NAO chamado (ja authorized)', "count={$mp->createCallCount}");
assert_test(
    strpos($result['redirect'], 'subscribed=1') !== false,
    'NS04b: redirect subscribed=1',
    $result['redirect']
);

// ---------- CENARIO 5: active/authorized para PLANO DIFERENTE -----------
echo "\n--- NS05: active Pro NAO bloqueia Premium ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'active',
        'raw_status' => 'authorized',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_auth_pro',
        'checkout_url' => 'https://mp.com/PRO_OLD',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'premium', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS05a: createPreapproval Premium chamado 1 vez', "count={$mp->createCallCount}");
assert_test($mp->lastCreatedPlanId === 'plan_premium_xyz', 'NS05b: plan_id e Premium', $mp->lastCreatedPlanId);
assert_test(
    strpos($result['redirect'], 'mercadopago.com.br') !== false,
    'NS05c: redirect para checkout MP Premium',
    $result['redirect']
);

// ---------- CENARIO 6: NOVA assinatura limpa ----------
echo "\n--- NS06: NOVA assinatura limpa (sem subscriptions) ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS06a: createPreapproval chamado 1 vez', "count={$mp->createCallCount}");
assert_test($mp->lastCreatedExternalRef === 'user_1_pro', 'NS06b: external_reference = user_1_pro', $mp->lastCreatedExternalRef);
assert_test($mp->lastCreatedEmail === 'maria@ex.com', 'NS06c: payer_email = email do user', $mp->lastCreatedEmail);
assert_test(
    strpos($result['redirect'], 'mp_new_1') !== false,
    'NS06d: redirect usa init_point novo',
    $result['redirect']
);

// ---------- CENARIO 7: rejected NAO bloqueia nova tentativa ----------
echo "\n--- NS07: assinatura rejected NAO bloqueia nova preapproval ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'rejected',
        'raw_status' => 'rejected',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_rej_123',
        'checkout_url' => null,
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS07a: createPreapproval chamado 1 vez (rejected NAO bloqueia)', "count={$mp->createCallCount}");
assert_test(
    strpos($result['redirect'], 'mercadopago.com.br') !== false,
    'NS07b: redirect para checkout MP',
    $result['redirect']
);

// ---------- CENARIO 8: API falha ----------
echo "\n--- NS08: createPreapproval retorna erro -> redirect service_error ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$mp->createMockResponse = ['ok' => false, 'status' => 500, 'error' => 'mp_error'];

$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 1, 'NS08a: createPreapproval chamado 1 vez', "count={$mp->createCallCount}");
assert_test(
    strpos($result['redirect'], 'service_error') !== false,
    'NS08b: redirect para service_error',
    $result['redirect']
);

// ---------- CENARIO 9: createPreapproval retorna init_point vazio ----------
echo "\n--- NS09: createPreapproval sem init_point -> redirect service_error ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$mp->createMockResponse = ['ok' => true, 'status' => 201, 'preapproval_id' => 'mp_x', 'init_point' => ''];

$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test(
    strpos($result['redirect'], 'service_error') !== false,
    'NS09: redirect service_error (init_point vazio)',
    $result['redirect']
);

// ---------- CENARIO 10: redirect usa EXATAMENTE o init_point da NOVA preapproval ----------
echo "\n--- NS10: redirect usa exatamente o init_point retornado pela nova preapproval ---\n";

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
    'NS10a: redirect EXATO retornado pela preapproval',
    $result['redirect']
);
assert_test($result['preapproval_id'] === 'mp_especifico_99', 'NS10b: preapproval_id correto', $result['preapproval_id'] ?? '');

// ---------- CENARIO 11: createPreapproval chamado APENAS uma vez ----------
echo "\n--- NS11: createPreapproval chamado exatamente uma vez por clique ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();

simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
assert_test($mp->createCallCount === 1, 'NS11a: 1 clique = 1 chamada', "count={$mp->createCallCount}");

simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
assert_test($mp->createCallCount === 2, 'NS11b: 2 cliques = 2 chamadas (sem storedInitPoint reutilizado)', "count={$mp->createCallCount}");

simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
assert_test($mp->createCallCount === 3, 'NS11c: 3 cliques = 3 chamadas', "count={$mp->createCallCount}");

// ---------- CENARIO 12: NUNCA vai para mercadopago_return.php antes de createPreapproval ----------
echo "\n--- NS12: action=subscribe NAO envia para mercadopago_return.php ANTES do checkout ---\n";

$testCases = [
    ['status' => 'pending', 'slug' => 'pro'],
    ['status' => 'cancelled', 'slug' => 'pro'],
    ['status' => 'rejected', 'slug' => 'premium'],
    ['status' => 'paused', 'slug' => 'pro'],
    ['status' => null, 'slug' => 'pro'],
];

foreach ($testCases as $i => $tc) {
    $db = new MockPDOSubs();
    if ($tc['status'] !== null) {
        $db->tables['subscriptions'] = [[
            'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => $tc['slug'],
            'status' => $tc['status'],
            'raw_status' => $tc['status'] ?? '',
            'external_reference' => 'user_1_' . $tc['slug'],
            'mp_preapproval_id' => 'mp_test_' . $i,
            'checkout_url' => null,
        ]];
    }
    $mp = new MockMPService();
    $result = simulateSubscribeAction($db, $mp, 1, $tc['slug'], 'maria@ex.com');
    assert_test(
        strpos($result['redirect'], 'mercadopago_return.php') === false,
        "NS12[$i]: status={$tc['status']}/{$tc['slug']} NAO vai para return.php antes do checkout",
        $result['redirect']
    );
    assert_test($mp->createCallCount === 1, "NS12[$i]b: createPreapproval chamado", "count={$mp->createCallCount}");
}

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

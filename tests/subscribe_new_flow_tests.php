<?php
/**
 * Testes de regressao do fluxo de NOVA ASSINATURA (action=subscribe).
 *
 * PREMISSA ALTERADA (motivo: POST /preapproval sem card_token_id retorna
 * HTTP 400; fluxo migrado para tokenizacao JS SDK + subscribe_token):
 * - action=subscribe NAO chama mais o Mercado Pago e NAO redireciona a
 *   init_point; ele cria/reutiliza attempt_token e redireciona para
 *   meu_plano?checkout=<attempt> (onde o CardForm e exibido).
 * - A assinatura no MP e criada depois, via action=subscribe_token.
 *
 * Garante que:
 * - tentativa e criada com attempt_token UUID valido e owned pelo usuario
 * - double-click/refresh reutiliza a tentativa (sem duplicatas)
 * - checkout_url/init_point legado nunca e usado
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
 * que decide redirect vs tentativa).
 * Retorna o "redirect" final (URL ou erro) + attempt criado/reutilizado.
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

    // B) Tentativa local opaca (novo fluxo): cria ou reutiliza attempt_token.
    // NENHUMA chamada ao Mercado Pago acontece aqui.
    $attempt = $subscriptionModel->createAttempt($userId, $slug, $planId);

    return [
        'redirect' => '/index.php?action=meu_plano&checkout=' . $attempt['attempt_token'],
        'attempt_token' => $attempt['attempt_token'],
        'attempt_created' => $attempt['created'],
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
                'attempt_token' => $params[':attempt_token'] ?? null,
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
        if (str_contains($sqlLower, 'from subscriptions') && isset($params[':token'])) {
            $tok = (string)($params[':token'] ?? '');
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((string)($s['attempt_token'] ?? '') === $tok && $tok !== '') return $s;
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
        string $backUrl,
        string $cardTokenId = '',
        string $idempotencyKey = '',
        $deviceId = null
    ): array {
        $this->createCallCount++;
        $this->createCalls[] = [
            'plan_id' => $planId,
            'payer_email' => $payerEmail,
            'external_reference' => $externalReference,
            'back_url' => $backUrl,
            'card_token_id' => $cardTokenId,
            'idempotency_key' => $idempotencyKey,
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

echo "\n=== TESTES: action=subscribe cria attempt + redirect checkout ===\n\n";

// ---------- CENARIO 1: assinatura cancelled NAO bloqueia ----------
echo "\n--- NS01: assinatura cancelled NAO bloqueia nova tentativa ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'cancelled',
        'raw_status' => 'cancelled',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_canc_old',
        'attempt_token' => null,
        'checkout_url' => 'https://mp.com/OLD_INIT_POINT_LEGADO',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 0, 'NS01a: NENHUMA chamada ao MP no subscribe (tokenizacao vem depois)', "count={$mp->createCallCount}");
assert_test(
    preg_match('/^\/index\.php\?action=meu_plano&checkout=[0-9a-f]{32}$/', $result['redirect']) === 1,
    'NS01b: redirect para checkout com attempt UUID',
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

assert_test($mp->createCallCount === 0, 'NS02a: NENHUMA chamada ao MP no subscribe', "count={$mp->createCallCount}");
assert_test(
    preg_match('/^\/index\.php\?action=meu_plano&checkout=[0-9a-f]{32}$/', $result['redirect']) === 1,
    'NS02b: redirect checkout com attempt UUID (Premium)',
    $result['redirect']
);
$smCheck = new Subscription($db);
$attemptRow = $smCheck->findByAttemptToken($result['attempt_token'] ?? '');
assert_test(
    $attemptRow !== null && (int)$attemptRow['user_id'] === 1 && $attemptRow['plan_slug'] === 'premium',
    'NS02c: tentativa pertence ao usuario 1 e ao plano premium'
);

// ---------- CENARIO 3: pending legada (sem attempt) PARA O MESMO PLANO ----------
echo "\n--- NS03: pending legada Pro gera NOVA tentativa (sem reuse inseguro) ---\n";

$db = new MockPDOSubs();
$db->tables['subscriptions'] = [
    [
        'id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'pending',
        'raw_status' => 'pending',
        'external_reference' => 'user_1_pro',
        'mp_preapproval_id' => 'mp_pend_old',
        'attempt_token' => null,
        'checkout_url' => 'https://mp.com/OLD_INIT_POINT',
    ],
];

$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test(($result['attempt_created'] ?? false) === true, 'NS03a: nova tentativa criada (legada sem token nao e reutilizada)');
assert_test(
    preg_match('/^\/index\.php\?action=meu_plano&checkout=[0-9a-f]{32}$/', $result['redirect']) === 1,
    'NS03b: redirect checkout com attempt UUID',
    $result['redirect']
);
assert_test(
    strpos($result['redirect'], 'OLD_INIT_POINT') === false,
    'NS03c: redirect NAO reutiliza init_point legado',
    $result['redirect']
);

// ---------- CENARIO 3b: pending COM attempt e reusada (double-click) ----------
echo "\n--- NS03b: pending com attempt_token e REUTILIZADA ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$first = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
$second = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test(
    ($first['attempt_token'] ?? '') !== '' && $first['attempt_token'] === ($second['attempt_token'] ?? null),
    'NS03ba: double-click reutiliza o MESMO attempt_token'
);
assert_test(($second['attempt_created'] ?? true) === false, 'NS03bb: segunda chamada nao cria tentativa');
assert_test(count($db->tables['subscriptions']) === 1, 'NS03bc: apenas 1 linha de tentativa no banco');

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

assert_test($mp->createCallCount === 0, 'NS05a: NENHUMA chamada ao MP no subscribe', "count={$mp->createCallCount}");
assert_test(
    preg_match('/^\/index\.php\?action=meu_plano&checkout=[0-9a-f]{32}$/', $result['redirect']) === 1,
    'NS05b: redirect checkout com attempt UUID (Premium)',
    $result['redirect']
);

// ---------- CENARIO 6: NOVA assinatura limpa ----------
echo "\n--- NS06: NOVA assinatura limpa (sem subscriptions) ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();
$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 0, 'NS06a: NENHUMA chamada ao MP no subscribe', "count={$mp->createCallCount}");
assert_test(
    Subscription::isAttemptToken($result['attempt_token'] ?? ''),
    'NS06b: attempt_token e UUID valido',
    $result['attempt_token'] ?? ''
);
$smCheck = new Subscription($db);
$stored = $smCheck->findByAttemptToken($result['attempt_token'] ?? '');
assert_test(
    $stored !== null && $stored['external_reference'] === $result['attempt_token'],
    'NS06c: external_reference local = attempt_token (sera enviado ao MP no subscribe_token)'
);
assert_test(
    $result['redirect'] === '/index.php?action=meu_plano&checkout=' . $result['attempt_token'],
    'NS06d: redirect exato para checkout da tentativa',
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

assert_test($mp->createCallCount === 0, 'NS07a: NENHUMA chamada ao MP (rejected NAO bloqueia, mas MP vem depois)', "count={$mp->createCallCount}");
assert_test(
    preg_match('/^\/index\.php\?action=meu_plano&checkout=[0-9a-f]{32}$/', $result['redirect']) === 1,
    'NS07b: redirect checkout com attempt UUID',
    $result['redirect']
);

// ---------- CENARIO 8: subscribe NUNCA chama o MP (falha de API impossivel aqui) ----------
echo "\n--- NS08: subscribe sem nenhuma chamada ao MP ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();

$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test($mp->createCallCount === 0, 'NS08a: zero chamadas ao MP (erros de API tratados no subscribe_token)', "count={$mp->createCallCount}");
assert_test(
    preg_match('/^\/index\.php\?action=meu_plano&checkout=[0-9a-f]{32}$/', $result['redirect']) === 1,
    'NS08b: redirect checkout mesmo assim',
    $result['redirect']
);

// ---------- CENARIO 9: init_point e irrelevante no novo fluxo ----------
echo "\n--- NS09: redirect nunca aponta para init_point/checkout MP ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();

$result = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');

assert_test(
    strpos($result['redirect'], 'mercadopago.com') === false,
    'NS09: redirect 100% local (init_point nao existe mais no subscribe)',
    $result['redirect']
);

// ---------- CENARIO 10: redirect exato carrega o attempt UUID ----------
echo "\n--- NS10: redirect exato meu_plano?checkout=<attempt> ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();

$result = simulateSubscribeAction($db, $mp, 1, 'premium', 'maria@ex.com');

assert_test(
    $result['redirect'] === '/index.php?action=meu_plano&checkout=' . ($result['attempt_token'] ?? ''),
    'NS10a: redirect EXATO com o attempt criado',
    $result['redirect']
);
$smCheck = new Subscription($db);
$owned = $smCheck->findByAttemptToken($result['attempt_token'] ?? '');
assert_test(
    $owned !== null && (int)$owned['user_id'] === 1 && $owned['plan_slug'] === 'premium',
    'NS10b: attempt pertence ao usuario 1 / plano premium'
);

// ---------- CENARIO 11: N cliques = 1 tentativa (idempotencia no subscribe) ----------
echo "\n--- NS11: multiplos cliques reutilizam a mesma tentativa ---\n";

$db = new MockPDOSubs();
$mp = new MockMPService();

$first = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
assert_test($mp->createCallCount === 0, 'NS11a: 1 clique = 0 chamadas MP', "count={$mp->createCallCount}");

$second = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
assert_test($mp->createCallCount === 0, 'NS11b: 2 cliques = 0 chamadas MP', "count={$mp->createCallCount}");
assert_test(
    $first['attempt_token'] === $second['attempt_token'],
    'NS11c: 2 cliques = MESMO attempt (sem duplicata)'
);

$third = simulateSubscribeAction($db, $mp, 1, 'pro', 'maria@ex.com');
assert_test(count($db->tables['subscriptions']) === 1, 'NS11d: 3 cliques = 1 linha no banco');
assert_test($third['attempt_token'] === $first['attempt_token'], 'NS11e: 3 cliques = MESMO attempt');

// ---------- CENARIO 12: NUNCA vai para mercadopago_return.php no subscribe ----------
echo "\n--- NS12: action=subscribe NAO envia para mercadopago_return.php ---\n";

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
        "NS12[$i]: status={$tc['status']}/{$tc['slug']} NAO vai para return.php",
        $result['redirect']
    );
    assert_test($mp->createCallCount === 0, "NS12[$i]b: zero chamadas ao MP", "count={$mp->createCallCount}");
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

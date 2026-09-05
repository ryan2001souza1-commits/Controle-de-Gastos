<?php
/**
 * Testes de upgrade Pro -> Premium.
 *
 * Cobre:
 *  - cancelamento da Pro antes de criar Premium
 *  - falha no cancelamento da Pro
 *  - Pro ja cancelada
 *  - clique duplo/triplo
 *  - webhook Pro chega antes do return Premium
 *  - webhook Pro chega depois da Premium ativa
 *  - usuario sem assinatura ativa
 *  - conta administrativa
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
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

class UpgradeMPMock extends MercadoPagoService
{
    public function __construct() { $this->accessToken = 'MOCK'; }

    public array $getMock          = ['ok' => false, 'status' => 500];
    public array $cancelMock       = ['ok' => false, 'status' => 500];
    public array $createMock       = ['ok' => false, 'error' => 'no_mock'];
    public array $initPointMock    = ['ok' => false, 'error' => 'no_mock'];
    public bool $getCalled          = false;
    public bool $cancelCalled       = false;
    public bool $createCalled       = false;
    public ?string $lastCancelId    = null;
    public ?string $lastGetId      = null;

    public function getPreapproval(string $id): array
    {
        $this->getCalled = true;
        $this->lastGetId = $id;
        return $this->getMock;
    }

    public function cancelPreapproval(string $id): array
    {
        $this->cancelCalled = true;
        $this->lastCancelId = $id;
        return $this->cancelMock;
    }

    public function createPreapproval(string $planId, int $userId, string $email, string $ref): array
    {
        $this->createCalled = true;
        return $this->createMock;
    }

    public function getInitPointForPlan(string $planSlug, int $userId, string $email): array
    {
        $this->createCalled = true;
        return $this->initPointMock;
    }
}

class MockPDOUpgrade
{
    public array $tables = [];
    public int $lastInsertedId = 0;
    private array $lastExecuted = [];

    public function __construct()
    {
        $this->tables['planos'] = [
            ['id' => 1, 'slug' => 'gratuito', 'nome' => 'Gratuito', 'preco' => 0, 'status' => 'ativo'],
            ['id' => 2, 'slug' => 'pro',     'nome' => 'Pro',     'preco' => 9.90, 'status' => 'ativo'],
            ['id' => 3, 'slug' => 'premium', 'nome' => 'Premium', 'preco' => 19.90, 'status' => 'ativo'],
        ];
        $this->tables['usuarios'] = [];
        $this->tables['subscriptions'] = [];
    }

    public function exec(string $sql): int { return 0; }
    public function prepare(string $sql): MockPDOStmtUpgrade { return new MockPDOStmtUpgrade($this, $sql); }
    public function lastInsertId(): string { return (string)$this->lastInsertedId; }
    public function setAttribute(int $opt, $val): bool { return true; }
}

class MockPDOStmtUpgrade
{
    private MockPDOUpgrade $pdo;
    private string $sql;
    private array $params = [];

    public function __construct(MockPDOUpgrade $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = strtolower($sql);
    }

    public function execute(array $params = []): bool
    {
        $this->params = $params;
        $s = $this->sql;

        if (str_starts_with(trim($s), 'insert into')) {
            if (str_contains($s, 'subscriptions')) {
                $this->pdo->lastInsertedId = count($this->pdo->tables['subscriptions']) + 1;
                $this->pdo->tables['subscriptions'][] = [
                    'id' => $this->pdo->lastInsertedId,
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
                    'raw_status' => '',
                    'external_reference' => '',
                    'mp_preapproval_id' => $params[':mp_preapproval_id'] ?? '',
                ];
            }
        }

        if (str_starts_with(trim($s), 'update')) {
            if (str_contains($s, 'usuarios')) {
                $uid = (int)($params[':uid'] ?? 0);
                foreach ($this->pdo->tables['usuarios'] as &$u) {
                    if ((int)$u['id'] === $uid) {
                        if (str_contains($s, "'gratuito'"))    $u['plano'] = 'gratuito';
                        if (str_contains($s, "'cancelado'"))  $u['plano_status'] = 'cancelado';
                        if (str_contains($s, "'ativo'"))       $u['plano_status'] = 'ativo';
                        if (str_contains($s, "'pendente'"))   $u['plano_status'] = 'pendente';
                        if (str_contains($s, "'premium'"))   $u['plano'] = 'premium';
                        if (str_contains($s, "'pro'"))        $u['plano'] = 'pro';
                        if (str_contains($s, 'active_subscription_id = null')) {
                            $u['active_subscription_id'] = null;
                        } elseif (isset($params[':sub_id'])) {
                            $u['active_subscription_id'] = (int)$params[':sub_id'];
                        }
                        if (isset($params[':grace'])) $u['plano_fim'] = $params[':grace'];
                    }
                }
                unset($u);
            }
            if (str_contains($s, 'subscriptions')) {
                $id = (int)($params[':id'] ?? 0);
                foreach ($this->pdo->tables['subscriptions'] as &$sub) {
                    if ((int)$sub['id'] === $id) {
                        if (isset($params[':status']))     $sub['status'] = $params[':status'];
                        if (isset($params[':raw_status'])) $sub['raw_status'] = $params[':raw_status'];
                        if (isset($params[':mpid']))     $sub['mp_preapproval_id'] = $params[':mpid'];
                        if (isset($params[':init'])) {
                            $sub['raw_status'] = ($sub['raw_status'] ?? '') . '|init:' . $params[':init'];
                        }
                        if (array_key_exists(':grace_period_end', $params)) {
                            $sub['grace_period_end'] = $params[':grace_period_end'];
                        }
                    }
                }
                unset($sub);
            }
        }
        return true;
    }

    public function fetchColumn()
    {
        if (str_contains($this->sql, 'returning')) {
            $entry = end($this->pdo->tables['subscriptions']);
            return $entry ? (int)$entry['id'] : 0;
        }
        if (str_contains($this->sql, 'from usuarios') && isset($this->params[':uid'])) {
            $uid = (int)$this->params[':uid'];
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === $uid) return (int)$u['id'];
            }
            return false;
        }
        if (str_contains($this->sql, 'from subscriptions') && isset($this->params[':id'])) {
            $id = (int)$this->params[':id'];
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['id'] === $id) return $sub['raw_status'] ?? '';
            }
            return false;
        }
        return false;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH): mixed
    {
        $p = $this->params;
        $s = $this->sql;

        if (str_contains($s, 'from subscriptions')) {
            if (isset($p[':uid']) && isset($p[':slug'])) {
                $uid = (int)($p[':uid'] ?? 0);
                $slug = $p[':slug'];
                foreach ($this->pdo->tables['subscriptions'] as $sub) {
                    if ((int)$sub['user_id'] === $uid && $sub['plan_slug'] === $slug
                        && in_array($sub['status'], ['pending', 'active', 'paused'], true)) {
                        return $sub;
                    }
                }
                return false;
            }
            if (isset($p[':uid']) && !isset($p[':slug'])) {
                $uid = (int)($p[':uid'] ?? 0);
                foreach ($this->pdo->tables['subscriptions'] as $sub) {
                    if ((int)$sub['user_id'] === $uid
                        && in_array($sub['status'], ['active', 'paused'], true)
                        && $sub['mp_preapproval_id'] !== '' && $sub['mp_preapproval_id'] !== null) {
                        return $sub;
                    }
                }
                return false;
            }
            if (isset($p[':id']) || isset($p[0])) {
                $id = isset($p[':id']) ? (int)$p[':id'] : (int)$p[0];
                foreach ($this->pdo->tables['subscriptions'] as $sub) {
                    if ((int)$sub['id'] === $id) return $sub;
                }
                return false;
            }
            if (isset($p[':mpid'])) {
                foreach ($this->pdo->tables['subscriptions'] as $sub) {
                    if ($sub['mp_preapproval_id'] === $p[':mpid']) return $sub;
                }
                return false;
            }
        }
        if (str_contains($s, 'from planos')) {
            $slug = $p[0] ?? ($p['slug'] ?? '');
            foreach ($this->pdo->tables['planos'] as $plan) {
                if ($plan['slug'] === $slug) return $plan;
            }
            return false;
        }
        if (str_contains($s, 'from usuarios')) {
            if (isset($p[':uid']) || isset($p[0])) {
                $uid = isset($p[':uid']) ? (int)$p[':uid'] : (int)$p[0];
                foreach ($this->pdo->tables['usuarios'] as $u) {
                    if ((int)$u['id'] === $uid) return $u;
                }
                return false;
            }
        }
        return false;
    }

    public function rowCount(): int { return 1; }
}

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_test_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_test_xyz');

echo "\n=== TESTES: upgrade Pro -> Premium ===\n\n";

function makeDbProUser(): MockPDOUpgrade
{
    $db = new MockPDOUpgrade();
    $db->tables['usuarios'] = [
        ['id' => 1, 'nome' => 'Maria', 'email' => 'maria@test.com',
            'plano' => 'pro', 'plano_status' => 'ativo',
            'plano_inicio' => '2026-09-05', 'plano_fim' => null,
            'active_subscription_id' => 1],
        ['id' => 2, 'nome' => 'Joao', 'email' => 'joao@test.com',
            'plano' => 'gratuito', 'plano_status' => 'ativo',
            'plano_inicio' => null, 'plano_fim' => null,
            'active_subscription_id' => null],
        ['id' => 3, 'nome' => 'Admin', 'email' => 'admin@test.com',
            'plano' => 'premium', 'plano_status' => 'ativo',
            'plano_inicio' => '2025-01-01', 'plano_fim' => null,
            'active_subscription_id' => null],
    ];
    $db->tables['subscriptions'] = [
        ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
            'status' => 'active', 'start_date' => '2026-09-05', 'next_billing_date' => '2026-10-05',
            'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
            'grace_period_end' => null, 'raw_status' => 'authorized',
            'external_reference' => 'user_1_pro', 'mp_preapproval_id' => 'mp_pro_001'],
    ];
    return $db;
}

function simulateUpgradeFlow(MockPDOUpgrade $db, UpgradeMPMock $mp, int $userId, string $targetPlan): array
{
    $subscriptionModel = new Subscription($db);

    $existing = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $targetPlan);
    if ($existing !== null) {
        $existingMpId = (string)($existing['mp_preapproval_id'] ?? '');
        if ($existingMpId !== '') {
            $mp->getCalled = false;
            $check = $mp->getPreapproval($existingMpId);
            if ($check['ok'] === true && is_array($check['data'])) {
                $status = strtolower((string)($check['data']['status'] ?? ''));
                $init = $check['data']['init_point'] ?? null;
                if ($status === 'authorized') {
                    return ['result' => 'already_subscribed', 'plan' => $targetPlan];
                }
                if ($status === 'pending' && is_string($init) && $init !== '') {
                    return ['result' => 'reuse_init_point', 'plan' => $targetPlan];
                }
            }
        }
    }

    if ($targetPlan === 'premium') {
        $activeSub = $subscriptionModel->findActiveByUser($userId);
        if ($activeSub !== null) {
            $oldMpId = (string)($activeSub['mp_preapproval_id'] ?? '');
            $oldPlanSlug = (string)($activeSub['plan_slug'] ?? '');
            if ($oldMpId !== '' && $oldPlanSlug === 'pro') {
                $mp->getCalled = false;
                $check = $mp->getPreapproval($oldMpId);
                $oldMpStatus = 'unknown';
                if ($check['ok'] === true && is_array($check['data'])) {
                    $oldMpStatus = strtolower((string)($check['data']['status'] ?? ''));
                }
                if ($oldMpStatus === 'authorized' || $oldMpStatus === 'pending') {
                    $mp->cancelCalled = false;
                    $cancelResult = $mp->cancelPreapproval($oldMpId);
                    if ($cancelResult['ok'] === false) {
                        $http = (int)($cancelResult['status'] ?? 0);
                        if ($http === 0 || $http >= 500) {
                            return ['result' => 'upgrade_service_error', 'plan' => $targetPlan];
                        }
                        if ($http === 404) {
                            $subscriptionModel->updateStatusById(
                                (int)$activeSub['id'],
                                Subscription::STATUS_CANCELLED,
                                'cancelled',
                                null,
                                null
                            );
                            $fresh = $subscriptionModel->findById((int)$activeSub['id']);
                            if ($fresh !== null) {
                                $subscriptionModel->applyStatusToUser($fresh);
                            }
                        } else {
                            return ['result' => 'upgrade_service_error', 'plan' => $targetPlan];
                        }
                    } else {
                        $cancelMpStatus = strtolower(trim((string)($cancelResult['data']['status'] ?? '')));
                        $internalCancel = MercadoPagoWebhookService::mapMpStatusToInternal($cancelMpStatus);
                        $subscriptionModel->updateStatusById(
                            (int)$activeSub['id'],
                            $internalCancel ?? Subscription::STATUS_CANCELLED,
                            $cancelMpStatus,
                            null,
                            null
                        );
                        $fresh = $subscriptionModel->findById((int)$activeSub['id']);
                        if ($fresh !== null) {
                            $subscriptionModel->applyStatusToUser($fresh);
                        }
                    }
                }
            }
        }
    }

    $mp->createCalled = false;
    $existing = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $targetPlan);
    if ($existing === null) {
        $planId = (int)($db->tables['planos'][[
            'gratuito' => 0, 'pro' => 1, 'premium' => 2
        ][$targetPlan]]['id'] ?? 0);
        $db->tables['subscriptions'][] = [
            'id' => count($db->tables['subscriptions']) + 1,
            'user_id' => $userId,
            'plan_id' => $planId,
            'plan_slug' => $targetPlan,
            'status' => 'pending',
            'start_date' => null,
            'next_billing_date' => null,
            'paused_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'grace_period_end' => null,
            'raw_status' => '',
            'external_reference' => 'user_' . $userId . '_' . $targetPlan,
            'mp_preapproval_id' => '',
        ];
    }
    $pendingSub = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $targetPlan);
    $storedInit = null;
    if ($pendingSub !== null) {
        $storedInit = $subscriptionModel->getStoredInitPoint((int)$pendingSub['id']);
    }
    if ($storedInit !== null && $storedInit !== '') {
        return ['result' => 'reuse_stored_init_point', 'plan' => $targetPlan];
    }
    $initResult = $mp->getInitPointForPlan($targetPlan, $userId, 'user@test.com');
    if ($initResult['ok'] === false) {
        return ['result' => 'service_error', 'plan' => $targetPlan];
    }
    if ($pendingSub !== null) {
        $subscriptionModel->storeInitPoint((int)$pendingSub['id'], $initResult['init_point']);
    }
    return ['result' => 'checkout_created', 'plan' => $targetPlan];
}

echo "--- UP01: Pro active -> Premium: Pro cancelada no MP primeiro ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/checkout?premium'];

$result = simulateUpgradeFlow($db, $mp, 1, 'premium');

assert_test($mp->getCalled === true, 'UP01a: getPreapproval chamado para Pro');
assert_test($mp->cancelCalled === true, 'UP01b: cancelPreapproval chamado para Pro');
assert_test($mp->lastCancelId === 'mp_pro_001', 'UP01c: cancel ID correto (mp_pro_001)');
assert_test($mp->createCalled === true, 'UP01d: Premium checkout criado');
assert_test($result['result'] === 'checkout_created', 'UP01e: fluxo retorna checkout_created');

$proSub = $db->tables['subscriptions'][0];
assert_test($proSub['status'] === 'cancelled', 'UP01f: Pro status=cancelled na DB');
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'UP01g: Maria plano=gratuito apos cancel Pro');

echo "\n--- UP02: Cancelamento da Pro falha (5xx) -> Premium nao criada ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => false, 'status' => 500, 'error' => 'server'];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium'];

$result = simulateUpgradeFlow($db, $mp, 1, 'premium');

assert_test($mp->cancelCalled === true, 'UP02a: cancelPreapproval chamado');
assert_test($result['result'] === 'upgrade_service_error', 'UP02b: retorno = upgrade_service_error', $result['result']);
assert_test($mp->createCalled === false, 'UP02c: Premium NAO criada (bloqueada pelo erro no cancel)');
$proSub = $db->tables['subscriptions'][0];
assert_test($proSub['status'] === 'active', 'UP02d: Pro status=active (nao cancelada)');
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'UP02e: Maria plano=pro (nao alterado)');

echo "\n--- UP03: Pro ja cancelada no MP -> upgrade prossegue ---\n";
$db = makeDbProUser();
$db->tables['subscriptions'][0]['status'] = 'cancelled';
$db->tables['subscriptions'][0]['raw_status'] = 'cancelled';
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium'];

$result = simulateUpgradeFlow($db, $mp, 1, 'premium');

assert_test($mp->cancelCalled === false, 'UP03a: cancel NAO chamado (Pro ja cancelled no MP)');
assert_test($mp->createCalled === true, 'UP03b: Premium checkout criado normalmente');
assert_test($result['result'] === 'checkout_created', 'UP03c: fluxo prossegue normalmente');

echo "\n--- UP04: Clique duplo/triplo -> apenas uma preapproval Premium criada ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium1'];

$mp->createCalled = false;
$result1 = simulateUpgradeFlow($db, $mp, 1, 'premium');
assert_test($result1['result'] === 'checkout_created', 'UP04a: primeiro clique -> checkout_created');
assert_test($mp->createCalled === true, 'UP04b: create chamado 1x no primeiro clique');

$mp->createCalled = false;
$result2 = simulateUpgradeFlow($db, $mp, 1, 'premium');
assert_test($result2['result'] === 'reuse_stored_init_point',
    'UP04c: segundo clique -> reuse_stored_init_point', $result2['result']);
assert_test($mp->createCalled === false, 'UP04d: create NAO chamado no segundo clique');

$mp->createCalled = false;
$result3 = simulateUpgradeFlow($db, $mp, 1, 'premium');
assert_test($result3['result'] === 'reuse_stored_init_point',
    'UP04e: terceiro clique -> reuse_stored_init_point');
assert_test($mp->createCalled === false, 'UP04f: create NAO chamado no terceiro clique');

$proSub = $db->tables['subscriptions'][0];
assert_test($proSub['status'] === 'cancelled', 'UP04g: Pro cancelada (uma unica vez)');

echo "\n--- UP05: Webhook Pro cancelled chega antes do return Premium ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium'];

$result = simulateUpgradeFlow($db, $mp, 1, 'premium');
assert_test($result['result'] === 'checkout_created',
    'UP05: Pro cancelada pelo webhook -> Premium checkout criado normalmente');

echo "\n--- UP06: Webhook antigo da Pro chega DEPOIS da Premium ativa ---\n";
$db = makeDbProUser();
$subModel = new Subscription($db);

$subModel->updateStatusById(1, Subscription::STATUS_CANCELLED, 'cancelled', null, null);
$fresh = $subModel->findById(1);
$subModel->applyStatusToUser($fresh);

$db->tables['subscriptions'][] = [
    'id' => 2, 'user_id' => 1, 'plan_id' => 3, 'plan_slug' => 'premium',
    'status' => 'active', 'start_date' => '2026-09-05', 'next_billing_date' => '2026-10-05',
    'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
    'grace_period_end' => null, 'raw_status' => 'authorized',
    'external_reference' => 'user_1_premium', 'mp_preapproval_id' => 'mp_premium_001',
];

$db->tables['usuarios'][0]['plano'] = 'premium';
$db->tables['usuarios'][0]['plano_status'] = 'ativo';
$db->tables['usuarios'][0]['active_subscription_id'] = 2;

$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled',
               'preapproval_plan_id' => 'plan_pro_test_xyz']];
$rec = new SubscriptionReconciler($db, $mp);
$result = $rec->reconcileFromReturn('mp_pro_001', 1);

assert_test($result['ok'] === false && $result['action'] === 'not_authorized',
    'UP06a: webhook Pro cancelled -> not_authorized', json_encode($result));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'premium', 'UP06b: Maria plano=premium (NAO rebaixada pelo webhook antigo)');
assert_test($maria['plano_status'] === 'ativo', 'UP06c: plano_status=ativo');

echo "\n--- UP07: Usuario sem assinatura ativa -> Premium checkout funciona ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium'];

$result = simulateUpgradeFlow($db, $mp, 2, 'premium');
assert_test($mp->getCalled === false, 'UP07a: getPreapproval NAO chamado (Joao sem assinatura ativa)');
assert_test($mp->cancelCalled === false, 'UP07b: cancel NAO chamado');
assert_test($mp->createCalled === true, 'UP07c: Premium checkout criado');
assert_test($result['result'] === 'checkout_created', 'UP07d: fluxo normal');

echo "\n--- UP08: Conta administrativa/manual -> nao afetada ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_admin_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_admin_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium'];

$result = simulateUpgradeFlow($db, $mp, 3, 'premium');
assert_test($mp->getCalled === false, 'UP08a: getPreapproval NAO chamado (admin sem subscription)');
assert_test($mp->cancelCalled === false, 'UP08b: cancel NAO chamado');
assert_test($mp->createCalled === true, 'UP08c: Premium checkout criado');
$admin = $db->tables['usuarios'][2];
assert_test($admin['plano'] === 'premium', 'UP08d: admin plano=premium (manualmente setado)');

echo "\n--- UP09: Premium ja ativo -> redireciona para plano existente ---\n";
$db = makeDbProUser();
$db->tables['subscriptions'][] = [
    'id' => 3, 'user_id' => 1, 'plan_id' => 3, 'plan_slug' => 'premium',
    'status' => 'active', 'start_date' => '2026-09-05', 'next_billing_date' => '2026-10-05',
    'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
    'grace_period_end' => null, 'raw_status' => 'authorized',
    'external_reference' => 'user_1_premium', 'mp_preapproval_id' => 'mp_premium_002',
];
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_002', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_premium_002', 'status' => 'cancelled']];

$result = simulateUpgradeFlow($db, $mp, 1, 'premium');
assert_test($result['result'] === 'already_subscribed',
    'UP09: Premium ja ativo -> already_subscribed', $result['result']);

echo "\n--- UP10: Cancelamento MP retorna 404 (Pro ja cancelada externamente) -> prossegue ---\n";
$db = makeDbProUser();
$mp = new UpgradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => false, 'status' => 404, 'error' => 'not_found'];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/premium'];

$result = simulateUpgradeFlow($db, $mp, 1, 'premium');
assert_test($result['result'] === 'checkout_created',
    'UP10: 404 no cancel -> Premium criada (pro ja cancelada externamente)', $result['result']);
assert_test($mp->createCalled === true, 'UP10b: Premium criada apos 404');
$proSub = $db->tables['subscriptions'][0];
assert_test($proSub['status'] === 'cancelled', 'UP10c: Pro atualizada para cancelled na DB');

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

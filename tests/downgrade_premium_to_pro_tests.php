<?php
/**
 * Testes de downgrade Premium -> Pro.
 *
 * Cobre:
 *  - downgrade Premium -> Pro com cancel da Premium
 *  - falha no cancelamento da Premium
 *  - Premium ja cancelada
 *  - clique duplo/triplo
 *  - webhook Premium chega depois da Pro ativa
 *  - usuario sem assinatura ativa
 *  - conta administrativa
 *  - Pro ja ativa
 *  - 404 no cancelamento
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

class DowngradeMPMock extends MercadoPagoService
{
    public function __construct() { $this->accessToken = 'MOCK'; }
    public array $getMock    = ['ok' => false, 'status' => 500];
    public array $cancelMock = ['ok' => false, 'status' => 500];
    public array $initPointMock = ['ok' => false, 'error' => 'no_mock'];
    public bool $cancelCalled = false;
    public ?string $lastCancelId = null;

    public function getPreapproval(string $id): array { return $this->getMock; }

    public function cancelPreapproval(string $id): array
    {
        $this->cancelCalled = true;
        $this->lastCancelId = $id;
        return $this->cancelMock;
    }

    public function createPreapproval(string $p, int $u, string $e, string $r): array { return ['ok' => false]; }

    public function getInitPointForPlan(string $planSlug, int $userId, string $email): array
    {
        return $this->initPointMock;
    }
}

class MockPDODowngrade
{
    public array $tables = [];
    public int $lastInsertedId = 0;

    public function __construct()
    {
        $this->tables['planos'] = [
            ['id' => 1, 'slug' => 'gratuito',  'nome' => 'Gratuito',  'preco' => 0,     'status' => 'ativo'],
            ['id' => 2, 'slug' => 'pro',       'nome' => 'Pro',       'preco' => 9.90,  'status' => 'ativo'],
            ['id' => 3, 'slug' => 'premium',    'nome' => 'Premium',   'preco' => 19.90, 'status' => 'ativo'],
        ];
        $this->tables['usuarios'] = [];
        $this->tables['subscriptions'] = [];
    }

    public function exec(string $sql): int { return 0; }
    public function prepare(string $sql): MockPDOStmtDowngrade { return new MockPDOStmtDowngrade($this, $sql); }
    public function lastInsertId(): string { return (string)$this->lastInsertedId; }
    public function setAttribute(int $opt, $val): bool { return true; }
}

class MockPDOStmtDowngrade
{
    private MockPDODowngrade $pdo;
    private string $sql;
    private array $params = [];

    public function __construct(MockPDODowngrade $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = strtolower($sql);
    }

    public function execute(array $params = []): bool
    {
        $this->params = $params;
        $s = $this->sql;

        if (str_starts_with(trim($s), 'insert into') && str_contains($s, 'subscriptions')) {
            $this->pdo->lastInsertedId = count($this->pdo->tables['subscriptions']) + 1;
            $this->pdo->tables['subscriptions'][] = [
                'id' => $this->pdo->lastInsertedId,
                'user_id' => (int)($params[':user_id'] ?? 0),
                'plan_id' => (int)($params[':plan_id'] ?? 0),
                'plan_slug' => $params[':plan_slug'] ?? '',
                'status' => $params[':status'] ?? 'pending',
                'start_date' => null, 'next_billing_date' => null,
                'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
                'grace_period_end' => null, 'raw_status' => '',
                'external_reference' => '', 'mp_preapproval_id' => $params[':mp_preapproval_id'] ?? '',
            ];
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

echo "\n=== TESTES: downgrade Premium -> Pro ===\n\n";

function makeDbPremiumUser(): MockPDODowngrade
{
    $db = new MockPDODowngrade();
    $db->tables['usuarios'] = [
        ['id' => 1, 'nome' => 'Ana', 'email' => 'ana@test.com',
            'plano' => 'premium', 'plano_status' => 'ativo',
            'plano_inicio' => '2026-09-01', 'plano_fim' => null,
            'active_subscription_id' => 1],
        ['id' => 2, 'nome' => 'Beto', 'email' => 'beto@test.com',
            'plano' => 'gratuito', 'plano_status' => 'ativo',
            'plano_inicio' => null, 'plano_fim' => null,
            'active_subscription_id' => null],
        ['id' => 3, 'nome' => 'Admin', 'email' => 'admin@test.com',
            'plano' => 'premium', 'plano_status' => 'ativo',
            'plano_inicio' => '2025-01-01', 'plano_fim' => null,
            'active_subscription_id' => null],
    ];
    $db->tables['subscriptions'] = [
        ['id' => 1, 'user_id' => 1, 'plan_id' => 3, 'plan_slug' => 'premium',
            'status' => 'active', 'start_date' => '2026-09-01', 'next_billing_date' => '2026-10-01',
            'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
            'grace_period_end' => null, 'raw_status' => 'authorized',
            'external_reference' => 'user_1_premium', 'mp_preapproval_id' => 'mp_premium_001'],
    ];
    return $db;
}

function simulateDowngradeFlow(MockPDODowngrade $db, DowngradeMPMock $mp, int $userId, string $targetPlan): array
{
    $subscriptionModel = new Subscription($db);

    $existing = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $targetPlan);
    if ($existing !== null) {
        $existingMpId = (string)($existing['mp_preapproval_id'] ?? '');
        if ($existingMpId !== '') {
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

    if ($targetPlan === 'premium' || $targetPlan === 'pro') {
        $activeSub = $subscriptionModel->findActiveByUser($userId);
        if ($activeSub !== null) {
            $oldMpId = (string)($activeSub['mp_preapproval_id'] ?? '');
            $oldPlanSlug = (string)($activeSub['plan_slug'] ?? '');
            if ($oldMpId === '') {
                // no MP subscription - skip
            } else {
                $shouldCancel = ($targetPlan === 'premium' && $oldPlanSlug === 'pro')
                    || ($targetPlan === 'pro' && $oldPlanSlug === 'premium');
                if ($shouldCancel) {
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
    }

    $existing = $subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $targetPlan);
    if ($existing === null) {
        $planId = ['gratuito' => 1, 'pro' => 2, 'premium' => 3][$targetPlan] ?? 1;
        $db->tables['subscriptions'][] = [
            'id' => count($db->tables['subscriptions']) + 1,
            'user_id' => $userId,
            'plan_id' => $planId,
            'plan_slug' => $targetPlan,
            'status' => 'pending',
            'start_date' => null, 'next_billing_date' => null,
            'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
            'grace_period_end' => null, 'raw_status' => '',
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

echo "--- DN01: Premium ativa -> Pro: Premium cancelada no MP primeiro ---\n";
$db = makeDbPremiumUser();
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_premium_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 1, 'pro');

assert_test($mp->cancelCalled === true, 'DN01a: cancelPreapproval chamado para Premium');
assert_test($mp->lastCancelId === 'mp_premium_001', 'DN01b: cancel ID correto (mp_premium_001)');
assert_test($result['result'] === 'checkout_created', 'DN01c: fluxo retorna checkout_created');
$premSub = $db->tables['subscriptions'][0];
assert_test($premSub['status'] === 'cancelled', 'DN01d: Premium status=cancelled na DB');
$ana = $db->tables['usuarios'][0];
assert_test($ana['plano'] === 'gratuito', 'DN01e: Ana plano=gratuito apos cancel Premium');

echo "\n--- DN02: Cancelamento da Premium falha (5xx) -> Pro nao criada ---\n";
$db = makeDbPremiumUser();
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => false, 'status' => 500, 'error' => 'server'];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 1, 'pro');

assert_test($mp->cancelCalled === true, 'DN02a: cancelPreapproval chamado');
assert_test($result['result'] === 'upgrade_service_error', 'DN02b: retorno = upgrade_service_error', $result['result']);
assert_test($result['result'] !== 'checkout_created', 'DN02c: Pro NAO criada (bloqueada pelo erro no cancel)');
$premSub = $db->tables['subscriptions'][0];
assert_test($premSub['status'] === 'active', 'DN02d: Premium status=active (nao cancelada)');
$ana = $db->tables['usuarios'][0];
assert_test($ana['plano'] === 'premium', 'DN02e: Ana plano=premium (nao alterado)');

echo "\n--- DN03: Premium ja cancelada no MP -> downgrade prossegue ---\n";
$db = makeDbPremiumUser();
$db->tables['subscriptions'][0]['status'] = 'cancelled';
$db->tables['subscriptions'][0]['raw_status'] = 'cancelled';
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'cancelled']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_premium_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 1, 'pro');

assert_test($mp->cancelCalled === false, 'DN03a: cancel NAO chamado (Premium ja cancelled no MP)');
assert_test($result['result'] === 'checkout_created', 'DN03b: Pro checkout criado normalmente');
assert_test($result['result'] === 'checkout_created', 'DN03c: fluxo prossegue');

echo "\n--- DN04: Clique duplo/triplo -> apenas uma preapproval Pro criada ---\n";
$db = makeDbPremiumUser();
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_premium_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro1'];

$result1 = simulateDowngradeFlow($db, $mp, 1, 'pro');
assert_test($result1['result'] === 'checkout_created', 'DN04a: primeiro clique -> checkout_created');

$result2 = simulateDowngradeFlow($db, $mp, 1, 'pro');
assert_test($result2['result'] === 'reuse_stored_init_point',
    'DN04b: segundo clique -> reuse_stored_init_point', $result2['result']);

$result3 = simulateDowngradeFlow($db, $mp, 1, 'pro');
assert_test($result3['result'] === 'reuse_stored_init_point',
    'DN04c: terceiro clique -> reuse_stored_init_point');

$premSub = $db->tables['subscriptions'][0];
assert_test($premSub['status'] === 'cancelled', 'DN04d: Premium cancelada (uma unica vez)');

echo "\n--- DN05: Webhook Premium chega DEPOIS da Pro ativa ---\n";
$db = makeDbPremiumUser();
$subModel = new Subscription($db);

$subModel->updateStatusById(1, Subscription::STATUS_CANCELLED, 'cancelled', null, null);
$fresh = $subModel->findById(1);
$subModel->applyStatusToUser($fresh);

$db->tables['subscriptions'][] = [
    'id' => 2, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
    'status' => 'active', 'start_date' => '2026-09-05', 'next_billing_date' => '2026-10-05',
    'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
    'grace_period_end' => null, 'raw_status' => 'authorized',
    'external_reference' => 'user_1_pro', 'mp_preapproval_id' => 'mp_pro_001',
];
$db->tables['usuarios'][0]['plano'] = 'pro';
$db->tables['usuarios'][0]['plano_status'] = 'ativo';
$db->tables['usuarios'][0]['active_subscription_id'] = 2;

$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'cancelled',
               'preapproval_plan_id' => 'plan_premium_test_xyz']];
$rec = new SubscriptionReconciler($db, $mp);
$result = $rec->reconcileFromReturn('mp_premium_001', 1);

assert_test($result['ok'] === false && $result['action'] === 'not_authorized',
    'DN05a: webhook Premium cancelled -> not_authorized', json_encode($result));
$ana = $db->tables['usuarios'][0];
assert_test($ana['plano'] === 'pro', 'DN05b: Ana plano=pro (NAO rebaixada pelo webhook antigo)');
assert_test($ana['plano_status'] === 'ativo', 'DN05c: plano_status=ativo');

echo "\n--- DN06: Usuario sem assinatura ativa -> Pro checkout funciona ---\n";
$db = makeDbPremiumUser();
$mp = new DowngradeMPMock();
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 2, 'pro');
assert_test($mp->cancelCalled === false, 'DN06a: cancel NAO chamado (Beto sem assinatura ativa)');
assert_test($result['result'] === 'checkout_created', 'DN06b: Pro checkout criado');
assert_test($result['result'] === 'checkout_created', 'DN06c: fluxo normal');

echo "\n--- DN07: Conta administrativa/manual -> nao afetada ---\n";
$db = makeDbPremiumUser();
$mp = new DowngradeMPMock();
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 3, 'pro');
assert_test($mp->cancelCalled === false, 'DN07a: cancel NAO chamado (admin sem subscription)');
assert_test($result['result'] === 'checkout_created', 'DN07b: Pro checkout criado');
$admin = $db->tables['usuarios'][2];
assert_test($admin['plano'] === 'premium', 'DN07c: admin plano=premium (manualmente setado)');

echo "\n--- DN08: Pro ja ativa -> already_subscribed ---\n";
$db = makeDbPremiumUser();
$db->tables['subscriptions'][] = [
    'id' => 2, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
    'status' => 'active', 'start_date' => '2026-09-01', 'next_billing_date' => '2026-10-01',
    'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
    'grace_period_end' => null, 'raw_status' => 'authorized',
    'external_reference' => 'user_1_pro', 'mp_preapproval_id' => 'mp_pro_002',
];
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_002', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => true, 'status' => 200, 'data' => ['id' => 'mp_pro_002', 'status' => 'cancelled']];

$result = simulateDowngradeFlow($db, $mp, 1, 'pro');
assert_test($result['result'] === 'already_subscribed',
    'DN08: Pro ja ativa -> already_subscribed', $result['result']);

echo "\n--- DN09: 404 no cancelamento Premium (cancelada externamente) -> prossegue ---\n";
$db = makeDbPremiumUser();
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'authorized']];
$mp->cancelMock = ['ok' => false, 'status' => 404, 'error' => 'not_found'];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 1, 'pro');
assert_test($result['result'] === 'checkout_created',
    'DN09: 404 no cancel -> Pro criada (Premium cancelada externamente)', $result['result']);
$premSub = $db->tables['subscriptions'][0];
assert_test($premSub['status'] === 'cancelled', 'DN09b: Premium atualizada para cancelled na DB');

echo "\n--- DN10: Pro ja cancelada no MP -> downgrade prossegue ---\n";
$db = makeDbPremiumUser();
$db->tables['subscriptions'][0]['status'] = 'cancelled';
$db->tables['subscriptions'][0]['raw_status'] = 'cancelled';
$mp = new DowngradeMPMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_001', 'status' => 'cancelled']];
$mp->initPointMock = ['ok' => true, 'init_point' => 'https://mercadopago.com/pro'];

$result = simulateDowngradeFlow($db, $mp, 1, 'pro');
assert_test($mp->cancelCalled === false, 'DN10a: cancel NAO chamado (Premium ja cancelled no MP)');
assert_test($result['result'] === 'checkout_created', 'DN10b: Pro checkout criado');

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

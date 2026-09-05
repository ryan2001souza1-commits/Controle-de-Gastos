<?php
/**
 * Testes do ciclo de vida de cancelamento de assinatura.
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

class MercadoPagoServiceCancelMock extends MercadoPagoService
{
    public function __construct() { $this->accessToken = 'MOCK'; }
    public array $cancelMock = ['ok' => false, 'status' => 500, 'error' => 'no_mock'];
    public array $getMock    = ['ok' => false, 'status' => 500, 'error' => 'no_mock'];
    public bool $cancelCalled = false;
    public ?string $cancelCalledId = null;

    public function cancelPreapproval(string $id): array
    {
        $this->cancelCalled = true;
        $this->cancelCalledId = $id;
        return $this->cancelMock;
    }

    public function getPreapproval(string $id): array { return $this->getMock; }
    public function createPreapproval(string $p, int $u, string $e, string $r): array { return ['ok' => false]; }
    public function getInitPointForPlan(string $p, int $u, string $e): array { return ['ok' => false]; }
}

class MockPDOCancel
{
    public array $tables = [];
    public int $lastInsertedId = 0;

    public function __construct()
    {
        $this->tables['planos'] = [
            ['id' => 1, 'slug' => 'gratuito', 'nome' => 'Gratuito', 'preco' => 0, 'status' => 'ativo'],
            ['id' => 2, 'slug' => 'pro',      'nome' => 'Pro',      'preco' => 9.90, 'status' => 'ativo'],
            ['id' => 3, 'slug' => 'premium',  'nome' => 'Premium',  'preco' => 19.90, 'status' => 'ativo'],
        ];
        $this->tables['usuarios'] = [];
        $this->tables['subscriptions'] = [];
    }

    public function exec(string $sql): int { return 0; }
    public function prepare(string $sql): MockPDOStmtCancel { return new MockPDOStmtCancel($this, $sql); }
    public function lastInsertId(): string { return (string)$this->lastInsertedId; }
    public function setAttribute(int $opt, $val): bool { return true; }
}

class MockPDOStmtCancel
{
    private MockPDOCancel $pdo;
    private string $sql;
    private array $params = [];

    public function __construct(MockPDOCancel $pdo, string $sql)
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
                        if (isset($params[':mpid']))       $sub['mp_preapproval_id'] = $params[':mpid'];
                        if (array_key_exists(':grace_period_end', $params)) {
                            $sub['grace_period_end'] = $params[':grace_period_end'];
                        }
                        if (array_key_exists(':next_billing_date', $params) && $params[':next_billing_date'] !== null) {
                            $sub['next_billing_date'] = $params[':next_billing_date'];
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
        return false;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH): mixed
    {
        $p = $this->params;
        $s = $this->sql;

        if (str_contains($s, 'from subscriptions')) {
            if (isset($p[':uid']) && isset($p[':slug'])) {
                $uid = (int)$p[':uid'];
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

    public function setAttribute(int $opt, $val): bool { return true; }

    public function rowCount(): int { return 1; }
}

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_test_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_test_xyz');

echo "\n=== TESTES: ciclo de vida de cancelamento ===\n\n";

function makeDbCancel(): MockPDOCancel
{
    $db = new MockPDOCancel();
    $db->tables['usuarios'] = [
        ['id' => 1, 'nome' => 'Maria', 'email' => 'maria@test.com',
            'plano' => 'pro', 'plano_status' => 'ativo',
            'plano_inicio' => '2026-09-05', 'plano_fim' => null,
            'active_subscription_id' => 1],
        ['id' => 2, 'nome' => 'Joao', 'email' => 'joao@test.com',
            'plano' => 'pro', 'plano_status' => 'ativo',
            'plano_inicio' => '2026-09-01', 'plano_fim' => null,
            'active_subscription_id' => 2],
        ['id' => 3, 'nome' => 'Admin', 'email' => 'admin@test.com',
            'plano' => 'premium', 'plano_status' => 'ativo',
            'plano_inicio' => '2025-01-01', 'plano_fim' => null,
            'active_subscription_id' => null],
    ];
    $db->tables['subscriptions'] = [
        ['id' => 1, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
            'status' => 'active', 'start_date' => null, 'next_billing_date' => '2026-10-05',
            'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
            'grace_period_end' => null, 'raw_status' => 'authorized',
            'external_reference' => 'user_1_pro', 'mp_preapproval_id' => 'mp_pro_001'],
        ['id' => 2, 'user_id' => 2, 'plan_id' => 2, 'plan_slug' => 'pro',
            'status' => 'active', 'start_date' => null, 'next_billing_date' => '2026-10-05',
            'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
            'grace_period_end' => null, 'raw_status' => 'authorized',
            'external_reference' => 'user_2_pro', 'mp_preapproval_id' => 'mp_pro_002'],
    ];
    return $db;
}

echo "--- CT01: findActiveByUser retorna subscription ativa com mp_id ---\n";
$db = makeDbCancel();
$subModel = new Subscription($db);
$active = $subModel->findActiveByUser(1);
assert_test($active !== null && $active['mp_preapproval_id'] === 'mp_pro_001',
    'CT01a: Maria tem subscription ativa com mp_preapproval_id');
assert_test($active['plan_slug'] === 'pro', 'CT01b: plan_slug=pro');
assert_test($active['status'] === 'active', 'CT01c: status=active');
$active2 = $subModel->findActiveByUser(2);
assert_test($active2 !== null && $active2['mp_preapproval_id'] === 'mp_pro_002',
    'CT01d: Joao tem subscription ativa');

echo "\n--- CT02: findActiveByUser retorna null para conta sem mp_preapproval_id ---\n";
$db = makeDbCancel();
$subModel = new Subscription($db);
$active = $subModel->findActiveByUser(3);
assert_test($active === null, 'CT02: user admin sem mp_preapproval_id -> null');

echo "\n--- CT03: findActiveByUser ignora pending sem mp_preapproval_id ---\n";
$db = new MockPDOCancel();
$db->tables['usuarios'] = [
    ['id' => 10, 'nome' => 'Zeca', 'email' => 'zeca@test.com',
        'plano' => 'gratuito', 'plano_status' => 'ativo',
        'plano_inicio' => null, 'plano_fim' => null, 'active_subscription_id' => null],
];
$db->tables['subscriptions'] = [
    ['id' => 10, 'user_id' => 10, 'plan_id' => 2, 'plan_slug' => 'pro',
        'status' => 'pending', 'start_date' => null, 'next_billing_date' => null,
        'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
        'grace_period_end' => null, 'raw_status' => '',
        'external_reference' => 'user_10_pro', 'mp_preapproval_id' => ''],
];
$subModel = new Subscription($db);
$active = $subModel->findActiveByUser(10);
assert_test($active === null, 'CT03: pending sem mp_preapproval_id -> null');

echo "\n--- CT04: cancelamento via action=cancel (fluxo completo) ---\n";
$db = makeDbCancel();
$mp = new MercadoPagoServiceCancelMock();
$mp->cancelMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$subModel = new Subscription($db);

$active = $subModel->findActiveByUser(1);
assert_test($active !== null, 'CT04a: subscription ativa encontrada');

$cancelResult = $mp->cancelPreapproval($active['mp_preapproval_id']);
assert_test($cancelResult['ok'] === true, 'CT04b: MP PUT ok');
assert_test($cancelResult['data']['status'] === 'cancelled', 'CT04c: MP confirma cancelled');

$mpStatus = strtolower((string)($cancelResult['data']['status']));
$internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatus);
$subModel->updateStatusById((int)$active['id'], $internalStatus, $mpStatus, null, null);
$fresh = $subModel->findById((int)$active['id']);
$subModel->applyStatusToUser($fresh);

$sub = $db->tables['subscriptions'][0];
assert_test($sub['status'] === 'cancelled', 'CT04d: subscription status=cancelled');
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'CT04e: plano=gratuito', 'plano=' . $maria['plano']);
assert_test($maria['plano_status'] === 'cancelado', 'CT04f: plano_status=cancelado');
assert_test($maria['active_subscription_id'] === null, 'CT04g: active_subscription_id=NULL');

echo "\n--- CT05: cross-user (Joao tenta usar preapproval da Maria) ---\n";
$db = makeDbCancel();
$mp = new MercadoPagoServiceCancelMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'authorized', 'preapproval_plan_id' => 'plan_pro_test_xyz']];
$rec = new SubscriptionReconciler($db, $mp);
$result = $rec->reconcileFromReturn('mp_pro_001', 2);
assert_test($result['ok'] === false && $result['action'] === 'user_mismatch',
    'CT05: preapproval de Maria nao pode ser usado por Joao', json_encode($result));
assert_test($mp->cancelCalled === false, 'CT05: cancel NAO chamado');
$joao = $db->tables['usuarios'][1];
assert_test($joao['plano'] === 'pro', 'CT05: Joao NAO teve plano alterado');

echo "\n--- CT06: preapproval com plan_slug diferente do existente ---\n";
$db = makeDbCancel();
$mp = new MercadoPagoServiceCancelMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_premium_x', 'status' => 'authorized',
               'preapproval_plan_id' => 'plan_premium_test_xyz']];
$rec = new SubscriptionReconciler($db, $mp);
$result = $rec->reconcileFromReturn('mp_premium_x', 2);
assert_test($result['ok'] === false && $result['action'] === 'no_pending_for_user',
    'CT06: Joao nao tem pending premium -> no_pending_for_user', json_encode($result));
assert_test($mp->cancelCalled === false, 'CT06: cancel NAO chamado');

echo "\n--- CT07: MP falha 5xx (transiente) ---\n";
$db = makeDbCancel();
$mp = new MercadoPagoServiceCancelMock();
$mp->getMock = ['ok' => false, 'status' => 500, 'error' => 'server'];
$rec = new SubscriptionReconciler($db, $mp);
$result = $rec->reconcileFromReturn('mp_pro_001', 1);
assert_test($result['ok'] === false && $result['action'] === 'transient_error',
    'CT07: MP 5xx -> transient_error', json_encode($result));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'CT07: plano NAO alterado (falha transiente)');

echo "\n--- CT08: webhook chega ANTES do cancelamento manual ---\n";
$db = makeDbCancel();
$mp = new MercadoPagoServiceCancelMock();
$mp->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled', 'preapproval_plan_id' => 'plan_pro_test_xyz']];
$rec = new SubscriptionReconciler($db, $mp);
$result = $rec->reconcileFromReturn('mp_pro_001', 1);
assert_test($result['ok'] === false && $result['action'] === 'not_authorized',
    'CT08: webhook cancelled primeiro -> not_authorized', json_encode($result));
assert_test($mp->cancelCalled === false, 'CT08: cancel NAO chamado pelo webhook');
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'CT08: Maria continua pro ate cancelamento manual');

echo "\n--- CT09: webhook chega DEPOIS do cancelamento manual (idempotencia) ---\n";
$db = makeDbCancel();
$mp = new MercadoPagoServiceCancelMock();
$mp->cancelMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled']];
$subModel = new Subscription($db);

$active = $subModel->findActiveByUser(1);
$cancelResult = $mp->cancelPreapproval($active['mp_preapproval_id']);
$mpStatus = strtolower((string)($cancelResult['data']['status']));
$internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatus);
$subModel->updateStatusById((int)$active['id'], $internalStatus, $mpStatus, null, null);
$fresh = $subModel->findById((int)$active['id']);
$subModel->applyStatusToUser($fresh);

$mp2 = new MercadoPagoServiceCancelMock();
$mp2->getMock = ['ok' => true, 'status' => 200,
    'data' => ['id' => 'mp_pro_001', 'status' => 'cancelled', 'preapproval_plan_id' => 'plan_pro_test_xyz']];
$rec2 = new SubscriptionReconciler($db, $mp2);
$result2 = $rec2->reconcileFromReturn('mp_pro_001', 1);
assert_test($result2['ok'] === false && $result2['action'] === 'not_authorized',
    'CT09: segunda chamada (webhook duplicado) -> not_authorized (idempotente)', json_encode($result2));
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'CT09: plano=gratuito (mantido apos webhook duplicado)');

echo "\n--- CT10: updateStatusById marca cancelled (antes de applyStatusToUser) ---\n";
$db = makeDbCancel();
$subModel = new Subscription($db);
$subModel->updateStatusById(1, Subscription::STATUS_CANCELLED, 'cancelled', null, null);
$sub = $db->tables['subscriptions'][0];
assert_test($sub['status'] === 'cancelled', 'CT10: updateStatusById atualizou status=cancelled');
assert_test($sub['raw_status'] === 'cancelled', 'CT10: raw_status=cancelled');

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

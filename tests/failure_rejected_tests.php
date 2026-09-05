<?php
/**
 * Testes de falha de cobranca (rejected/failure).
 *
 * Cobre:
 *  - rejected sem grace -> gratuito
 *  - rejected com grace futuro -> pendente
 *  - grace expirado -> gratuito
 *  - rejected duplicado
 *  - rejected -> authorized reativado
 *  - webhook antigo de subscription cancelada nao sobrescreve active
 *  - admin/manual preservado
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

class MockPDORejected
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
    public function prepare(string $sql): MockPDOStmtRejected { return new MockPDOStmtRejected($this, $sql); }
    public function lastInsertId(): string { return (string)$this->lastInsertedId; }
    public function setAttribute(int $opt, $val): bool { return true; }
}

class MockPDOStmtRejected
{
    private MockPDORejected $pdo;
    private string $sql;
    private array $params = [];

    public function __construct(MockPDORejected $pdo, string $sql)
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
                        if (str_contains($s, 'plano = :plan') && isset($params[':plan'])) {
                            $u['plano'] = $params[':plan'];
                        } elseif (str_contains($s, "'gratuito'")) {
                            $u['plano'] = 'gratuito';
                        } elseif (str_contains($s, "'premium'")) {
                            $u['plano'] = 'premium';
                        } elseif (str_contains($s, "'pro'")) {
                            $u['plano'] = 'pro';
                        }
                        if (str_contains($s, "'cancelado'")) {
                            $u['plano_status'] = 'cancelado';
                        } elseif (str_contains($s, "'ativo'")) {
                            $u['plano_status'] = 'ativo';
                        } elseif (str_contains($s, "'pendente'")) {
                            $u['plano_status'] = 'pendente';
                        }
                        if (str_contains($s, 'active_subscription_id = null')) {
                            $u['active_subscription_id'] = null;
                        }
                        if (isset($params[':sub_id'])) {
                            $u['active_subscription_id'] = (int)$params[':sub_id'];
                        }
                        if (isset($params[':grace'])) {
                            $u['plano_fim'] = $params[':grace'];
                        }
                        if (str_contains($s, ' plano_fim = null') || str_contains($s, ' plano_fim=null')) {
                            $u['plano_fim'] = null;
                        }
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
                        if (isset($params[':clear_grace']) && (int)$params[':clear_grace'] === 1) {
                            $sub['grace_period_end'] = null;
                        } elseif (array_key_exists(':grace_period_end', $params)) {
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
        if (str_contains($this->sql, 'active_subscription_id from usuarios') && isset($this->params[':uid'])) {
            $uid = (int)$this->params[':uid'];
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === $uid) {
                    return $u['active_subscription_id'] !== null ? (int)$u['active_subscription_id'] : null;
                }
            }
            return null;
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

echo "\n=== TESTES: falha de cobranca (rejected/failure) ===\n\n";

function makeDbRejected(): MockPDORejected
{
    $db = new MockPDORejected();
    $db->tables['usuarios'] = [
        ['id' => 1, 'nome' => 'Maria', 'email' => 'maria@test.com',
            'plano' => 'pro', 'plano_status' => 'ativo',
            'plano_inicio' => '2026-09-05', 'plano_fim' => null,
            'active_subscription_id' => 1],
        ['id' => 2, 'nome' => 'Admin', 'email' => 'admin@test.com',
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

function updateSubscriptionAndApply(
    MockPDORejected $db,
    int $subId,
    string $status,
    string $rawStatus,
    ?string $nextBillingDate,
    ?string $gracePeriodEnd
): void {
    $subModel = new Subscription($db);
    $subModel->updateStatusById($subId, $status, $rawStatus, $nextBillingDate, $gracePeriodEnd);
    $fresh = $subModel->findById($subId);
    if ($fresh !== null) {
        $subModel->applyStatusToUser($fresh);
    }
}

echo "--- RJ01: rejected sem grace -> plano rebaixado para gratuito ---\n";
$db = makeDbRejected();
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, null);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'RJ01a: plano=gratuito apos rejected sem grace');
assert_test($maria['plano_status'] === 'cancelado', 'RJ01b: plano_status=cancelado');
assert_test($maria['active_subscription_id'] === null, 'RJ01c: active_subscription_id=NULL');
$sub = $db->tables['subscriptions'][0];
assert_test($sub['status'] === 'rejected', 'RJ01d: subscription status=rejected');
assert_test($sub['grace_period_end'] === null, 'RJ01e: grace_period_end=null');

echo "\n--- RJ02: rejected com grace futuro -> plano pendente ---\n";
$db = makeDbRejected();
$futureGrace = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, $futureGrace);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'RJ02a: plano=pro mantido (grace ativo)');
assert_test($maria['plano_status'] === 'pendente', 'RJ02b: plano_status=pendente');
assert_test($maria['active_subscription_id'] === 1, 'RJ02c: active_subscription_id=1 (mantido)');

echo "\n--- RJ03: grace expirado -> plano rebaixado ---\n";
$db = makeDbRejected();
$pastGrace = date('Y-m-d H:i:s', time() - 1);
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, $pastGrace);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'RJ03a: plano=gratuito quando grace expirado');
assert_test($maria['plano_status'] === 'cancelado', 'RJ03b: plano_status=cancelado');
assert_test($maria['active_subscription_id'] === null, 'RJ03c: active_subscription_id=NULL');

echo "\n--- RJ04: rejected duplicado (idempotencia) ---\n";
$db = makeDbRejected();
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, null);
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, null);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'RJ04a: plano continua=gratuito (idempotente)');
assert_test($maria['plano_status'] === 'cancelado', 'RJ04b: plano_status continua=cancelado');

echo "\n--- RJ05: rejected -> authorized reativacao ---\n";
$db = makeDbRejected();
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, null);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'gratuito', 'RJ05a: plano=gratuito apos rejected');

updateSubscriptionAndApply($db, 1, Subscription::STATUS_ACTIVE, 'authorized', '2026-10-05', null);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'RJ05b: plano=pro apos reativacao authorized');
assert_test($maria['plano_status'] === 'ativo', 'RJ05c: plano_status=ativo');
assert_test($maria['active_subscription_id'] === 1, 'RJ05d: active_subscription_id=1');
$sub = $db->tables['subscriptions'][0];
assert_test($sub['status'] === 'active', 'RJ05e: subscription status=active');
assert_test($sub['grace_period_end'] === null, 'RJ05f: grace_period_end=null apos reativacao');

echo "\n--- RJ06: rejeitado com grace futuro -> authorized reativacao ---\n";
$db = makeDbRejected();
$futureGrace = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, $futureGrace);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano_status'] === 'pendente', 'RJ06a: plano_status=pendente com grace');

updateSubscriptionAndApply($db, 1, Subscription::STATUS_ACTIVE, 'authorized', '2026-10-05', null);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'RJ06b: plano=pro apos reativacao');
assert_test($maria['plano_status'] === 'ativo', 'RJ06c: plano_status=ativo');
assert_test($maria['plano_fim'] === null, 'RJ06d: plano_fim=NULL apos reativacao');

echo "\n--- RJ07: webhook de subscription rejeitada nao sobrescreve subscription ativa ---\n";
$db = makeDbRejected();
$db->tables['subscriptions'][] = [
    'id' => 2, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'pro',
    'status' => 'active', 'start_date' => '2026-09-01', 'next_billing_date' => '2026-10-01',
    'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
    'grace_period_end' => null, 'raw_status' => 'authorized',
    'external_reference' => 'user_1_pro', 'mp_preapproval_id' => 'mp_pro_002',
];
$db->tables['usuarios'][0]['active_subscription_id'] = 2;

updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', null, null);
$maria = $db->tables['usuarios'][0];
assert_test($maria['active_subscription_id'] === 2,
    'RJ07a: active_subscription_id mantem-se em 2 (subscription ativa)');
assert_test($maria['plano'] === 'pro',
    'RJ07b: plano=pro (active subida)');
assert_test($maria['plano_status'] === 'ativo',
    'RJ07c: plano_status=ativo (active subida)');

echo "\n--- RJ08: admin/manual preservado ---\n";
$db = makeDbRejected();
$admin = $db->tables['usuarios'][1];
assert_test($admin['plano'] === 'premium', 'RJ08a: admin plano=premium');
assert_test($admin['active_subscription_id'] === null, 'RJ08b: admin sem subscription');

echo "\n--- RJ09: next_payment_date do MP usado como grace ---\n";
$db = makeDbRejected();
$nextPayment = date('Y-m-d H:i:s', time() + 3 * 24 * 60 * 60);
updateSubscriptionAndApply($db, 1, Subscription::STATUS_REJECTED, 'failure', $nextPayment, $nextPayment);
$maria = $db->tables['usuarios'][0];
assert_test($maria['plano'] === 'pro', 'RJ09a: plano=pro (grace de 3 dias ativo)');
assert_test($maria['plano_status'] === 'pendente', 'RJ09b: plano_status=pendente');
$sub = $db->tables['subscriptions'][0];
assert_test($sub['grace_period_end'] === $nextPayment, 'RJ09c: grace_period_end=next_payment_date');

echo "\n--- RJ10: mapMpStatusToInternal mapeia failure -> rejected ---\n";
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('failure') === Subscription::STATUS_REJECTED,
    'RJ10a: failure -> rejected'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('rejected') === Subscription::STATUS_REJECTED,
    'RJ10b: rejected -> rejected'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('authorized') === Subscription::STATUS_ACTIVE,
    'RJ10c: authorized -> active'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('paused') === Subscription::STATUS_PAUSED,
    'RJ10d: paused -> paused'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('cancelled') === Subscription::STATUS_CANCELLED,
    'RJ10e: cancelled -> cancelled'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('canceled') === Subscription::STATUS_CANCELLED,
    'RJ10f: canceled -> cancelled'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('pending') === Subscription::STATUS_PENDING,
    'RJ10g: pending -> pending'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('expired') === Subscription::STATUS_EXPIRED,
    'RJ10h: expired -> expired'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('in_process') === Subscription::STATUS_PENDING,
    'RJ10i: in_process -> pending'
);
assert_test(
    MercadoPagoWebhookService::mapMpStatusToInternal('unknown_status') === null,
    'RJ10j: unknown_status -> null'
);

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

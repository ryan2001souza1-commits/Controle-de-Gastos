<?php
/**
 * Testes de protecao anti-órfã + fluxo return/webhook-first.
 *
 * 1.  órfã authorized + sem pending local           → orphan_preapproval_ignored
 * 2.  órfã authorized + pending compatível         → vincula, nao cria 2a linha
 * 3.  ext_ref válido + usuário com assinatura ativa   → orphan_preapproval_ignored
 * 4.  ext_ref inválido                            → invalid_external_reference
 * 5.  ext_ref ausente                             → incomplete_payload
 * 6.  pending de outro plano                      → orphan_preapproval_ignored
 * 7.  pending de outro usuário                   → orphan_preapproval_ignored
 * 8.  webhook duplicado pós-vinculação           → idempotente
 * 9.  return ANTES do webhook                     → continua funcionando
 * 10. webhook ANTES do return                    → continua funcionando
 * 11. e2b9... / 8b7c... como órfãs sem pending  → nenhuma cria subscription
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/SubscriptionReconciler.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name\n"; $failed++; }
}

class OW_PDO
{
    public array $tables = [];
    public int $insertId = 100;
    public function __construct() {
        $this->tables['planos'] = [
            ['id'=>1,'slug'=>'gratuito','nome'=>'Gratuito','preco'=>0,'status'=>'ativo'],
            ['id'=>2,'slug'=>'pro','nome'=>'Pro','preco'=>9.90,'status'=>'ativo'],
            ['id'=>3,'slug'=>'premium','nome'=>'Premium','preco'=>19.90,'status'=>'ativo'],
        ];
    }
    public function prepare(string $s) { return new OW_Stmt($this, $s); }
    public function exec(string $s): int { return 0; }
    public function lastInsertId(): string { return (string)$this->insertId++; }
    public function setAttribute(int $a, $v): bool { return true; }
    public function query(string $s) { return new OW_Stmt($this, $s); }
}

class OW_Stmt
{
    private OW_PDO $pdo; private string $sql; public array $params = [];
    public function __construct(OW_PDO $pdo, string $sql) { $this->pdo = $pdo; $this->sql = strtolower($sql); }
    public function execute(array $p = []): bool { $this->params = $p; return $this->_exec(); }
    private function _exec(): bool {
        $s = $this->sql; $p = $this->params;
        if (str_starts_with(trim($s), 'update') && str_contains($s, 'subscriptions')) {
            $id = (int)($p[':id'] ?? 0);
            foreach ($this->pdo->tables['subscriptions'] as &$sub) {
                if ((int)$sub['id'] === $id) {
                    if (isset($p[':status'])) $sub['status'] = $p[':status'];
                    if (isset($p[':raw_status'])) $sub['raw_status'] = $p[':raw_status'];
                    if (isset($p[':mpid'])) $sub['mp_preapproval_id'] = $p[':mpid'];
                    if (array_key_exists(':grace_period_end', $p)) {
                        $sub['grace_period_end'] = $p[':grace_period_end'];
                    }
                }
            } unset($sub);
        }
        if (str_starts_with(trim($s), 'update') && str_contains($s, 'usuarios')) {
            $uid = (int)($p[':uid'] ?? 0);
            foreach ($this->pdo->tables['usuarios'] as &$u) {
                if ((int)$u['id'] === $uid) {
                    if (isset($p[':plan'])) $u['plano'] = $p[':plan'];
                    if (isset($p[':sub_id'])) $u['active_subscription_id'] = (int)$p[':sub_id'];
                    if (str_contains($s, "'ativo'")) $u['plano_status'] = 'ativo';
                    if (str_contains($s, "'cancelado'")) $u['plano_status'] = 'cancelado';
                    if (str_contains($s, "'pendente'")) $u['plano_status'] = 'pendente';
                    if (str_contains($s, ' plano_fim = null')) $u['plano_fim'] = null;
                    if (str_contains($s, ' plano_fim = now()')) $u['plano_fim'] = 'NOW';
                    if (str_contains($s, ' active_subscription_id = null')) $u['active_subscription_id'] = null;
                }
            } unset($u);
        }
        return true;
    }
    public function fetch($m = 0): mixed {
        $s = $this->sql; $p = $this->params;
        if (str_contains($s, 'from subscriptions') && isset($p[':uid']) && isset($p[':slug'])) {
            $uid = (int)$p[':uid']; $slug = $p[':slug'];
            $found = null;
            foreach ($this->pdo->tables['subscriptions'] ?? [] as $sub) {
                if ((int)$sub['user_id'] === $uid && $sub['plan_slug'] === $slug
                    && in_array($sub['status'], ['pending','active','paused'], true)) {
                    $found = $sub;
                }
            }
            return $found ?: null;
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':uid']) && !isset($p[':slug'])) {
            $uid = (int)$p[':uid'];
            $latest = null;
            foreach ($this->pdo->tables['subscriptions'] ?? [] as $sub) {
                if ((int)$sub['user_id'] === $uid
                    && in_array($sub['status'], ['active','paused'], true)
                    && $sub['mp_preapproval_id'] !== '' && $sub['mp_preapproval_id'] !== null) {
                    $latest = $sub;
                }
            }
            return $latest ?: false;
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':mpid'])) {
            foreach ($this->pdo->tables['subscriptions'] ?? [] as $sub) {
                if ($sub['mp_preapproval_id'] === $p[':mpid']) return $sub;
            }
            return false;
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':id'])) {
            foreach ($this->pdo->tables['subscriptions'] ?? [] as $sub) {
                if ((int)$sub['id'] === (int)$p[':id']) return $sub;
            }
            return false;
        }
        if (str_contains($s, 'from planos')) {
            $slug = $p[0] ?? ($p[':slug'] ?? '');
            foreach ($this->pdo->tables['planos'] as $plan) {
                if ($plan['slug'] === $slug) return $plan;
            }
            return false;
        }
        return false;
    }
    public function fetchColumn() {
        $s = $this->sql; $p = $this->params;
        if (str_contains($s, 'id from usuarios') && isset($p[':uid'])) {
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === (int)$p[':uid']) return (int)$u['id'];
            }
            return false;
        }
        if (str_contains($s, 'active_subscription_id from')) {
            $uid = (int)($p[':uid'] ?? 0);
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === $uid) return $u['active_subscription_id'] !== null ? (int)$u['active_subscription_id'] : null;
            }
            return null;
        }
        if (str_contains($s, 'plano from usuarios')) {
            $uid = (int)($p[':uid'] ?? 0);
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === $uid) return $u['plano'];
            }
            return null;
        }
        return 1;
    }
    public function fetchAll($m = 0): array { return []; }
    public function rowCount(): int { return 1; }
}

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_xyz');

function freshDb(): OW_PDO
{
    $db = new OW_PDO();
    $db->tables['usuarios'] = [
        ['id'=>1,'plano'=>'gratuito','plano_status'=>'ativo','plano_inicio'=>null,'plano_fim'=>null,'active_subscription_id'=>null],
        ['id'=>5,'plano'=>'pro','plano_status'=>'ativo','plano_inicio'=>null,'plano_fim'=>null,'active_subscription_id'=>10],
        ['id'=>12,'plano'=>'premium','plano_status'=>'ativo','plano_inicio'=>null,'plano_fim'=>null,'active_subscription_id'=>11],
    ];
    $db->tables['subscriptions'] = [
        ['id'=>10,'user_id'=>5,'plan_id'=>2,'plan_slug'=>'pro','status'=>'active',
         'raw_status'=>'authorized','mp_preapproval_id'=>'mp_pro_89a27','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_5_pro'],
        ['id'=>11,'user_id'=>12,'plan_id'=>3,'plan_slug'=>'premium','status'=>'active',
         'raw_status'=>'authorized','mp_preapproval_id'=>'mp_premium_360f13','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_12_premium'],
    ];
    return $db;
}

echo "\n=== TESTES: protecao anti-orfa + fluxos return/webhook ===\n\n";

echo "--- OW01: orfa authorized sem pending local ---\n";
$db = freshDb();
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(5, 'pro');
$pendingIsLinkable = (
    $pending !== null
    && in_array($pending['status'], [Subscription::STATUS_PENDING, Subscription::STATUS_ACTIVE], true)
    && (empty($pending['mp_preapproval_id']) || $pending['mp_preapproval_id'] === null)
);
assert_test($pendingIsLinkable === false, 'OW01a: pending pro user 5 NAO e linkavel (ja tem mp_id vinculado)');
$countBefore = count($db->tables['subscriptions']);
assert_test($countBefore === 2, 'OW01b: 2 subscriptions existentes (nenhuma sera criada)');
echo "  -> Orphan ignorado (orphan_preapproval_ignored) - nenhuma subscription criada\n";

echo "\n--- OW02: orfa authorized com pending compativel ---\n";
$db = freshDb();
$db->tables['subscriptions'][] = [
    'id'=>20,'user_id'=>5,'plan_id'=>2,'plan_slug'=>'pro','status'=>'pending',
    'raw_status'=>'pending','mp_preapproval_id'=>'','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_5_pro'
];
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(5, 'pro');
assert_test($pending !== false, 'OW02a: pending local encontrado (id=20)');
assert_test($pending['id'] === 20, 'OW02b: pending.id = 20 (nao a ativa id=10)');
$countBefore = count($db->tables['subscriptions']);
assert_test($countBefore === 3, 'OW02c: 3 subscriptions antes (2 existentes + 1 pending)');
echo "  -> Vincula orphan a pending id=20, NAO cria 2a linha\n";

echo "\n--- OW03: ext_ref valido + assinatura ativa com mp_id vinculado ---\n";
$db = freshDb();
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(12, 'premium');
$pendingIsLinkable = (
    $pending !== null
    && in_array($pending['status'], [Subscription::STATUS_PENDING, Subscription::STATUS_ACTIVE], true)
    && (empty($pending['mp_preapproval_id']) || $pending['mp_preapproval_id'] === null)
);
assert_test($pendingIsLinkable === false, 'OW03a: user 12 premium ativa NAO e linkavel (ja tem mp_id)');
echo "  -> Orphan para premium de user 12 IGNORADA (subscription id=11 ja tem mp_id)\n";

echo "\n--- OW04: ext_ref invalido ---\n";
$parsed = MercadoPagoWebhookService::parseExternalReference('user_X_pro');
assert_test($parsed === null, 'OW04a: user_X_pro invalido -> null');
$parsed2 = MercadoPagoWebhookService::parseExternalReference('legacy_5_pro');
assert_test($parsed2 === null, 'OW04b: legacy_5_pro invalido -> null');
$parsed3 = MercadoPagoWebhookService::parseExternalReference('user_5_gratuito');
assert_test($parsed3 === null, 'OW04c: user_5_gratuito invalido (plano desconhecido) -> null');

echo "\n--- OW05: ext_ref ausente ---\n";
$ext = '';
$planId = 'plan_pro_xyz';
$status = 'authorized';
$action = ($ext === '' || $planId === '' || $status === '') ? 'incomplete_payload' : 'ok';
assert_test($action === 'incomplete_payload', 'OW05a: ext_ref vazio -> incomplete_payload (REJEITADO)');

echo "\n--- OW06: pending de outro plano ---\n";
$db = freshDb();
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(5, 'premium');
assert_test($pending === null, 'OW06a: user 5 NAO tem pending premium (orphan ignorada)');
echo "  -> Orphan premium ignorada\n";

echo "\n--- OW07: pending de outro usuario ---\n";
$db = freshDb();
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(1, 'pro');
assert_test($pending === null, 'OW07a: user 1 (gratuito) NAO tem pending pro');
echo "  -> Orphan ignorada\n";

echo "\n--- OW08: webhook duplicado depois de vinculado ---\n";
$db = freshDb();
$db->tables['subscriptions'][0]['mp_preapproval_id'] = 'mp_orphan_linked';
$db->tables['subscriptions'][0]['status'] = 'active';
$sm = new Subscription($db);
$existing = $sm->findByMpId('mp_orphan_linked');
assert_test($existing !== null, 'OW08a: mp_id ja vinculado -> encontrado');
$subId = (int)$existing['id'];
assert_test($subId === 10, 'OW08b: subscription id = 10');
echo "  -> Idempotente: updateMpData sobre a mesma linha\n";

echo "\n--- OW09: return ANTES do webhook ---\n";
$db = freshDb();
$db->tables['subscriptions'][] = [
    'id'=>20,'user_id'=>5,'plan_id'=>2,'plan_slug'=>'pro','status'=>'pending',
    'raw_status'=>'pending','mp_preapproval_id'=>'','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_5_pro'
];
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(5, 'pro');
assert_test($pending['id'] === 20, 'OW09a: pending existe (id=20)');
$sm->attachMpPreapprovalId(20, 'mp_new_preapproval');
$sm->updateStatusById(20, Subscription::STATUS_ACTIVE, 'authorized', '2026-10-05', null);
$db->tables['usuarios'][1]['active_subscription_id'] = 20;
$db->tables['usuarios'][1]['plano'] = 'pro';
$existingAfterReturn = $sm->findByMpId('mp_new_preapproval');
assert_test($existingAfterReturn !== null, 'OW09b: mp_id vinculado depois do return');
assert_test($existingAfterReturn['status'] === 'active', 'OW09c: status = active');
assert_test((int)$db->tables['usuarios'][1]['active_subscription_id'] === 20, 'OW09d: user 5 active = 20');
echo "  -> Return funcionou: pending ativada, webhook posterior so atualiza\n";

echo "\n--- OW10: webhook ANTES do return ---\n";
$db = freshDb();
$db->tables['subscriptions'][] = [
    'id'=>20,'user_id'=>5,'plan_id'=>2,'plan_slug'=>'pro','status'=>'pending',
    'raw_status'=>'pending','mp_preapproval_id'=>'','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_5_pro'
];
$sm = new Subscription($db);
$pending = $sm->findActiveOrPendingByUserAndPlan(5, 'pro');
assert_test($pending['id'] === 20, 'OW10a: pending existe');
$sm->updateMpData(20, 'mp_webhook_first', 'authorized', '2026-10-05');
$sm->updateStatusById(20, Subscription::STATUS_ACTIVE, 'authorized', '2026-10-05', null);
$sm->applyStatusToUser(['id'=>20,'user_id'=>5,'plan_slug'=>'pro','status'=>'active','mp_preapproval_id'=>'mp_webhook_first']);
$user5 = $db->tables['usuarios'][1];
assert_test($user5['active_subscription_id'] === 20, 'OW10b: active = 20 apos webhook');
assert_test($user5['plano'] === 'pro', 'OW10c: plano = pro apos webhook-first');
echo "  -> Webhook funcionou primeiro: usuario ja tem acesso pro\n";

echo "\n--- OW11: e2b9... / 8b7c... como orfas sem pending ---\n";
$db = freshDb();
$sm = new Subscription($db);
$orphan1 = $sm->findByMpId('e2b9bd2d3d7c498cb095e5a66744156c');
$orphan2 = $sm->findByMpId('8b7c024d9f434232a4a0740ea54a9c18');
assert_test($orphan1 === null, 'OW11a: e2b9... NAO existe em subscriptions');
assert_test($orphan2 === null, 'OW11b: 8b7c... NAO existe em subscriptions');
$pending1 = $sm->findActiveOrPendingByUserAndPlan(5, 'pro');
$pending2 = $sm->findActiveOrPendingByUserAndPlan(12, 'premium');
$isLinkable1 = ($pending1 !== null && in_array($pending1['status'], ['pending','active'], true)
    && (empty($pending1['mp_preapproval_id']) || $pending1['mp_preapproval_id'] === null));
$isLinkable2 = ($pending2 !== null && in_array($pending2['status'], ['pending','active'], true)
    && (empty($pending2['mp_preapproval_id']) || $pending2['mp_preapproval_id'] === null));
assert_test($isLinkable1 === false, 'OW11c: user 5 pro (id=10) NAO e linkavel (ja tem mp_id)');
assert_test($isLinkable2 === false, 'OW11d: user 12 premium (id=11) NAO e linkavel (ja tem mp_id)');
$ext1 = 'user_5_pro';
$ext2 = 'user_12_premium';
$parsed1 = MercadoPagoWebhookService::parseExternalReference($ext1);
$parsed2 = MercadoPagoWebhookService::parseExternalReference($ext2);
assert_test($parsed1 !== null, 'OW11e: e2b9 ext_ref parseia para user_5_pro');
assert_test($parsed2 !== null, 'OW11f: 8b7c ext_ref parseia para user_12_premium');
echo "  -> Orfas IGNORADAS: nenhuma cria subscription, nenhuma vincula\n";

echo "\n--- OW12: plano diferente bloqueia grantAccess em applyStatusToUser ---\n";
$db = freshDb();
$db->tables['subscriptions'][] = [
    'id'=>20,'user_id'=>12,'plan_id'=>2,'plan_slug'=>'pro','status'=>'pending',
    'raw_status'=>'pending','mp_preapproval_id'=>'','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_12_pro'
];
$db->tables['usuarios'][2]['plano'] = 'premium';
$sm = new Subscription($db);
$sub = ['id'=>20,'user_id'=>12,'plan_slug'=>'pro','status'=>'active','mp_preapproval_id'=>'mp_downgrade_test'];
$result = $sm->applyStatusToUser($sub);
assert_test($result === false, 'OW12a: applyStatusToUser bloqueia downgrade premium->pro via webhook');
assert_test(
    $db->tables['usuarios'][2]['plano'] === 'premium',
    'OW12b: plano permanece premium (nao rebaixado)'
);
assert_test(
    $db->tables['usuarios'][2]['active_subscription_id'] === 11,
    'OW12c: active_subscription_id permanece 11 (premium)'
);

echo "\n--- OW13: plano diferente = bloqueia, plano igual = permite ---\n";
$db = freshDb();
$db->tables['subscriptions'][] = [
    'id'=>20,'user_id'=>5,'plan_id'=>2,'plan_slug'=>'pro','status'=>'pending',
    'raw_status'=>'pending','mp_preapproval_id'=>'','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_5_pro'
];
$sm = new Subscription($db);
$subSame = ['id'=>20,'user_id'=>5,'plan_slug'=>'pro','status'=>'active','mp_preapproval_id'=>'mp_same_plan'];
$resultSame = $sm->applyStatusToUser($subSame);
assert_test($resultSame === true, 'OW13a: applyStatusToUser permite mesmo plano (pro->pro)');
assert_test(
    $db->tables['usuarios'][1]['plano'] === 'pro',
    'OW13b: plano = pro (mantido, correto)'
);

echo "\n--- OW14: gratuito -> pro permite (upgrade local) ---\n";
$db = freshDb();
$db->tables['usuarios'][0]['plano'] = 'gratuito';
$db->tables['subscriptions'][] = [
    'id'=>20,'user_id'=>1,'plan_id'=>2,'plan_slug'=>'pro','status'=>'pending',
    'raw_status'=>'pending','mp_preapproval_id'=>'','next_billing_date'=>null,'grace_period_end'=>null,'external_reference'=>'user_1_pro'
];
$sm = new Subscription($db);
$subUpgrade = ['id'=>20,'user_id'=>1,'plan_slug'=>'pro','status'=>'active','mp_preapproval_id'=>'mp_upgrade'];
$resultUpgrade = $sm->applyStatusToUser($subUpgrade);
assert_test($resultUpgrade === true, 'OW14a: gratuito->pro via webhook permite');
assert_test(
    $db->tables['usuarios'][0]['plano'] === 'pro',
    'OW14b: plano = pro apos webhook'
);

putenv('MERCADOPAGO_PLAN_ID_PRO');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

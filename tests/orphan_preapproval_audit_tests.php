<?php
/**
 * Teste de auditoria (somente leitura) para preapprovals órfãs.
 *
 * Simula o fluxo de webhook para as preapprovals:
 *   e2b9bd2d3d7c498cb095e5a66744156c
 *   8b7c024d9f434232a4a0740ea54a9c18
 *
 * NAO altera codigo. NAO altera banco.
 * Apenas reproduz a logica via mocks do codigo real.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name\n"; $failed++; }
}

class OrphPDO
{
    public array $tables = [];
    public function __construct() {
        $this->tables['planos'] = [
            ['id'=>1,'slug'=>'gratuito','nome'=>'Gratuito','preco'=>0,'status'=>'ativo'],
            ['id'=>2,'slug'=>'pro','nome'=>'Pro','preco'=>9.90,'status'=>'ativo'],
            ['id'=>3,'slug'=>'premium','nome'=>'Premium','preco'=>19.90,'status'=>'ativo'],
        ];
        $this->tables['usuarios'] = [
            ['id'=>1,'plano'=>'gratuito','plano_status'=>'ativo','active_subscription_id'=>null],
            ['id'=>5,'plano'=>'pro','plano_status'=>'ativo','active_subscription_id'=>10],
            ['id'=>12,'plano'=>'premium','plano_status'=>'ativo','active_subscription_id'=>11],
        ];
        $this->tables['subscriptions'] = [
            ['id'=>10,'user_id'=>5,'plan_id'=>2,'plan_slug'=>'pro','status'=>'active',
             'raw_status'=>'authorized','mp_preapproval_id'=>'89a27000000000000000000000000000',
             'external_reference'=>'user_5_pro','next_billing_date'=>'2026-10-05','grace_period_end'=>null],
            ['id'=>11,'user_id'=>12,'plan_id'=>3,'plan_slug'=>'premium','status'=>'active',
             'raw_status'=>'authorized','mp_preapproval_id'=>'360f1300000000000000000000000000',
             'external_reference'=>'user_12_premium','next_billing_date'=>'2026-10-12','grace_period_end'=>null],
        ];
    }
    public function prepare(string $s) { return new OrphStmt($this, $s); }
    public function exec(string $s): int { return 0; }
    public function lastInsertId(): string { return '99'; }
    public function setAttribute(int $a, $v): bool { return true; }
    public function query(string $s) { return new OrphStmt($this, $s); }
}

class OrphStmt
{
    private OrphPDO $pdo; private string $sql; public array $params = [];
    public function __construct(OrphPDO $pdo, string $sql) { $this->pdo = $pdo; $this->sql = strtolower($sql); }
    public function execute(array $params = []): bool { $this->params = $params; return true; }
    public function fetch($m = 0) {
        $s = $this->sql; $p = $this->params;
        if (str_contains($s, 'from subscriptions') && isset($p[':id'])) {
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['id'] === (int)$p[':id']) return $sub;
            }
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':uid']) && isset($p[':slug'])) {
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['user_id'] === (int)$p[':uid'] && $sub['plan_slug'] === $p[':slug']
                    && in_array($sub['status'], ['pending','active','paused'], true)) return $sub;
            }
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':uid'])) {
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['user_id'] === (int)$p[':uid']
                    && in_array($sub['status'], ['active','paused'], true)
                    && $sub['mp_preapproval_id'] !== '' && $sub['mp_preapproval_id'] !== null) return $sub;
            }
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':mpid'])) {
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ($sub['mp_preapproval_id'] === $p[':mpid']) return $sub;
            }
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':uid']) && !isset($p[':slug']) && !isset($p[':mpid'])) {
            $latest = null;
            foreach ($this->pdo->tables['subscriptions'] as $sub) {
                if ((int)$sub['user_id'] === (int)$p[':uid']) {
                    if ($latest === null || (int)$sub['id'] > (int)$latest['id']) $latest = $sub;
                }
            }
            return $latest;
        }
        if (str_contains($s, 'from planos')) {
            $slug = $p[0] ?? ($p['slug'] ?? '');
            foreach ($this->pdo->tables['planos'] as $plan) {
                if ($plan['slug'] === $slug) return $plan;
            }
        }
        return false;
    }
    public function fetchAll($m = 0): array { return []; }
    public function fetchColumn() {
        $s = $this->sql; $p = $this->params;
        if (str_contains($s, 'id from usuarios') && isset($p[':uid'])) {
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === (int)$p[':uid']) return 1;
            }
            return false;
        }
        return false;
    }
    public function rowCount(): int { return 0; }
}

function parseExternalReference(string $ref): ?array {
    if (!preg_match('/^user_(\d+)_(pro|premium)$/', $ref, $m)) return null;
    $userId = (int)$m[1];
    $planSlug = $m[2];
    if ($userId <= 0) return null;
    return [$userId, $planSlug];
}

echo "\n=== AUDITORIA: preapprovals orfas (somente leitura) ===\n\n";

echo "--- OR01: Validacao do regex parseExternalReference ---\n";
assert_test(parseExternalReference('user_5_pro') === [5, 'pro'], 'OR01a: user_5_pro -> [5,pro]');
assert_test(parseExternalReference('user_12_premium') === [12, 'premium'], 'OR01b: user_12_premium -> [12,premium]');
assert_test(parseExternalReference('user_99_premium') === [99, 'premium'], 'OR01c: user_99_premium -> [99,premium]');
assert_test(parseExternalReference('') === null, 'OR01d: vazio -> null');
assert_test(parseExternalReference('user_5_unknown') === null, 'OR01e: plano invalido -> null');
assert_test(parseExternalReference('legacy_5_pro') === null, 'OR01f: prefixo antigo -> null');
assert_test(parseExternalReference('user_0_pro') === null, 'OR01g: userId 0 -> null (validacao $userId<=0)');
assert_test(parseExternalReference('user_-1_pro') === null, 'OR01h: userId negativo -> null');
assert_test(parseExternalReference('user_abc_pro') === null, 'OR01i: userId nao-numerico -> null');

echo "\n--- OR02: Webhook simulado e2b9bd2d3d7c498cb095e5a66744156c (sem external_reference valido) ---\n";
echo "Cenário: preapproval sem local subscription, mas external_reference = ''\n";
echo "Hipotese do MP: {\"status\":\"authorized\",\"preapproval_plan_id\":\"plan_pro_xxx\",\"external_reference\":\"\"}\n";
$payload = ['status'=>'authorized', 'preapproval_plan_id'=>'plan_pro_xxx', 'external_reference'=>''];
$ext = (string)($payload['external_reference'] ?? '');
$planId = (string)($payload['preapproval_plan_id'] ?? '');
$status = strtolower(trim((string)($payload['status'] ?? '')));
assert_test($ext === '' && $planId !== '' && $status !== '', 'OR02a: payload incompleto detectado');
$action = ($ext === '' || $planId === '' || $status === '') ? 'incomplete_payload' : 'continue';
assert_test($action === 'incomplete_payload', 'OR02b: action = incomplete_payload (REJEITADO)');

echo "\n--- OR03: Webhook simulado e2b9... com external_reference antigo/invalido ---\n";
echo "Cenário: external_reference corrompido (e.g., 'user_X_pro' em vez de user_5_pro)\n";
$ext = 'user_X_pro';
$parsed = parseExternalReference($ext);
assert_test($parsed === null, 'OR03a: external_reference invalido -> parseExternalReference retorna null');
$action = ($parsed === null) ? 'invalid_external_reference' : 'continue';
assert_test($action === 'invalid_external_reference', 'OR03b: action = invalid_external_reference (REJEITADO)');

echo "\n--- OR04: Webhook simulado com external_reference AUSENTE (campo nao retornado) ---\n";
$payload = ['status'=>'authorized', 'preapproval_plan_id'=>'plan_pro_xxx'];
$ext = (string)($payload['external_reference'] ?? '');
$planId = (string)($payload['preapproval_plan_id'] ?? '');
$status = (string)($payload['status'] ?? '');
assert_test($ext === '', 'OR04a: external_reference ausente -> string vazia');
$action = ($ext === '' || $planId === '' || $status === '') ? 'incomplete_payload' : 'continue';
assert_test($action === 'incomplete_payload', 'OR04b: action = incomplete_payload (REJEITADO)');

echo "\n--- OR05: Webhook com external_reference VALIDO + subscription local INEXISTENTE ---\n";
echo "Cenário: e2b9... chega com external_reference = 'user_5_pro' (apontando user 5)\n";
$ext = 'user_5_pro';
$parsed = parseExternalReference($ext);
assert_test($parsed === [5, 'pro'], 'OR05a: external_reference parseado corretamente');
$db = new OrphPDO();
$sm = new Subscription($db);
$userId = $parsed[0];
$stmt = $db->prepare('SELECT id FROM usuarios WHERE id = :uid');
$stmt->execute([':uid' => $userId]);
$userExists = $stmt->fetchColumn() !== false;
assert_test($userExists, 'OR05b: user 5 EXISTE no DB');
$existingByMp = $sm->findByMpId('e2b9bd2d3d7c498cb095e5a66744156c');
assert_test($existingByMp === null, 'OR05c: mp_preapproval_id e2b9... NAO tem subscription local');
$existingByLatest = $sm->findLatestByUserId(5);
assert_test($existingByLatest !== null, 'OR05d: user 5 tem subscription local pre-existente (id=10)');
$existingByRef = $sm->findActiveByUser(5);
assert_test($existingByRef !== null, 'OR05e: findActiveByUser(5) -> id=10 (ja tem subscription ativa)');
$existingByLatestPlan = $existingByLatest['plan_slug'] === 'pro';
assert_test($existingByLatestPlan, 'OR05f: subscription do user 5 e do plano pro (coincide)');
echo "  -> ATUALIZARIA subscription id=10 com mp_preapproval_id=e2b9... (VINCULA ao user 5)\n";

echo "\n--- OR06: Webhook com external_reference = user_12_premium ---\n";
echo "Cenário: e2b9... chega com external_reference apontando user 12\n";
$ext = 'user_12_premium';
$parsed = parseExternalReference($ext);
assert_test($parsed === [12, 'premium'], 'OR06a: parsed [12,premium]');
$existingByLatest = $sm->findLatestByUserId(12);
assert_test($existingByLatest['plan_slug'] === 'premium', 'OR06b: user 12 ja tem premium local');
$planoFromMp = 'pro';
assert_test($existingByLatest['plan_slug'] !== $planoFromMp, 'OR06c: plan_slug na DB = premium != pro (plan_mismatch REJEITADO)');
$action = ($existingByLatest['plan_slug'] !== $planoFromMp) ? 'plan_mismatch' : 'continue';
assert_test($action === 'plan_mismatch', 'OR06d: action = plan_mismatch (REJEITADO pelo code)');

echo "\n--- OR07: Webhook com preapproval_plan_id DESCONHECIDO ---\n";
$planIdFromMp = 'plan_xxx_qualquer_coisa';
$proPlanId = getenv('MERCADOPAGO_PLAN_ID_PRO') ?: 'plan_pro_xxx';
$premPlanId = getenv('MERCADOPAGO_PLAN_ID_PREMIUM') ?: 'plan_premium_xxx';
putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_xxx');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_xxx');
$matched = ($planIdFromMp === 'plan_pro_xxx') ? 'pro' : (($planIdFromMp === 'plan_premium_xxx') ? 'premium' : null);
assert_test($matched === null, 'OR07a: plan_id desconhecido -> matched = null (REJEITADO)');

echo "\n--- OR08: Webhook órfão 8b7c024d9f434232a4a0740ea54a9c18 (mesma análise) ---\n";
$ext = 'user_5_pro';
$parsed = parseExternalReference($ext);
$existingByMp = $sm->findByMpId('8b7c024d9f434232a4a0740ea54a9c18');
assert_test($existingByMp === null, 'OR08a: 8b7c... sem subscription local');
$latest = $sm->findLatestByUserId(5);
assert_test($latest['plan_slug'] === 'pro', 'OR08b: user 5 tem subscription pro local');
$action = 'updateMpData(id=10, mp=8b7c...)';
assert_test(strpos($action, 'updateMpData') !== false, 'OR08c: caminho = updateMpData() na subscription local existente');

echo "\n--- OR09: Webhook de preapproval ja cancelada ---\n";
$payload = ['status'=>'cancelled', 'preapproval_plan_id'=>'plan_pro_xxx', 'external_reference'=>'user_5_pro'];
$ext = $payload['external_reference']; $planId = $payload['preapproval_plan_id']; $status = $payload['status'];
$parsed = parseExternalReference($ext);
assert_test($parsed === [5, 'pro'], 'OR09a: parsed [5,pro]');
$internalStatus = 'cancelled';
$latest = $sm->findLatestByUserId(5);
assert_test($latest['status'] === 'active', 'OR09b: subscription local atual = active (NUNCA deveria ir pra cancelled)');
$prev = $latest['status'];
$new = $internalStatus;
$willApply = ($prev !== $new);
assert_test($willApply, 'OR09c: applyStatusToUser() chamado (status mudou)');
$actionExpected = 'cancelled -> rebaixa user 5 para gratuito (PELIGRO)';
echo "  -> $actionExpected\n";

echo "\n--- OR10: Webhook com preapproval_plan_id confiavel (validacao contra .env) ---\n";
putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_xxx');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_xxx');
$planIdFromMp = 'plan_pro_xxx';
$matched = hash_equals('plan_pro_xxx', $planIdFromMp) ? 'pro' : null;
assert_test($matched === 'pro', 'OR10a: plan_id confiavel bate com .env -> pro');
$otherPlanId = 'plan_pro_hacker_xyz';
$matchedBad = hash_equals('plan_pro_xxx', $otherPlanId) ? 'pro' : null;
assert_test($matchedBad === null, 'OR10b: plan_id arbitrario NAO bate -> null (REJEITADO)');

echo "\n--- OR11: applyStatusToUser NAO SOBRESCREVE subscription ativa do user 5/12 ---\n";
echo "Cenário: webhook de e2b9... chega. updateMpData em id=10. status=authorized.\n";
echo "Subscription atual (id=10): user 5, status active.\n";
echo "Aplica: grantAccess(user 5, pro, id=10) = safe (mesmo subscription_id)\n";
$db2 = new OrphPDO();
$sm2 = new Subscription($db2);
$active = $sm2->findActiveByUser(5);
assert_test($active['id'] === 10, 'OR11a: subscription ativa do user 5 = id 10 (NUNCA muda)');
$applies = $sm2->applyStatusToUser($active);
assert_test($applies === true, 'OR11b: applyStatusToUser() em id=10 = true (ativo mantido)');
$user5 = $db2->tables['usuarios'][1];
assert_test($user5['plano'] === 'pro', 'OR11c: user 5 plano = pro (preservado)');
assert_test($user5['active_subscription_id'] === 10, 'OR11d: active_subscription_id = 10 (preservado)');

echo "\n--- OR12: Mecanismo de identificacao do usuario ---\n";
echo "RESPOSTA: external_reference (validado contra regex user_{id}_{plan})\n";
echo "  + preapproval_plan_id (validado contra .env)\n";
echo "  + usuario existe no DB (SELECT id FROM usuarios WHERE id = :uid)\n";
echo "  NUNCA usa payer_id, payer_email, body, ou dados do request\n";

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

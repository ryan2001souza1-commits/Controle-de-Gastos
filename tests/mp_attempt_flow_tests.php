<?php
/**
 * Testes do fluxo por tentativa (attempt_token UUID):
 * tokenizacao JS SDK + POST /preapproval + webhook deterministico.
 *
 * Cobre (§13 do plano aprovado):
 * - formato/ownership/criacao/reuse de attempt
 * - webhook via attempt: sucesso, duplicado, antes/depois da persistencia
 * - attempt desconhecido/invalido, plan_mismatch, mp_conflict (2 direcoes)
 * - no-op sem identidade (sem adivinhacao)
 * - legado user_{id}_{slug} preservado
 * - authorized/rejected/cancelled/paused via webhook
 * - mesmo mp_preapproval_id em dois usuarios (bloqueado)
 * - concorrencia A/B em ordens permutadas (sem cross-account)
 * - card_token_id nunca aparece em saidas/erros do servico
 *
 * Sem rede, sem cartao real: FakeMpService + FakeAttemptPDO em memoria.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';

putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_xyz');

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

class FakeAttemptStmt extends PDOStatement
{
    public FakeAttemptPDO $pdo;
    public string $sqlText = '';
    public array $bound = [];

    protected function __construct() {}

    public static function make(FakeAttemptPDO $pdo, string $sql): self
    {
        $s = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $s->pdo = $pdo;
        $s->sqlText = strtolower($sql);
        return $s;
    }

    public function execute(?array $params = null): bool
    {
        $this->bound = $params ?? [];
        $t = $this->pdo->tables;
        $sql = $this->sqlText;
        $p = $this->bound;

        if (str_starts_with(trim($sql), 'insert into subscriptions')) {
            $id = ++$this->pdo->lastId;
            $t['subscriptions'][] = [
                'id' => $id,
                'user_id' => (int)($p[':user_id'] ?? 0),
                'plan_id' => (int)($p[':plan_id'] ?? 0),
                'plan_slug' => (string)($p[':plan_slug'] ?? ''),
                'status' => (string)($p[':status'] ?? 'pending'),
                'start_date' => null,
                'next_billing_date' => $p[':next_billing_date'] ?? null,
                'paused_at' => null,
                'cancelled_at' => null,
                'expired_at' => null,
                'grace_period_end' => null,
                'raw_status' => (string)($p[':raw_status'] ?? ''),
                'external_reference' => (string)($p[':external_reference'] ?? ''),
                'mp_preapproval_id' => (string)($p[':mp_preapproval_id'] ?? ''),
                'attempt_token' => isset($p[':attempt_token']) ? (string)$p[':attempt_token'] : null,
                'checkout_url' => null,
            ];
            $this->pdo->tables = $t;
            $this->pdo->lastRow = end($t['subscriptions']);
        }

        if (str_starts_with(trim($sql), 'update subscriptions')) {
            $rows = &$this->pdo->tables['subscriptions'];
            foreach ($rows as &$s) {
                $match = false;
                if (isset($p[':id']) && (int)$s['id'] === (int)$p[':id']) $match = true;
                if (!$match) continue;
                // attach condicional: so quando mp vazio
                if (str_contains($sql, 'mp_preapproval_id = :mpid') && !str_contains($sql, 'raw_status')) {
                    $cur = (string)($s['mp_preapproval_id'] ?? '');
                    if ($cur === '' || $cur === null) {
                        $s['mp_preapproval_id'] = (string)$p[':mpid'];
                        $this->pdo->lastAffected = 1;
                    } else {
                        $this->pdo->lastAffected = 0;
                    }
                    continue;
                }
                if (isset($p[':mpid'])) $s['mp_preapproval_id'] = (string)$p[':mpid'];
                if (isset($p[':raw_status'])) $s['raw_status'] = (string)$p[':raw_status'];
                if (isset($p[':status'])) $s['status'] = (string)$p[':status'];
                if (array_key_exists(':next_billing_date', $p) && $p[':next_billing_date'] !== null) {
                    $s['next_billing_date'] = $p[':next_billing_date'];
                }
                if (array_key_exists(':grace_period_end', $p) && $p[':grace_period_end'] !== null) {
                    $s['grace_period_end'] = $p[':grace_period_end'];
                }
                $this->pdo->lastAffected = 1;
            }
            unset($s);
        }

        if (str_starts_with(trim($sql), 'update usuarios')) {
            foreach ($this->pdo->tables['usuarios'] as &$u) {
                if ((int)$u['id'] !== (int)($p[':uid'] ?? -1)) continue;
                if (isset($p[':plan'])) $u['plano'] = $p[':plan'];
                if (isset($p[':sub_id'])) $u['active_subscription_id'] = (int)$p[':sub_id'];
                if (isset($p[':grace'])) $u['plano_fim'] = $p[':grace'];
            }
            unset($u);
        }
        return true;
    }

    private function subRows(): array
    {
        return $this->pdo->tables['subscriptions'];
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        $sql = $this->sqlText;
        $p = $this->bound;
        if (str_contains($sql, 'from subscriptions') && isset($p[':token'])) {
            foreach (array_reverse($this->subRows()) as $s) {
                if ((string)($s['attempt_token'] ?? '') === (string)$p[':token'] && $p[':token'] !== '') {
                    return $s;
                }
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':mpid'])) {
            foreach (array_reverse($this->subRows()) as $s) {
                if ((string)($s['mp_preapproval_id'] ?? '') === (string)$p[':mpid'] && $p[':mpid'] !== '') {
                    return $s;
                }
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':uid']) && isset($p[':slug'])) {
            foreach (array_reverse($this->subRows()) as $s) {
                if ((int)$s['user_id'] === (int)$p[':uid'] && (string)$s['plan_slug'] === (string)$p[':slug']
                    && in_array($s['status'], ['pending', 'active', 'paused'], true)) {
                    return $s;
                }
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':id'])) {
            foreach ($this->subRows() as $s) {
                if ((int)$s['id'] === (int)$p[':id']) return $s;
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':uid'])) {
            foreach (array_reverse($this->subRows()) as $s) {
                if ((int)$s['user_id'] === (int)$p[':uid']) return $s;
            }
            return false;
        }
        if (str_contains($sql, 'from planos')) {
            $slug = $p[0] ?? ($p[':slug'] ?? ($p['slug'] ?? ''));
            foreach ($this->pdo->tables['planos'] as $plan) {
                if ($plan['slug'] === $slug) return $plan;
            }
            return false;
        }
        return false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $sql = $this->sqlText;
        $p = $this->bound;
        if (str_contains($sql, 'returning id')) {
            return $this->pdo->lastRow ? (int)$this->pdo->lastRow['id'] : false;
        }
        if (str_contains($sql, 'from usuarios') && isset($p[':uid'])) {
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] === (int)$p[':uid']) return (int)$u['id'];
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':mpid'])) {
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((string)($s['mp_preapproval_id'] ?? '') === (string)$p[':mpid'] && $p[':mpid'] !== '') {
                    return (int)$s['id'];
                }
            }
            return false;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return $this->pdo->lastAffected; }
}

class FakeAttemptPDO extends PDO
{
    public array $tables = [];
    public int $lastId = 100;
    public ?array $lastRow = null;
    public int $lastAffected = 0;
    public int $txDepth = 0;

    public function __construct()
    {
        $this->tables['planos'] = [
            ['id' => 1, 'slug' => 'gratuito', 'nome' => 'Gratuito', 'preco' => 0],
            ['id' => 2, 'slug' => 'pro', 'nome' => 'Pro', 'preco' => 9.90],
            ['id' => 3, 'slug' => 'premium', 'nome' => 'Premium', 'preco' => 19.90],
        ];
        $this->tables['usuarios'] = [
            ['id' => 5, 'nome' => 'A', 'email' => 'a@ex.com', 'plano' => 'gratuito', 'plano_status' => 'ativo', 'active_subscription_id' => null, 'plano_fim' => null, 'plano_inicio' => null],
            ['id' => 12, 'nome' => 'B', 'email' => 'b@ex.com', 'plano' => 'gratuito', 'plano_status' => 'ativo', 'active_subscription_id' => null, 'plano_fim' => null, 'plano_inicio' => null],
        ];
        $this->tables['subscriptions'] = [];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return FakeAttemptStmt::make($this, $query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return FakeAttemptStmt::make($this, $query);
    }
    public function exec(string $statement): int|false { return 0; }
    public function beginTransaction(): bool { $this->txDepth++; return true; }
    public function commit(): bool { $this->txDepth = max(0, $this->txDepth - 1); return true; }
    public function rollBack(): bool { $this->txDepth = max(0, $this->txDepth - 1); return true; }
    public function inTransaction(): bool { return $this->txDepth > 0; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
}

class FakeAttemptMP extends MercadoPagoService
{
    public array $preapprovals = [];
    public int $postCount = 0;

    public function __construct() { $this->accessToken = 'TEST'; }

    public function addPreapproval(string $id, string $status, string $extRef, string $planId): void
    {
        $this->preapprovals[$id] = [
            'id' => $id,
            'status' => $status,
            'external_reference' => $extRef,
            'preapproval_plan_id' => $planId,
            'payer_email' => 'payer@mp.com',
            'payer_id' => 999,
        ];
    }

    public function getPreapproval(string $id): array
    {
        if (!isset($this->preapprovals[$id])) {
            return ['ok' => false, 'status' => 404, 'error' => 'not_found'];
        }
        return ['ok' => true, 'status' => 200, 'data' => $this->preapprovals[$id]];
    }
}

function makeAttemptEnv(): array
{
    $db = new FakeAttemptPDO();
    $mp = new FakeAttemptMP();
    $sm = new Subscription($db);
    return [$db, $mp, $sm];
}

echo "\n=== TESTES: fluxo por tentativa (attempt_token) ===\n\n";

echo "--- AT01: formato e unicidade do attempt_token ---\n";
$t1 = Subscription::newAttemptToken();
$t2 = Subscription::newAttemptToken();
assert_test(Subscription::isAttemptToken($t1), 'AT01a: token gerado e valido');
assert_test($t1 !== $t2, 'AT01b: tokens distintos (nao sequencial)');
assert_test(!Subscription::isAttemptToken('user_5_pro'), 'AT01c: legado nao e attempt');
assert_test(!Subscription::isAttemptToken(''), 'AT01d: vazio rejeitado');

echo "\n--- AT02: createAttempt cria e reutiliza ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a1 = $sm->createAttempt(5, 'pro', 2);
assert_test(($a1['created'] ?? false) === true, 'AT02a: primeira chamada cria');
assert_test(Subscription::isAttemptToken($a1['attempt_token'] ?? ''), 'AT02b: token valido gravado');
$a2 = $sm->createAttempt(5, 'pro', 2);
assert_test(($a2['created'] ?? true) === false, 'AT02c: segunda chamada reutiliza');
assert_test($a1['attempt_token'] === $a2['attempt_token'], 'AT02d: mesmo token (sem duplicata)');
assert_test(count($db->tables['subscriptions']) === 1, 'AT02e: 1 linha no banco');

echo "\n--- AT03: ownership ---\n";
assert_test($sm->ownsAttempt(5, $a1['attempt_token']), 'AT03a: dono reconhece tentativa');
assert_test(!$sm->ownsAttempt(12, $a1['attempt_token']), 'AT03b: outro usuario NAO e dono');
assert_test(!$sm->ownsAttempt(0, $a1['attempt_token']), 'AT03c: user_id 0 rejeitado');
assert_test(!$sm->ownsAttempt(5, 'zzz'), 'AT03d: token malformado rejeitado');

echo "\n--- AT04: webhook via attempt (sucesso authorized) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->addPreapproval('mp_X001', 'authorized', $a['attempt_token'], 'plan_pro_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$r = $ws->process('mp_X001');
assert_test($r['action'] === 'processed', 'AT04a: webhook processado', $r['action']);
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === 'mp_X001', 'AT04b: mp_preapproval_id vinculado');
assert_test(($row['status'] ?? '') === 'active', 'AT04c: status active', $row['status'] ?? '');
$userA = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
assert_test(($userA['plano'] ?? '') === 'pro', 'AT04d: usuario 5 promovido a pro');

echo "\n--- AT05: webhook duplicado e idempotente ---\n";
$r2 = $ws->process('mp_X001');
assert_test($r2['action'] === 'processed', 'AT05a: duplicado retorna processed');
assert_test(count($db->tables['subscriptions']) === 1, 'AT05b: nenhuma linha duplicada');

echo "\n--- AT06: webhook ANTES da persistencia inicial ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
$mp->addPreapproval('mp_EARLY', 'authorized', $a['attempt_token'], 'plan_premium_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$r = $ws->process('mp_EARLY');
assert_test($r['action'] === 'processed', 'AT06a: webhook vincula attempt ainda sem mp id');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === 'mp_EARLY', 'AT06b: vinculo criado pelo webhook');
assert_test((int)($row['user_id'] ?? 0) === 12, 'AT06c: dono correto (12, sem cross-account)');

echo "\n--- AT07: sem identidade -> no-op ---\n";
$mp->addPreapproval('mp_NOID', 'authorized', '', 'plan_pro_xyz');
$r = $ws->process('mp_NOID');
assert_test($r['action'] === 'no_identity_noop', 'AT07a: ext_ref vazio -> no-op', $r['action']);
assert_test(count($db->tables['subscriptions']) === 1, 'AT07b: nada criado/vinculado');

echo "\n--- AT08: attempt desconhecido -> no-op ---\n";
$mp->addPreapproval('mp_UNK', 'authorized', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'plan_pro_xyz');
$r = $ws->process('mp_UNK');
assert_test($r['action'] === 'attempt_unknown', 'AT08a: UUID sem tentativa -> no-op');

echo "\n--- AT09: plan_mismatch (premium apresentado a attempt pro) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->addPreapproval('mp_MM', 'authorized', $a['attempt_token'], 'plan_premium_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$r = $ws->process('mp_MM');
assert_test($r['action'] === 'plan_mismatch', 'AT09a: plano divergente rejeitado');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === '', 'AT09b: nada vinculado');

echo "\n--- AT10: mesmo mp_preapproval_id em dois usuarios (bloqueado) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$aA = $sm->createAttempt(5, 'pro', 2);
$aB = $sm->createAttempt(12, 'pro', 2);
$mp->addPreapproval('mp_SHARED', 'authorized', $aA['attempt_token'], 'plan_pro_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$rA = $ws->process('mp_SHARED');
assert_test($rA['action'] === 'processed', 'AT10a: vinculo resolve para o dono A');
// Ataque realista: B abre o return com o ID de A (?preapproval_id=mp_SHARED).
// A pagina de retorno so exibe sucesso se findByMpId->owner === sessao:
$foundByMp = $sm->findByMpId('mp_SHARED');
$wouldShowSuccessToB = ($foundByMp !== null && (int)($foundByMp['user_id'] ?? 0) === 12);
assert_test(!$wouldShowSuccessToB, 'AT10b: return NAO mostra sucesso para B (owner e A)');
assert_test((int)($foundByMp['user_id'] ?? 0) === 5, 'AT10c: owner real e A');
// Webhook reprocessado continua resolvendo para A (ext_ref vem do MP, nao do request):
$rA2 = $ws->process('mp_SHARED');
assert_test($rA2['action'] === 'processed', 'AT10d: reprocessamento mantem dono A');
$userB = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 12) $userB = $u; }
assert_test(($userB['plano'] ?? '') === 'gratuito', 'AT10e: usuario B NAO promovido');

echo "\n--- AT10b: tentativa ja ligada a outro MP ID rejeita segundo vinculo ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$sm->updateMpData((int)$a['id'], 'mp_OLD', 'authorized', null);
$mp->addPreapproval('mp_NEW', 'authorized', $a['attempt_token'], 'plan_pro_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$r = $ws->process('mp_NEW');
assert_test($r['action'] === 'mp_conflict', 'AT10f: segundo vinculo rejeitado (mp_conflict)', $r['action']);
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === 'mp_OLD', 'AT10g: vinculo original preservado');

echo "\n--- AT11: rejected/cancelled nao ativam; paused mantem acesso (regra existente) ---\n";
foreach (['rejected' => ['rejected', 'gratuito'], 'cancelled' => ['cancelled', 'gratuito'], 'paused' => ['paused', 'pro']] as $mpStatus => [$expectedStatus, $expectedPlan]) {
    [$db, $mp, $sm] = makeAttemptEnv();
    $a = $sm->createAttempt(5, 'pro', 2);
    $mp->addPreapproval('mp_ST_' . $mpStatus, $mpStatus, $a['attempt_token'], 'plan_pro_xyz');
    $ws = new MercadoPagoWebhookService($db, $mp);
    $r = $ws->process('mp_ST_' . $mpStatus);
    $row = $sm->findByAttemptToken($a['attempt_token']);
    assert_test($r['action'] === 'processed' && ($row['status'] ?? '') === $expectedStatus, "AT11-$mpStatus: status=$expectedStatus");
    $userA = null;
    foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
    assert_test(($userA['plano'] ?? '') === $expectedPlan, "AT11-$mpStatus: plano usuario=$expectedPlan");
}

echo "\n--- AT12: legado user_{id}_{slug} preservado ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$db->tables['subscriptions'][] = [
    'id' => 200, 'user_id' => 5, 'plan_id' => 2, 'plan_slug' => 'pro',
    'status' => 'pending', 'start_date' => null, 'next_billing_date' => null,
    'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
    'grace_period_end' => null, 'raw_status' => 'pending',
    'external_reference' => 'user_5_pro', 'mp_preapproval_id' => '',
    'attempt_token' => null, 'checkout_url' => null,
];
$mp->addPreapproval('mp_LEG', 'authorized', 'user_5_pro', 'plan_pro_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$r = $ws->process('mp_LEG');
assert_test($r['action'] === 'processed', 'AT12a: legado processado', $r['action']);

echo "\n--- AT13: concorrencia A/B em ordens permutadas ---\n";
foreach (['AB', 'BA'] as $order) {
    [$db, $mp, $sm] = makeAttemptEnv();
    $aA = $sm->createAttempt(5, 'pro', 2);
    $aB = $sm->createAttempt(12, 'pro', 2);
    assert_test($aA['attempt_token'] !== $aB['attempt_token'], "AT13-$order: attempts distintos");
    $mp->addPreapproval('mp_A', 'authorized', $aA['attempt_token'], 'plan_pro_xyz');
    $mp->addPreapproval('mp_B', 'authorized', $aB['attempt_token'], 'plan_pro_xyz');
    $ws = new MercadoPagoWebhookService($db, $mp);
    $seq = ($order === 'AB') ? ['mp_A', 'mp_B'] : ['mp_B', 'mp_A'];
    foreach ($seq as $id) {
        $r = $ws->process($id);
        assert_test($r['action'] === 'processed', "AT13-$order: webhook $id processed");
    }
    $rowA = $sm->findByAttemptToken($aA['attempt_token']);
    $rowB = $sm->findByAttemptToken($aB['attempt_token']);
    assert_test(($rowA['mp_preapproval_id'] ?? '') === 'mp_A' && (int)$rowA['user_id'] === 5, "AT13-$order: mp_A -> usuario 5");
    assert_test(($rowB['mp_preapproval_id'] ?? '') === 'mp_B' && (int)$rowB['user_id'] === 12, "AT13-$order: mp_B -> usuario 12");
    $plans = [];
    foreach ($db->tables['usuarios'] as $u) { $plans[(int)$u['id']] = $u['plano']; }
    assert_test(($plans[5] ?? '') === 'pro' && ($plans[12] ?? '') === 'pro', "AT13-$order: ambos promovidos corretamente");
}

echo "\n--- AT14: card_token_id nunca aparece em saidas do servico ---\n";
// Usa o servico REAL ate o ponto pre-rede: validacoes executam 100% do
// codigo real antes de qualquer curl; nenhuma saida pode ecoar o token.
$svc = new FakeAttemptMP();
$res2 = $svc->createPreapproval('plan_pro_xyz', 'a@ex.com', $aA['attempt_token'], 'https://example.com/r', '');
$blob2 = json_encode($res2);
assert_test(strpos($blob2, 'tok_SECRETO') === false, 'AT14a: token ausente no retorno de erro (codigo real)');
assert_test(($res2['error'] ?? '') === 'invalid_card_token', 'AT14b: erro generico invalid_card_token');
$res3 = $svc->createPreapproval('plan_pro_xyz', 'a@ex.com', $aA['attempt_token'], 'https://example.com/r', 'tok_com ESPACO!');
assert_test(strpos(json_encode($res3), 'tok_com') === false, 'AT14c: token malformado nao ecoado');
// Garantia estatica: nenhum error_log do servico interpola o card token.
$svcSrc = file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
$logLines = [];
foreach (explode("\n", $svcSrc) as $line) {
    if (str_contains($line, 'error_log')) $logLines[] = $line;
}
$leak = false;
foreach ($logLines as $line) {
    if (stripos($line, 'cardToken') !== false || stripos($line, 'card_token') !== false) $leak = true;
}
assert_test(!$leak && count($logLines) > 0, 'AT14d: nenhum error_log referencia card token (' . count($logLines) . ' logs auditados)');

echo "\n--- AT15: hardening pós security-review ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
assert_test($sm->updateStatusById((int)$a['id'], 'hacked_status', 'x', null, null) === false, 'AT15a: status arbitrario rejeitado');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['status'] ?? '') === 'pending', 'AT15b: linha intacta apos rejeicao');
[$ts, $v1] = MercadoPagoWebhookService::parseSignatureHeader('ts=1700000000, v1=abcdef1234');
assert_test($ts === '1700000000' && $v1 === 'abcdef1234', 'AT15c: header com espaco apos virgula parseado');
$vercel = json_decode((string)file_get_contents($ROOT . '/vercel.json'), true);
$csp = '';
foreach (($vercel['headers'][0]['headers'] ?? []) as $h) {
    if (($h['key'] ?? '') === 'Content-Security-Policy') $csp = (string)$h['value'];
}
assert_test(str_contains($csp, 'https://sdk.mercadopago.com'), 'AT15d: CSP cobre sdk.mercadopago.com');
assert_test(preg_match('/connect-src[^;]*sdk\.mercadopago\.com/', $csp) === 1, 'AT15e: connect-src inclui SDK (tokenizacao)');
assert_test(preg_match('/frame-src[^;]*mercadopago\.com/', $csp) === 1, 'AT15f: frame-src inclui MP (iframes CardForm)');
assert_test(!str_contains($csp, '*.'), 'AT15g: sem wildcards na CSP');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

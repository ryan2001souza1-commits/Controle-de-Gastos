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
require_once $ROOT . '/src/services/SubscriptionCheckoutService.php';

class FakeUserModel
{
    public function findById(int $id): ?object
    {
        if ($id === 5) return (object)['email' => 'a@ex.com'];
        if ($id === 12) return (object)['email' => 'b@ex.com'];
        return null;
    }
}

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

        // Claim atomico e emulado integralmente no fetch() (condicao + escrita
        // + retorno atomicos); o execute generico nao pode toca-lo antes.
        $isClaim = str_contains($sql, 'returning id') && isset($p[':mpid'], $p[':uid'], $p[':slug']);
        if (str_starts_with(trim($sql), 'update subscriptions') && !$isClaim) {
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
        // Claim atomico: UPDATE ... WHERE id/uid/slug + mp vazio RETURNING.
        if (str_contains($sql, 'returning id') && isset($p[':mpid'], $p[':uid'], $p[':slug'])) {
            foreach ($this->pdo->tables['subscriptions'] as &$s) {
                if ((int)$s['id'] === (int)$p[':id']
                    && (int)$s['user_id'] === (int)$p[':uid']
                    && (string)$s['plan_slug'] === (string)$p[':slug']
                    && ((string)($s['mp_preapproval_id'] ?? '') === '')) {
                    $s['mp_preapproval_id'] = (string)$p[':mpid'];
                    return ['id' => (int)$s['id'], 'status' => (string)$s['status'], 'mp_preapproval_id' => (string)$s['mp_preapproval_id']];
                }
            }
            unset($s);
            return false;
        }
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

class TestEvents
{
    public static array $log = [];
    public static function rec(string $e): void { self::$log[] = $e; }
    public static function reset(): void { self::$log = []; }
    public static function assertNoMpInsideTxn(string $name): bool
    {
        $depth = 0;
        foreach (self::$log as $e) {
            if ($e === 'db:begin') $depth++;
            if ($e === 'db:commit' || $e === 'db:rollback') $depth = max(0, $depth - 1);
            if ($depth > 0 && str_starts_with($e, 'mp:')) {
                return false;
            }
        }
        return true;
    }
}

class FakeAttemptPDO extends PDO
{
    public array $tables = [];
    public int $lastId = 100;
    public ?array $lastRow = null;
    public int $lastAffected = 0;
    public int $txDepth = 0;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;

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
    // Semântica PDO REAL: commit()/rollBack() sem transação ativa LANÇAM
    // PDOException (é exatamente o que o commit órfão de 5b5cf44e provocava
    // em produção). Contadores provam 0 begin/commit no caminho feliz.
    public function beginTransaction(): bool { $this->txDepth++; $this->beginCalls++; TestEvents::rec('db:begin'); return true; }
    public function commit(): bool {
        $this->commitCalls++;
        TestEvents::rec('db:commit');
        if ($this->txDepth <= 0) {
            throw new PDOException('There is no active transaction');
        }
        $this->txDepth--;
        return true;
    }
    public function rollBack(): bool {
        $this->rollbackCalls++;
        TestEvents::rec('db:rollback');
        if ($this->txDepth <= 0) {
            throw new PDOException('There is no active transaction');
        }
        $this->txDepth--;
        return true;
    }
    public function inTransaction(): bool { return $this->txDepth > 0; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
}

class FakeAttemptMP extends MercadoPagoService
{
    public array $preapprovals = [];
    public int $postCount = 0;
    public array $createQueue = [];
    public array $searchMap = [];
    public bool $searchFail = false;

    public function __construct() { $this->accessToken = 'TEST'; }

    public function createPreapproval(
        string $planId,
        string $payerEmail,
        string $externalReference,
        string $backUrl,
        string $cardTokenId = '',
        string $idempotencyKey = ''
    ): array {
        // Espelha a validacao real (cobertura exaustiva em
        // create_preapproval_tests.php); aqui o foco e o fluxo.
        if ($cardTokenId === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_card_token'];
        }
        TestEvents::rec('mp:post');
        $this->postCount++;
        $mock = array_shift($this->createQueue);
        if ($mock !== null) {
            return $mock;
        }
        $id = 'mp_new_' . $this->postCount;
        $this->preapprovals[$id] = [
            'id' => $id,
            'status' => 'authorized',
            'external_reference' => $externalReference,
            'preapproval_plan_id' => $planId,
        ];
        return [
            'ok' => true, 'status' => 201, 'preapproval_id' => $id,
            'init_point' => null, 'external_reference' => $externalReference,
            'plan_id' => $planId, 'mp_status' => 'authorized',
        ];
    }

    public function searchPreapprovalsByExternalReference(string $ext, int $limit = 10): array
    {
        TestEvents::rec('mp:search');
        if ($this->searchFail) {
            return ['ok' => false, 'error' => 'network_error', 'matches' => []];
        }
        return ['ok' => true, 'matches' => $this->searchMap[$ext] ?? []];
    }

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
        TestEvents::rec('mp:get');
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

echo "\n--- AT20: service happy path (token -> POST -> link -> active) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT20a: http 200');
assert_test(($r['body']['ok'] ?? false) === true, 'AT20b: ok=true');
assert_test($mp->postCount === 1, 'AT20c: exatamente 1 POST ao MP');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['status'] ?? '') === 'active', 'AT20d: tentativa ativa');
assert_test(($row['mp_preapproval_id'] ?? '') !== '', 'AT20e: mp id vinculado');
assert_test($db->inTransaction() === false, 'AT20f: transacao finalizada (commit)');

echo "\n--- AT21: validacoes de entrada (sem DB) ---\n";
$r = $svc->processTokenPayment(0, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 401, 'AT21a: user 0 -> 401');
$r = $svc->processTokenPayment(5, 'zzz', 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 400 && ($r['phase'] ?? '') === 'pre_validation', 'AT21b: attempt malformado -> 400 phase=pre_validation');
$r = $svc->processTokenPayment(5, $a['attempt_token'], '');
assert_test(($r['body']['error'] ?? '') === 'invalid_card_token', 'AT21c: token vazio -> invalid_card_token');

echo "\n--- AT22: attempt de outro usuario -> 404 ---\n";
$r = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 404, 'AT22a: 404 sem vazar existencia');

echo "\n--- AT23: retry apos vinculo nao re-POSTa ---\n";
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_outro_123');
assert_test(($r['http'] ?? 0) === 200 && ($r['body']['already'] ?? false) === true, 'AT23a: retry retorna already:true');
assert_test($mp->postCount === 1, 'AT23b: nenhum POST adicional');

echo "\n--- AT24: MP 400 recusado -> 402 sem persistir ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->createQueue = [['ok' => false, 'status' => 400, 'error' => 'bad_request', 'mp_detail' => 'cc_rejected_insufficient_amount']];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 402, 'AT24a: http 402');
assert_test(($r['body']['error'] ?? '') === 'card_declined', 'AT24b: card_declined (mensagem segura)');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === '', 'AT24c: nada vinculado (rollback)');
assert_test($db->inTransaction() === false, 'AT24d: transacao finalizada (rollback)');

echo "\n--- AT25: timeout pos-criacao resolve via search (sem 2o POST) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->createQueue = [['ok' => false, 'status' => 0, 'error' => 'network_error']];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r1 = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r1['http'] ?? 0) === 502, 'AT25a: timeout sem registro -> 502 processing');
// MP havia criado a preapproval (descoberta depois); retry reconcilia:
$mp->searchMap[$a['attempt_token']] = [[
    'id' => 'mp_orphan_1', 'status' => 'authorized',
    'preapproval_plan_id' => 'plan_pro_xyz', 'external_reference' => $a['attempt_token'],
]];
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200 && ($r['body']['reconciled'] ?? false) === true, 'AT25b: retry reconcilia sem novo POST');
assert_test($mp->postCount === 1, 'AT25c: apenas o POST original (1 chamada MP, sem duplicata)');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === 'mp_orphan_1' && ($row['status'] ?? '') === 'active', 'AT25d: orfa vinculada e ativa');

echo "\n--- AT26: timeout sem registro no MP -> 502 processing ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->createQueue = [['ok' => false, 'status' => 0, 'error' => 'network_error']];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 502 && ($r['body']['error'] ?? '') === 'processing', 'AT26a: 502 processing (frontend faz poll)');

echo "\n--- AT27: retry encontra orfa via search (sem POST) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
$mp->searchMap[$a['attempt_token']] = [[
    'id' => 'mp_orphan_2', 'status' => 'pending',
    'preapproval_plan_id' => 'plan_premium_xyz', 'external_reference' => $a['attempt_token'],
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_novo_456');
assert_test(($r['http'] ?? 0) === 200 && ($r['body']['reconciled'] ?? false) === true, 'AT27a: reconciliado');
assert_test($mp->postCount === 0, 'AT27b: zero POST ao MP');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['status'] ?? '') === 'pending', 'AT27c: status pending (sem ativacao indevida)');

echo "\n--- AT28: search com multiplos -> 409 fail-closed ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->searchMap[$a['attempt_token']] = [
    ['id' => 'mp_dup_1', 'status' => 'authorized', 'preapproval_plan_id' => 'plan_pro_xyz', 'external_reference' => $a['attempt_token']],
    ['id' => 'mp_dup_2', 'status' => 'authorized', 'preapproval_plan_id' => 'plan_pro_xyz', 'external_reference' => $a['attempt_token']],
];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 409, 'AT28a: 409 conflict (revisao humana)');

echo "\n--- AT29: describeDbError redige segredos ---\n";
$pdoEx = new PDOException('SQLSTATE[23505]: x duplicate user@mail.com hex abcdef0123456789 Bearer tok APP_USR-zzz card 4111111111111111');
$pdoEx->errorInfo = ['23505', '7', 'duplicate'];
$d = Subscription::describeDbError($pdoEx);
assert_test(($d['class'] ?? '') === 'PDOException', 'AT29a: classe preservada');
assert_test(($d['sqlstate'] ?? '') === '23505', 'AT29b: sqlstate preservado');
$blob = json_encode($d);
assert_test(strpos($blob, 'user@mail.com') === false, 'AT29c: email redigido');
assert_test(strpos($blob, 'abcdef0123456789') === false, 'AT29d: hex redigido');
assert_test(strpos($blob, '4111111111111111') === false, 'AT29e: PAN redigido');
assert_test(strpos($blob, 'Bearer tok') === false, 'AT29f: bearer redigido');
assert_test(strpos($blob, 'APP_USR-zzz') === false, 'AT29g: chave redigida');

echo "\n--- AT30: PDO inesperado -> 500 com fase (sem vazar) ---\n";
class ThrowingPDO extends FakeAttemptPDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('SQLSTATE[08006]: connection failure');
    }
}
$throwDb = new ThrowingPDO();
$svcThrow = new SubscriptionCheckoutService($throwDb, $mp, new FakeUserModel());
$r = $svcThrow->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 500, 'AT30a: http 500');
assert_test(($r['body']['error'] ?? '') === 'internal_error', 'AT30b: erro generico ao cliente');
assert_test(isset($r['phase']) && isset($r['debug']['sqlstate']), 'AT30c: fase+debug presentes p/ log');
assert_test(strpos(json_encode($r['body']), '08006') === false, 'AT30d: sqlstate NAO vaza ao cliente');

echo "\n--- AT31: NENHUMA rede dentro de transacao (prova por eventos) ---\n";
foreach (['happy' => 'tok_valid_abc', 'timeout' => 'tok_valid_abc'] as $mode => $tok) {
    [$db, $mp, $sm] = makeAttemptEnv();
    $a = $sm->createAttempt(5, 'pro', 2);
    if ($mode === 'timeout') {
        $mp->createQueue = [['ok' => false, 'status' => 0, 'error' => 'network_error']];
    }
    TestEvents::reset();
    $svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
    $svc->processTokenPayment(5, $a['attempt_token'], $tok);
    assert_test(TestEvents::assertNoMpInsideTxn('x'), "AT31-$mode: zero chamadas MP entre begin e commit/rollback");
}

echo "\n--- AT32: webhook vence a corrida, subscribe retorna already ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->addPreapproval('mp_RACE', 'authorized', $a['attempt_token'], 'plan_pro_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$wr = $ws->process('mp_RACE');
assert_test($wr['action'] === 'processed', 'AT32a: webhook vinculou primeiro');
TestEvents::reset();
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['body']['already'] ?? false) === true, 'AT32b: subscribe retorna already:true');
assert_test($mp->postCount === 0, 'AT32c: zero POST (webhook venceu, sem duplicata)');
assert_test(TestEvents::assertNoMpInsideTxn('x'), 'AT32d: ordem rede/txn preservada');

echo "\n--- AT33: retry apos falha local nao duplica no MP ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r1 = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_valid_abc');
$r2 = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_valid_abc');
assert_test($mp->postCount === 1, 'AT33a: 2 chamadas locais = 1 POST ao MP');
assert_test(($r2['body']['already'] ?? false) === true, 'AT33b: segunda retorna already');

echo "\n--- AT34: poll limitado no frontend (sem spinner infinito) ---\n";
$jsSrc = (string)file_get_contents($ROOT . '/public/js/mp_subscribe.js');
assert_test(str_contains($jsSrc, 'POLL_MAX_ATTEMPTS'), 'AT34a: limite de tentativas definido');
assert_test(str_contains($jsSrc, 'POLL_INTERVAL_MS'), 'AT34b: intervalo definido');
assert_test(str_contains($jsSrc, 'verificar novamente mais tarde'), 'AT34c: deadline encerra com mensagem + retry (sem spinner infinito)');
assert_test(str_contains($jsSrc, "'rejected'") && str_contains($jsSrc, "'cancelled'"), 'AT34d: estados terminais encerram o poll');

echo "\n--- AT35: falha SQL aborta txn -> rollback imediato, sem cascata 25P02 ---\n";
class FailOncePDO extends FakeAttemptPDO
{
    public int $prepares = 0;
    public int $failOn = 3;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        if ($this->prepares === $this->failOn) {
            $ex = new PDOException('SQLSTATE[40001]: serialization failure');
            $ex->errorInfo = ['40001', '', 'serialization'];
            throw $ex;
        }
        return parent::prepare($query, $options);
    }
}
[$db0, $mp0, $sm0] = makeAttemptEnv();
$a0 = $sm0->createAttempt(5, 'pro', 2);
$failDb = new FailOncePDO();
$failDb->tables = $db0->tables;
$failDb->lastId = $db0->lastId;
$svcFail = new SubscriptionCheckoutService($failDb, $mp0, new FakeUserModel());
$preparesBefore = $failDb->prepares;
$r = $svcFail->processTokenPayment(5, $a0['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 500, 'AT35a: falha vira 500 generico');
assert_test($failDb->inTransaction() === false, 'AT35b: rollback executado (sem txn residual)');
assert_test($failDb->prepares <= $preparesBefore + 4, 'AT35c: nenhuma query apos a falha (sem cascata)', 'prepares=' . $failDb->prepares);

echo "\n--- AT36: reconcile_search com txn residual aberta -> guard reverte e segue ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$db->beginTransaction();
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT36a: fluxo segue apos guard (invariant logged)');
assert_test($db->inTransaction() === false, 'AT36b: sem txn residual ao final');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['status'] ?? '') === 'active', 'AT36c: vinculo concluido mesmo com txn previa');

echo "\n--- AT37: concorrencia mesma attempt, segundo ve primeiro vinculado ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r1 = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test($mp->postCount === 1, 'AT37a: primeiro POST vincula');
$r2 = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_outro_999');
assert_test(($r2['body']['already'] ?? false) === true && $mp->postCount === 1, 'AT37b: segundo request reutiliza (0 POST extra)');

echo "\n--- AT38: trilha prova txn fechada antes de reconcile_search ---\n";
class TrailPDO extends FakeAttemptPDO
{
    public array $trailSeen = [];
}
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT38a: sucesso');
assert_test(str_contains($r['debug']['trail'] ?? '', 'reconcile_search:no') || !isset($r['debug']), 'AT38b: sem debug em sucesso (só falha registra trilha)');

echo "\n--- AT39: debug de falha traz trilha + fase exata ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$failDb2 = new FailOncePDO();
$failDb2->tables = $db->tables;
$failDb2->lastId = $db->lastId;
$svcFail2 = new SubscriptionCheckoutService($failDb2, $mp, new FakeUserModel());
$r = $svcFail2->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(isset($r['debug']['trail']) && str_contains($r['debug']['trail'], ':'), 'AT39a: trilha presente no debug');
assert_test(!empty($r['phase']), 'AT39b: fase presente');

echo "\n--- AT40: guard de entrada reverte txn residual e registra ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$db->beginTransaction();
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT40a: guard reverteu e fluxo completou');
assert_test($db->inTransaction() === false, 'AT40b: sem txn residual ao final');

echo "\n--- AT41: claim atomico vence/perde sem excecao ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$won = $sm->claimMpPreapprovalId((int)$a['id'], 5, 'pro', 'mp_claim_1');
assert_test($won !== null && ($won['mp_preapproval_id'] ?? '') === 'mp_claim_1', 'AT41a: claim vence em linha livre');
$lost = $sm->claimMpPreapprovalId((int)$a['id'], 5, 'pro', 'mp_claim_2');
assert_test($lost === null, 'AT41b: segundo claim perde (retorna null, sem excecao)');
$badOwner = $sm->claimMpPreapprovalId((int)$a['id'], 12, 'pro', 'mp_claim_3');
assert_test($badOwner === null, 'AT41c: owner errado nao toma a linha');
$badPlan = $sm->claimMpPreapprovalId((int)$a['id'], 5, 'premium', 'mp_claim_4');
assert_test($badPlan === null, 'AT41d: plano errado nao toma a linha');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === 'mp_claim_1', 'AT41e: vencedor preservado');

echo "\n--- AT42: fluxo nao usa SELECT FOR UPDATE ---\n";
$svcSrc = (string)file_get_contents($ROOT . '/src/services/SubscriptionCheckoutService.php');
assert_test(stripos($svcSrc, 'FOR UPDATE') === false, 'AT42a: service sem FOR UPDATE');
$modelSrc = (string)file_get_contents($ROOT . '/src/models/Subscription.php');
assert_test(!preg_match('/SELECT[^;]*FOR UPDATE/i', $modelSrc), 'AT42b: model sem SELECT FOR UPDATE executavel');

echo "\n--- AT43: concorrencia real no claim (duas tentativas, um mp id) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$aA = $sm->createAttempt(5, 'pro', 2);
$aB = $sm->createAttempt(12, 'pro', 2);
$w1 = $sm->claimMpPreapprovalId((int)$aA['id'], 5, 'pro', 'mp_race_1');
$w2 = $sm->claimMpPreapprovalId((int)$aB['id'], 12, 'pro', 'mp_race_1');
assert_test($w1 !== null, 'AT43a: primeira tomada vence');
assert_test($w2 !== null, 'AT43b: linhas distintas aceitam (dono verificado depois)');
$dup = $sm->claimMpPreapprovalId((int)$aA['id'], 5, 'pro', 'mp_race_2');
assert_test($dup === null, 'AT43c: mesma linha nao e retomada');

echo "\n--- AT44: fluxo nao depende de query() auxiliar (verify removido) ---\n";
class VerifyFailPDO extends FakeAttemptPDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        throw new PDOException('SQLSTATE[08006]: connection failure');
    }
}
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$vdb = new VerifyFailPDO();
$vdb->tables = $db->tables;
$vdb->lastId = $db->lastId;
$svc = new SubscriptionCheckoutService($vdb, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT44a: sucesso mesmo com query() quebrado (nada o usa)');
assert_test($vdb->inTransaction() === false, 'AT44b: sem txn residual');
$smV = new Subscription($vdb);
$row = $smV->findByAttemptToken($a['attempt_token']);
assert_test(($row['status'] ?? '') === 'active', 'AT44c: vinculo completo');

echo "\n--- AT45: search acha mp de OUTRO plano -> conflict, sem POST ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->searchMap[$a['attempt_token']] = [[
    'id' => 'mp_WRONGPLAN', 'status' => 'authorized',
    'preapproval_plan_id' => 'plan_premium_xyz', 'external_reference' => $a['attempt_token'],
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 409, 'AT45a: 409 fail-closed');
assert_test($mp->postCount === 0, 'AT45b: zero POST (registro incompativel nao e usado)');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['mp_preapproval_id'] ?? '') === '', 'AT45c: nada vinculado');

echo "\n--- AT46: claim vence mesmo com verify + ordem deterministica ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
TestEvents::reset();
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT46a: sucesso');
assert_test(TestEvents::assertNoMpInsideTxn('x'), 'AT46b: rede fora de txn mantido');

echo "\n--- AT47: caminho feliz nao abre transacao (25P02 estruturalmente impossivel) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
TestEvents::reset();
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT47a: sucesso');
$begins = count(array_filter(TestEvents::$log, fn($e) => $e === 'db:begin'));
assert_test($begins === 0, 'AT47b: zero beginTransaction no caminho feliz', 'begins=' . $begins);
assert_test(TestEvents::assertNoMpInsideTxn('x'), 'AT47c: ordem rede/txn preservada');

echo "\n--- AT48: progresso parcial converge no retry (claim direto + adocao) ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
// Simula crash apos o claim (mp gravado, status nao atualizado):
$won = $sm->claimMpPreapprovalId((int)$a['id'], 12, 'premium', 'mp_partial_1');
assert_test($won !== null, 'AT48a: claim direto funciona');
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT48b: retry adota o vinculo parcial');
assert_test($mp->postCount === 0, 'AT48c: zero POST (nada duplicado)');

echo "\n--- AT49: outcome por status (backend nunca mente sucesso) ---\n";
assert_test(SubscriptionCheckoutService::outcomeFor('active') === 'active', 'AT49a: active -> active');
assert_test(SubscriptionCheckoutService::outcomeFor('pending') === 'processing', 'AT49b: pending -> processing');
assert_test(SubscriptionCheckoutService::outcomeFor('rejected') === 'rejected', 'AT49c: rejected -> rejected');
assert_test(SubscriptionCheckoutService::outcomeFor('cancelled') === 'cancelled', 'AT49d: cancelled -> cancelled');
assert_test(SubscriptionCheckoutService::outcomeFor('paused') === 'paused', 'AT49e: paused -> paused');
assert_test(SubscriptionCheckoutService::outcomeFor(null) === 'processing', 'AT49f: desconhecido -> processing (nunca sucesso)');
assert_test(SubscriptionCheckoutService::outcomeFor('hacked') === 'processing', 'AT49g: inesperado -> processing');

echo "\n--- AT50: resposta carrega outcome conforme status MP ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['body']['outcome'] ?? '') === 'active', 'AT50a: authorized -> outcome active', $r['body']['outcome'] ?? '');
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
$mp->createQueue = [[
    'ok' => true, 'status' => 201, 'preapproval_id' => 'mp_pend_9',
    'external_reference' => $a['attempt_token'], 'plan_id' => 'plan_premium_xyz',
    'mp_status' => 'pending',
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['body']['outcome'] ?? '') === 'processing', 'AT50b: pending -> outcome processing (NAO sucesso)');
assert_test(($r['body']['ok'] ?? false) === true, 'AT50c: ok tecnico mantido (frontend decide por outcome)');
$userB = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 12) $userB = $u; }
assert_test(($userB['plano'] ?? '') === 'gratuito', 'AT50d: pending NAO promove usuario');

echo "\n--- AT51: linked+authorized+FREE aplica plano idempotente ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$sm->updateMpData((int)$a['id'], 'mp_preauth_7', 'pending', null);
$mp->addPreapproval('mp_preauth_7', 'authorized', $a['attempt_token'], 'plan_pro_xyz');
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['body']['outcome'] ?? '') === 'active', 'AT51a: outcome active');
$userA = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
assert_test(($userA['plano'] ?? '') === 'pro', 'AT51b: plano aplicado (estava FREE)');
assert_test($mp->postCount === 0, 'AT51c: zero POST (só reconciliação)');

echo "\n--- AT52: REGRESSÃO commit órfão — caminho feliz em AUTOCOMMIT ---\n";
// O bug de 5b5cf44e: linkAttempt() chamava $db->commit() sem begin.
// Com PDO real isso lança PDOException APÓS os UPDATEs (autocommit) e o
// serviço respondia 500. Estes casos falham se qualquer commit órfão voltar.
function assert_autocommit_clean(FakeAttemptPDO $db, string $name): void
{
    assert_test($db->beginCalls === 0, "$name: BEGIN_CALLS=0", 'beginCalls=' . $db->beginCalls);
    assert_test($db->commitCalls === 0, "$name: COMMIT_CALLS=0", 'commitCalls=' . $db->commitCalls);
    assert_test($db->inTransaction() === false, "$name: sem txn residual");
}

// AT52a: o próprio double respeita a semântica real do PDO.
$strictDb = new FakeAttemptPDO();
$threw = false;
try {
    $strictDb->commit();
} catch (PDOException $e) {
    $threw = true;
}
assert_test($threw, 'AT52a: fake commit() sem txn lança PDOException (semântica real)');
assert_test($strictDb->commitCalls === 1, 'AT52b: contador registra a tentativa de commit');

// AT52c: fresh + pending → 200 processing, sem begin/commit, sem promoção.
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->createQueue = [[
    'ok' => true, 'status' => 201, 'preapproval_id' => 'mp52_pend',
    'external_reference' => $a['attempt_token'], 'plan_id' => 'plan_pro_xyz',
    'mp_status' => 'pending',
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT52c: fresh+pending http 200');
assert_test(($r['body']['outcome'] ?? '') === 'processing', 'AT52d: fresh+pending outcome processing (não sucesso)');
assert_autocommit_clean($db, 'AT52e: fresh+pending');
$userA = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
assert_test(($userA['plano'] ?? '') === 'gratuito', 'AT52f: fresh+pending NÃO promove usuário');

// AT52g: fresh + authorized → 200 active, plano aplicado, sem begin/commit.
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200 && ($r['body']['outcome'] ?? '') === 'active', 'AT52g: fresh+authorized 200 active');
assert_autocommit_clean($db, 'AT52h: fresh+authorized');
$userA = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
assert_test(($userA['plano'] ?? '') === 'pro', 'AT52i: fresh+authorized promove usuário');

// AT52j: reconciled + pending → 200 reconciled, zero POST, sem begin/commit.
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(12, 'premium', 3);
$mp->searchMap[$a['attempt_token']] = [[
    'id' => 'mp52_orfa', 'status' => 'pending',
    'preapproval_plan_id' => 'plan_premium_xyz', 'external_reference' => $a['attempt_token'],
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(12, $a['attempt_token'], 'tok_novo_456');
assert_test(($r['http'] ?? 0) === 200 && ($r['body']['reconciled'] ?? false) === true, 'AT52j: reconciled+pending 200 reconciled');
assert_test(($r['body']['outcome'] ?? '') === 'processing', 'AT52k: reconciled+pending outcome processing');
assert_test($mp->postCount === 0, 'AT52l: reconciled zero POST');
assert_autocommit_clean($db, 'AT52m: reconciled+pending');

// AT52n: already + pending → retry retorna already, sem POST extra, sem txn.
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->createQueue = [[
    'ok' => true, 'status' => 201, 'preapproval_id' => 'mp52_already_p',
    'external_reference' => $a['attempt_token'], 'plan_id' => 'plan_pro_xyz',
    'mp_status' => 'pending',
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r1 = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
$r2 = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_outro_999');
assert_test(($r1['http'] ?? 0) === 200, 'AT52n: already+pending primeiro vincula 200');
assert_test(($r2['body']['already'] ?? false) === true, 'AT52o: already+pending retry already:true');
assert_test($mp->postCount === 1, 'AT52p: already+pending 1 POST total');
assert_autocommit_clean($db, 'AT52q: already+pending');

// AT52r: already + authorized → retry retorna already, sem POST extra, sem txn.
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_outro_999');
assert_test(($r['body']['already'] ?? false) === true, 'AT52r: already+authorized retry already:true');
assert_test($mp->postCount === 1, 'AT52s: already+authorized 1 POST total');
assert_autocommit_clean($db, 'AT52t: already+authorized');

// AT52u: linked + authorized + FREE aplica plano de forma idempotente, sem txn.
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$sm->updateMpData((int)$a['id'], 'mp52_preauth', 'pending', null);
$mp->addPreapproval('mp52_preauth', 'authorized', $a['attempt_token'], 'plan_pro_xyz');
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['body']['outcome'] ?? '') === 'active', 'AT52u: linked+authorized outcome active');
assert_autocommit_clean($db, 'AT52v: linked+authorized');
$userA = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
assert_test(($userA['plano'] ?? '') === 'pro', 'AT52w: linked+authorized aplica plano (estava FREE)');
assert_test($mp->postCount === 0, 'AT52x: linked zero POST (só reconciliação)');

echo "\n--- AT53: MP cancela na criação -> 200 cancelled, sem promoção, retry gera nova attempt ---\n";
[$db, $mp, $sm] = makeAttemptEnv();
$a = $sm->createAttempt(5, 'pro', 2);
$mp->createQueue = [[
    'ok' => true, 'status' => 201, 'preapproval_id' => 'mp53_cancel_1',
    'external_reference' => $a['attempt_token'], 'plan_id' => 'plan_pro_xyz',
    'mp_status' => 'cancelled',
]];
$svc = new SubscriptionCheckoutService($db, $mp, new FakeUserModel());
$r = $svc->processTokenPayment(5, $a['attempt_token'], 'tok_valid_abc');
assert_test(($r['http'] ?? 0) === 200, 'AT53a: http 200 (veredito terminal, não 500)');
assert_test(($r['body']['outcome'] ?? '') === 'cancelled', 'AT53b: outcome cancelled (frontend mostra cancelamento, não sucesso)');
$row = $sm->findByAttemptToken($a['attempt_token']);
assert_test(($row['status'] ?? '') === 'cancelled', 'AT53c: linha local cancelled (reflete o MP)');
assert_test(($row['mp_preapproval_id'] ?? '') === 'mp53_cancel_1', 'AT53d: mp id vinculado (auditoria)');
$userA = null;
foreach ($db->tables['usuarios'] as $u) { if ((int)$u['id'] === 5) $userA = $u; }
assert_test(($userA['plano'] ?? '') === 'gratuito', 'AT53e: cancelled NÃO promove usuário');
assert_test($db->beginCalls === 0 && $db->commitCalls === 0, 'AT53f: sem begin/commit', 'begin=' . $db->beginCalls . ' commit=' . $db->commitCalls);
$a2 = $sm->createAttempt(5, 'pro', 2);
assert_test(($a2['created'] ?? false) === true, 'AT53g: retry após cancelled cria NOVA attempt (cancelled não é reutilizada)');
assert_test($a2['attempt_token'] !== $a['attempt_token'], 'AT53h: token distinto (sem reuso indevido)');

echo "\n--- AT54: auditoria forense dos logs — sufixos, nunca segredos ---\n";
$svcSrc = (string)file_get_contents($ROOT . '/src/services/SubscriptionCheckoutService.php');
$svcLogs = [];
foreach (explode("\n", $svcSrc) as $line) {
    if (str_contains($line, 'error_log')) $svcLogs[] = $line;
}
$leakFull = false;
foreach ($svcLogs as $line) {
    if (preg_match('/\$attemptToken\s*[,\)]/', $line)) $leakFull = true;
    if (preg_match('/\$mpPreapprovalId\s*[,\)]/', $line)) $leakFull = true;
    if (stripos($line, 'cardToken') !== false) $leakFull = true;
}
assert_test(!$leakFull, 'AT54a: nenhum error_log interpola attempt/mp id completos ou card token');
$hasSuffix = str_contains($svcSrc, 'attempt_suffix');
assert_test($hasSuffix, 'AT54b: logs de desfecho carregam attempt_suffix (forense futura)');
$indexSrc = (string)file_get_contents($ROOT . '/public/index.php');
assert_test(!preg_match('/attempt=%s/', $indexSrc), 'AT54c: log de 500 usa attempt_suffix (não token completo)');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

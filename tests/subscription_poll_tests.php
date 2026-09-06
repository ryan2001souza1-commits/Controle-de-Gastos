<?php
/**
 * Testes do SubscriptionPollService (poll com reconciliação controlada).
 *
 * Cobre (Fase 13):
 *  1. MP retorna pending -> poll mantém pending, sem promoção
 *  2. poll consulta MP quando stale; NÃO consulta quando fresh (throttle)
 *  3. MP pending -> authorized: vira active + aplica plano
 *  4. MP pending -> rejected: vira rejected, sem promoção
 *  6. webhook ausente + poll recupera (authorized via poll, sem webhook)
 *  7. linked+authorized+FREE aplica de forma idempotente
 *  9. poll + webhook simultâneos: webhook vence, poll vê terminal sem rede
 * 11. preapproval inexistente (MP 404): mantém pending, sem escrita
 * 12. mp_preapproval_id inválido: zero chamadas ao MP
 * 13. commit sem transaction falha no fake (semântica PDO real)
 * 14. caminho feliz não chama begin/commit (contadores)
 * 15. frontend: pending NÃO mostra sucesso
 * 16. frontend: active mostra sucesso
 *  + mapa canônico central mapMercadoPagoSubscriptionStatus()
 *
 * Itens 5/8/10 já cobertos em mp_attempt_flow_tests.php (AT04/AT05/AT25).
 * Sem rede real: FakePollMP + FakePollPDO em memória.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';
require_once $ROOT . '/src/services/SubscriptionCheckoutService.php';
require_once $ROOT . '/src/services/SubscriptionPollService.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

class FakePollStmt extends PDOStatement
{
    public FakePollPDO $pdo;
    public string $sqlText = '';
    public array $bound = [];

    protected function __construct() {}

    public static function make(FakePollPDO $pdo, string $sql): self
    {
        $s = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $s->pdo = $pdo;
        $s->sqlText = strtolower($sql);
        return $s;
    }

    public function execute(?array $params = null): bool
    {
        $this->bound = $params ?? [];
        $sql = $this->sqlText;
        $p = $this->bound;

        if (str_starts_with(trim($sql), 'update subscriptions')) {
            $rows = &$this->pdo->tables['subscriptions'];
            foreach ($rows as &$s) {
                if ((int)$s['id'] !== (int)($p[':id'] ?? -1)) continue;
                if (isset($p[':mpid'])) $s['mp_preapproval_id'] = (string)$p[':mpid'];
                if (isset($p[':raw_status'])) $s['raw_status'] = (string)$p[':raw_status'];
                if (array_key_exists(':next_billing_date', $p) && $p[':next_billing_date'] !== null) {
                    $s['next_billing_date'] = $p[':next_billing_date'];
                }
                if (isset($p[':status'])) {
                    // updateStatusById: respeita allowlist como o modelo real.
                    if (!in_array($p[':status'], ['pending','active','paused','cancelled','expired','rejected'], true)) {
                        continue;
                    }
                    $s['status'] = (string)$p[':status'];
                    if (array_key_exists(':grace_period_end', $p)) {
                        if ((int)($p[':clear_grace'] ?? 0) === 1) $s['grace_period_end'] = null;
                        elseif ($p[':grace_period_end'] !== null) $s['grace_period_end'] = $p[':grace_period_end'];
                    }
                }
                $s['updated_at'] = date('Y-m-d H:i:s');
                $this->pdo->lastAffected = 1;
            }
            unset($s);
        }

        if (str_starts_with(trim($sql), 'update usuarios')) {
            foreach ($this->pdo->tables['usuarios'] as &$u) {
                if ((int)$u['id'] !== (int)($p[':uid'] ?? -1)) continue;
                if (str_contains($sql, "'gratuito'")) {
                    $u['plano'] = 'gratuito';
                    $u['plano_status'] = 'ativo';
                    $u['active_subscription_id'] = null;
                    continue;
                }
                if (isset($p[':plan'])) { $u['plano'] = $p[':plan']; $u['plano_status'] = 'ativo'; }
                if (isset($p[':sub_id'])) $u['active_subscription_id'] = (int)$p[':sub_id'];
                if (isset($p[':grace'])) $u['plano_fim'] = $p[':grace'];
                if (str_contains($sql, 'plano_fim = null')) $u['plano_fim'] = null;
            }
            unset($u);
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        $sql = $this->sqlText;
        $p = $this->bound;
        if (str_contains($sql, 'from subscriptions') && isset($p[':token'])) {
            foreach (array_reverse($this->pdo->tables['subscriptions']) as $s) {
                if ((string)($s['attempt_token'] ?? '') === (string)$p[':token'] && $p[':token'] !== '') {
                    return $s;
                }
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':mpid'])) {
            foreach (array_reverse($this->pdo->tables['subscriptions']) as $s) {
                if ((string)($s['mp_preapproval_id'] ?? '') === (string)$p[':mpid'] && $p[':mpid'] !== '') {
                    return $s;
                }
            }
            return false;
        }
        if (str_contains($sql, 'from subscriptions') && isset($p[':id'])) {
            foreach ($this->pdo->tables['subscriptions'] as $s) {
                if ((int)$s['id'] === (int)$p[':id']) return $s;
            }
            return false;
        }
        if (str_contains($sql, 'from planos')) {
            $slug = $p[0] ?? '';
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
        if (str_contains($sql, 'from usuarios') && isset($p[':uid'])) {
            foreach ($this->pdo->tables['usuarios'] as $u) {
                if ((int)$u['id'] !== (int)$p[':uid']) continue;
                if (str_contains($sql, 'select plano ')) return (string)$u['plano'];
                if (str_contains($sql, 'active_subscription_id')) return $u['active_subscription_id'];
                return (int)$u['id'];
            }
            return false;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return $this->pdo->lastAffected; }
}

class FakePollPDO extends PDO
{
    public array $tables = [];
    public int $lastId = 500;
    public int $lastAffected = 0;
    public int $txDepth = 0;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;

    public function __construct()
    {
        $this->tables['planos'] = [
            ['id' => 1, 'slug' => 'gratuito', 'nome' => 'Gratuito'],
            ['id' => 2, 'slug' => 'pro', 'nome' => 'Pro'],
            ['id' => 3, 'slug' => 'premium', 'nome' => 'Premium'],
        ];
        $this->tables['usuarios'] = [
            ['id' => 5, 'nome' => 'A', 'email' => 'a@ex.com', 'plano' => 'gratuito', 'plano_status' => 'ativo', 'active_subscription_id' => null, 'plano_fim' => null, 'plano_inicio' => null],
        ];
        $this->tables['subscriptions'] = [];
    }

    /** Cria linha de attempt com updated_at controlado (stale vs fresh). */
    public function addAttempt(string $token, string $planSlug, string $status, string $mpId, string $updatedAt): int
    {
        $id = ++$this->lastId;
        $this->tables['subscriptions'][] = [
            'id' => $id, 'user_id' => 5, 'plan_id' => 2, 'plan_slug' => $planSlug,
            'status' => $status, 'start_date' => null, 'next_billing_date' => null,
            'paused_at' => null, 'cancelled_at' => null, 'expired_at' => null,
            'grace_period_end' => null, 'raw_status' => $status,
            'external_reference' => $token, 'mp_preapproval_id' => $mpId,
            'attempt_token' => $token, 'checkout_url' => null,
            'created_at' => '2000-01-01 00:00:00', 'updated_at' => $updatedAt,
        ];
        return $id;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return FakePollStmt::make($this, $query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return FakePollStmt::make($this, $query);
    }
    public function exec(string $statement): int|false { return 0; }
    public function beginTransaction(): bool { $this->txDepth++; $this->beginCalls++; return true; }
    public function commit(): bool {
        $this->commitCalls++;
        if ($this->txDepth <= 0) {
            throw new PDOException('There is no active transaction');
        }
        $this->txDepth--;
        return true;
    }
    public function rollBack(): bool {
        $this->rollbackCalls++;
        if ($this->txDepth <= 0) {
            throw new PDOException('There is no active transaction');
        }
        $this->txDepth--;
        return true;
    }
    public function inTransaction(): bool { return $this->txDepth > 0; }
}

class FakePollMP extends MercadoPagoService
{
    public int $getCalls = 0;
    /** @var array<string,array> mp_id => data|['__error__'=>[...]] */
    public array $fixtures = [];

    public function __construct() { $this->accessToken = 'TEST'; }

    public function getPreapproval(string $id): array
    {
        $this->getCalls++;
        if (!isset($this->fixtures[$id])) {
            return ['ok' => false, 'status' => 404, 'error' => 'not_found'];
        }
        $f = $this->fixtures[$id];
        if (isset($f['__error__'])) {
            return $f['__error__'];
        }
        return ['ok' => true, 'status' => 200, 'data' => $f];
    }
}

function poll_user_plan(FakePollPDO $db): string
{
    foreach ($db->tables['usuarios'] as $u) {
        if ((int)$u['id'] === 5) return (string)$u['plano'];
    }
    return '?';
}

function poll_row(FakePollPDO $db, int $id): array
{
    foreach ($db->tables['subscriptions'] as $s) {
        if ((int)$s['id'] === $id) return $s;
    }
    return [];
}

echo "\n=== TESTES: poll com reconciliação controlada ===\n\n";

echo "--- P00: mapa canônico central ---\n";
$map = fn(string $s) => MercadoPagoWebhookService::mapMercadoPagoSubscriptionStatus($s);
assert_test($map('authorized') === 'active', 'P00a: authorized -> active');
assert_test($map('active') === 'active', 'P00b: active -> active');
assert_test($map('pending') === 'pending', 'P00c: pending -> pending');
assert_test($map('in_process') === 'pending', 'P00d: in_process -> pending');
assert_test($map('paused') === 'paused', 'P00e: paused -> paused');
assert_test($map('cancelled') === 'cancelled', 'P00f: cancelled -> cancelled');
assert_test($map('canceled') === 'cancelled', 'P00g: canceled -> cancelled');
assert_test($map('rejected') === 'rejected', 'P00h: rejected -> rejected');
assert_test($map('failure') === 'rejected', 'P00i: failure -> rejected');
assert_test($map('expired') === 'expired', 'P00j: expired -> expired');
assert_test($map('weird_status') === null, 'P00k: desconhecido -> null (nunca ativa)');
assert_test(MercadoPagoWebhookService::mapMpStatusToInternal('authorized') === 'active', 'P00l: alias legado delega ao canônico');

echo "\n--- P01: MP pending -> mantém pending, sem promoção ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'pro', 'pending', 'mp_pend_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_pend_1'] = ['id' => 'mp_pend_1', 'status' => 'pending', 'preapproval_plan_id' => 'x', 'next_payment_date' => null];
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
assert_test(($r['http'] ?? 0) === 200, 'P01a: http 200');
assert_test(($r['body']['status'] ?? '') === 'pending', 'P01b: status pending');
assert_test(($r['body']['outcome'] ?? '') === 'processing', 'P01c: outcome processing (não sucesso)');
assert_test(($r['body']['synced'] ?? false) === true, 'P01d: reconciliado com o MP');
assert_test(poll_user_plan($db) === 'gratuito', 'P01e: usuário segue FREE');
assert_test(poll_row($db, $id)['status'] === 'pending', 'P01f: linha segue pending');

echo "\n--- P02: throttle — fresh não consulta MP; stale consulta ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$idFresh = $db->addAttempt('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'pro', 'pending', 'mp_fresh_1', date('Y-m-d H:i:s'));
$mp->fixtures['mp_fresh_1'] = ['id' => 'mp_fresh_1', 'status' => 'authorized', 'preapproval_plan_id' => 'x', 'next_payment_date' => null];
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
assert_test($mp->getCalls === 0, 'P02a: updated_at recente -> zero chamadas MP');
assert_test(($r['body']['synced'] ?? true) === false, 'P02b: synced=false (leitura local)');
$idStale = $db->addAttempt('cccccccccccccccccccccccccccccccc', 'pro', 'pending', 'mp_stale_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_stale_1'] = ['id' => 'mp_stale_1', 'status' => 'pending', 'preapproval_plan_id' => 'x', 'next_payment_date' => null];
$r2 = $svc->getStatus(5, 'cccccccccccccccccccccccccccccccc');
assert_test($mp->getCalls === 1, 'P02c: stale -> exatamente 1 chamada MP');
assert_test(($r2['body']['synced'] ?? false) === true, 'P02d: synced=true');

echo "\n--- P03/P06: pending -> authorized via poll (webhook ausente) aplica plano ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('dddddddddddddddddddddddddddddddd', 'pro', 'pending', 'mp_auth_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_auth_1'] = ['id' => 'mp_auth_1', 'status' => 'authorized', 'preapproval_plan_id' => 'x', 'next_payment_date' => '2026-10-06 10:00:00'];
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, 'dddddddddddddddddddddddddddddddd');
assert_test(($r['body']['status'] ?? '') === 'active', 'P03a: poll recupera active sem webhook');
assert_test(($r['body']['outcome'] ?? '') === 'active', 'P03b: outcome active (sucesso real)');
assert_test(poll_user_plan($db) === 'pro', 'P03c: usuário promovido a pro');
assert_test(poll_row($db, $id)['status'] === 'active', 'P03d: linha virou active');

echo "\n--- P04: pending -> rejected vira rejected, sem promoção ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'pro', 'pending', 'mp_rej_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_rej_1'] = ['id' => 'mp_rej_1', 'status' => 'rejected', 'preapproval_plan_id' => 'x', 'next_payment_date' => null];
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
assert_test(($r['body']['status'] ?? '') === 'rejected', 'P04a: status rejected');
assert_test(($r['body']['outcome'] ?? '') === 'rejected', 'P04b: outcome rejected (frontend mostra recusa)');
assert_test(poll_user_plan($db) === 'gratuito', 'P04c: usuário segue FREE');

echo "\n--- P07: linked+authorized+FREE idempotente (duplo poll) ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('ffffffffffffffffffffffffffffffff', 'pro', 'pending', 'mp_idem_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_idem_1'] = ['id' => 'mp_idem_1', 'status' => 'authorized', 'preapproval_plan_id' => 'x', 'next_payment_date' => null];
$svc = new SubscriptionPollService($db, $mp);
$r1 = $svc->getStatus(5, 'ffffffffffffffffffffffffffffffff');
$r2 = $svc->getStatus(5, 'ffffffffffffffffffffffffffffffff');
assert_test(($r1['body']['outcome'] ?? '') === 'active' && ($r2['body']['outcome'] ?? '') === 'active', 'P07a: ambos active');
assert_test(poll_user_plan($db) === 'pro', 'P07b: plano aplicado uma vez, sem duplicata');
assert_test(count($db->tables['subscriptions']) === 1, 'P07c: nenhuma linha duplicada');

echo "\n--- P09: webhook vence a corrida; poll posterior não usa rede ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('11111111111111111111111111111111', 'pro', 'pending', '', '2000-01-01 00:00:00');
$mp->fixtures['mp_race_9'] = ['id' => 'mp_race_9', 'status' => 'authorized', 'preapproval_plan_id' => 'plan_pro_xyz', 'external_reference' => '11111111111111111111111111111111'];
putenv('MERCADOPAGO_PLAN_ID_PRO=plan_pro_xyz');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=plan_premium_xyz');
$ws = new MercadoPagoWebhookService($db, $mp);
$wr = $ws->process('mp_race_9');
assert_test($wr['action'] === 'processed', 'P09a: webhook vinculou primeiro');
$callsBefore = $mp->getCalls;
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, '11111111111111111111111111111111');
assert_test(($r['body']['status'] ?? '') === 'active', 'P09b: poll vê active terminal');
assert_test($mp->getCalls === $callsBefore, 'P09c: zero chamadas MP adicionais');

echo "\n--- P11: MP 404 mantém pending, sem escrita ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('22222222222222222222222222222222', 'pro', 'pending', 'mp_ghost_1', '2000-01-01 00:00:00');
$svc = new SubscriptionPollService($db, $mp);
$before = poll_row($db, $id);
$r = $svc->getStatus(5, '22222222222222222222222222222222');
$after = poll_row($db, $id);
assert_test(($r['body']['status'] ?? '') === 'pending', 'P11a: mantém pending (fail-open)');
$sameExceptThrottle = true;
foreach (['status', 'raw_status', 'mp_preapproval_id', 'plan_slug'] as $f) {
    if (($before[$f] ?? null) !== ($after[$f] ?? null)) $sameExceptThrottle = false;
}
assert_test($sameExceptThrottle, 'P11b: nenhum estado alterado (só throttle)');
assert_test(poll_user_plan($db) === 'gratuito', 'P11c: usuário intacto');
$callsAfterFirst = $mp->getCalls;
$rAgain = $svc->getStatus(5, '22222222222222222222222222222222');
assert_test($mp->getCalls === $callsAfterFirst, 'P11e: backoff — 2º poll imediato sem novo GET ao MP');

echo "\n--- P11b: erro transiente do MP mantém pending ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('33333333333333333333333333333333', 'pro', 'pending', 'mp_flaky_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_flaky_1'] = ['__error__' => ['ok' => false, 'status' => 0, 'error' => 'network_error']];
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, '33333333333333333333333333333333');
assert_test(($r['body']['status'] ?? '') === 'pending' && ($r['body']['outcome'] ?? '') === 'processing', 'P11d: transiente -> pending local');

echo "\n--- P12: mp_preapproval_id inválido -> zero chamadas MP ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('44444444444444444444444444444444', 'pro', 'pending', 'id com espaço!', '2000-01-01 00:00:00');
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, '44444444444444444444444444444444');
assert_test($mp->getCalls === 0, 'P12a: id malformado nunca vai ao MP');
assert_test(($r['http'] ?? 0) === 200, 'P12b: responde local 200');

echo "\n--- P12b: sem vínculo -> zero chamadas MP; 404 não vaza existência ---\n";
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('55555555555555555555555555555555', 'pro', 'pending', '', '2000-01-01 00:00:00');
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, '55555555555555555555555555555555');
assert_test($mp->getCalls === 0, 'P12c: sem mp id -> zero chamadas MP');
$r404 = $svc->getStatus(5, 'ffffffff000000000000000000000000');
assert_test(($r404['http'] ?? 0) === 404, 'P12d: attempt inexistente -> 404');
$rOther = $svc->getStatus(999, '55555555555555555555555555555555');
assert_test(($rOther['http'] ?? 0) === 404, 'P12e: outro usuário -> 404 idêntico');

echo "\n--- P13/P14: semântica real de transação + zero begin/commit ---\n";
$strictDb = new FakePollPDO();
$threw = false;
try {
    $strictDb->commit();
} catch (PDOException $e) {
    $threw = true;
}
assert_test($threw, 'P13a: commit() sem txn lança PDOException');
$db = new FakePollPDO();
$mp = new FakePollMP();
$id = $db->addAttempt('66666666666666666666666666666666', 'pro', 'pending', 'mp_nc_1', '2000-01-01 00:00:00');
$mp->fixtures['mp_nc_1'] = ['id' => 'mp_nc_1', 'status' => 'authorized', 'preapproval_plan_id' => 'x', 'next_payment_date' => null];
$svc = new SubscriptionPollService($db, $mp);
$r = $svc->getStatus(5, '66666666666666666666666666666666');
assert_test(($r['http'] ?? 0) === 200 && ($r['body']['outcome'] ?? '') === 'active', 'P14a: sync feliz 200 active');
assert_test($db->beginCalls === 0, 'P14b: BEGIN_CALLS=0', 'beginCalls=' . $db->beginCalls);
assert_test($db->commitCalls === 0, 'P14c: COMMIT_CALLS=0', 'commitCalls=' . $db->commitCalls);
assert_test($db->inTransaction() === false, 'P14d: sem txn residual');

echo "\n--- P15/P16: contrato semântico do frontend ---\n";
$jsSrc = (string)file_get_contents($ROOT . '/public/js/mp_subscribe.js');
assert_test(str_contains($jsSrc, 'decideInitialAction'), 'P15a: resposta inicial passa pela state machine (sucesso só via outcome active)');
assert_test(str_contains($jsSrc, 'stopSubscriptionPolling'), 'P15a2: stop único existe');
assert_test(str_contains($jsSrc, "data.outcome === 'active'") || str_contains($jsSrc, "o === 'active'"), 'P15b: gate active via outcome');
assert_test(!preg_match('/if\s*\(\s*data\.ok\s*\)\s*\{\s*[^}]*sucess/si', $jsSrc), 'P15c: nenhum if(data.ok) => sucesso');

echo "\n--- P17: wiring do stop único + sem timers órfãos + observabilidade ---\n";
$calls = substr_count($jsSrc, 'stopSubscriptionPolling()');
assert_test($calls >= 6, 'P17a: stop chamado em todos os desfechos (terminais+timeout+abort+success)', 'calls=' . $calls);
assert_test(str_contains($jsSrc, 'clearTimeout'), 'P17b: timers com limpeza explícita');
assert_test(strpos($jsSrc, 'setInterval') === false, 'P17c: nenhum setInterval (só timeouts rastreados)');
assert_test(str_contains($jsSrc, 'pollGeneration') || str_contains($jsSrc, 'myGen') || str_contains($jsSrc, 'fetchGen'), 'P17d: guarda de geração contra resposta atrasada');
assert_test(str_contains($jsSrc, '[subscription-ui]'), 'P17e: observabilidade sanitizada presente');
$consoleLeak = preg_match('/console\.(info|log|debug|warn|error)\s*\([^)]*(ATTEMPT_TOKEN|card_token|cardToken|cardholderEmail|identificationNumber|PUBLIC_KEY)/', $jsSrc);
assert_test($consoleLeak === 0, 'P17f: nenhum log de console com token/cartão/email/chave');
assert_test(str_contains($jsSrc, 'attempt_suffix'), 'P17g: logs usam sufixo, nunca correlator completo');
assert_test(str_contains($jsSrc, 'Verificar novamente'), 'P17h: retry controlado após timeout/abort');
assert_test(str_contains($jsSrc, "'processing'") || str_contains($jsSrc, '"processing"'), 'P15c: pending mapeia para processing');
assert_test(str_contains($jsSrc, 'meu_plano&subscribed=1'), 'P16a: active redireciona para sucesso');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

<?php
/**
 * Testes do fluxo subscription_start / cancel (ETAPA 1).
 *
 * SEM rede, SEM banco real, SEM credenciais reais:
 * - MercadoPagoClient::$transport substituído por stub;
 * - PDO substituído por FakePDO em memória (só o SQL usado pelo serviço);
 * - env com valores fictícios de teste.
 *
 * Cobre: plano inválido/gratuito, config ausente, duplicada aberta/ativa,
 * payload oficial, preço fora do frontend, rollback em falha, init_point
 * inválido, cancelamento via PUT canceled, rotas/CSRF, frontend sem segredos.
 */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoClient.php';
require_once $ROOT . '/src/services/SubscriptionService.php';

class FakeSubStmt extends PDOStatement
{
    private FakeSubPDO $pdo;
    private string $sql;
    private array $rows = [];
    private int $pos = 0;

    protected function __construct(FakeSubPDO $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public static function make(FakeSubPDO $pdo, string $sql): self
    {
        $ref = new ReflectionClass(self::class);
        /** @var self $s */
        $s = $ref->newInstanceWithoutConstructor();
        $s->pdo = $pdo;
        $s->sql = $sql;
        return $s;
    }

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->run($this->sql, $params ?? []);
        $this->pos = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->pos >= count($this->rows)) return false;
        return $this->rows[$this->pos++];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        if ($row === false) return false;
        $vals = array_values($row);
        return $vals[$column] ?? false;
    }
}

class FakeSubPDO extends PDO
{
    public array $usuarios = [];
    public array $planos = [];
    public array $subs = [];
    public int $seq = 0;
    public string $lastId = '0';
    private bool $inTx = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return FakeSubStmt::make($this, $query);
    }

    public function lastInsertId(?string $name = null): string|false { return $this->lastId; }
    public function beginTransaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollBack(): bool { $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }

    /** @return array<int,array<string,mixed>> */
    public function run(string $sql, array $p): array
    {
        $n = preg_replace('/\s+/', ' ', trim($sql));

        if (str_starts_with($n, 'INSERT INTO subscriptions')) {
            $row = [
                'id' => 0, 'user_id' => (int)($p[0] ?? 0), 'plan_id' => (int)($p[1] ?? 0),
                'plan_slug' => (string)($p[2] ?? ''), 'status' => 'pending',
                'attempt_token' => (string)($p[3] ?? ''), 'external_reference' => (string)($p[4] ?? ''),
                'mp_preapproval_id' => null, 'checkout_url' => null, 'raw_status' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $this->assertUnique($row, null);
            $this->seq++;
            $row['id'] = $this->seq;
            $this->subs[$this->seq] = $row;
            $this->lastId = (string)$this->seq;
            return [];
        }

        if (preg_match('/SELECT \* FROM subscriptions WHERE user_id = \? AND status IN \(([^)]+)\)/i', $n, $m)) {
            $wanted = [];
            foreach (explode(',', $m[1]) as $s) $wanted[] = trim($s, " '");
            $out = [];
            foreach ($this->subs as $r) {
                if ((int)$r['user_id'] === (int)($p[0] ?? -1) && in_array($r['status'], $wanted, true)) $out[] = $r;
            }
            usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: ($b['id'] <=> $a['id']));
            return array_slice($out, 0, 1);
        }

        if (preg_match("/SELECT \* FROM subscriptions WHERE user_id = \? AND status = 'active'/i", $n)) {
            $out = [];
            foreach ($this->subs as $r) {
                if ((int)$r['user_id'] === (int)($p[0] ?? -1) && $r['status'] === 'active') $out[] = $r;
            }
            usort($out, fn($a, $b) => $b['id'] <=> $a['id']);
            return array_slice($out, 0, 1);
        }

        if (preg_match('/^DELETE FROM subscriptions WHERE id = \?/i', $n)) {
            unset($this->subs[(int)($p[0] ?? 0)]);
            return [];
        }

        if (str_contains($n, 'UPDATE subscriptions') && str_contains($n, 'mp_preapproval_id')) {
            $id = (int)($p[3] ?? 0);
            if (!isset($this->subs[$id])) return [];
            $this->assertUnique(['mp_preapproval_id' => $p[0], 'attempt_token' => null], $id);
            $this->subs[$id]['mp_preapproval_id'] = $p[0];
            $this->subs[$id]['checkout_url'] = $p[1];
            $this->subs[$id]['raw_status'] = $p[2];
            return [];
        }

        if (preg_match("/UPDATE subscriptions SET status = 'cancelled'/i", $n)) {
            $id = (int)($p[0] ?? 0);
            if (isset($this->subs[$id])) $this->subs[$id]['status'] = 'cancelled';
            return [];
        }

        if (preg_match('/SELECT active_subscription_id, plano FROM usuarios WHERE id = \?/i', $n)) {
            $id = (int)($p[0] ?? 0);
            return isset($this->usuarios[$id]) ? [$this->usuarios[$id]] : [];
        }

        if (preg_match("/UPDATE usuarios SET plano = 'gratuito'/i", $n)) {
            $id = (int)($p[0] ?? 0);
            if (isset($this->usuarios[$id])) {
                $this->usuarios[$id]['plano'] = 'gratuito';
                $this->usuarios[$id]['plano_status'] = 'ativo';
                $this->usuarios[$id]['active_subscription_id'] = null;
            }
            return [];
        }

        if (preg_match("/SELECT \* FROM planos WHERE slug = \? AND status = 'ativo'/i", $n)) {
            foreach ($this->planos as $r) {
                if ($r['slug'] === ($p[0] ?? null) && $r['status'] === 'ativo') return [$r];
            }
            return [];
        }

        return [];
    }

    private function assertUnique(array $row, ?int $exceptId): void
    {
        foreach (['mp_preapproval_id', 'attempt_token'] as $col) {
            $v = (string)($row[$col] ?? '');
            if ($v === '') continue;
            foreach ($this->subs as $id => $r) {
                if ($exceptId !== null && $id === $exceptId) continue;
                if ((string)($r[$col] ?? '') === $v) {
                    throw new PDOException('SQLSTATE[23505]: duplicate ' . $col);
                }
            }
        }
    }
}

$passed = 0; $failed = 0;
function mp_start_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

echo "\n=== TESTES: subscription_start / cancel (ETAPA 1) ===\n\n";

// ---- env fictício ----
putenv('MERCADOPAGO_ACCESS_TOKEN=TEST-TOKEN-FAKE-0002');
putenv('MERCADOPAGO_PLAN_ID_PRO=test-plan-pro-0001');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=test-plan-premium-0002');
putenv('APP_URL=https://exemplo-teste.local');
foreach (['MERCADOPAGO_ACCESS_TOKEN','MERCADOPAGO_PLAN_ID_PRO','MERCADOPAGO_PLAN_ID_PREMIUM','APP_URL'] as $k) {
    $_ENV[$k] = (string)getenv($k);
}

function freshDb(): FakeSubPDO
{
    $db = new FakeSubPDO();
    $db->planos = [
        1 => ['id' => 1, 'nome' => 'Pro', 'slug' => 'pro', 'preco' => 9.90, 'status' => 'ativo'],
        2 => ['id' => 2, 'nome' => 'Premium', 'slug' => 'premium', 'preco' => 19.90, 'status' => 'ativo'],
    ];
    $db->usuarios = [
        1 => ['id' => 1, 'plano' => 'gratuito', 'plano_status' => 'ativo', 'active_subscription_id' => null],
    ];
    return $db;
}

$captured = [];
function armSuccess(array &$captured, string $mpId = 'mp-test-001'): void
{
    MercadoPagoClient::$transport = function (string $method, string $url, ?array $body, string $token) use (&$captured, $mpId) {
        $captured = ['method' => $method, 'url' => $url, 'body' => $body];
        return ['ok' => true, 'http' => 201, 'data' => [
            'id' => $mpId, 'status' => 'pending',
            'init_point' => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=' . $mpId,
            'external_reference' => $body['external_reference'] ?? '',
        ], 'error' => ''];
    };
}

// ---- S01: plano inválido / gratuito rejeitados, sem HTTP ----
$db = freshDb();
$calls = 0;
MercadoPagoClient::$transport = function () use (&$calls) { $calls++; return ['ok' => true, 'http' => 201, 'data' => [], 'error' => '']; };
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'gold');
mp_start_assert(!$r['ok'] && $r['error'] === 'invalid_plan' && $calls === 0, 'S01 plano inválido rejeitado sem HTTP');
$r = $svc->start(1, 'u@exemplo.com', 'gratuito');
mp_start_assert(!$r['ok'] && $r['error'] === 'invalid_plan' && $calls === 0, 'S01b gratuito rejeitado (só pro/premium)');
mp_start_assert(count($db->subs) === 0, 'S01c nada persistido em rejeição');

// ---- S02: sem config = config_error (fail-closed) ----
putenv('MERCADOPAGO_ACCESS_TOKEN'); unset($_ENV['MERCADOPAGO_ACCESS_TOKEN']);
$svc2 = new SubscriptionService(freshDb());
$r = $svc2->start(1, 'u@exemplo.com', 'pro');
mp_start_assert(!$r['ok'] && $r['error'] === 'config_error', 'S02 sem token -> config_error');
putenv('MERCADOPAGO_ACCESS_TOKEN=TEST-TOKEN-FAKE-0002');
$_ENV['MERCADOPAGO_ACCESS_TOKEN'] = 'TEST-TOKEN-FAKE-0002';
mp_start_assert(!SubscriptionService::isConfigured('gold'), 'S02b slug inválido nunca configurado');

// ---- S03: PRO usa PLAN_ID_PRO; payload oficial; preço fora do frontend ----
$db = freshDb();
armSuccess($captured);
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'pro');
mp_start_assert($r['ok'] && $r['subscription_id'] > 0, 'S03 pro aceito');
mp_start_assert($captured['url'] === 'https://api.mercadopago.com/preapproval', 'S03b POST /preapproval oficial');
mp_start_assert(($captured['body']['preapproval_plan_id'] ?? '') === 'test-plan-pro-0001', 'S03c pro -> MERCADOPAGO_PLAN_ID_PRO');
mp_start_assert(($captured['body']['payer_email'] ?? '') === 'u@exemplo.com', 'S03d payer_email oficial presente');
mp_start_assert(is_string($captured['body']['external_reference'] ?? null) && ($captured['body']['back_url'] ?? '') === 'https://exemplo-teste.local/index.php?action=mp_return', 'S03e external_reference + back_url de APP_URL');
mp_start_assert(strpos(json_encode($captured['body']), '9.9') === false && !isset($captured['body']['transaction_amount']), 'S03f preço NUNCA sai do frontend');
$saved = $db->subs[$r['subscription_id']];
mp_start_assert($saved['status'] === 'pending' && $saved['mp_preapproval_id'] === 'mp-test-001' && $db->usuarios[1]['plano'] === 'gratuito', 'S03g pending persistido, plano NÃO ativado');
mp_start_assert(strpos($r['init_point'], 'https://') === 0, 'S03h init_point https retornado');

// ---- S04: PREMIUM usa PLAN_ID_PREMIUM ----
$db = freshDb();
armSuccess($captured, 'mp-test-002');
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'premium');
mp_start_assert($r['ok'] && ($captured['body']['preapproval_plan_id'] ?? '') === 'test-plan-premium-0002', 'S04 premium -> MERCADOPAGO_PLAN_ID_PREMIUM');

// ---- S05: duplicada ativa bloqueia ----
$db = freshDb();
$db->subs[1] = ['id' => 1, 'user_id' => 1, 'plan_id' => 1, 'plan_slug' => 'pro', 'status' => 'active',
    'attempt_token' => str_repeat('a', 32), 'external_reference' => 'x', 'mp_preapproval_id' => 'mp-old',
    'checkout_url' => 'https://www.mercadopago.com.br/c', 'raw_status' => 'authorized', 'created_at' => date('Y-m-d H:i:s')];
$db->seq = 1;
$calls = 0;
MercadoPagoClient::$transport = function () use (&$calls) { $calls++; return ['ok' => true, 'http' => 201, 'data' => [], 'error' => '']; };
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'premium');
mp_start_assert(!$r['ok'] && $r['error'] === 'already_subscribed' && $calls === 0 && count($db->subs) === 1, 'S05 ativa existente bloqueia sem HTTP');

// ---- S06: aberta com checkout retoma (sem duplicar no MP) ----
$db = freshDb();
$db->subs[1] = ['id' => 1, 'user_id' => 1, 'plan_id' => 1, 'plan_slug' => 'pro', 'status' => 'pending',
    'attempt_token' => str_repeat('b', 32), 'external_reference' => 'y', 'mp_preapproval_id' => 'mp-open',
    'checkout_url' => 'https://www.mercadopago.com.br/checkout?preapproval_id=mp-open', 'raw_status' => 'pending',
    'created_at' => date('Y-m-d H:i:s')];
$db->seq = 1;
$calls = 0;
MercadoPagoClient::$transport = function () use (&$calls) { $calls++; return ['ok' => true, 'http' => 201, 'data' => [], 'error' => '']; };
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'pro');
mp_start_assert($r['ok'] && $r['subscription_id'] === 1 && $calls === 0 && count($db->subs) === 1, 'S06 checkout aberto retomado sem novo POST');

// ---- S07: erro da API = gateway_error + rollback ----
$db = freshDb();
MercadoPagoClient::$transport = fn() => ['ok' => false, 'http' => 500, 'data' => [], 'error' => 'http_5xx'];
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'pro');
mp_start_assert(!$r['ok'] && $r['error'] === 'gateway_error' && count($db->subs) === 0, 'S07 falha API desfaz tentativa');

// ---- S08: resposta sem init_point válido = gateway_error ----
$db = freshDb();
MercadoPagoClient::$transport = fn() => ['ok' => true, 'http' => 201, 'data' => ['id' => 'mp-x', 'status' => 'pending', 'init_point' => 'http://evil.local/x'], 'error' => ''];
$svc = new SubscriptionService($db);
$r = $svc->start(1, 'u@exemplo.com', 'pro');
mp_start_assert(!$r['ok'] && $r['error'] === 'gateway_error' && count($db->subs) === 0, 'S08 init_point não-https rejeitado');

// ---- S09: external_reference correlaciona user+plano+attempt ----
$db = freshDb();
armSuccess($captured, 'mp-test-009');
$svc = new SubscriptionService($db);
$svc->start(7, 'z@exemplo.com', 'premium');
$parsed = SubscriptionService::parseExternalReference((string)$captured['body']['external_reference']);
mp_start_assert($parsed !== null && $parsed['user_id'] === 7 && $parsed['plan'] === 'premium', 'S09 external_reference user+plano+attempt');

// ---- S10: cancel sem ativa ----
$svc = new SubscriptionService(freshDb());
$r = $svc->cancelActive(1);
mp_start_assert(!$r['ok'] && $r['error'] === 'no_active_subscription', 'S10 cancel sem ativa');

// ---- S11: cancel com mp_id faz PUT canceled + baixa local ----
$db = freshDb();
$db->subs[5] = ['id' => 5, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'premium', 'status' => 'active',
    'attempt_token' => str_repeat('c', 32), 'external_reference' => 'w', 'mp_preapproval_id' => 'mp-cancel-me',
    'checkout_url' => null, 'raw_status' => 'authorized', 'created_at' => date('Y-m-d H:i:s')];
$db->seq = 5;
$db->usuarios[1]['plano'] = 'premium';
$db->usuarios[1]['active_subscription_id'] = 5;
$putBody = null;
MercadoPagoClient::$transport = function (string $m, string $u, ?array $b) use (&$putBody) {
    $putBody = ['method' => $m, 'url' => $u, 'body' => $b];
    return ['ok' => true, 'http' => 200, 'data' => ['id' => 'mp-cancel-me', 'status' => 'cancelled'], 'error' => ''];
};
$svc = new SubscriptionService($db);
$r = $svc->cancelActive(1);
mp_start_assert($r['ok'], 'S11 cancel ok');
mp_start_assert(($putBody['method'] ?? '') === 'PUT' && ($putBody['body']['status'] ?? '') === 'canceled', 'S11b PUT status=canceled oficial');
mp_start_assert($db->subs[5]['status'] === 'cancelled' && $db->usuarios[1]['plano'] === 'gratuito', 'S11c baixa local + downgrade');

// ---- S12: PUT falha = nada muda local ----
$db = freshDb();
$db->subs[5] = ['id' => 5, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'premium', 'status' => 'active',
    'attempt_token' => str_repeat('d', 32), 'external_reference' => 'v', 'mp_preapproval_id' => 'mp-x',
    'checkout_url' => null, 'raw_status' => 'authorized', 'created_at' => date('Y-m-d H:i:s')];
$db->seq = 5;
$db->usuarios[1]['plano'] = 'premium';
$db->usuarios[1]['active_subscription_id'] = 5;
MercadoPagoClient::$transport = fn() => ['ok' => false, 'http' => 500, 'data' => [], 'error' => 'http_5xx'];
$svc = new SubscriptionService($db);
$r = $svc->cancelActive(1);
mp_start_assert(!$r['ok'] && $r['error'] === 'cancel_service_error' && $db->subs[5]['status'] === 'active' && $db->usuarios[1]['plano'] === 'premium', 'S12 falha no MP não dessincroniza');

// ---- S13: rotas + CSRF + mp_return neutro ----
$router = (string)file_get_contents($ROOT . '/public/index.php');
mp_start_assert(strpos($router, "'subscription_start'") !== false && strpos($router, "'subscription_cancel'") !== false, 'S13 rotas subscription_* presentes');
mp_start_assert(preg_match("/csrfProtectedActions = \[[^\]]*'subscription_start'[^\]]*'subscription_cancel'[^\]]*\]/s", $router) === 1, 'S13b CSRF protege subscription_*');
mp_start_assert(preg_match("/subscription_start.*?requireLogin\(\)/s", $router) === 1 || strpos($router, 'subscriptionStart') !== false, 'S13c subscription_start exige login (controller)');
$profile = (string)file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$at = (int)strpos($profile, 'function mpReturn');
$mpReturnBody = $at > 0 ? substr($profile, $at, 900) : '';
mp_start_assert($at > 0 && strpos($mpReturnBody, 'UPDATE') === false && strpos($mpReturnBody, 'meu_plano') !== false, 'S13d mp_return neutro, sem ativação');

// ---- S14: frontend sem segredos/SDK, com form oficial ----
$view = (string)file_get_contents($ROOT . '/public/meu_plano.php');
mp_start_assert(strpos($view, 'action=subscription_start') !== false, 'S14 form subscription_start presente');
mp_start_assert(strpos($view, 'csrf_field()') !== false && strpos($view, 'name="plan"') !== false, 'S14b CSRF + plan slug (sem preço)');
foreach (['sdk.mercadopago', 'CardForm', 'cardForm', 'Bricks', 'MERCADOPAGO_ACCESS_TOKEN', 'api.mercadopago.com', 'APP_USR-'] as $bad) {
    mp_start_assert(strpos($view, $bad) === false, "S14c frontend sem '$bad'");
}

MercadoPagoClient::$transport = null;
foreach (['MERCADOPAGO_ACCESS_TOKEN','MERCADOPAGO_PLAN_ID_PRO','MERCADOPAGO_PLAN_ID_PREMIUM','APP_URL'] as $k) {
    putenv($k); unset($_ENV[$k], $_SERVER[$k]);
}

echo "\n=== RESUMO START ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

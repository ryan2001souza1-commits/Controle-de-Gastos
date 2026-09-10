<?php
/**
 * Billing Sync Tests — regras neutras de sincronizacao de cobranca.
 *
 * Cobre (sem banco real, sem API, sem credenciais):
 *  - normalizacao de provider/status;
 *  - pending/paused/expired NAO ativam nem rebaixam sozinhos;
 *  - cancelled/rejected rebaixam para gratuito/ativo;
 *  - active ativa somente plano pago valido;
 *  - anti-IDOR (sessao x alvo), preco do catalogo, external_reference;
 *  - sanitizacao de segredos;
 *  - transacao com commit/rollback (fakes de PDO);
 *  - segunda assinatura ativa incompativel e rejeitada;
 *  - processamento duplicado nao gera efeito duplicado;
 *  - guards da migration minima (sem DROP/DELETE em historico).
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/PlanService.php';
require_once $ROOT . '/src/services/BillingSyncService.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } else {
        echo "  \033[31m✗\033[0m $name\n";
        $failed++;
    }
}

function assert_throws(string $name, callable $fn): void
{
    try {
        $fn();
        assert_test(false, $name . ' (nao lancou excecao)');
    } catch (Throwable $e) {
        assert_test(true, $name);
    }
}

class FakeBillingStmt extends PDOStatement
{
    public mixed $col = null;
    public array $all = [];
    public int $count = 1;
    public ?array $params = null;

    public function execute(?array $params = null): bool
    {
        $this->params = $params;
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->col;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->all;
    }

    public function rowCount(): int
    {
        return $this->count;
    }
}

class FakeBillingPDO extends PDO
{
    /** @var string[] */
    public array $queries = [];
    /** @var FakeBillingStmt[] */
    public array $queue = [];
    public bool $inTx = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public ?Throwable $prepareError = null;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->inTx = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        $this->committed = true;
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTx = false;
        $this->rolledBack = true;
        return true;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($this->prepareError !== null) {
            throw $this->prepareError;
        }
        $stmt = array_shift($this->queue);
        return $stmt ?? new FakeBillingStmt();
    }
}

function stmt(array $all = [], int $count = 1): FakeBillingStmt
{
    $s = new FakeBillingStmt();
    $s->all = $all;
    $s->count = $count;
    return $s;
}

echo "\n=== BILLING SYNC TESTS ===\n\n";

echo "-- provider (nenhum gateway ativo) --\n";
assert_test(BillingSyncService::normalizeProvider('mercadopago') === null, 'BS01: mercadopago nao e mais provedor ativo');
assert_test(BillingSyncService::normalizeProvider('  MercadoPago ') === null, 'BS02: nenhuma variacao de gateway removido e aceita');
assert_test(BillingSyncService::normalizeProvider('stripe') === null, 'BS03: provider desconhecido rejeitado');
assert_test(BillingSyncService::normalizeProvider('') === null, 'BS04: provider vazio rejeitado');
assert_test(BillingSyncService::normalizeProvider(null) === null, 'BS05: provider null rejeitado');

echo "\n-- status --\n";
foreach (['pending', 'active', 'paused', 'cancelled', 'expired', 'rejected'] as $st) {
    assert_test(BillingSyncService::normalizeSubscriptionStatus($st) === $st, "BS06: status interno '{$st}' aceito");
}
assert_test(BillingSyncService::normalizeSubscriptionStatus('authorized') === null, 'BS07: status externo cru nao vaza para o modelo interno');
assert_test(BillingSyncService::normalizeSubscriptionStatus("active' OR '1'='1") === null, 'BS08: injecao em status rejeitada');

echo "\n-- resolvePlanUpdate: nao ativacao --\n";
foreach (['pending', 'paused', 'expired'] as $st) {
    $r = BillingSyncService::resolvePlanUpdate($st, 'pro');
    assert_test($r['change'] === false, "BS09: {$st} nao altera plano sozinho");
}
$r = BillingSyncService::resolvePlanUpdate('cancelled', 'pro');
assert_test($r['change'] === true && $r['plano'] === 'gratuito' && $r['plano_status'] === 'ativo' && $r['clear_active_subscription'] === true, 'BS10: cancelled rebaixa para gratuito/ativo');
$r = BillingSyncService::resolvePlanUpdate('rejected', 'premium');
assert_test($r['change'] === true && $r['plano'] === 'gratuito' && $r['clear_active_subscription'] === true, 'BS11: rejected rebaixa para gratuito/ativo');

echo "\n-- resolvePlanUpdate: ativacao --\n";
$r = BillingSyncService::resolvePlanUpdate('active', 'pro');
assert_test($r['change'] === true && $r['plano'] === 'pro' && $r['plano_status'] === 'ativo' && $r['clear_active_subscription'] === false, 'BS12: active+pro ativa plano pago');
$r = BillingSyncService::resolvePlanUpdate('active', 'premium');
assert_test($r['change'] === true && $r['plano'] === 'premium', 'BS13: active+premium ativa plano pago');
assert_throws('BS14: active+gratuito rejeitado', fn() => BillingSyncService::resolvePlanUpdate('active', 'gratuito'));
assert_throws('BS15: plan_slug com injecao SQL rejeitado', fn() => BillingSyncService::resolvePlanUpdate('active', "pro'; DROP TABLE usuarios;--"));
assert_throws('BS16: plan_slug arbitrario rejeitado', fn() => BillingSyncService::resolvePlanUpdate('active', 'diamante'));
assert_throws('BS17: status invalido rejeitado', fn() => BillingSyncService::resolvePlanUpdate('authorized', 'pro'));

echo "\n-- anti-IDOR e preco do catalogo --\n";
assert_test(BillingSyncService::authenticatedUserId(42, 42) === 42, 'BS18: sessao confere com alvo');
assert_throws('BS19: user_id de outro usuario rejeitado', fn() => BillingSyncService::authenticatedUserId(42, 43));
assert_throws('BS20: user_id zero rejeitado', fn() => BillingSyncService::authenticatedUserId(42, 0));
assert_test(BillingSyncService::catalogPriceOrFail(9.90, 'pro') === 9.90, 'BS21: preco vem do catalogo');
assert_throws('BS22: catalogo sem preco falha em vez de aceitar valor do frontend', fn() => BillingSyncService::catalogPriceOrFail(null, 'pro'));

echo "\n-- external_reference --\n";
$attempt = str_repeat('a1', 16);
assert_test(
    BillingSyncService::buildExternalReference(7, 'pro', $attempt) === "user_7_pro_{$attempt}",
    'BS23: external_reference deterministica user/plano/attempt'
);
assert_throws('BS24: attempt_token fora do formato rejeitado', fn() => BillingSyncService::buildExternalReference(7, 'pro', 'xyz'));
assert_throws('BS25: slug invalido rejeitado na referencia', fn() => BillingSyncService::buildExternalReference(7, 'pro" OR 1=1--', $attempt));

echo "\n-- sanitizacao --\n";
$clean = BillingSyncService::sanitizePayload([
    'id' => 'evt_1',
    'amount' => 9.90,
    'access_token' => 'SEGREDO',
    'nested' => ['card_number' => '4111', 'status' => 'approved'],
]);
assert_test($clean['access_token'] === '[redacted]' && $clean['nested']['card_number'] === '[redacted]', 'BS26: segredos redigidos inclusive aninhados');
assert_test($clean['amount'] === 9.90 && $clean['nested']['status'] === 'approved', 'BS27: dados nao sensiveis preservados');

echo "\n-- applyPlanUpdate transacional --\n";
$db = new FakeBillingPDO();
$db->queue = [stmt([], 0), stmt([], 1), stmt([], 1)];
BillingSyncService::applyPlanUpdate($db, 42, 100, BillingSyncService::resolvePlanUpdate('active', 'pro'));
assert_test($db->committed && !$db->rolledBack, 'BS28: ativacao valida faz commit sem rollback');
assert_test(count($db->queries) === 3 && str_contains($db->queries[1], 'UPDATE usuarios') && str_contains($db->queries[2], 'UPDATE subscriptions'), 'BS29: atualiza usuarios + subscription vinculada');

// Duplicata: mesma resolucao aplicada de novo gera os mesmos SETs (efeito idempotente).
$db2 = new FakeBillingPDO();
$db2->queue = [stmt([], 0), stmt([], 1), stmt([], 1)];
$res = BillingSyncService::resolvePlanUpdate('active', 'pro');
BillingSyncService::applyPlanUpdate($db2, 42, 100, $res);
BillingSyncService::applyPlanUpdate($db2, 42, 100, $res);
assert_test($db2->committed && !$db2->rolledBack && count($db2->queries) === 6, 'BS30: reaplicacao nao gera efeito duplicado divergente');

// Subscription de outro usuario: UPDATE com user_id nao afeta linha -> rollback.
$db3 = new FakeBillingPDO();
$db3->queue = [stmt([], 1), stmt([], 0)];
try {
    BillingSyncService::applyPlanUpdate($db3, 42, 999, BillingSyncService::resolvePlanUpdate('cancelled', 'pro'));
    assert_test(false, 'BS31: associacao cruzada deveria falhar');
} catch (Throwable $e) {
    assert_test($db3->rolledBack && !$db3->committed, 'BS31: associacao ao usuario errado faz rollback');
}

// Segunda assinatura ativa incompativel: recusa antes de alterar qualquer linha.
$db4 = new FakeBillingPDO();
$db4->queue = [stmt([['id' => 9]], 1)];
try {
    BillingSyncService::applyPlanUpdate($db4, 42, 100, BillingSyncService::resolvePlanUpdate('active', 'pro'));
    assert_test(false, 'BS32: segunda ativa deveria falhar');
} catch (Throwable $e) {
    assert_test($db4->rolledBack && count($db4->queries) === 1, 'BS32: segunda assinatura ativa incompativel bloqueada com rollback');
}

// Falha no meio do caminho: rollback garantido.
$db5b = new class extends FakeBillingPDO {
    public int $calls = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        $this->calls++;
        if ($this->calls === 1) {
            return stmt([], 0);
        }
        throw new RuntimeException('falha simulada no UPDATE');
    }
};
try {
    BillingSyncService::applyPlanUpdate($db5b, 42, 100, BillingSyncService::resolvePlanUpdate('active', 'pro'));
    assert_test(false, 'BS33: falha no UPDATE deveria propagar');
} catch (Throwable $e) {
    assert_test($db5b->rolledBack && !$db5b->committed, 'BS33: falha intermediaria faz rollback total');
}

// change=false nao toca no banco.
$db6 = new FakeBillingPDO();
BillingSyncService::applyPlanUpdate($db6, 42, 100, BillingSyncService::resolvePlanUpdate('pending', 'pro'));
assert_test(count($db6->queries) === 0 && !$db6->committed && !$db6->rolledBack, 'BS34: pending nao escreve no banco');

echo "\n-- guards da migration minima --\n";
$mig = (string)file_get_contents($ROOT . '/src/migrations.php');
$cleanup = (string)file_get_contents($ROOT . '/src/migrations/remove_legacy_payment_gateways.php');
$schema = (string)file_get_contents($ROOT . '/database/schema.sql');
assert_test(str_contains($mig, 'ADD COLUMN IF NOT EXISTS provider VARCHAR(30)'), 'BS35: migration adiciona subscriptions.provider');
assert_test(str_contains($mig, 'ADD COLUMN IF NOT EXISTS provider_plan_id VARCHAR(80)'), 'BS36: migration adiciona subscriptions.provider_plan_id');
assert_test(str_contains($mig, 'CREATE TABLE IF NOT EXISTS webhook_events'), 'BS37: migration cria webhook_events');
assert_test(str_contains($mig, 'uq_webhook_events_provider_event'), 'BS38: migration tem UNIQUE de deduplicacao');
assert_test(!preg_match('/DROP\s+COLUMN\s+IF\s+EXISTS\s+"?provider"?(\s|;|"|\')/i', $cleanup), 'BS39: cleanup nao apaga a nova coluna provider');
assert_test(!preg_match('/DROP\s+TABLE\s+IF\s+EXISTS\s+(webhook_events|subscriptions|usuarios)(\s|;)/i', $mig), 'BS40: migration nao destroi tabelas');
assert_test(preg_match('/\bUPDATE\s+usuarios\s+SET\b[^;]*(plano|active_subscription)/i', $mig) === 0, 'BS41a: migration nunca altera plano/vinculo de usuarios (só bootstrap de is_admin pre-existente)');
assert_test(preg_match('/\bDELETE\s+FROM\s+(usuarios|subscriptions|webhook_events)\b/i', $mig) === 0, 'BS41b: migration nunca faz DELETE em usuarios/subscriptions/webhook_events');
assert_test(preg_match_all('/\bUPDATE\s+subscriptions\b/i', $mig) === 1 && str_contains($mig, 'SET checkout_url'), 'BS41c: unico UPDATE em subscriptions e o backfill historico idempotente de checkout_url');
assert_test(str_contains($schema, 'provider_plan_id') && str_contains($schema, 'webhook_events'), 'BS42: schema.sql espelha a migration');
assert_test(
    strpos($mig, 'ADD COLUMN IF NOT EXISTS provider VARCHAR(30)') !== false
    && strpos($mig, 'idx_subscriptions_provider_plan') > strpos($mig, 'ADD COLUMN IF NOT EXISTS provider VARCHAR(30)'),
    'BS43: indice provider_plan criado APOS o ADD COLUMN (ordem de execucao)'
);

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

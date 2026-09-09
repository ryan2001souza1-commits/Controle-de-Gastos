<?php
/**
 * Webhook Ledger Tests — deduplicacao e sanitizacao de eventos.
 *
 * Cobre (sem banco real, sem API, sem credenciais):
 *  - normalizacao/valicao do evento (provider, ids, tipos, payload);
 *  - SQL idempotente com ON CONFLICT DO NOTHING;
 *  - primeira reserva true, redelivery false (sem efeito duplicado);
 *  - payload persistido sempre sanitizado;
 *  - guards da tabela webhook_events em migrations.php/schema.sql.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/PlanService.php';
require_once $ROOT . '/src/services/BillingSyncService.php';
require_once $ROOT . '/src/services/WebhookLedger.php';

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

class FakeLedgerStmt extends PDOStatement
{
    public int $count = 1;
    public ?array $params = null;

    public function execute(?array $params = null): bool
    {
        $this->params = $params;
        return true;
    }

    public function rowCount(): int
    {
        return $this->count;
    }
}

class FakeLedgerPDO extends PDO
{
    /** @var string[] */
    public array $queries = [];
    /** @var array|null */
    public ?array $lastParams = null;
    /** @var int[] fila de rowCount por prepare */
    public array $counts = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        $s = new FakeLedgerStmt();
        $s->count = array_shift($this->counts) ?? 1;
        $orig = $s;
        // intercepta params no execute via wrapper
        return new class ($orig, $this) extends PDOStatement {
            public function __construct(private FakeLedgerStmt $inner, private FakeLedgerPDO $db)
            {
            }
            public function execute(?array $params = null): bool
            {
                $this->db->lastParams = $params;
                return $this->inner->execute($params);
            }
            public function rowCount(): int
            {
                return $this->inner->rowCount();
            }
        };
    }
}

echo "\n=== WEBHOOK LEDGER TESTS ===\n\n";

echo "-- normalizeEvent --\n";
$evt = WebhookLedger::normalizeEvent([
    'provider' => '  MercadoPago ',
    'provider_event_id' => 'evt_123',
    'event_type' => 'subscription.updated',
    'subscription_id' => 100,
    'resource_id' => 'pre_456',
    'payload' => ['status' => 'authorized', 'access_token' => 'SEGREDO'],
]);
assert_test($evt['provider'] === 'mercadopago', 'WL01: provider normalizado');
assert_test($evt['provider_event_id'] === 'evt_123' && $evt['subscription_id'] === 100, 'WL02: ids preservados');
assert_test($evt['payload']['access_token'] === '[redacted]' && $evt['payload']['status'] === 'authorized', 'WL03: payload sanitizado na normalizacao');

assert_throws('WL04: provider desconhecido rejeitado', fn() => WebhookLedger::normalizeEvent(['provider' => 'x', 'provider_event_id' => 'e1']));
assert_throws('WL05: evento sem id rejeitado', fn() => WebhookLedger::normalizeEvent(['provider' => 'mercadopago', 'provider_event_id' => '  ']));
assert_throws('WL06: evento com id gigante rejeitado', fn() => WebhookLedger::normalizeEvent(['provider' => 'mercadopago', 'provider_event_id' => str_repeat('x', 121)]));
assert_throws('WL07: subscription_id invalido rejeitado', fn() => WebhookLedger::normalizeEvent(['provider' => 'mercadopago', 'provider_event_id' => 'e1', 'subscription_id' => -5]));
assert_throws('WL08: payload nao-array rejeitado', fn() => WebhookLedger::normalizeEvent(['provider' => 'mercadopago', 'provider_event_id' => 'e1', 'payload' => 'str']));

echo "\n-- SQL idempotente --\n";
$sql = WebhookLedger::insertSql();
assert_test(str_contains($sql, 'INSERT INTO webhook_events'), 'WL09: insert no ledger');
assert_test(str_contains($sql, 'ON CONFLICT (provider, provider_event_id) DO NOTHING'), 'WL10: dedupe via ON CONFLICT DO NOTHING');

echo "\n-- reserve: primeira x redelivery --\n";
$db = new FakeLedgerPDO();
$db->counts = [1, 0];
$first = WebhookLedger::reserve($db, $evt);
$second = WebhookLedger::reserve($db, $evt);
assert_test($first === true && $second === false, 'WL11: redelivery retorna false (sem efeito duplicado)');
assert_test(count($db->queries) === 2 && $db->queries[0] === $db->queries[1], 'WL12: mesma instrucao idempotente nas duas tentativas');

$persisted = json_decode((string)($db->lastParams[5] ?? ''), true);
assert_test(is_array($persisted) && ($persisted['access_token'] ?? null) === '[redacted]', 'WL13: segredo nunca chega ao payload persistido');
assert_test(!str_contains((string)($db->lastParams[5] ?? ''), 'SEGREDO'), 'WL14: valor do segredo ausente do JSON persistido');

echo "\n-- guards da tabela --\n";
$mig = (string)file_get_contents($ROOT . '/src/migrations.php');
$schema = (string)file_get_contents($ROOT . '/database/schema.sql');
assert_test(str_contains($mig, 'CONSTRAINT uq_webhook_events_provider_event UNIQUE (provider, provider_event_id)'), 'WL15: UNIQUE(provider, provider_event_id) na migration');
assert_test(str_contains($schema, 'CONSTRAINT uq_webhook_events_provider_event UNIQUE (provider, provider_event_id)'), 'WL16: UNIQUE espelhada no schema.sql');
assert_test(str_contains($mig, 'idx_webhook_events_subscription') && str_contains($mig, 'idx_webhook_events_type'), 'WL17: indices do ledger presentes');
assert_test(!preg_match('/DROP\s+TABLE\s+IF\s+EXISTS\s+webhook_events(\s|;)/i', $mig), 'WL18: ledger nunca e dropado pela migration');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

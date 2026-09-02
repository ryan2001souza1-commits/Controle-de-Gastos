<?php
/**
 * Testes do Subscription model — MockPDO estende PDO (type-hints PHP 8.5).
 * Usa Reflection para injetar mock sem chamar o construtor PDO real.
 */
require_once __DIR__ . '/../src/models/Subscription.php';

class MockPDO extends PDO {
    public $capturedSql = null;
    public $lastInsert = 42;
    public $stmt;

    public function __construct() {}

    public function prepare($query, $options = []) {
        $this->capturedSql = $query;
        return new class {
            public $executed = false;
            public $rowCountVal = 1;
            public $fetchReturn = false;
            public $fetchColumnReturn = null;
            public $params = [];

            public function execute($params = null): bool { $this->executed = true; if ($params) $this->params = $params; return true; }
            public function fetch($mode = null, $cursorOrientation = null, $cursorOffset = 0) { return $this->fetchReturn; }
            public function fetchAll(...$args) { return []; }
            public function fetchColumn($column = 0) { return $this->fetchColumnReturn; }
            public function rowCount(): int { return $this->rowCountVal; }
            public function setFetchMode($mode, ...$args): bool { return true; }
            public function bindValue($param, $value, $type = PDO::PARAM_STR): bool { return true; }
            public function bindParam($param, &$var, $type = PDO::PARAM_STR, $length = null, $options = null): bool { return true; }
            public function errorInfo(): array { return []; }
            public function errorCode() { return null; }
            public function closeCursor(): bool { return true; }
            public function columnCount(): int { return 0; }
            public function getColumnMeta($column): array { return []; }
            public function nextRowset(): bool { return false; }
        };
    }

    public function lastInsertId($seqname = null): string|false { return (string)$this->lastInsert; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function inTransaction(): bool { return false; }
    public function exec(string $statement): int|false { return 0; }
    public function query(string $query, mixed ...$args): PDOStatement|false {
        return new class {
            public function execute($params = null): bool { return true; }
            public function fetch(...$args) { return false; }
            public function fetchAll(...$args) { return []; }
            public function fetchColumn(...$args) { return null; }
            public function rowCount(): int { return 0; }
            public function setFetchMode(...$args): bool { return true; }
            public function bindValue(...$args): bool { return true; }
            public function bindParam(...$args): bool { return true; }
            public function errorInfo(): array { return []; }
            public function errorCode() { return null; }
            public function closeCursor(): bool { return true; }
            public function columnCount(): int { return 0; }
            public function getColumnMeta($column): array { return []; }
            public function nextRowset(): bool { return false; }
        };
    }
    public function quote(string $string, $type = PDO::PARAM_STR): string|false { return "'" . str_replace("'", "''", $string) . "'"; }
    public function getAttribute(int $attribute): mixed { return null; }
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public static function getAvailableDrivers(): array { return []; }
    public function errorCode(): ?string { return null; }
    public function errorInfo(): array { return ['00000', null, null]; }
}

$db = new MockPDO();
$subRef = new ReflectionClass(Subscription::class);
$sub = $subRef->newInstanceWithoutConstructor();
$dbProp = $subRef->getProperty('db');
$dbProp->setAccessible(true);
$dbProp->setValue($sub, $db);

echo "=== Subscription Model Tests ===\n";

// Teste 1: mapMpStatus
echo "\n--- mapMpStatus ---\n";
$tests = [
    "pending" => "pending",
    "authorized" => "active",
    "active" => "active",
    "paused" => "paused",
    "cancelled" => "cancelled",
    "canceled" => "cancelled",
    "expired" => "expired",
    "rejected" => "rejected",
    "unknown" => "pending",
    "" => "pending",
    "AUTHORIZED" => "active",
    "Canceled" => "cancelled",
];
$fail = false;
foreach ($tests as $mp => $expected) {
    $got = $sub->mapMpStatus($mp);
    if ($got !== $expected) {
        echo "  FAIL: mapMpStatus('$mp') expected '$expected' got '$got'\n";
        $fail = true;
    }
}
if (!$fail) echo "  PASS: mapMpStatus (all 12 cases)\n";

// Teste 2: createFromPreapproval
echo "\n--- createFromPreapproval ---\n";
try {
    $id = $sub->createFromPreapproval([
        'id' => 'preapproval_test_xyz',
        'external_reference' => 'user_1_pro',
        '_user_id_local' => 1,
        '_plan_id_local' => 2,
        '_plan_slug_local' => 'pro',
        'preapproval_plan_id' => 'plan_xyz',
        'status' => 'pending',
        'auto_recurring' => [
            'transaction_amount' => 29.90,
            'currency_id' => 'BRL',
            'frequency' => 1,
            'frequency_type' => 'months',
        ],
    ]);
    echo "  PASS: createFromPreapproval returned $id\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 3: findActiveByUserId
echo "\n--- findActiveByUserId ---\n";
try {
    $r = $sub->findActiveByUserId(1);
    echo "  PASS: findActiveByUserId returned " . ($r === null ? 'null' : 'array') . "\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 4: updateStatusByMpId
echo "\n--- updateStatusByMpId ---\n";
try {
    $r = $sub->updateStatusByMpId('preapproval_test_xyz', 'active', 'authorized', null, null);
    echo "  PASS: updateStatusByMpId returned " . ($r ? 'true' : 'false') . "\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 5: findByMpPreapprovalId
echo "\n--- findByMpPreapprovalId ---\n";
try {
    $r = $sub->findByMpPreapprovalId('preapproval_test_xyz');
    echo "  PASS: findByMpPreapprovalId returned " . ($r === null ? 'null' : 'array') . "\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 6: findByExternalReference
echo "\n--- findByExternalReference ---\n";
try {
    $r = $sub->findByExternalReference('user_1_pro');
    echo "  PASS: findByExternalReference returned " . ($r === null ? 'null' : 'array') . "\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 7: applyStatusToUser (active)
echo "\n--- applyStatusToUser (active) ---\n";
try {
    $r = $sub->applyStatusToUser(['user_id' => 1, 'plan_slug' => 'pro', 'status' => 'active', 'id' => 42]);
    echo "  PASS: applyStatusToUser(active) returned " . ($r ? 'true' : 'false') . "\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 8: applyStatusToUser (cancelled)
echo "\n--- applyStatusToUser (cancelled) ---\n";
try {
    $r = $sub->applyStatusToUser(['user_id' => 1, 'plan_slug' => 'pro', 'status' => 'cancelled', 'id' => 42, 'grace_period_end' => null]);
    echo "  PASS: applyStatusToUser(cancelled) returned " . ($r ? 'true' : 'false') . "\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 9: applyStatusToUser (invalid user)
echo "\n--- applyStatusToUser (uid=0) ---\n";
try {
    $r = $sub->applyStatusToUser(['user_id' => 0, 'plan_slug' => 'pro', 'status' => 'active', 'id' => 1]);
    echo $r === false ? "  PASS: returned false\n" : "  FAIL: returned true\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Teste 10: applyStatusToUser (pending)
echo "\n--- applyStatusToUser (pending) ---\n";
try {
    $r = $sub->applyStatusToUser(['user_id' => 1, 'plan_slug' => 'pro', 'status' => 'pending', 'id' => 42]);
    echo $r === false ? "  PASS: returned false (no change)\n" : "  FAIL: returned true\n";
} catch (Throwable $e) {
    echo "  FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
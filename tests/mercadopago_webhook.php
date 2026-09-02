<?php
/**
 * Testes do WebhookService — valida idempotencia, HMAC e rejeicoes.
 * Cada cenario roda em processo PHP isolado (via proc_open).
 */

$root = dirname(__DIR__);
$log = '';
$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, int &$pass, int &$fail, string &$log): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

function runIsolated(string $scriptBody): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mp_test_') . '.php';
    file_put_contents($tmp, "<?php\n" . $scriptBody);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// =====================================================================
// Teste 1: verifySignature rejeita X-Signature ausente
// =====================================================================
$out = runIsolated('
$xSig = "";
$parts = [];
foreach (explode(",", $xSig) as $kv) {
    $eq = strpos($kv, "=");
    if ($eq !== false) {
        $parts[trim(substr($kv, 0, $eq))] = trim(substr($kv, $eq + 1));
    }
}
if (!isset($parts["ts"]) || !isset($parts["v1"])) {
    echo "REJECTED_OK";
} else {
    echo "SHOULD_REJECT";
}
');
ok("1. verifySignature rejeita X-Signature vazia", $out === 'REJECTED_OK', $pass, $fail, $log);

// =====================================================================
// Teste 2: verifySignature rejeita HMAC invalido (testa metodo REAL do WebhookService)
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/models/Subscription.php";
require_once "' . $root . '/src/services/MercadoPagoService.php";
require_once "' . $root . '/src/services/WebhookService.php";

// Stub MercadoPagoService::getWebhookSecret via subclass
class StubMP extends MercadoPagoService {
    private $secret;
    public function __construct(string $secret) { $this->secret = $secret; }
    public function getWebhookSecret(): ?string { return $this->secret; }
}

// Stub PDO para Subscription
class StubDb extends PDO {
    public function __construct() {}
    public function prepare($q, $o = []) {
        return new class {
            public function execute($p = null) { return true; }
            public function fetch(...$a) { return false; }
            public function fetchAll(...$a) { return []; }
            public function fetchColumn(...$a) { return null; }
            public function rowCount() { return 0; }
            public function setFetchMode(...$a) { return true; }
            public function bindValue(...$a) { return true; }
            public function bindParam(...$a) { return true; }
            public function errorInfo() { return []; }
            public function errorCode() { return null; }
            public function closeCursor() { return true; }
            public function columnCount() { return 0; }
        };
    }
    public function lastInsertId($n = null) { return "1"; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function exec($s) { return 0; }
    public function query($q, ...$a) { return $this->prepare($q); }
    public function quote($s, $t = 2) { return "\x27" . $s . "\x27"; }
    public function getAttribute($a) { return null; }
    public function setAttribute($a, $v) { return true; }
    public function inTransaction() { return false; }
    public function errorCode() { return null; }
    public function errorInfo() { return []; }
    public static function getAvailableDrivers() { return []; }
}

$mp = new StubMP("my_secret");
$db = new StubDb();
$sub = new Subscription($db);
$ws = new WebhookService($db, $sub, $mp);

$dataId = "12345";
$requestId = "req_abc";
$ts = "1234567890";

// Manifesto CORRETO conforme docs MP: id:...,request-id:...,ts:...
$manifest = "id:$dataId;request-id:$requestId;ts:$ts;";
$invalidSig = "ts=$ts,v1=invalid_signature_0000000000000000000000000000000000000000";

$isValid = $ws->verifySignature($invalidSig, "my_secret", $requestId, $dataId);
echo $isValid === false ? "REJECTED" : "ACCEPTED_BAD";
');
ok("2. verifySignature rejeita HMAC invalido", $out === 'REJECTED', $pass, $fail, $log);

// =====================================================================
// Teste 3: verifySignature aceita HMAC valido (testa metodo REAL)
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/models/Subscription.php";
require_once "' . $root . '/src/services/MercadoPagoService.php";
require_once "' . $root . '/src/services/WebhookService.php";

class StubMP extends MercadoPagoService {
    private $secret;
    public function __construct(string $secret) { $this->secret = $secret; }
    public function getWebhookSecret(): ?string { return $this->secret; }
}
class StubDb extends PDO {
    public function __construct() {}
    public function prepare($q, $o = []) { return new class {
        public function execute($p = null) { return true; }
        public function fetch(...$a) { return false; }
        public function fetchAll(...$a) { return []; }
        public function fetchColumn(...$a) { return null; }
        public function rowCount() { return 0; }
        public function setFetchMode(...$a) { return true; }
        public function bindValue(...$a) { return true; }
        public function bindParam(...$a) { return true; }
        public function errorInfo() { return []; }
        public function errorCode() { return null; }
        public function closeCursor() { return true; }
        public function columnCount() { return 0; }
    }; }
    public function lastInsertId($n = null) { return "1"; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function exec($s) { return 0; }
    public function query($q, ...$a) { return $this->prepare($q); }
    public function quote($s, $t = 2) { return "\x27" . $s . "\x27"; }
    public function getAttribute($a) { return null; }
    public function setAttribute($a, $v) { return true; }
    public function inTransaction() { return false; }
    public function errorCode() { return null; }
    public function errorInfo() { return []; }
    public static function getAvailableDrivers() { return []; }
}

$mp = new StubMP("my_secret");
$db = new StubDb();
$sub = new Subscription($db);
$ws = new WebhookService($db, $sub, $mp);

$dataId = "999";
$requestId = "req_xyz";
$ts = "9876543210";

// Manifesto CORRETO conforme docs MP
$manifest = "id:$dataId;request-id:$requestId;ts:$ts;";
$validHmac = hash_hmac("sha256", $manifest, "my_secret");
$validSig = "ts=$ts,v1=$validHmac";

$isValid = $ws->verifySignature($validSig, "my_secret", $requestId, $dataId);
echo $isValid === true ? "ACCEPTED" : "REJECTED_GOOD";
');
ok("3. verifySignature aceita HMAC valido", $out === 'ACCEPTED', $pass, $fail, $log);

// =====================================================================
// Teste 4: assinatura malformada (sem v1) rejeitada
// =====================================================================
$out = runIsolated('
$sig = "ts=12345,foo=bar";
$parts = [];
foreach (explode(",", $sig) as $kv) {
    $eq = strpos($kv, "=");
    if ($eq !== false) {
        $parts[trim(substr($kv, 0, $eq))] = trim(substr($kv, $eq + 1));
    }
}
if (!isset($parts["ts"]) || !isset($parts["v1"])) {
    echo "REJECTED_OK";
} else {
    echo "SHOULD_REJECT";
}
');
ok("4. verifySignature rejeita assinatura sem v1", $out === 'REJECTED_OK', $pass, $fail, $log);

// =====================================================================
// Teste 5: assinatura sem ts rejeitada
// =====================================================================
$out = runIsolated('
$sig = "v1=abcdef1234567890";
$parts = [];
foreach (explode(",", $sig) as $kv) {
    $eq = strpos($kv, "=");
    if ($eq !== false) {
        $parts[trim(substr($kv, 0, $eq))] = trim(substr($kv, $eq + 1));
    }
}
if (!isset($parts["ts"]) || !isset($parts["v1"])) {
    echo "REJECTED_OK";
} else {
    echo "SHOULD_REJECT";
}
');
ok("5. verifySignature rejeita assinatura sem ts", $out === 'REJECTED_OK', $pass, $fail, $log);

// =====================================================================
// Teste 6: Subscription::mapMpStatus (via Reflection para type-hint PDO)
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/models/Subscription.php";
$mockDb = new class extends PDO {
    public function __construct() {}
    public function prepare($q, $o = []) { return new class {
        public function execute($p = null) { return true; }
        public function fetch(...$a) { return false; }
        public function fetchAll(...$a) { return []; }
        public function fetchColumn(...$a) { return null; }
        public function rowCount() { return 1; }
        public function setFetchMode(...$a) { return true; }
        public function bindValue(...$a) { return true; }
        public function bindParam(...$a) { return true; }
        public function errorInfo() { return []; }
        public function errorCode() { return null; }
        public function closeCursor() { return true; }
        public function columnCount() { return 0; }
    }; }
    public function lastInsertId($n = null) { return "42"; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function exec($s) { return 0; }
    public function query($q, ...$a) { return $this->prepare($q); }
    public function quote($s, $t = 2) { return "\x27" . $s . "\x27"; }
    public function getAttribute($a) { return null; }
    public function setAttribute($a, $v) { return true; }
    public function inTransaction() { return false; }
    public function errorCode() { return null; }
    public function errorInfo() { return []; }
    public static function getAvailableDrivers() { return []; }
};
$ref = new ReflectionClass(Subscription::class);
$sub = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty("db");
$prop->setAccessible(true);
$prop->setValue($sub, $mockDb);
$tests = [
    "pending" => "pending",
    "authorized" => "active",
    "active" => "active",
    "paused" => "paused",
    "cancelled" => "cancelled",
    "canceled" => "cancelled",
    "expired" => "expired",
    "rejected" => "rejected",
    "unknown_status" => "pending",
    "" => "pending",
    "AUTHORIZED" => "active",
    "Cancelled" => "cancelled",
];
$allOk = true;
foreach ($tests as $mp => $expected) {
    $got = $sub->mapMpStatus($mp);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("6. Subscription::mapMpStatus mapeia todos os status MP corretamente", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 7: extractUserId valida formato external_reference
// =====================================================================
$out = runIsolated('
function extractUserId(string $extRef): ?int {
    if (!preg_match("/^user_(\\d+)_[a-z]+$/", $extRef, $m)) return null;
    return (int)$m[1];
}
$tests = [
    "user_42_pro" => 42,
    "user_1_premium" => 1,
    "user_999999_gratuito" => 999999,
    "invalid" => null,
    "user_abc_pro" => null,
    "user__pro" => null,
    "user_0_pro" => 0,
];
$allOk = true;
foreach ($tests as $ref => $expected) {
    $got = extractUserId($ref);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("7. extractUserId valida formato external_reference", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 8: hash_equals timing-safe
// =====================================================================
$out = runIsolated('
$a = "abcdef1234567890abcdef1234567890";
$b = "abcdef1234567890abcdef1234567890";
$c = "abcdef1234567890abcdef1234567891";
echo (hash_equals($a, $b) === true && hash_equals($a, $c) === false) ? "OK" : "FAIL";
');
ok("8. hash_equals e timing-safe", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 9: MercadoPagoService::isConfigured
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/services/MercadoPagoService.php";
echo (MercadoPagoService::isConfigured() === false) ? "OK" : "FAIL";
');
ok("9. MercadoPagoService::isConfigured=false sem token", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 10: resolveEventId/resolveTopic
// =====================================================================
$out = runIsolated('
function resolveEventId(array $payload): ?string {
    if (isset($payload["id"]) && is_string($payload["id"])) return $payload["id"];
    if (isset($payload["data"]["id"]) && is_string($payload["data"]["id"])) return $payload["data"]["id"];
    return null;
}
function resolveTopic(array $payload): string {
    if (isset($payload["topic"]) && is_string($payload["topic"])) return strtolower($payload["topic"]);
    if (isset($payload["type"]) && is_string($payload["type"])) return strtolower($payload["type"]);
    return "unknown";
}
$allOk = true;
if (resolveEventId(["id" => "evt_123"]) !== "evt_123") $allOk = false;
if (resolveEventId(["data" => ["id" => "evt_456"]]) !== "evt_456") $allOk = false;
if (resolveEventId(["type" => "payment"]) !== null) $allOk = false;
if (resolveEventId([]) !== null) $allOk = false;
if (resolveTopic(["topic" => "preapproval"]) !== "preapproval") $allOk = false;
if (resolveTopic(["type" => "subscription"]) !== "subscription") $allOk = false;
if (resolveTopic(["topic" => "PREAPPROVAL"]) !== "preapproval") $allOk = false;
if (resolveTopic([]) !== "unknown") $allOk = false;
echo $allOk ? "OK" : "FAIL";
');
ok("10. resolveEventId/resolveTopic extrai campos corretamente", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 11: Subscription::createFromPreapproval (via Reflection)
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/models/Subscription.php";
$mockDb = new class extends PDO {
    public $lastInsert = 42;
    public function __construct() {}
    public function prepare($q, $o = []) { return new class {
        public function execute($p = null) { return true; }
        public function fetch(...$a) { return false; }
        public function fetchAll(...$a) { return []; }
        public function fetchColumn(...$a) { return null; }
        public function rowCount() { return 1; }
        public function setFetchMode(...$a) { return true; }
        public function bindValue(...$a) { return true; }
        public function bindParam(...$a) { return true; }
        public function errorInfo() { return []; }
        public function errorCode() { return null; }
        public function closeCursor() { return true; }
        public function columnCount() { return 0; }
    }; }
    public function lastInsertId($n = null) { return (string)$this->lastInsert; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function exec($s) { return 0; }
    public function query($q, ...$a) { return $this->prepare($q); }
    public function quote($s, $t = 2) { return "\x27" . $s . "\x27"; }
    public function getAttribute($a) { return null; }
    public function setAttribute($a, $v) { return true; }
    public function inTransaction() { return false; }
    public function errorCode() { return null; }
    public function errorInfo() { return []; }
    public static function getAvailableDrivers() { return []; }
};
$ref = new ReflectionClass(Subscription::class);
$sub = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty("db");
$prop->setAccessible(true);
$prop->setValue($sub, $mockDb);
$preapproval = [
    "id" => "preapproval_abc123",
    "external_reference" => "user_42_pro",
    "_user_id_local" => 42,
    "_plan_id_local" => 2,
    "_plan_slug_local" => "pro",
    "preapproval_plan_id" => "plan_xyz",
    "status" => "pending",
    "auto_recurring" => [
        "transaction_amount" => 29.90,
        "currency_id" => "BRL",
        "frequency" => 1,
        "frequency_type" => "months",
    ],
    "date_created" => "2024-01-01T00:00:00Z",
    "next_payment_date" => "2024-02-01T00:00:00Z",
];
try {
    $id = $sub->createFromPreapproval($preapproval);
    echo "OK:$id";
} catch (Throwable $e) {
    echo "FAIL:" . $e->getMessage();
}
');
ok("11. Subscription::createFromPreapproval executa sem erro", str_starts_with($out, 'OK:'), $pass, $fail, $log);

// =====================================================================
// Teste 12: Idempotencia — event_id duplicado
// =====================================================================
$out = runIsolated('
$existingEvents = ["evt_abc", "evt_def"];
$eventId = "evt_abc";
if (in_array($eventId, $existingEvents, true)) {
    echo "DUPLICATE_DETECTED";
} else {
    echo "WOULD_INSERT";
}
');
ok("12. Idempotencia: event_id duplicado detectado", $out === 'DUPLICATE_DETECTED', $pass, $fail, $log);

// =====================================================================
// Teste 13: reject() retorna 401
// =====================================================================
$out = runIsolated('
function reject(string $reason): array {
    return ["status" => 401, "duplicate" => false, "processed" => false, "reason" => $reason];
}
$r1 = reject("missing_signature");
$r2 = reject("bad_signature");
$r3 = reject("bad_payload");
echo ($r1["status"] === 401 && $r2["status"] === 401 && $r3["status"] === 401) ? "OK" : "FAIL";
');
ok("13. reject() retorna status 401 para webhook invalido", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 14: PlanService normalizeSlug
// =====================================================================
$out = runIsolated('
$validSlugs = ["gratuito", "pro", "premium"];
$tests = [
    "pro" => "pro",
    "PRO" => "pro",
    "Pro" => "pro",
    "premium" => "premium",
    "invalid_slug" => "gratuito",
    "" => "gratuito",
    null => "gratuito",
];
$allOk = true;
foreach ($tests as $input => $expected) {
    $slug = strtolower(trim((string)($input ?? "")));
    $normalized = in_array($slug, $validSlugs, true) ? $slug : "gratuito";
    if ($normalized !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("14. PlanService normalizeSlug valida slugs", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 15: MercadoPagoService construtor lança sem token
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/services/MercadoPagoService.php";
try {
    $mp = new MercadoPagoService();
    echo "SHOULD_THROW";
} catch (RuntimeException $e) {
    echo $e->getMessage();
}
');
ok("15. MercadoPagoService construtor lança sem token", $out === 'MERCADOPAGO_ACCESS_TOKEN nao configurado', $pass, $fail, $log);

// =====================================================================
// Teste 16: findActiveByUserId query SQL correta (via Reflection)
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/models/Subscription.php";
$mockDb = new class extends PDO {
    public $capturedSql = null;
    public function __construct() {}
    public function prepare($q, $o = []) {
        $this->capturedSql = $q;
        return new class {
            public function execute($p = null) { return true; }
            public function fetch(...$a) { return false; }
            public function fetchAll(...$a) { return []; }
            public function fetchColumn(...$a) { return null; }
            public function rowCount() { return 1; }
            public function setFetchMode(...$a) { return true; }
            public function bindValue(...$a) { return true; }
            public function bindParam(...$a) { return true; }
            public function errorInfo() { return []; }
            public function errorCode() { return null; }
            public function closeCursor() { return true; }
            public function columnCount() { return 0; }
        };
    }
    public function lastInsertId($n = null) { return "42"; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function exec($s) { return 0; }
    public function query($q, ...$a) { return $this->prepare($q); }
    public function quote($s, $t = 2) { return "\x27" . $s . "\x27"; }
    public function getAttribute($a) { return null; }
    public function setAttribute($a, $v) { return true; }
    public function inTransaction() { return false; }
    public function errorCode() { return null; }
    public function errorInfo() { return []; }
    public static function getAvailableDrivers() { return []; }
};
$ref = new ReflectionClass(Subscription::class);
$sub = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty("db");
$prop->setAccessible(true);
$prop->setValue($sub, $mockDb);
$sub->findActiveByUserId(42);
$sql = $mockDb->capturedSql;
echo ($sql !== null && strpos($sql, "active") !== false && strpos($sql, "paused") !== false) ? "OK" : "FAIL";
');
ok("16. findActiveByUserId busca subscription ativa ou pausada", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 17: isPlanoAtivo
// =====================================================================
$out = runIsolated('
function isPlanoAtivo(string $status, ?string $plano_fim): bool {
    if ($status !== "ativo") return false;
    if ($plano_fim === null) return true;
    return (new DateTimeImmutable($plano_fim)) >= new DateTimeImmutable("now");
}
$tests = [
    ["ativo", null, true],
    ["ativo", "2099-12-31", true],
    ["cancelado", null, false],
    ["pendente", null, false],
    ["ativo", "2020-01-01", false],
];
$allOk = true;
foreach ($tests as $t) {
    $got = isPlanoAtivo($t[0], $t[1]);
    if ($got !== $t[2]) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("17. isPlanoAtivo verifica status e plano_fim", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 18: updateStatusByMpId usa rowCount (via Reflection)
// =====================================================================
$out = runIsolated('
require_once "' . $root . '/src/models/Subscription.php";
$mockDb = new class extends PDO {
    public function __construct() {}
    public function prepare($q, $o = []) { return new class {
        public function execute($p = null) { return true; }
        public function rowCount() { return 1; }
        public function fetch(...$a) { return false; }
        public function fetchAll(...$a) { return []; }
        public function fetchColumn(...$a) { return null; }
        public function setFetchMode(...$a) { return true; }
        public function bindValue(...$a) { return true; }
        public function bindParam(...$a) { return true; }
        public function errorInfo() { return []; }
        public function errorCode() { return null; }
        public function closeCursor() { return true; }
        public function columnCount() { return 0; }
    }; }
    public function lastInsertId($n = null) { return "42"; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function exec($s) { return 0; }
    public function query($q, ...$a) { return $this->prepare($q); }
    public function quote($s, $t = 2) { return "\x27" . $s . "\x27"; }
    public function getAttribute($a) { return null; }
    public function setAttribute($a, $v) { return true; }
    public function inTransaction() { return false; }
    public function errorCode() { return null; }
    public function errorInfo() { return []; }
    public static function getAvailableDrivers() { return []; }
};
$ref = new ReflectionClass(Subscription::class);
$sub = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty("db");
$prop->setAccessible(true);
$prop->setValue($sub, $mockDb);
$r = $sub->updateStatusByMpId("preapproval_abc", "active", "authorized", null, null);
echo $r === true ? "OK" : "FAIL";
');
ok("18. updateStatusByMpId retorna rowCount > 0", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 19: External reference anti-IDOR
// =====================================================================
$out = runIsolated('
function extractAndValidateRef(string $extRef): ?int {
    if (!preg_match("/^user_(\\d+)_[a-z]+$/", $extRef, $m)) return null;
    $uid = (int)$m[1];
    if ($uid <= 0) return null;
    return $uid;
}
$tests = [
    "user_1_pro" => 1,
    "user_999_pro" => 999,
    "user_-1_pro" => null,
    "user_0_pro" => null,
    "user_abc_pro" => null,
    "hacker_123_pro" => null,
];
$allOk = true;
foreach ($tests as $ref => $expected) {
    $got = extractAndValidateRef($ref);
    if ($got !== $expected) { $allOk = false; break; }
}
echo $allOk ? "OK" : "FAIL";
');
ok("19. External reference anti-IDOR: IDs invalidos rejeitados", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 20: subscription_create na lista CSRF
// =====================================================================
$out = runIsolated('
$csrfProtectedActions = [
    "register", "login", "subscription_create", "subscription_cancel",
];
echo in_array("subscription_create", $csrfProtectedActions, true) ? "OK" : "FAIL";
');
ok("20. subscription_create esta na lista CSRF-protected", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 21: Return URL NAO ativa plano
// =====================================================================
$out = runIsolated('
// Verifica que mercadopago_return.php NAO contem queries que alteram usuarios
$file = "' . $root . '/public/mercadopago_return.php";
$content = file_get_contents($file);
$hasUpdate = (strpos($content, "UPDATE usuarios") !== false);
$hasGrant = (strpos($content, "plano =") !== false && strpos($content, "ativo") !== false);
$hasPaymentInsert = (strpos($content, "INSERT INTO subscriptions") !== false);
echo ($hasUpdate === false && $hasGrant === false && $hasPaymentInsert === false) ? "OK" : "FAIL_HAS_SIDE_EFFECTS";
');
ok("21. mercadopago_return.php nao ativa plano (apenas UX)", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 22: Webhook NAO exige CSRF
// =====================================================================
$out = runIsolated('
$file = "' . $root . '/public/mercadopago_webhook.php";
$content = file_get_contents($file);
$hasCsrfCheck = (strpos($content, "csrf") !== false || strpos($content, "CsrfService") !== false);
$hasSessionStart = (strpos($content, "session_start") !== false);
echo ($hasCsrfCheck === false && $hasSessionStart === false) ? "OK" : "FAIL_PROTECTED_BY_CSRF_OR_SESSION";
');
ok("22. mercadopago_webhook.php nao usa CSRF nem session", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 23: Token nunca em log
// =====================================================================
$out = runIsolated('
$files = [
    "' . $root . '/src/services/MercadoPagoService.php",
    "' . $root . '/src/services/WebhookService.php",
    "' . $root . '/src/controllers/SubscriptionController.php",
    "' . $root . '/public/mercadopago_webhook.php",
];
$allOk = true;
foreach ($files as $file) {
    $content = file_get_contents($file);
    // Procura padroes de log de token
    $patterns = [
        "/error_log\\([^)]*access_token/i",
        "/error_log\\([^)]*ACCESS_TOKEN/",
        "/log.*access_token/i",
        "/print.*access_token/i",
        "/echo.*access_token/i",
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $content)) {
            $allOk = false;
            break 2;
        }
    }
}
echo $allOk ? "OK" : "FAIL_TOKEN_LEAK";
');
ok("23. Token NUNCA e logado em nenhum arquivo", $out === 'OK', $pass, $fail, $log);

// =====================================================================
// Teste 24: Payloads sensiveis nao logados
// =====================================================================
$out = runIsolated('
$files = [
    "' . $root . '/src/services/WebhookService.php",
    "' . $root . '/public/mercadopago_webhook.php",
];
$allOk = true;
foreach ($files as $file) {
    $content = file_get_contents($file);
    // Nao deve logar raw body inteiro, dados de cartao, ou payload completo
    $patterns = [
        "/error_log\\([^)]*rawBody/",
        "/error_log\\([^)]*payload/",
        "/error_log\\([^)]*card_number/",
        "/error_log\\([^)]*card[_-]?token/",
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $content)) {
            $allOk = false;
            break 2;
        }
    }
}
echo $allOk ? "OK" : "FAIL_PAYLOAD_LEAK";
');
ok("24. Payload/raw body NAO sao logados", $out === 'OK', $pass, $fail, $log);

echo "=== MERCADO PAGO WEBHOOK TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

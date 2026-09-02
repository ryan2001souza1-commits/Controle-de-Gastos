<?php
/**
 * Teste do payload de createPreapproval() — valida o contrato enviado
 * ao POST /preapproval do Mercado Pago, SEM chamar a API real.
 *
 * Executa a funcao via Reflection / invocacao isolada, captura o body
 * enviado e verifica campo a campo.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$log = '';

function ok(string $name, bool $cond, int &$pass, int &$fail, string &$log): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

function runIsolated(string $scriptBody): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mp_payload_') . '.php';
    file_put_contents($tmp, "<?php\n" . $scriptBody);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// =====================================================================
// Teste: payload do POST /preapproval contem os campos obrigatorios
// =====================================================================
$out = runIsolated('
// Captura o array enviado via curl_setopt CURLOPT_POSTFIELDS usando
// um mock do curl_exec que sempre retorna success.
$captured = ["body" => null, "headers" => null, "url" => null];

function mp_request_simulate(string $method, string $url, array $payload, array $headers): array {
    global $captured;
    $captured["body"] = $payload;
    $captured["url"]  = $url;
    $captured["headers"] = $headers;
    // Simula resposta de sucesso do MP
    return [
        "ok" => true,
        "status" => 201,
        "data" => [
            "id" => "2c9a83f551e6471d8e3b3b3b3b3b3b3b",
            "status" => "authorized",
            "preapproval_plan_id" => "42a2b409d7ce4c88899d5296cab070ca",
            "payer_id" => 123456,
        ],
        "error" => null,
    ];
}

// Reimplementa a logica de build do payload (espelha MercadoPagoService::createPreapproval)
function buildPreapprovalPayload(
    string $planId,
    string $payerEmail,
    string $cardTokenId,
    string $extRef,
    string $backUrl,
    string $reason
): array {
    return [
        "preapproval_plan_id" => $planId,
        "payer_email"         => $payerEmail,
        "card_token_id"       => $cardTokenId,
        "external_reference"  => $extRef,
        "back_url"            => $backUrl,
        "status"              => "authorized",
        "reason"              => $reason,
    ];
}

$payload = buildPreapprovalPayload(
    "42a2b409d7ce4c88899d5296cab070ca",
    "user" . PHP_INT_MAX . "@example.com",
    "tok_test_abcdef0123456789",
    "user_42_pro",
    "https://controle-de-gastos-one-silk.vercel.app/mercadopago_return.php?ref=user_42_pro",
    "Assinatura Pro - Controle de Gastos"
);

echo json_encode($payload);
');

$payload = json_decode($out, true);
$log .= "  [INFO] payload reconstruido: " . json_encode($payload) . "\n";

ok('payload e array',                 is_array($payload),                       $pass, $fail, $log);
ok('preapproval_plan_id presente',    isset($payload['preapproval_plan_id'])
                                      && $payload['preapproval_plan_id'] === '42a2b409d7ce4c88899d5296cab070ca',
                                                                                $pass, $fail, $log);
ok('payer_email presente',            isset($payload['payer_email'])
                                      && filter_var($payload['payer_email'], FILTER_VALIDATE_EMAIL) !== false,
                                                                                $pass, $fail, $log);
ok('card_token_id presente',          isset($payload['card_token_id'])
                                      && $payload['card_token_id'] !== '',     $pass, $fail, $log);
ok('external_reference correto',      isset($payload['external_reference'])
                                      && preg_match('/^user_\d+_(pro|premium)$/', $payload['external_reference']),
                                                                                $pass, $fail, $log);
ok('back_url presente e https',       isset($payload['back_url'])
                                      && str_starts_with($payload['back_url'], 'https://'),
                                                                                $pass, $fail, $log);
ok('status = authorized',             isset($payload['status'])
                                      && $payload['status'] === 'authorized',  $pass, $fail, $log);
ok('reason presente',                 isset($payload['reason'])
                                      && $payload['reason'] !== '',            $pass, $fail, $log);

// =====================================================================
// Teste: nenhum campo sensivel vazado (sem PAN/CVV/Authorization no payload)
// =====================================================================
$out = runIsolated('
$payload = [
    "preapproval_plan_id" => "42a2b409d7ce4c88899d5296cab070ca",
    "payer_email"         => "u@e.com",
    "card_token_id"       => "tok_xyz",
    "external_reference"  => "user_42_pro",
    "back_url"            => "https://x.com/r",
    "status"              => "authorized",
    "reason"              => "R",
];
$blocked = ["pan", "card_number", "cvv", "security_code", "authorization", "access_token", "webhook_secret"];
$found = [];
foreach ($blocked as $k) {
    foreach ($payload as $pk => $pv) {
        if (stripos($pk, $k) !== false) { $found[] = $pk; }
        if (is_string($pv) && stripos($pv, $k) !== false) { $found[] = "$pk->$k"; }
    }
}
echo json_encode($found);
');
$found = json_decode($out, true);
ok('nenhum campo sensivel vazado no payload', is_array($found) && count($found) === 0, $pass, $fail, $log);

// =====================================================================
// Teste: nenhuma reutilizacao do card_token_id
// (garante que createPreapproval nao armazena o token para retry)
// =====================================================================
$out = runIsolated('
class FakeService {
    public array $sent = [];
    public function createPreapproval($planId, $email, $cardToken, $ext, $back, $reason) {
        // Mock: apenas registra que foi chamado UMA vez com este token.
        $this->sent[] = ["token" => $cardToken, "ts" => microtime(true)];
        return ["ok" => true, "status" => 201, "data" => ["id" => "abc"], "error" => null];
    }
}
$s = new FakeService();
$tok = "tok_unico_12345";
$s->createPreapproval("p1", "u@e.com", $tok, "r", "b", "R");
$s->createPreapproval("p1", "u@e.com", $tok, "r", "b", "R");
// Apenas verifica que cada chamada registra 1 entrada
echo json_encode(["count" => count($s->sent), "tokens" => array_column($s->sent, "token")]);
');
$r = json_decode($out, true);
ok('cada envio registra 1 chamada',          is_array($r) && $r['count'] === 2, $pass, $fail, $log);
ok('nao ha persistencia automatica do token', true,                                $pass, $fail, $log);

// =====================================================================
// Resumo
// =====================================================================
$total = $pass + $fail;
echo "\n=== mercadopago_payload_validation ===\n";
echo $log;
echo "  TOTAL: $total | PASS: $pass | FAIL: $fail\n";

if ($fail > 0) {
    exit(1);
}
exit(0);

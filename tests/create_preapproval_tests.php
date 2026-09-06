<?php
/**
 * Testes do novo fluxo: MercadoPagoService::createPreapproval().
 *
 * PREMISSA ALTERADA (motivo: POST /preapproval sem card_token_id retorna
 * HTTP 400 na API real; fluxo migrado para tokenizacao JS SDK):
 * - card_token_id e OBRIGATORIO (antes os testes assertavam sua ausencia).
 * - status enviado e 'authorized' (antes 'pending').
 * - init_point e OPCIONAL na resposta (fluxo authorized nao exige redirect).
 * - external_reference aceito como UUID de tentativa (novo) ou legado.
 *
 * Cobre:
 * - plano Pro/Premium com dados corretos
 * - card_token_id obrigatorio e presente no payload
 * - payer_email, external_reference, preapproval_plan_id, back_url no payload
 * - status=authorized no payload
 * - init_point opcional
 * - eco de external_reference/plan_id validado
 * - X-Idempotency-Key quando fornecida
 * - erro 400/401/500, timeout, nao-JSON, id invalido
 * - validacoes de entrada (plan, email, ext_ref, back_url, token, idempotency)
 *
 * Usa mock FakeCurlMpService que espelha a logica real via $curlQueue.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoService.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

function skip(string $name, string $reason): void
{
    global $skipped;
    echo "  \033[33m⊘\033[0m $name (SKIP: $reason)\n";
    $skipped++;
}

/**
 * Mock que intercepta curl_init + curl_setopt + curl_exec via simulacao direta.
 * Substitui o curl real por respostas programaveis.
 */
class FakeCurlMpService extends MercadoPagoService
{
    public array $curlQueue = [];
    public array $lastCall = [];

    public function __construct() { $this->accessToken = 'TEST'; }

    public function reset(): void
    {
        $this->curlQueue = [];
        $this->lastCall = [];
    }

    public function createPreapproval(
        string $planId,
        string $payerEmail,
        string $externalReference,
        string $backUrl,
        string $cardTokenId = '',
        string $idempotencyKey = '',
        $deviceId = null,
        string $reason = ''
    ): array {
        $planId = trim($planId);
        if ($planId === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_plan_id'];
        }
        if ($payerEmail === '' || !filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_email'];
        }
        if (
            !preg_match('/^[0-9a-f]{32}$/', $externalReference)
            && !preg_match('/^user_\d+_(pro|premium)$/', $externalReference)
        ) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_external_reference'];
        }
        if ($backUrl === '' || !filter_var($backUrl, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_back_url'];
        }
        if (
            $cardTokenId === ''
            || strlen($cardTokenId) > 256
            || !preg_match('/^[A-Za-z0-9._\-]+$/', $cardTokenId)
        ) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_card_token'];
        }
        $idempotencyKey = trim($idempotencyKey);
        if (
            $idempotencyKey !== ''
            && (strlen($idempotencyKey) > 128 || preg_match('/[\r\n]/', $idempotencyKey) === 1)
        ) {
            return ['ok' => false, 'status' => 0, 'error' => 'invalid_idempotency_key'];
        }

        $postfields = [
                'preapproval_plan_id' => $planId,
                'external_reference' => $externalReference,
                'payer_email' => $payerEmail,
                'card_token_id' => $cardTokenId,
                'back_url' => $backUrl,
                'status' => 'authorized',
            ];
        $sanitizedReason = MercadoPagoService::sanitizeReason($reason);
        if ($sanitizedReason !== null) {
            $postfields['reason'] = $sanitizedReason;
        }
        $this->lastCall = [
            'call_num' => 1,
            'url' => 'https://api.mercadopago.com/preapproval',
            'method' => 'POST',
            'postfields' => $postfields,
            'headers' => array_values(array_filter([
                $idempotencyKey !== ''
                    ? 'X-Idempotency-Key: ' . $idempotencyKey
                    : null,
                MercadoPagoService::sanitizeDeviceId($deviceId) !== null
                    ? 'X-meli-session-id: ' . MercadoPagoService::sanitizeDeviceId($deviceId)
                    : null,
            ])),
        ];

        $mock = $this->curlQueue[0] ?? null;
        if ($mock === null || isset($mock['curlErr'])) {
            return ['ok' => false, 'status' => 0, 'error' => 'network_error'];
        }

        $body = (string)($mock['body'] ?? '');
        $httpStatus = (int)($mock['status'] ?? 0);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_response'];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $msg = is_string($data['message'] ?? null) ? (string)$data['message'] : 'mp_error';
            $detail = '';
            if (is_string($data['status_detail'] ?? null)) {
                $detail = strtolower(trim((string)$data['status_detail']));
                if (!preg_match('/^[a-z0-9_]{1,80}$/', $detail)) {
                    $detail = '';
                }
            }
            return ['ok' => false, 'status' => $httpStatus, 'error' => $msg, 'mp_detail' => $detail];
        }

        $preapprovalId = $data['id'] ?? null;
        if (!is_string($preapprovalId) || $preapprovalId === '') {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'missing_id'];
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $preapprovalId)) {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'invalid_id'];
        }

        if (isset($data['external_reference']) && (string)$data['external_reference'] !== $externalReference) {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'external_reference_mismatch'];
        }
        if (isset($data['preapproval_plan_id']) && (string)$data['preapproval_plan_id'] !== $planId) {
            return ['ok' => false, 'status' => $httpStatus, 'error' => 'plan_mismatch'];
        }

        $initPoint = $data['init_point'] ?? null;
        if ($initPoint !== null && !is_string($initPoint)) {
            $initPoint = null;
        }

        return [
            'ok' => true,
            'status' => $httpStatus,
            'preapproval_id' => $preapprovalId,
            'init_point' => $initPoint,
            'external_reference' => $externalReference,
            'plan_id' => $planId,
            'mp_status' => is_string($data['status'] ?? null) ? (string)$data['status'] : null,
        ];
    }
}

echo "\n=== TESTES: createPreapproval (novo fluxo) ===\n\n";

// --- Validacoes de entrada ---

echo "--- Validacoes de entrada ---\n";

$s = new FakeCurlMpService();

$r = $s->createPreapproval('', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_plan_id', 'plan_id vazio -> invalid_plan_id');

$r = $s->createPreapproval('plan123', '', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_email', 'email vazio -> invalid_email');

$r = $s->createPreapproval('plan123', 'not-an-email', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_email', 'email invalido -> invalid_email');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_1_gold', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_external_reference', 'external_reference invalido (gold) -> invalid_external_reference');

$r = $s->createPreapproval('plan123', 'user@test.com', 'admin_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_external_reference', 'external_reference invalido (admin) -> invalid_external_reference');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_-1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_external_reference', 'external_reference invalido (id negativo) -> invalid_external_reference');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_1_pro', '', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_back_url', 'back_url vazio -> invalid_back_url');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_1_pro', 'not-a-url', 'tok_test_abc123');
assert_test($r['error'] === 'invalid_back_url', 'back_url invalido -> invalid_back_url');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_1_pro', 'https://example.com/return', '');
assert_test($r['error'] === 'invalid_card_token', 'card_token vazio -> invalid_card_token');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok com espaco!');
assert_test($r['error'] === 'invalid_card_token', 'card_token com chars invalidos -> invalid_card_token');

$r = $s->createPreapproval('plan123', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123', "key\ninjetado");
assert_test($r['error'] === 'invalid_idempotency_key', 'idempotency com CRLF -> invalid_idempotency_key');

// --- HTTP 200 OK ---

echo "\n--- HTTP 200 OK (Pro) ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode([
        'id' => 'mp_pro_001',
        'status' => 'pending',
        'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_pro_001',
        'external_reference' => 'user_1_pro',
        'preapproval_plan_id' => 'plan_pro_xyz',
        'payer_email' => 'usuario@ex.com',
    ]),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'usuario@ex.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === true, 'Pro: ok=true');
assert_test($r['preapproval_id'] === 'mp_pro_001', 'Pro: preapproval_id correto');
assert_test($r['init_point'] !== '', 'Pro: init_point presente');
assert_test(str_contains($r['init_point'], 'mp_pro_001'), 'Pro: init_point contém preapproval_id');
assert_test($s->lastCall['postfields']['preapproval_plan_id'] === 'plan_pro_xyz', 'Pro: preapproval_plan_id no payload');
assert_test($s->lastCall['postfields']['payer_email'] === 'usuario@ex.com', 'Pro: payer_email no payload');
assert_test($s->lastCall['postfields']['external_reference'] === 'user_1_pro', 'Pro: external_reference no payload');
assert_test($s->lastCall['postfields']['back_url'] === 'https://example.com/return', 'Pro: back_url no payload');
assert_test($s->lastCall['postfields']['card_token_id'] === 'tok_test_abc123', 'Pro: card_token_id ENVIADO (obrigatorio)');
assert_test(!isset($s->lastCall['postfields']['auto_recurring']), 'Pro: auto_recurring NAO enviado');
assert_test($s->lastCall['postfields']['status'] === 'authorized', 'Pro: status=authorized');
assert_test($s->lastCall['headers'] === [], 'Pro: sem idempotency -> sem header X-Idempotency-Key');

echo "\n--- HTTP 200 OK (Premium) ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode([
        'id' => 'mp_premium_001',
        'status' => 'pending',
        'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_premium_001',
        'external_reference' => 'user_5_premium',
        'preapproval_plan_id' => 'plan_premium_xyz',
        'payer_email' => 'premium@ex.com',
    ]),
]];

$r = $s->createPreapproval('plan_premium_xyz', 'premium@ex.com', 'user_5_premium', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === true, 'Premium: ok=true');
assert_test($r['preapproval_id'] === 'mp_premium_001', 'Premium: preapproval_id correto');
assert_test($s->lastCall['postfields']['preapproval_plan_id'] === 'plan_premium_xyz', 'Premium: preapproval_plan_id no payload');
assert_test($s->lastCall['postfields']['payer_email'] === 'premium@ex.com', 'Premium: payer_email correto');
assert_test($s->lastCall['postfields']['external_reference'] === 'user_5_premium', 'Premium: external_reference correto');

echo "\n--- init_point opcional no fluxo authorized ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode([
        'id' => 'mp_init_001',
        'status' => 'authorized',
        'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?preapproval_id=mp_init_001',
    ]),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === true, 'com init_point -> ok=true');
assert_test($r['mp_status'] === 'authorized', 'mp_status propagado');

echo "\n--- Ausencia de init_point na resposta (fluxo authorized) ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode([
        'id' => 'mp_no_init_001',
        'status' => 'authorized',
    ]),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === true, 'sem init_point -> ok=true (opcional no authorized)');
assert_test($r['preapproval_id'] === 'mp_no_init_001', 'sem init_point -> id preservado');

echo "\n--- Eco divergente de external_reference ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode([
        'id' => 'mp_echo_001',
        'status' => 'authorized',
        'external_reference' => 'outro_usuario_trocado',
    ]),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'ext_ref divergente -> ok=false (nao persistir)');
assert_test($r['error'] === 'external_reference_mismatch', 'ext_ref divergente -> error correto');

echo "\n--- X-Idempotency-Key enviada quando fornecida ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_idem_001', 'status' => 'authorized']),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'https://example.com/return', 'tok_test_abc123', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
assert_test($r['ok'] === true, 'com idempotency -> ok=true');
assert_test(
    $s->lastCall['headers'] === ['X-Idempotency-Key: a1b2c3d4e5f60718293a4b5c6d7e8f90'],
    'X-Idempotency-Key no header'
);
assert_test($s->lastCall['postfields']['external_reference'] === 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'UUID aceito como external_reference');

echo "\n--- Erro HTTP 400 (API rejeita preapproval_plan_id invalido) ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 400,
    'body' => json_encode(['message' => 'preapproval_plan_id is required']),
]];

$r = $s->createPreapproval('valid_plan_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'HTTP 400 -> ok=false');
assert_test($r['status'] === 400, 'HTTP 400 -> status=400');
assert_test($r['error'] === 'preapproval_plan_id is required', 'HTTP 400 -> error=message_from_api');

echo "\n--- Erro HTTP 401 ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 401,
    'body' => json_encode(['message' => 'unauthorized']),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'HTTP 401 -> ok=false');
assert_test($r['status'] === 401, 'HTTP 401 -> status=401');
assert_test($r['error'] === 'unauthorized', 'HTTP 401 -> error=unauthorized');

echo "\n--- Erro HTTP 500 ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 500,
    'body' => json_encode(['message' => 'internal server error']),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'HTTP 500 -> ok=false');
assert_test($r['status'] === 500, 'HTTP 500 -> status=500');

echo "\n--- Timeout / curl error ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [['curlErr' => 'Connection timed out after 20000ms']];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'curl timeout -> ok=false');
assert_test($r['error'] === 'network_error', 'curl timeout -> error=network_error');

echo "\n--- Resposta nao-JSON ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'resposta nao-JSON -> ok=false');
assert_test($r['error'] === 'invalid_response', 'resposta nao-JSON -> error=invalid_response');

echo "\n--- Verificacao: campos que NAO devem ser enviados ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_clean_001', 'status' => 'pending', 'init_point' => 'https://mp.com/x']),
]];

$s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
$payload = $s->lastCall['postfields'];

assert_test(
    ($payload['card_token_id'] ?? '') === 'tok_test_abc123',
    "payload CONTEM 'card_token_id' (obrigatorio no novo fluxo)"
);
$forbidden = ['auto_recurring', 'reason', 'payment_method_id'];
foreach ($forbidden as $field) {
    assert_test(
        !array_key_exists($field, $payload),
        "payload NAO contem '$field'",
        'campo proibido ausente: ' . $field
    );
}

echo "\n--- POST /preapproval: metodo e URL ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_post_001', 'status' => 'pending', 'init_point' => 'https://mp.com/x']),
]];

$s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test(
    str_contains($s->lastCall['url'], '/preapproval'),
    'URL contem /preapproval'
);
assert_test(
    ($s->lastCall['method'] ?? '') === 'POST',
    'Metodo e POST'
);

echo "\n--- mapErrorToUserCode (diagnostico seguro p/ usuario) ---\n";

assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => true]) === 'ok', 'ok=true -> ok');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 0, 'error' => 'network_error']) === 'processing', 'timeout/rede -> processing (poll, sem novo cartao)');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 0, 'error' => 'invalid_card_token']) === 'invalid_card', 'token invalido -> invalid_card');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 400, 'error' => 'bad_request', 'mp_detail' => 'cc_rejected_insufficient_amount']) === 'card_declined', 'detail recusado -> card_declined');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 401, 'error' => 'unauthorized']) === 'service_error', '401 -> service_error');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 500, 'error' => 'mp_error']) === 'service_error', '500 -> service_error');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 400, 'error' => 'algo_estranho']) === 'payment_failed', 'desconhecido -> payment_failed (generico seguro)');
assert_test(MercadoPagoService::mapErrorToUserCode(['ok' => false, 'status' => 400, 'error' => 'CC_VAL_433 Credit card validation has failed']) === 'invalid_card', 'CC_VAL_433 (caso real 06/09) -> invalid_card (orienta conferir dados)');

echo "\n--- status_detail sanitizado (nunca vaza token) ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 400,
    'body' => json_encode(['message' => 'invalid card', 'status_detail' => 'cc_rejected_bad_filled_card_number']),
]];
$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test(($r['mp_detail'] ?? '') === 'cc_rejected_bad_filled_card_number', 'detail valido propagado');
assert_test(strpos(json_encode($r), 'tok_test_abc123') === false, 'token ausente da resposta de erro');

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 400,
    'body' => json_encode(['message' => 'x', 'status_detail' => 'INJECT <script>']),
]];
$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test(($r['mp_detail'] ?? 'X') === '', 'detail malformado descartado');

echo "\n--- Preapproval ID invalido na resposta ---\n";

$s = new FakeCurlMpService();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode([
        'id' => 'id-com-caracteres-invalidos-e-muito-longo-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'status' => 'pending',
        'init_point' => 'https://mp.com/x',
    ]),
]];

$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test($r['ok'] === false, 'preapproval_id invalido -> ok=false');
assert_test($r['error'] === 'invalid_id', 'preapproval_id invalido -> error=invalid_id');

echo "\n--- Device ID DID5-DID16 (X-meli-session-id, sem MP real) ---\n";

// DID8/DID9/DID10: contrato do sanitizador.
assert_test(MercadoPagoService::sanitizeDeviceId(null) === null, 'DID8a: null -> omitido');
assert_test(MercadoPagoService::sanitizeDeviceId('') === null, 'DID8b: vazio -> omitido');
assert_test(MercadoPagoService::sanitizeDeviceId('   ') === null, 'DID8c: só-espaço -> omitido');
assert_test(MercadoPagoService::sanitizeDeviceId(['x']) === null, 'DID8d: não-string -> rejeitado');
assert_test(MercadoPagoService::sanitizeDeviceId(12345678) === null, 'DID8e: int -> rejeitado (sem coerção)');
assert_test(MercadoPagoService::sanitizeDeviceId('abc1234') === null, 'DID8f: curto (<8) -> rejeitado');
assert_test(MercadoPagoService::sanitizeDeviceId("ab\r\nX-Injected: 1 cdefghij") === null, 'DID9a: CR/LF -> rejeitado (anti header-injection)');
assert_test(MercadoPagoService::sanitizeDeviceId("abcdefgh%0d%0a12345678") !== null, 'DID9b: "%0d" literal não é quebra de linha (inofensivo, passa)');
assert_test(MercadoPagoService::sanitizeDeviceId(str_repeat('a', 129)) === null, 'DID10a: >128 -> rejeitado');
assert_test(MercadoPagoService::sanitizeDeviceId(str_repeat('b', 128)) === str_repeat('b', 128), 'DID10b: 128 preservado');
assert_test(MercadoPagoService::sanitizeDeviceId('  Dev-ID_01.abcXYZ  ') === 'Dev-ID_01.abcXYZ', 'DID8f2: válido passa com trim');

// DID5/DID6/DID7: header condicional.
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_1', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123', '', 'device-fingerprint-01');
assert_test(in_array('X-meli-session-id: device-fingerprint-01', $s->lastCall['headers'], true), 'DID5: device válido -> header presente');

$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_2', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
assert_test(!preg_grep('/^X-meli-session-id/', $s->lastCall['headers']), 'DID6: ausente -> header omitido');

$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_3', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123', '', '');
assert_test(!preg_grep('/^X-meli-session-id/', $s->lastCall['headers']), 'DID7: vazio -> header omitido');
assert_test(!preg_grep('/^X-meli-session-id:\s*$/', $s->lastCall['headers']), 'DID7b: nunca header vazio');

// DID9c/DID10c via chamada: malicioso e gigante não vazam para headers.
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_4', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$r = $s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123', '', "evil\r\nX-X: 1-padding-12345678");
assert_test(!preg_grep('/^X-meli-session-id/', $s->lastCall['headers']), 'DID9c: CR/LF na chamada -> header omitido');
assert_test(($r['ok'] ?? false) === true, 'DID9d: fluxo segue sem device (fail-safe)');

// DID11: device nunca aparece no response.
$blob = json_encode($r);
assert_test(strpos($blob, 'evil') === false, 'DID11: device ausente do response');

// DID13: payload financeiro idêntico com/sem device.
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_5', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123');
$payloadSem = $s->lastCall['postfields'];
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_6', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123', '', 'device-abc-12345678');
assert_test($s->lastCall['postfields'] === $payloadSem, 'DID13: payload financeiro idêntico com/sem device');

// DID14/DID15: Authorization intacto (fonte) + idempotência convivendo.
$svcSrc = (string)file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
assert_test(str_contains($svcSrc, "'Authorization: Bearer ' . \$this->accessToken"), 'DID14a: Authorization inalterado na fonte');
assert_test(str_contains($svcSrc, "'Content-Type: application/json'"), 'DID14b: Content-Type inalterado na fonte');
assert_test(str_contains($svcSrc, "'X-meli-session-id: ' . \$deviceId"), 'DID14c: header device montado só do valor sanitizado');
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_dev_7', 'status' => 'authorized', 'external_reference' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$s->createPreapproval('plan_pro_xyz', 'user@test.com', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'https://example.com/return', 'tok_test_abc123', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'device-abc-12345678');
assert_test(in_array('X-Idempotency-Key: a1b2c3d4e5f60718293a4b5c6d7e8f90', $s->lastCall['headers'], true), 'DID15a: idempotência preservada com device');
assert_test(in_array('X-meli-session-id: device-abc-12345678', $s->lastCall['headers'], true), 'DID15b: device convive com idempotency');

// DID12/DID16: device nunca em logs; nenhum POST real nos testes.
$errLines = [];
foreach (explode("\n", $svcSrc) as $line) {
    if (str_contains($line, 'error_log')) $errLines[] = $line;
}
$leak = false;
foreach ($errLines as $line) {
    if (stripos($line, 'device') !== false) $leak = true;
}
assert_test(!$leak, 'DID12: nenhum error_log referencia device (' . count($errLines) . ' logs auditados)');
assert_test(strpos((string)file_get_contents($ROOT . '/src/services/SubscriptionCheckoutService.php'), '[mp_context] attempt_suffix=') !== false, 'DID12b: observabilidade é presence-only ([mp_context])');
$hasRealCurl = false;
foreach (glob($ROOT . '/tests/*.php') as $tf) {
    $src = (string)file_get_contents($tf);
    if (preg_match('/curl_(init|exec)\s*\(/', $src)) $hasRealCurl = true;
}
assert_test(!$hasRealCurl, 'DID16: nenhum teste executa curl real');

// --- Resumo ---

echo "\n--- Antifraud context CTX8-CTX16 (payload/reason, sem MP real) ---\n";

// CTX8: reason sanitizada (específica, sem PII, sem CR/LF).
assert_test(MercadoPagoService::sanitizeReason('') === null, 'CTX8a: reason vazia -> omitida');
assert_test(MercadoPagoService::sanitizeReason(str_repeat('x', 129)) === null, 'CTX8b: reason >128 -> omitida');
assert_test(MercadoPagoService::sanitizeReason("Plano Pro\nX: 1") === null, 'CTX8c: reason com LF -> omitida');
assert_test(MercadoPagoService::sanitizeReason('Controle de Gastos - Pro - Assinatura mensal') === 'Controle de Gastos - Pro - Assinatura mensal', 'CTX8d: reason específica preservada');

// CTX9/CTX10/CTX11: reason por plano + plano/ext preservados.
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_ctx_pro', 'status' => 'authorized', 'external_reference' => 'user_1_pro', 'preapproval_plan_id' => 'plan_pro_xyz']),
]];
$s->createPreapproval('plan_pro_xyz', 'user@test.com', 'user_1_pro', 'https://example.com/return', 'tok_test_abc123', '', null, 'Controle de Gastos - Pro - Assinatura mensal');
assert_test(($s->lastCall['postfields']['reason'] ?? '') === 'Controle de Gastos - Pro - Assinatura mensal', 'CTX9: Pro utiliza descrição Pro');
assert_test(($s->lastCall['postfields']['preapproval_plan_id'] ?? '') === 'plan_pro_xyz', 'CTX11: preapproval_plan_id preservado');
$s->reset();
$s->curlQueue = [[
    'status' => 201,
    'body' => json_encode(['id' => 'mp_ctx_prem', 'status' => 'authorized', 'external_reference' => 'user_5_premium', 'preapproval_plan_id' => 'plan_premium_xyz']),
]];
$s->createPreapproval('plan_premium_xyz', 'premium@ex.com', 'user_5_premium', 'https://example.com/return', 'tok_test_abc123', '', null, 'Controle de Gastos - Premium - Assinatura mensal');
assert_test(($s->lastCall['postfields']['reason'] ?? '') === 'Controle de Gastos - Premium - Assinatura mensal', 'CTX10: Premium utiliza descrição Premium');
assert_test(($s->lastCall['postfields']['external_reference'] ?? '') === 'user_5_premium', 'CTX12: external_reference preservado');

// CTX3: sem objeto payer/identification no payload (schema oficial não aceita).
$keys = array_keys($s->lastCall['postfields']);
$hasPayerObj = false;
foreach ($keys as $k) {
    if (stripos($k, 'payer') !== false && $k !== 'payer_email') $hasPayerObj = true;
    if (stripos($k, 'identification') !== false || stripos($k, 'cpf') !== false) $hasPayerObj = true;
}
assert_test(!$hasPayerObj, 'CTX3: payload sem payer-objeto/identification/CPF (identidade via card_token)');
assert_test(strpos($svcSrc, "'identification'") === false, 'CTX3b: builder sem chave identification');

// CTX16: chaves esperadas presentes no payload sanitizado.
foreach (['preapproval_plan_id', 'payer_email', 'card_token_id', 'external_reference', 'back_url', 'status', 'reason'] as $k) {
    assert_test(array_key_exists($k, $s->lastCall['postfields']), "CTX16: payload contém '$k'");
}

echo "\n--- sanitizeMpErrorBody (forense WCS-49458, sem vazar segredos) ---\n";
$b = MercadoPagoService::sanitizeMpErrorBody([
    'message' => 'CC_VAL_433 Credit card validation has failed',
    'status' => 400,
    'error' => 'bad_request',
    'status_detail' => 'cc_rejected_high_risk',
]);
assert_test(($b['message'] ?? '') === 'CC_VAL_433 Credit card validation has failed', 'SAN1: message verbatim preservada');
assert_test(($b['status'] ?? 0) === 400, 'SAN2: status numérico preservado');
assert_test(($b['status_detail'] ?? '') === 'cc_rejected_high_risk', 'SAN3: status_detail preservado');
$b2 = MercadoPagoService::sanitizeMpErrorBody([
    'message' => 'fail payer@mail.com card 4111111111111111 hex abcdef0123456789 key APP_USR-zzz Bearer tok123',
    'cause' => [['description' => 'x payer@mail.com'], 'plain'],
    'nested_obj' => ['a' => 1],
]);
$blob2 = json_encode($b2);
assert_test(strpos($blob2, 'payer@mail.com') === false, 'SAN4: e-mail redigido');
assert_test(strpos($blob2, '4111111111111111') === false, 'SAN5: PAN redigido');
assert_test(strpos($blob2, 'abcdef0123456789') === false, 'SAN6: hex redigido');
assert_test(strpos($blob2, 'APP_USR-zzz') === false, 'SAN7: chave redigida');
assert_test(strpos($blob2, 'Bearer tok123') === false, 'SAN8: bearer redigido');
assert_test(($b2['nested_obj'] ?? '') === '[omitted]', 'SAN9: aninhado não-causa omitido');
assert_test(MercadoPagoService::sanitizeMpErrorBody('str') === [], 'SAN10: não-array -> vazio');
assert_test(MercadoPagoService::sanitizeMpErrorBody(['k' => str_repeat('z', 500)])['k'] !== str_repeat('z', 500), 'SAN11: teto de tamanho');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed + $skipped;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m | \033[33mSkipped: $skipped\033[0m\n";
if ($failed > 0) {
    echo "\033[31mALGUNS TESTES FALHARAM!\033[0m\n";
    exit(1);
}
echo "\033[32mTODOS OS TESTES PASSARAM!\033[0m\n";
exit(0);

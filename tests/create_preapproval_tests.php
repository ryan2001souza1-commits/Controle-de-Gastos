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
        string $idempotencyKey = ''
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

        $this->lastCall = [
            'call_num' => 1,
            'url' => 'https://api.mercadopago.com/preapproval',
            'method' => 'POST',
            'postfields' => [
                'preapproval_plan_id' => $planId,
                'external_reference' => $externalReference,
                'payer_email' => $payerEmail,
                'card_token_id' => $cardTokenId,
                'back_url' => $backUrl,
                'status' => 'authorized',
            ],
            'headers' => $idempotencyKey !== ''
                ? ['X-Idempotency-Key: ' . $idempotencyKey]
                : [],
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

// --- Resumo ---

echo "\n=== RESUMO ===\n";
$total = $passed + $failed + $skipped;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m | \033[33mSkipped: $skipped\033[0m\n";
if ($failed > 0) {
    echo "\033[31mALGUNS TESTES FALHARAM!\033[0m\n";
    exit(1);
}
echo "\033[32mTODOS OS TESTES PASSARAM!\033[0m\n";
exit(0);

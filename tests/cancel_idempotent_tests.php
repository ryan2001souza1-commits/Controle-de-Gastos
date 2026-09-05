<?php
/**
 * Testes do comportamento idempotente e edge cases do cancelPreapproval().
 *
 * Cobre:
 *  - HTTP 200 normal (cancela)
 *  - HTTP 200 + ja cancelled (already_cancelled)
 *  - HTTP 400 + "cannot modify a cancelled" (idempotente)
 *  - HTTP 400 + outros motivos (erro de verdade)
 *  - HTTP 401/403/404/500 (erro)
 *  - resposta nao-JSON
 *  - network error
 *  - id invalido
 *  - 2 chamadas consecutivas -> estado final cancelled
 *  - sync idempotente (action=cancel chama update local mesmo se already_cancelled)
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

class FakeCurlMpService extends MercadoPagoService
{
    public array $queue = [];
    public array $lastCall = [];

    public function __construct() { $this->accessToken = 'TEST'; }

    protected function curlPut(string $url, array $headers, string $payload, string &$body, int &$httpStatus, string &$curlErr, int $timeout = 30): void
    {
        $this->lastCall = ['url' => $url, 'headers' => $headers, 'payload' => $payload, 'timeout' => $timeout];
        if (empty($this->queue)) {
            $body = '';
            $httpStatus = 0;
            $curlErr = 'no mock';
            return;
        }
        $mock = array_shift($this->queue);
        $body = (string)($mock['body'] ?? '');
        $httpStatus = (int)($mock['status'] ?? 0);
        $curlErr = $mock['curlErr'] ?? '';
    }
}

$svc = new FakeCurlMpService();

echo "--- 1. HTTP 200 + status cancelled (normal) ---\n";
$svc->queue = [['status' => 200, 'body' => json_encode(['id' => 'abc', 'status' => 'cancelled'])]];
$res = $svc->cancelPreapproval('abc123');
assert_test($res['ok'] === true, '1a: ok=true');
assert_test(($res['already_cancelled'] ?? false) === true, '1b: already_cancelled=true');

echo "\n--- 2. HTTP 200 + status authorized (resposta inconsistente) ---\n";
$svc2 = new FakeCurlMpService();
$svc2->queue = [['status' => 200, 'body' => json_encode(['id' => 'abc', 'status' => 'authorized'])]];
$res = $svc2->cancelPreapproval('abc123');
assert_test($res['ok'] === true, '2a: ok=true (PUT aceito)');
assert_test(($res['already_cancelled'] ?? false) === false, '2b: already_cancelled=false');

echo "\n--- 3. HTTP 400 + 'cannot modify a cancelled' -> idempotente ---\n";
$svc3 = new FakeCurlMpService();
$svc3->queue = [['status' => 400, 'body' => json_encode(['message' => 'You can not modify a cancelled preapproval.', 'status' => 400])]];
$res = $svc3->cancelPreapproval('abc123');
assert_test($res['ok'] === true, '3a: ok=true (idempotente)');
assert_test(($res['already_cancelled'] ?? false) === true, '3b: already_cancelled=true');
assert_test(($res['status'] ?? 0) === 400, '3c: status preservado=400');

echo "\n--- 4. HTTP 400 + 'cannot update a cancelled' (variacao) -> idempotente ---\n";
$svc4 = new FakeCurlMpService();
$svc4->queue = [['status' => 400, 'body' => json_encode(['message' => 'You cannot update a cancelled subscription'])]];
$res = $svc4->cancelPreapproval('abc123');
assert_test($res['ok'] === true, '4a: ok=true');
assert_test(($res['already_cancelled'] ?? false) === true, '4b: already_cancelled=true');

echo "\n--- 5. HTTP 400 + motivo diferente (ex: bad payload) -> erro ---\n";
$svc5 = new FakeCurlMpService();
$svc5->queue = [['status' => 400, 'body' => json_encode(['message' => 'Invalid request body', 'status' => 400])]];
$res = $svc5->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '5a: ok=false (erro real)');
assert_test(($res['error'] ?? '') === 'Invalid request body', '5b: error preservado');
assert_test(!isset($res['already_cancelled']), '5c: NAO marca already_cancelled');

echo "\n--- 6. HTTP 401 (auth) -> erro ---\n";
$svc6 = new FakeCurlMpService();
$svc6->queue = [['status' => 401, 'body' => json_encode(['message' => 'unauthorized'])]];
$res = $svc6->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '6a: ok=false');
assert_test(($res['status'] ?? 0) === 401, '6b: status=401');

echo "\n--- 7. HTTP 403 -> erro ---\n";
$svc7 = new FakeCurlMpService();
$svc7->queue = [['status' => 403, 'body' => json_encode(['message' => 'forbidden'])]];
$res = $svc7->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '7a: ok=false');
assert_test(($res['status'] ?? 0) === 403, '7b: status=403');

echo "\n--- 8. HTTP 404 -> erro (not_found) ---\n";
$svc8 = new FakeCurlMpService();
$svc8->queue = [['status' => 404, 'body' => json_encode(['message' => 'not found'])]];
$res = $svc8->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '8a: ok=false');
assert_test(($res['error'] ?? '') === 'not_found', '8b: error=not_found');
assert_test(($res['status'] ?? 0) === 404, '8c: status=404');

echo "\n--- 9. HTTP 500 -> erro ---\n";
$svc9 = new FakeCurlMpService();
$svc9->queue = [['status' => 500, 'body' => json_encode(['message' => 'internal'])]];
$res = $svc9->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '9a: ok=false');
assert_test(($res['status'] ?? 0) === 500, '9b: status=500');

echo "\n--- 10. Resposta nao-JSON ---\n";
$svc10 = new FakeCurlMpService();
$svc10->queue = [['status' => 502, 'body' => '<html>Bad Gateway</html>']];
$res = $svc10->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '10a: ok=false');
assert_test(($res['error'] ?? '') === 'invalid_response', '10b: error=invalid_response');

echo "\n--- 11. Network error (curl) ---\n";
$svc11 = new FakeCurlMpService();
$svc11->queue = [['status' => 0, 'curlErr' => 'Connection timed out']];
$res = $svc11->cancelPreapproval('abc123');
assert_test($res['ok'] === false, '11a: ok=false');
assert_test(($res['error'] ?? '') === 'network_error', '11b: error=network_error');

echo "\n--- 12. ID invalido (regex) ---\n";
$res = $svc->cancelPreapproval('bad id with spaces');
assert_test($res['ok'] === false, '12a: ok=false para id invalido');
assert_test(($res['error'] ?? '') === 'invalid_id', '12b: error=invalid_id');

echo "\n--- 13. ID vazio ---\n";
$res = $svc->cancelPreapproval('');
assert_test($res['ok'] === false, '13a: ok=false');
assert_test(($res['error'] ?? '') === 'invalid_id', '13b: error=invalid_id');

echo "\n--- 14. Duas chamadas consecutivas (1a ok, 2a 400 already cancelled) ---\n";
$svc14 = new FakeCurlMpService();
$svc14->queue = [
    ['status' => 200, 'body' => json_encode(['id' => 'p1', 'status' => 'cancelled'])],
    ['status' => 400, 'body' => json_encode(['message' => 'You can not modify a cancelled preapproval.', 'status' => 400])],
];
$r1 = $svc14->cancelPreapproval('p1');
$r2 = $svc14->cancelPreapproval('p1');
assert_test($r1['ok'] === true, '14a: 1a chamada ok');
assert_test($r2['ok'] === true, '14b: 2a chamada ok (idempotente)');
assert_test(($r2['already_cancelled'] ?? false) === true, '14c: 2a marcada already_cancelled');
assert_test(($r1['already_cancelled'] ?? false) === true, '14d: 1a marcada already_cancelled (status=cancelled)');

echo "\n--- 15. Headers enviados contem Authorization Bearer + Content-Type JSON ---\n";
$svc15 = new FakeCurlMpService();
$svc15->queue = [['status' => 200, 'body' => '{"status":"cancelled"}']];
$svc15->cancelPreapproval('test_id');
$hdrs = $svc15->lastCall['headers'];
$hasAuth = false; $hasCT = false;
foreach ($hdrs as $h) {
    if (stripos($h, 'Authorization: Bearer') === 0) $hasAuth = true;
    if (stripos($h, 'Content-Type: application/json') === 0) $hasCT = true;
}
assert_test($hasAuth, '15a: header Authorization presente');
assert_test($hasCT, '15b: header Content-Type application/json');
assert_test(str_contains($svc15->lastCall['payload'], '"status":"cancelled"'), '15c: payload com status=cancelled');

echo "\n--- 16. URL construida corretamente ---\n";
$svc16 = new FakeCurlMpService();
$svc16->queue = [['status' => 200, 'body' => '{"status":"cancelled"}']];
$svc16->cancelPreapproval('abc-def-test-id');
assert_test(str_contains($svc16->lastCall['url'], '/preapproval/'), '16a: URL tem /preapproval/');

echo "\n=== RESUMO ===\n";
echo "Total: $passed | Passed: $passed | Failed: $failed\n";
exit($failed === 0 ? 0 : 1);

<?php
/**
 * Testes — infraestrutura minima de webhook MP (SEM rede, SEM credenciais).
 *
 * Cobre:
 * - POST valido (com e sem secret configurado)
 * - GET rejeitado (405)
 * - JSON invalido (400)
 * - tipos aceitos (4 topicos)
 * - tipo desconhecido tratado com seguranca (200, sem efeito)
 * - ID em formatos diferentes (data.id, ?data.id, data_id, id)
 * - assinatura valida (200 validado)
 * - assinatura invalida (403) / ausente (401)
 * - secret ausente (modo diagnostico 200, sem efeito)
 * - segredo nao vaza (logs, respostas, arquivos)
 * - nenhuma escrita no banco
 * - nenhum Access Token usado no webhook
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoWebhookHandler.php';

$passed = 0;
$failed = 0;
function mp_wh_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  \033[32m✓\033[0m $name\n";
        $passed++;
    } else {
        echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n";
        $failed++;
    }
}

const MP_WH_SECRET = 'TEST_WEBHOOK_SECRET_FAKE_123';

function mp_wh_code(string $src): string
{
    $out = '';
    foreach (explode("\n", $src) as $line) {
        $t = ltrim($line);
        if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '/*')) {
            continue;
        }
        $out .= $line . "\n";
    }
    return $out;
}
const MP_WH_REQ = 'req-uuid-teste-001';

function mp_wh_valid(string $dataId, string $secret = MP_WH_SECRET, string $req = MP_WH_REQ, string $ts = '1700000000'): array
{
    return [
        'x-signature' => MercadoPagoWebhookHandler::buildTestSignature($dataId, $req, $ts, $secret),
        'x-request-id' => $req,
    ];
}

function mp_wh_body(string $type, string $id): string
{
    return json_encode(['type' => $type, 'data' => ['id' => $id]]);
}

echo "\n=== WEBHOOK MP minimo ===\n\n";

// ------------------------------------------------------------------
// W01: POST valido
// ------------------------------------------------------------------
echo "--- W01: POST valido ---\n";
$res = MercadoPagoWebhookHandler::process('POST', mp_wh_valid('wh_111'), 'data.id=wh_111', mp_wh_body('subscription_preapproval', 'wh_111'), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 200, 'W01a POST valido + assinatura -> 200', json_encode($res));
mp_wh_assert(($res['body']['ok'] ?? false) === true, 'W01b body ok=true');
mp_wh_assert(($res['log']['validated'] ?? false) === true, 'W01c log validated=true');

// ------------------------------------------------------------------
// W02: GET rejeitado
// ------------------------------------------------------------------
echo "\n--- W02: metodo inadequado ---\n";
foreach (['GET', 'PUT', 'DELETE', 'HEAD'] as $m) {
    $res = MercadoPagoWebhookHandler::process($m, [], '', '', MP_WH_SECRET);
    mp_wh_assert($res['http_code'] === 405, "W02 $m -> 405");
}

// ------------------------------------------------------------------
// W03: JSON invalido
// ------------------------------------------------------------------
echo "\n--- W03: payload invalido ---\n";
$res = MercadoPagoWebhookHandler::process('POST', [], '', 'NAO-JSON{{{', MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 400, 'W03a JSON invalido -> 400');
$res = MercadoPagoWebhookHandler::process('POST', [], '', '', MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 400, 'W03b corpo vazio -> 400');
$res = MercadoPagoWebhookHandler::process('POST', [], '', '[]', MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 400, 'W03c JSON nao-objeto -> 400');

// ------------------------------------------------------------------
// W04/W05: tipos aceitos vs desconhecido
// ------------------------------------------------------------------
echo "\n--- W04/W05: tipos ---\n";
foreach (MercadoPagoWebhookHandler::ALLOWED_TYPES as $t) {
    $res = MercadoPagoWebhookHandler::process('POST', mp_wh_valid('id_' . $t), 'data.id=id_' . $t, mp_wh_body($t, 'id_' . $t), MP_WH_SECRET);
    mp_wh_assert($res['http_code'] === 200 && ($res['log']['known_type'] ?? false) === true, "W04 tipo aceito: $t");
}
$res = MercadoPagoWebhookHandler::process('POST', [], '', json_encode(['type' => 'topic_inexistente_xyz']), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 200, 'W05a tipo desconhecido -> 200 seguro');
mp_wh_assert(($res['body']['handled'] ?? true) === false, 'W05b desconhecido sem efeito (handled=false)');
mp_wh_assert(($res['log']['known_type'] ?? true) === false, 'W05c log marca known_type=false');

// ------------------------------------------------------------------
// W06: ID em formatos diferentes
// ------------------------------------------------------------------
echo "\n--- W06: extracao defensiva de ID ---\n";
mp_wh_assert(MercadoPagoWebhookHandler::extractId(['data' => ['id' => 'abc123']], '') === 'abc123', 'W06a data.id no body');
mp_wh_assert(MercadoPagoWebhookHandler::extractId([], 'data.id=qry456') === 'qry456', 'W06b ?data.id na query (dot->underscore)');
mp_wh_assert(MercadoPagoWebhookHandler::extractId([], 'data_id=qry789') === 'qry789', 'W06c ?data_id na query');
mp_wh_assert(MercadoPagoWebhookHandler::extractId(['data_id' => 'b111'], '') === 'b111', 'W06d data_id no body');
mp_wh_assert(MercadoPagoWebhookHandler::extractId(['id' => 'c222'], '') === 'c222', 'W06e id raiz no body');
mp_wh_assert(MercadoPagoWebhookHandler::extractId([], '') === null, 'W06f ausente -> null');
mp_wh_assert(MercadoPagoWebhookHandler::extractId(['data' => ['id' => 'a b!']], '') === null, 'W06g formato invalido -> null');

// ------------------------------------------------------------------
// W07/W08: assinatura valida/invalida
// ------------------------------------------------------------------
echo "\n--- W07/W08: HMAC ---\n";
$headers = mp_wh_valid('sig_ok_1');
$res = MercadoPagoWebhookHandler::process('POST', $headers, 'data.id=sig_ok_1', mp_wh_body('payment', 'sig_ok_1'), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 200 && ($res['log']['validated'] ?? false) === true, 'W07 assinatura valida -> 200 validado');

$bad = $headers;
$bad['x-signature'] = 'ts=1700000000,v1=' . str_repeat('0', 64);
$res = MercadoPagoWebhookHandler::process('POST', $bad, 'data.id=sig_ok_1', mp_wh_body('payment', 'sig_ok_1'), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 403, 'W08a assinatura invalida -> 403');

$res = MercadoPagoWebhookHandler::process('POST', ['x-request-id' => MP_WH_REQ], 'data.id=sig_ok_1', mp_wh_body('payment', 'sig_ok_1'), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 401, 'W08b assinatura ausente -> 401');

$wrongSecret = mp_wh_valid('sig_ok_1', 'OUTRO_SECRET');
$res = MercadoPagoWebhookHandler::process('POST', $wrongSecret, 'data.id=sig_ok_1', mp_wh_body('payment', 'sig_ok_1'), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 403, 'W08c secret trocado -> 403');

$res = MercadoPagoWebhookHandler::process('POST', mp_wh_valid('id_a'), 'data.id=id_b', mp_wh_body('payment', 'id_a'), MP_WH_SECRET);
mp_wh_assert($res['http_code'] === 403, 'W08d data.id query divergente do body -> 403');

// ------------------------------------------------------------------
// W09: secret ausente (modo diagnostico, sem efeito)
// ------------------------------------------------------------------
echo "\n--- W09: secret ausente ---\n";
$res = MercadoPagoWebhookHandler::process('POST', [], 'data.id=zz1', mp_wh_body('payment', 'zz1'), '');
mp_wh_assert($res['http_code'] === 200, 'W09a sem secret -> 200 diagnostico');
mp_wh_assert(($res['body']['handled'] ?? true) === false, 'W09b sem secret sem efeito');
mp_wh_assert(($res['log']['validated'] ?? true) === false, 'W09c log validated=false');

// ------------------------------------------------------------------
// W10/W11/W12: segredo nao vaza, sem banco, sem Access Token
// ------------------------------------------------------------------
echo "\n--- W10/W11/W12: seguranca estatica ---\n";
$handlerSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoWebhookHandler.php');
$endpointSrc = (string)@file_get_contents($ROOT . '/public/mercadopago_webhook.php');

mp_wh_assert(file_exists($ROOT . '/public/mercadopago_webhook.php'), 'W10a endpoint existe');
mp_wh_assert(strpos($endpointSrc, '$_SERVER[\'REQUEST_METHOD\']') !== false || strpos($endpointSrc, '$_SERVER["REQUEST_METHOD"]') !== false, 'W10b endpoint le metodo');
mp_wh_assert(strpos($endpointSrc, 'php://input') !== false, 'W10c endpoint le body bruto');
mp_wh_assert(strpos($endpointSrc, 'MERCADOPAGO_WEBHOOK_SECRET') !== false, 'W10d endpoint le secret do ambiente');

// segredo nao aparece em respostas nem logs do handler
$res = MercadoPagoWebhookHandler::process('POST', $bad, 'data.id=sig_ok_1', mp_wh_body('payment', 'sig_ok_1'), MP_WH_SECRET);
$dump = json_encode($res);
mp_wh_assert(!str_contains($dump, MP_WH_SECRET), 'W10e resposta/log sem secret');
mp_wh_assert(!str_contains(mp_wh_code($handlerSrc), 'MERCADOPAGO_ACCESS_TOKEN'), 'W11a handler NAO usa Access Token');
mp_wh_assert(!str_contains(mp_wh_code($endpointSrc), 'MERCADOPAGO_ACCESS_TOKEN'), 'W11b endpoint NAO usa Access Token');
mp_wh_assert(!str_contains($handlerSrc, 'getDBConnection') && !str_contains($endpointSrc, 'getDBConnection'), 'W12a sem conexao DB');
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'CREATE TABLE', 'ALTER TABLE'] as $kw) {
    $inHandler = stripos($handlerSrc, $kw) !== false;
    $inEndpoint = stripos($endpointSrc, $kw) !== false;
    mp_wh_assert(!$inHandler && !$inEndpoint, "W12b sem '$kw' no webhook");
}
// endpoint standalone: sem require de config/banco/sessao
mp_wh_assert(strpos($endpointSrc, 'config.php') === false, 'W12c endpoint sem config.php');
mp_wh_assert(strpos($endpointSrc, 'session_start') === false, 'W12d endpoint sem sessao');
// retorno nao ativa plano: webhook nao referencia plano/ativacao
mp_wh_assert(stripos($handlerSrc . $endpointSrc, 'UPDATE usuarios SET plano') === false, 'W12e webhook nao ativa plano');
$whCodeAll = mp_wh_code($handlerSrc . "\n" . $endpointSrc);
mp_wh_assert(stripos($whCodeAll, 'premium') === false && stripos($whCodeAll, 'plano_status') === false && stripos($whCodeAll, 'ativar') === false, 'W12f webhook sem logica de plano');

echo "\n=== RESUMO WEBHOOK ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

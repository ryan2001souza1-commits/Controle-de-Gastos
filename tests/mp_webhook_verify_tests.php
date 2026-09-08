<?php
/**
 * Testes — verificacao autenticada do webhook MP (SEM rede, SEM credenciais).
 *
 * Fluxo: webhook subscription_preapproval com x-signature valida
 * => GET /preapproval/{ID} na API oficial (fonte de verdade) =>
 * resultado estruturado, SEM alterar banco (nenhum status ativa plano).
 *
 * Cobre:
 * - signature valida -> pode consultar API
 * - signature invalida -> ZERO chamadas API
 * - GET /preapproval/{id} correto (metodo, URL, Bearer sem vazamento)
 * - authorized/pending/paused/cancelled reconhecidos
 * - plan ID Pro -> pro / Premium -> premium / desconhecido rejeitado
 * - external_reference valida/invalida, user_id invalido, divergencia
 * - external_reference ausente/vazia => verificada, NAO correlacionada
 * - payer_email/payer_id jamais identificam usuario
 * - ID retornado diferente, JSON invalido
 * - HTTP 401/403/404/429/500, timeout, token ausente
 * - nenhum INSERT/UPDATE/DELETE/migration/ativacao
 * - nenhum segredo em logs/respostas
 * - checkout hospedado continua funcionando
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoWebhookHandler.php';
require_once $ROOT . '/src/services/MercadoPagoSubscriptionVerifier.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookProcessor.php';

$passed = 0;
$failed = 0;
function mp_vfy_assert(bool $cond, string $name, string $detail = ''): void
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

const MP_VFY_TOKEN = 'TEST_MP_ACCESS_TOKEN_FAKE';
const MP_VFY_PRO = 'TEST_PLAN_PRO_1';
const MP_VFY_PREM = 'TEST_PLAN_PREM_2';
const MP_VFY_SECRET = 'TEST_WH_SECRET_FAKE';
const MP_VFY_REQ = 'req-verify-001';
const MP_VFY_TS = '1700000000';

function mp_vfy_headers(string $dataId, string $secret = MP_VFY_SECRET): array
{
    return [
        'x-signature' => MercadoPagoWebhookHandler::buildTestSignature($dataId, MP_VFY_REQ, MP_VFY_TS, $secret),
        'x-request-id' => MP_VFY_REQ,
    ];
}

function mp_vfy_body(string $id): string
{
    return json_encode(['type' => 'subscription_preapproval', 'data' => ['id' => $id]]);
}

function mp_vfy_api(string $id, string $status, string $planId, $ref, array $extra = []): string
{
    $payload = [
        'id' => $id,
        'status' => $status,
        'preapproval_plan_id' => $planId,
    ];
    if ($ref !== null) {
        $payload['external_reference'] = $ref;
    }
    return json_encode($payload + $extra);
}

/** Verificador com HTTP mockado (conta chamadas e captura requisicao). */
function mp_vfy_verifier(?array &$call, ?int &$calls, $apiRes): MercadoPagoSubscriptionVerifier
{
    $calls = 0;
    return new MercadoPagoSubscriptionVerifier(
        function (string $method, string $url, array $headers) use (&$call, &$calls, $apiRes) {
            $calls++;
            $call = ['method' => $method, 'url' => $url, 'headers' => $headers];
            return is_callable($apiRes) ? $apiRes() : $apiRes;
        },
        MP_VFY_TOKEN,
        MP_VFY_PRO,
        MP_VFY_PREM
    );
}

function mp_vfy_code(string $src): string
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

echo "\n=== VERIFY webhook subscription_preapproval ===\n\n";

// ------------------------------------------------------------------
// V01: signature valida -> consulta API (URL, metodo, Bearer corretos)
// ------------------------------------------------------------------
echo "--- V01: gate valido consulta API ---\n";
$call = null;
$calls = 0;
$apiOk = ['http_code' => 200, 'body' => mp_vfy_api('sub_100', 'authorized', MP_VFY_PRO, 'user_12_pro'), 'error' => ''];
$verifier = mp_vfy_verifier($call, $calls, $apiOk);
$res = MercadoPagoWebhookProcessor::processNotification('POST', mp_vfy_headers('sub_100'), 'data.id=sub_100', mp_vfy_body('sub_100'), MP_VFY_SECRET, $verifier);
mp_vfy_assert($calls === 1, 'V01a signature valida -> 1 chamada API');
mp_vfy_assert(($call['method'] ?? '') === 'GET', 'V01b metodo GET', json_encode($call));
mp_vfy_assert(($call['url'] ?? '') === 'https://api.mercadopago.com/preapproval/sub_100', 'V01c URL = /preapproval/{id}', json_encode($call));
$authHeader = null;
foreach ($call['headers'] ?? [] as $h) {
    if (stripos($h, 'Authorization: Bearer ') === 0) {
        $authHeader = $h;
    }
}
mp_vfy_assert($authHeader !== null, 'V01d header Authorization Bearer enviado');
// Redacao no seam de teste: o mock NUNCA recebe o token real.
mp_vfy_assert($authHeader === 'Authorization: Bearer ***REDACTED***', 'V01d2 token redigido para o handler (sem vazamento)');
$refProp = new ReflectionProperty(MercadoPagoSubscriptionVerifier::class, 'accessToken');
$refProp->setAccessible(true);
mp_vfy_assert($refProp->getValue($verifier) === MP_VFY_TOKEN, 'V01d3 token correto chega ao verificador');
mp_vfy_assert($res['http_code'] === 200 && ($res['body']['verified_subscription'] ?? false) === true, 'V01e 200 verificado', json_encode($res['body']));
mp_vfy_assert(($res['body']['correlated_user'] ?? false) === true, 'V01e2 correlacionado (ref valida)');
mp_vfy_assert(($res['body']['handled'] ?? true) === false, 'V01f handled=false (nada ativado)');
mp_vfy_assert(!str_contains(json_encode($res), MP_VFY_TOKEN) && !str_contains(json_encode($res), MP_VFY_SECRET), 'V01g resposta sem segredos');

// ------------------------------------------------------------------
// V02: signature invalida/ausente -> ZERO chamadas API
// ------------------------------------------------------------------
echo "\n--- V02: gate invalido nao consulta ---\n";
$call = null;
$calls = 0;
$verifier = mp_vfy_verifier($call, $calls, $apiOk);
$res = MercadoPagoWebhookProcessor::processNotification(
    'POST',
    ['x-signature' => 'ts=1700000000,v1=' . str_repeat('0', 64), 'x-request-id' => MP_VFY_REQ],
    'data.id=sub_100',
    mp_vfy_body('sub_100'),
    MP_VFY_SECRET,
    $verifier
);
mp_vfy_assert($calls === 0, 'V02a assinatura invalida -> 0 chamadas API');
mp_vfy_assert($res['http_code'] === 403, 'V02b mantem 403 do gate');

$calls = 0;
$res = MercadoPagoWebhookProcessor::processNotification('POST', [], 'data.id=sub_100', mp_vfy_body('sub_100'), MP_VFY_SECRET, $verifier);
mp_vfy_assert($calls === 0, 'V02c assinatura ausente -> 0 chamadas API');
mp_vfy_assert($res['http_code'] === 401, 'V02d mantem 401 do gate');

$calls = 0;
$res = MercadoPagoWebhookProcessor::processNotification('POST', mp_vfy_headers('sub_100'), 'data.id=sub_100', mp_vfy_body('sub_100'), '', $verifier);
mp_vfy_assert($calls === 0, 'V02e secret ausente -> 0 chamadas API (modo diagnostico)');

// outros topicos validos nao verificam nesta etapa
foreach (['subscription_authorized_payment', 'subscription_preapproval_plan', 'payment'] as $t) {
    $calls = 0;
    $b = json_encode(['type' => $t, 'data' => ['id' => 'x9']]);
    $h = mp_vfy_headers('x9');
    $res = MercadoPagoWebhookProcessor::processNotification('POST', $h, 'data.id=x9', $b, MP_VFY_SECRET, $verifier);
    mp_vfy_assert($calls === 0, "V02f tipo $t nao consulta API nesta etapa");
    mp_vfy_assert($res['http_code'] === 200, "V02g tipo $t mantem 200 seguro");
}

// ------------------------------------------------------------------
// V03: status reconhecidos
// ------------------------------------------------------------------
echo "\n--- V03: status ---\n";
foreach (['authorized', 'pending', 'paused', 'cancelled'] as $st) {
    $call = null;
    $calls = 0;
    $v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('s_' . $st, $st, MP_VFY_PRO, 'user_7_pro'), 'error' => '']);
    $r = $v->verify('s_' . $st);
    mp_vfy_assert($r['verified_subscription'] === true && $r['correlated_user'] === true && $r['status'] === $st && $r['retryable'] === false, "V03 status $st reconhecido");
}
$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('s_x', 'weird_status', MP_VFY_PRO, 'user_7_pro'), 'error' => '']);
$r = $v->verify('s_x');
mp_vfy_assert($r['verified_subscription'] === false && $r['correlated_user'] === false && $r['reason'] === 'unknown_status', 'V03e status desconhecido rejeitado');

// ------------------------------------------------------------------
// V04: plano por preapproval_plan_id + external_reference
// ------------------------------------------------------------------
echo "\n--- V04: plano e referencia ---\n";
$call = null;
$calls = 0;
$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('a1', 'authorized', MP_VFY_PRO, 'user_12_pro'), 'error' => '']);
$r = $v->verify('a1');
mp_vfy_assert($r['verified_subscription'] === true && $r['correlated_user'] === true && $r['plan_slug'] === 'pro' && $r['user_id'] === 12 && $r['subscription_id'] === 'a1', 'V04a plan PRO -> pro, user 12');

$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('a2', 'pending', MP_VFY_PREM, 'user_3_premium'), 'error' => '']);
$r = $v->verify('a2');
mp_vfy_assert($r['verified_subscription'] === true && $r['correlated_user'] === true && $r['plan_slug'] === 'premium' && $r['user_id'] === 3, 'V04b plan PREMIUM -> premium, user 3');

$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('a3', 'authorized', 'PLAN_DESCONHECIDO', 'user_3_pro'), 'error' => '']);
$r = $v->verify('a3');
mp_vfy_assert($r['verified_subscription'] === false && $r['correlated_user'] === false && $r['reason'] === 'plan_unknown', 'V04c plan ID desconhecido rejeitado');

foreach (['lixo', 'user_12', 'user__pro', 'user_-5_pro', 'user_12_gold', 'user_12_pro_extra', 'ext-999'] as $badRef) {
    $v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('a4', 'authorized', MP_VFY_PRO, $badRef), 'error' => '']);
    $r = $v->verify('a4');
    mp_vfy_assert($r['verified_subscription'] === false && $r['correlated_user'] === false && $r['reason'] === 'bad_reference', "V04d ref inesperada '$badRef' rejeitada");
}
$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('a4b', 'authorized', MP_VFY_PRO, 'user_0_pro'), 'error' => '']);
$r = $v->verify('a4b');
mp_vfy_assert($r['verified_subscription'] === false && $r['user_id'] === null && $r['reason'] === 'invalid_user', 'V04d2 user_id zero rejeitado');

// divergencia: plan PRO + ref premium
$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('a5', 'authorized', MP_VFY_PRO, 'user_12_premium'), 'error' => '']);
$r = $v->verify('a5');
mp_vfy_assert($r['verified_subscription'] === false && $r['reason'] === 'divergence', 'V04e divergencia plano/ref NAO processada');

// ID retornado diferente do solicitado
$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('OUTRO_ID', 'authorized', MP_VFY_PRO, 'user_12_pro'), 'error' => '']);
$r = $v->verify('a5');
mp_vfy_assert($r['verified_subscription'] === false && $r['reason'] === 'id_mismatch', 'V04f ID divergente rejeitado');

// ------------------------------------------------------------------
// V04x: external_reference ausente/vazia => verificada, NAO correlacionada
// (o checkout hospedado atual nao define referencia — nada e inventado)
// ------------------------------------------------------------------
echo "\n--- V04x: sem referencia (checkout hospedado) ---\n";
$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('h1', 'authorized', MP_VFY_PRO, null), 'error' => '']);
$r = $v->verify('h1');
mp_vfy_assert($r['verified_subscription'] === true && $r['correlated_user'] === false && $r['user_id'] === null, 'V04x1 ref ausente => verificada, sem usuario');
mp_vfy_assert($r['plan_slug'] === 'pro' && $r['status'] === 'authorized' && $r['reason'] === 'uncorrelated', 'V04x2 plano/status preservados, reason=uncorrelated');

$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('h2', 'pending', MP_VFY_PREM, ''), 'error' => '']);
$r = $v->verify('h2');
mp_vfy_assert($r['verified_subscription'] === true && $r['correlated_user'] === false && $r['user_id'] === null, 'V04x3 ref vazia => verificada, sem usuario');

$v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('h3', 'authorized', MP_VFY_PRO, '   '), 'error' => '']);
$r = $v->verify('h3');
mp_vfy_assert($r['verified_subscription'] === true && $r['correlated_user'] === false, 'V04x4 ref so-espacos => verificada, sem usuario');

// endpoint: uncorrelated responde 200 sem user_id e sem ativar nada
$call = null;
$calls = 0;
$apiNoRef = ['http_code' => 200, 'body' => mp_vfy_api('h9', 'authorized', MP_VFY_PRO, null), 'error' => ''];
$proc = MercadoPagoWebhookProcessor::processNotification(
    'POST', mp_vfy_headers('h9'), 'data.id=h9', mp_vfy_body('h9'), MP_VFY_SECRET,
    mp_vfy_verifier($call, $calls, $apiNoRef)
);
mp_vfy_assert($proc['http_code'] === 200, 'V04x5 endpoint 200 para uncorrelated');
mp_vfy_assert(($proc['body']['verified_subscription'] ?? false) === true && ($proc['body']['correlated_user'] ?? true) === false, 'V04x6 body A=true B=false');
mp_vfy_assert(!array_key_exists('user_id', $proc['body']), 'V04x7 body sem user_id inventado');
mp_vfy_assert(($proc['body']['handled'] ?? true) === false, 'V04x8 nada ativado (handled=false)');

// jamais inferir usuario por e-mail ou payer_id
foreach ([
    ['payer_email' => 'alguem@exemplo.com'],
    ['payer' => ['email' => 'alguem@exemplo.com', 'id' => '999']],
    ['payer_id' => '999'],
    ['payer_email' => 'user_12_pro'],
] as $i => $xtra) {
    $v = mp_vfy_verifier($call, $calls, ['http_code' => 200, 'body' => mp_vfy_api('p' . $i, 'authorized', MP_VFY_PRO, null, $xtra), 'error' => '']);
    $r = $v->verify('p' . $i);
    mp_vfy_assert($r['user_id'] === null && $r['correlated_user'] === false && $r['verified_subscription'] === true, "V04x9 payer nunca vira user_id (caso $i)");
}

// parse unitario
$pr = MercadoPagoSubscriptionVerifier::parseExternalReference('user_12_pro');
mp_vfy_assert($pr === ['user_id' => 12, 'plan_slug' => 'pro'], 'V04g parse user_12_pro');
mp_vfy_assert(MercadoPagoSubscriptionVerifier::parseExternalReference('user_0_pro') === null, 'V04h user_id zero invalido');
mp_vfy_assert(MercadoPagoSubscriptionVerifier::resolvePlanById(MP_VFY_PRO, MP_VFY_PRO, MP_VFY_PREM) === 'pro', 'V04i resolve pro');
mp_vfy_assert(MercadoPagoSubscriptionVerifier::resolvePlanById('XXX', MP_VFY_PRO, MP_VFY_PREM) === null, 'V04j resolve desconhecido=null');
mp_vfy_assert(MercadoPagoSubscriptionVerifier::isValidPreapprovalId('abc-123_X') === true, 'V04k ID valido aceito');
mp_vfy_assert(MercadoPagoSubscriptionVerifier::isValidPreapprovalId('a b!') === false, 'V04l ID inseguro rejeitado');
mp_vfy_assert(MercadoPagoSubscriptionVerifier::buildPreapprovalUrl('x1') === 'https://api.mercadopago.com/preapproval/x1', 'V04m URL fixa + ID');

// ------------------------------------------------------------------
// V05: erros HTTP / transporte / token
// ------------------------------------------------------------------
echo "\n--- V05: falhas ---\n";
function mp_vfy_fail(string $name, $apiRes, string $expectReason, bool $expectRetry, int $expectHttp): void
{
    $call = null;
    $calls = 0;
    $v = mp_vfy_verifier($call, $calls, $apiRes);
    $r = $v->verify('sub_1');
    mp_vfy_assert($r['verified_subscription'] === false, "$name nao verificado");
    mp_vfy_assert($r['reason'] === $expectReason, "$name reason=$expectReason (veio {$r['reason']})");
    mp_vfy_assert($r['retryable'] === $expectRetry, "$name retryable=" . ($expectRetry ? 'true' : 'false'));
    // mapeamento no processador (endpoint)
    $proc = MercadoPagoWebhookProcessor::processNotification(
        'POST', mp_vfy_headers('sub_1'), 'data.id=sub_1', mp_vfy_body('sub_1'), MP_VFY_SECRET,
        mp_vfy_verifier($call, $calls, $apiRes)
    );
    mp_vfy_assert($proc['http_code'] === $expectHttp, "$name HTTP $expectHttp (veio {$proc['http_code']})");
    mp_vfy_assert(!str_contains(json_encode($proc), MP_VFY_TOKEN), "$name resposta sem token");
}

mp_vfy_fail('V05a JSON invalido', ['http_code' => 200, 'body' => 'NAO-JSON{{{', 'error' => ''], 'invalid_json', false, 200);
mp_vfy_fail('V05b HTTP 401', ['http_code' => 401, 'body' => '{"message":"unauthorized"}', 'error' => ''], 'http_error', true, 500);
mp_vfy_fail('V05c HTTP 403', ['http_code' => 403, 'body' => 'forbidden', 'error' => ''], 'http_error', true, 500);
mp_vfy_fail('V05d HTTP 404', ['http_code' => 404, 'body' => '{"message":"not found"}', 'error' => ''], 'http_error', true, 500);
mp_vfy_fail('V05e HTTP 429', ['http_code' => 429, 'body' => 'rate limited', 'error' => ''], 'http_error', true, 500);
mp_vfy_fail('V05f HTTP 500', ['http_code' => 500, 'body' => 'erro', 'error' => ''], 'http_error', true, 500);
mp_vfy_fail('V05g timeout', ['http_code' => 0, 'body' => '', 'error' => 'Operation timed out'], 'timeout', true, 500);

// token ausente -> temporal, sem chamada
$call = null;
$calls = 0;
$v = new MercadoPagoSubscriptionVerifier(function () use (&$calls) {
    $calls++;
    return ['http_code' => 200, 'body' => '{}', 'error' => ''];
}, '', MP_VFY_PRO, MP_VFY_PREM);
$r = $v->verify('sub_1');
mp_vfy_assert($calls === 0, 'V05h token ausente -> 0 chamadas API');
mp_vfy_assert($r['verified_subscription'] === false && $r['reason'] === 'missing_token' && $r['retryable'] === true, 'V05i token ausente temporal');

// ID invalido -> sem chamada
$calls = 0;
$r = $v->verify('id ruim!');
mp_vfy_assert($calls === 0, 'V05j ID inseguro -> 0 chamadas API (anti-SSRF)');

// ------------------------------------------------------------------
// V06: estatico — sem banco/migration/ativacao/segredos; checkout ok
// ------------------------------------------------------------------
echo "\n--- V06: estatico ---\n";
$vfySrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoSubscriptionVerifier.php');
$procSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoWebhookProcessor.php');
$epSrc = (string)@file_get_contents($ROOT . '/public/mercadopago_webhook.php');
$vfyCode = mp_vfy_code($vfySrc);
$procCode = mp_vfy_code($procSrc);
$epCode = mp_vfy_code($epSrc);
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'CREATE TABLE', 'ALTER TABLE', 'CREATE INDEX'] as $kw) {
    mp_vfy_assert(stripos($vfyCode, $kw) === false && stripos($procCode, $kw) === false, "V06a sem '$kw' no verify/processador");
}
mp_vfy_assert(strpos($epCode, 'getDBConnection') === false && strpos($epCode, 'INSERT INTO') === false && strpos($epCode, 'UPDATE ') === false, 'V06b endpoint sem banco');
mp_vfy_assert(stripos($vfyCode . $procCode . $epCode, 'UPDATE usuarios SET plano') === false, 'V06c nenhuma ativacao de plano');
mp_vfy_assert(stripos($vfyCode . $procCode, 'CardForm') === false && stripos($vfyCode . $procCode, 'mercadopago.js') === false, 'V06d sem CardForm/MP.js');
mp_vfy_assert(strpos($vfyCode, 'MERCADOPAGO_PUBLIC_KEY') === false, 'V06e sem Public Key');
mp_vfy_assert(strpos($vfyCode, "MERCADOPAGO_ACCESS_TOKEN") === false, 'V06f verifier nao le token direto (via starter/fromEnv)');
mp_vfy_assert(strpos($vfyCode, 'https://api.mercadopago.com/preapproval/') !== false, 'V06g base da API fixa + TLS');
mp_vfy_assert(strpos($vfyCode, 'TIMEOUT_SECONDS = 7') !== false, 'V06h timeout 7s/3s compativel Vercel');
mp_vfy_assert(strpos($vfyCode, 'correlated_user') !== false, 'V06h2 resultado distingue correlacao');
mp_vfy_assert(preg_match('/payer_email|payer_id/', $vfyCode) !== 1, 'V06h3 payer nunca identifica usuario');
// checkout hospedado continua funcionando
mp_vfy_assert(MercadoPagoCheckoutStarter::buildPlanUrl('P1') === 'https://api.mercadopago.com/preapproval_plan/P1', 'V06i checkout intacto (buildPlanUrl)');
mp_vfy_assert(method_exists('MercadoPagoCheckoutStarter', 'resolveCheckoutUrl'), 'V06j checkout intacto (resolveCheckoutUrl)');

echo "\n=== RESUMO VERIFY ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

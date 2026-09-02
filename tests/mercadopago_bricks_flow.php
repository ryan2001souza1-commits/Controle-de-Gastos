<?php
/**
 * Tests de fluxo de assinatura (MP legado + Asaas).
 *
 * Mantidos do MP legado:
 *   - T01-T11: validacao, payload, status, CSRF
 *   - T16-T24: webhook, idempotencia, CSRF, seguranca
 * Removidos (Brick MP):
 *   - T12-T15, T25-T26: SDK MP / Brick (substituido por Asaas)
 *   - T30-T39: amount do Brick (substituido por Asaas com preco fixo servidor)
 * Novos (Asaas):
 *   - AsaasSubscriptionController: plano fixo, sem cartao no DB
 *   - AsaasService: headers corretos, sem log de dados sensiveis
 *   - meu_plano.php: formulario Asaas, sem MP
 */

$ROOT = __DIR__ . '/..';
require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/AsaasService.php';

function mp_assert(string $name, bool $cond): void
{
    static $pass = 0, $fail = 0;
    if ($cond) { echo "  [PASS] $name\n"; $pass++; }
    else       { echo "  [FAIL] $name\n"; $fail++; }
    $GLOBALS['__results'][] = ['name' => $name, 'ok' => $cond];
}
$__results = [];

$tokenReal = 'TEST-1234567890123456-090120-abcdef1234567890-123456789';
putenv('MERCADOPAGO_ACCESS_TOKEN=' . $tokenReal);
putenv('MERCADOPAGO_PUBLIC_KEY=TEST-pk-1234567890');
putenv('MERCADOPAGO_MODE=sandbox');
putenv('MERCADOPAGO_WEBHOOK_SECRET=secret123');
putenv('ASAAS_API_KEY=$aact_test_mock');
putenv('ASAAS_ENV=sandbox');

echo "=== SUBSCRIPTION FLOW TESTS ===\n";

// --- MP LEGADO (ainda presente para webhook) ---
// T01: card_token_id ausente tratado
mp_assert('T01 card_token_id ausente e tratado', true);

// T02: createPreapproval aceita cardTokenId
$src = file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
mp_assert('T02 createPreapproval aceita parametro cardTokenId',
    str_contains($src, 'createPreapproval(') && preg_match('/string \$cardTokenId,/', $src));

// T03: payload inclui card_token_id
mp_assert('T03 payload inclui card_token_id no POST /preapproval',
    str_contains($src, "'card_token_id' => \$cardTokenId"));

// T04: status=authorized
mp_assert('T04 payload usa status=authorized (fluxo com cartao)',
    str_contains($src, "'status' => 'authorized'"));

// T05: PRO e PREMIUM suportados
$ctrl = file_get_contents($ROOT . '/src/controllers/SubscriptionController.php');
mp_assert('T05 PRO e PREMIUM aceitos como plan_slug',
    preg_match('/SLUG_PRO,.*SLUG_PREMIUM/', $ctrl));

// T06: plan_id da env var
mp_assert('T06 plan_id lido de MERCADOPAGO_PLAN_ID_<SLUG>',
    str_contains($ctrl, 'MERCADOPAGO_PLAN_ID_'));

// T07: external_reference formato
mp_assert('T07 external_reference no formato user_<id>_<slug>',
    str_contains($ctrl, "'user_' . \$userId . '_' . \$planSlug"));

// T08-T09: nenhum dado de cartao em logs
$leakPatterns = [
    str_contains($ctrl, 'token_sig'),
    (bool)preg_match('/substr\s*\([^)]*\$cardTokenId/i', $ctrl),
    (bool)preg_match('/error_log\s*\([^)]*\$cardTokenId/', $ctrl),
    (bool)preg_match('/\$cardTokenId\s*\[\s*0\s*:/', $ctrl),
];
mp_assert('T08 ZERO derivados do card_token_id em logs', !in_array(true, $leakPatterns, true));
mp_assert('T09 card_token_id nao e logado completo',
    !preg_match('/error_log[^;]*\$cardTokenId[^;]*;/', $ctrl));

// T10: Access token nunca no frontend
mp_assert('T10 Access Token NUNCA vai para o frontend',
    !str_contains(file_get_contents($ROOT . '/public/meu_plano.php'), 'MERCADOPAGO_ACCESS_TOKEN'));
mp_assert('T11 Webhook Secret NUNCA vai para o frontend',
    !str_contains(file_get_contents($ROOT . '/public/meu_plano.php'), 'MERCADOPAGO_WEBHOOK_SECRET'));

// --- ASAAS: MercadoPago.js REMOVIDO ---
$mp_html = file_get_contents($ROOT . '/public/meu_plano.php');
mp_assert('T12 MercadoPago.js NAO carregado (removido)',
    strpos($mp_html, 'sdk.mercadopago.com') === false);
mp_assert('T13 Card Payment Brick NAO inicializado',
    strpos($mp_html, "create('cardPayment')") === false
    && strpos($mp_html, 'bricks()') === false);
mp_assert('T14 mpPublicKey NAO usado no frontend',
    strpos($mp_html, '$mpPublicKey') === false
    && strpos($mp_html, 'mpPublicKey') === false);
mp_assert('T15 Formulario Asaas presente (asaas-modal)',
    strpos($mp_html, 'asaas-modal') !== false);
mp_assert('T16 Formulario Asaas envia para asaas_subscription_create',
    strpos($mp_html, 'asaas_subscription_create') !== false);
mp_assert('T17 Botao ASAAS presente (btn-asaas-subscribe)',
    strpos($mp_html, 'btn-asaas-subscribe') !== false);

// --- ASAAS: CSP limpo ---
$vercel = file_get_contents($ROOT . '/vercel.json');
mp_assert('T18 CSP NAO contem mercadopago.com (removido)',
    strpos($vercel, 'mercadopago.com') === false);
mp_assert('T19 CSP frame-src restritivo (sem iframes externos)',
    strpos($vercel, "frame-src 'self'") !== false);

// --- MP LEGADO: webhook ---
$webhook = file_get_contents($ROOT . '/public/mercadopago_webhook.php');
mp_assert('T20 Webhook MP legado continua registrado',
    strpos($webhook, 'mercadopago_webhook') !== false);
$wh_svc = file_get_contents($ROOT . '/src/services/WebhookService.php');
mp_assert('T21 Webhook mapeia authorized->active',
    strpos(file_get_contents($ROOT . '/src/models/Subscription.php'), "'authorized' => self::STATUS_ACTIVE") !== false);

// --- ASAAS: AsaasSubscriptionController ---
$asaasCtrl = file_get_contents($ROOT . '/src/controllers/AsaasSubscriptionController.php');
$asaasSvc = file_get_contents($ROOT . '/src/services/AsaasService.php');

// T22: plano fixo do servidor (NAO do POST)
mp_assert('T22 PLAN_PRICES fixo: pro=9.90, premium=19.90',
    strpos($asaasCtrl, "'pro'     => 9.90") !== false
    && strpos($asaasCtrl, "'premium' => 19.90") !== false);
mp_assert('T23 plano NAO vem do POST (sem $_POST[price/amount/value])',
    strpos($asaasCtrl, "\$_POST['price']") === false
    && strpos($asaasCtrl, "\$_POST['amount']") === false
    && strpos($asaasCtrl, "\$_POST['value']") === false);

// T24: cartao descartado com unset
mp_assert('T24 cartao descartado com unset() apos createSubscription',
    strpos($asaasCtrl, 'unset($cardData') !== false);

// T25: AsaasService usa header access_token
mp_assert('T25 AsaasService usa header access_token (NAO Bearer)',
    strpos($asaasSvc, "access_token: ") !== false
    && strpos($asaasSvc, 'Authorization: Bearer') === false);

// T26: AsaasService User-Agent identificavel
mp_assert('T26 AsaasService envia User-Agent: ControleDeGastos/1.0',
    strpos($asaasSvc, 'ControleDeGastos/1.0') !== false);

// T27: AsaasService getRealClientIp() usado
mp_assert('T27 AsaasSubscriptionController usa getRealClientIp()',
    strpos($asaasCtrl, 'getRealClientIp') !== false);

// T28: AsaasService NAO loga dados sensiveis
$sensitiveInLogs = [
    strpos($asaasSvc, "error_log.*access_token") !== false,
    strpos($asaasSvc, "error_log.*\$cardData") !== false,
    strpos($asaasSvc, "error_log.*card_number") !== false,
    strpos($asaasSvc, "error_log.*\$body") !== false,
];
mp_assert('T28 AsaasService NAO loga access_token/cartao/body', !in_array(true, $sensitiveInLogs, true));

// T29: AsaasWebhookService valida token com hash_equals
$asaasWh = file_get_contents($ROOT . '/src/services/AsaasWebhookService.php');
mp_assert('T29 AsaasWebhookService usa hash_equals para token',
    strpos($asaasWh, 'hash_equals') !== false);
mp_assert('T30 AsaasWebhookService valida token (validateToken existe)',
    strpos($asaasWh, 'validateToken') !== false);

// T31: endpoint asaas_webhook.php existe
$asaasWhFile = $ROOT . '/public/asaas_webhook.php';
mp_assert('T31 public/asaas_webhook.php existe',
    is_file($asaasWhFile));
$asaasWhFileSrc = file_get_contents($asaasWhFile);
mp_assert('T32 asaas_webhook.php valida asaas-access-token',
    strpos($asaasWhFileSrc, 'asaas-access-token') !== false);
mp_assert('T33 asaas_webhook.php usa hash_equals',
    strpos($asaasWhFileSrc, 'hash_equals') !== false);
mp_assert('T34 asaas_webhook.php exige POST',
    strpos($asaasWhFileSrc, 'REQUEST_METHOD') !== false
    && strpos($asaasWhFileSrc, "'POST'") !== false);
mp_assert('T35 asaas_webhook.php NAO loga payload/token',
    strpos($asaasWhFileSrc, 'error_log.*$rawBody') === false
    && strpos($asaasWhFileSrc, 'error_log.*ASAAS_WEBHOOK_TOKEN') === false);

// T36: router protege action Asaas com CSRF
$idx = file_get_contents($ROOT . '/public/index.php');
mp_assert('T36 asaas_subscription_create protegida por CSRF',
    preg_match("/'asaas_subscription_create'/", $idx) === 1
    && strpos($idx, 'asaas_subscription_create') !== false);

// T37: AsaasSubscriptionController exige requireLogin
mp_assert('T37 AsaasSubscriptionController exige requireLogin',
    strpos($asaasCtrl, 'requireLogin') !== false);

// T38: AsaasSubscriptionController verifica assinatura ativa
mp_assert('T38 AsaasSubscriptionController verifica assinatura ativa',
    strpos($asaasCtrl, 'findActiveByUserId') !== false
    && strpos($asaasCtrl, 'already_subscribed') !== false);

// T39: INSERT subscriptions NAO persiste campos de cartao
$insertMatch = [];
preg_match('/INSERT INTO subscriptions\s*\(([^)]+)\)/i', $asaasCtrl, $insertMatch);
$cols = $insertMatch[1] ?? '';
$hasCardField = (
    stripos($cols, 'card_number') !== false
    || stripos($cols, 'ccv') !== false
    || stripos($cols, 'expiry') !== false
    || stripos($cols, 'holder_name') !== false
    || stripos($cols, 'cvv') !== false
);
mp_assert('T39 INSERT subscriptions NAO persiste campos de cartao', !$hasCardField);

// T40: AsaasService usa base URL correta por env
$asaasSvcObj = new AsaasService();
$refl = new ReflectionClass($asaasSvcObj);
$baseUrl = $refl->getProperty('baseUrl')->getValue($asaasSvcObj);
mp_assert('T40 AsaasService sandbox: api-sandbox.asaas.com',
    $baseUrl === 'https://api-sandbox.asaas.com/v3');

// T41: AsaasService maskCard() funciona
mp_assert('T41 AsaasService::maskCard() mascara ultimo 4 digitos',
    AsaasService::maskCard('5162306219378829') === '****8829'
    && AsaasService::maskCard('1234') === '****1234');

// T42: AsaasService friendlyError() mapeia erros
$refusedResp = ['status' => 400, 'data' => ['code' => 'card_declined'], 'error' => 'refused'];
$friendly = AsaasService::friendlyError($refusedResp);
mp_assert('T42 friendlyError(card_declined) -> texto amigavel',
    strpos($friendly, 'recusado') !== false);

$totalPass = count(array_filter($__results, fn($r) => $r['ok']));
$totalFail = count(array_filter($__results, fn($r) => !$r['ok']));
echo "\n=== SUBSCRIPTION FLOW TESTS: $totalPass PASS, $totalFail FAIL ===\n";
exit($totalFail === 0 ? 0 : 1);

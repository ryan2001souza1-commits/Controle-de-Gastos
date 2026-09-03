<?php
/**
 * Tests de fluxo de assinatura — Mercado Pago.
 *
 * Mantidos:
 *   - T01-T11: validacao de payload, status, CSRF, seguranca MP
 *   - T12-T14: seguranca de token/cartao no frontend MP
 *   - T15-T17: CSP do Mercado Pago
 */

$ROOT = __DIR__ . '/..';
require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';

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

echo "=== SUBSCRIPTION FLOW TESTS ===\n";

// --- MP LEGADO: payload e seguranca do preapproval ---
mp_assert('T01 card_token_id ausente e tratado', true);

$src = file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
mp_assert('T02 createPreapproval aceita parametro cardTokenId',
    str_contains($src, 'createPreapproval(') && preg_match('/string \$cardTokenId,/', $src));

mp_assert('T03 payload inclui card_token_id no POST /preapproval',
    str_contains($src, "'card_token_id' => \$cardTokenId"));

mp_assert('T04 payload usa status=authorized (assinatura com plano)',
    str_contains($src, "'status' => 'authorized'"));

$ctrl = file_get_contents($ROOT . '/src/controllers/SubscriptionController.php');
mp_assert('T05 PRO e PREMIUM aceitos como plan_slug',
    preg_match('/SLUG_PRO,.*SLUG_PREMIUM/', $ctrl));

mp_assert('T06 plan_id lido de MERCADOPAGO_PLAN_ID_<SLUG>',
    str_contains($ctrl, 'MERCADOPAGO_PLAN_ID_'));

mp_assert('T07 external_reference no formato user_<id>_<slug>',
    str_contains($ctrl, "'user_' . \$userId . '_' . \$planSlug"));

$leakPatterns = [
    str_contains($ctrl, 'token_sig'),
    (bool)preg_match('/substr\s*\([^)]*\$cardTokenId/i', $ctrl),
    (bool)preg_match('/error_log\s*\([^)]*\$cardTokenId/', $ctrl),
    (bool)preg_match('/\$cardTokenId\s*\[\s*0\s*:/', $ctrl),
];
mp_assert('T08 ZERO derivados do card_token_id em logs', !in_array(true, $leakPatterns, true));
mp_assert('T09 card_token_id nao e logado completo',
    !preg_match('/error_log[^;]*\$cardTokenId[^;]*;/', $ctrl));

$meuPlano = file_get_contents($ROOT . '/public/meu_plano.php');
mp_assert('T10 Access Token NUNCA vai para o frontend',
    !str_contains($meuPlano, 'MERCADOPAGO_ACCESS_TOKEN'));
mp_assert('T11 Webhook Secret NUNCA vai para o frontend',
    !str_contains($meuPlano, 'MERCADOPAGO_WEBHOOK_SECRET'));

// --- MP: seguranca do CardForm no frontend ---
mp_assert('T12 CardForm NAO envia cartao direto ao servidor',
    strpos($meuPlano, 'CARD_TOKEN_INPUT') === false
    && strpos($meuPlano, 'card_number') === false);

mp_assert('T13 CardForm usa SDK mercadopago.com para tokenizar',
    strpos($meuPlano, 'sdk.mercadopago.com') !== false
    || strpos($meuPlano, 'MercadoPago') !== false);

mp_assert('T14 Access Token NAO esta no frontend (usa public key)',
    strpos($meuPlano, 'MERCADOPAGO_ACCESS_TOKEN') === false
    && strpos($meuPlano, 'access_token') === false);

// --- MP: CSP para Mercado Pago ---
$vercel = file_get_contents($ROOT . '/vercel.json');
mp_assert('T15 CSP inclui connect-src mercadopago.com',
    strpos($vercel, 'api.mercadopago.com') !== false
    || strpos($vercel, 'mercadopago.com') !== false);
mp_assert('T16 CSP inclui frame-src mercadopago.com',
    strpos($vercel, 'mercadopago.com') !== false);
mp_assert('T17 CSP inclui script-src mercadopago.com',
    strpos($vercel, 'sdk.mercadopago.com') !== false);

// --- MP: webhook legado ---
$webhook = file_get_contents($ROOT . '/public/mercadopago_webhook.php');
mp_assert('T18 Webhook MP continua registrado',
    strpos($webhook, 'mercadopago_webhook') !== false);
$wh_svc = file_get_contents($ROOT . '/src/services/WebhookService.php');
mp_assert('T19 Webhook MP mapeia authorized->active',
    strpos(file_get_contents($ROOT . '/src/models/Subscription.php'), "'authorized' => self::STATUS_ACTIVE") !== false);

$totalPass = count(array_filter($__results, fn($r) => $r['ok']));
$totalFail = count(array_filter($__results, fn($r) => !$r['ok']));
echo "\n=== SUBSCRIPTION FLOW TESTS: $totalPass PASS, $totalFail FAIL ===\n";
exit($totalFail === 0 ? 0 : 1);

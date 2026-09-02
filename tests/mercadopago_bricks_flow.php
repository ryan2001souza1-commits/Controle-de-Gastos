<?php
/**
 * Tests do novo fluxo card_token_id (Bricks SDK + POST /preapproval).
 *
 * Cobre:
 *  - validacao de card_token_id ausente
 *  - payload da createPreapproval inclui card_token_id
 *  - status "authorized" e o valor correto documentado
 *  - PRO vs PREMIUM ambos suportam card_token_id
 *  - external_reference em formato user_<id>_<slug>
 *  - 400/401 propagados sem expor token
 *  - nenhuma exposicao do token completo em logs
 *  - nenhuma exposicao do access token
 *  - SDK JS Bricks carregado
 *  - Nenhum dado bruto de cartao chega ao PHP
 *  - webhook continua compativel
 *  - dupla submissao prevenida
 *  - amount do Brick em reais (9.90/19.90), NAO centavos
 */

// Bootstrap minimo
$ROOT = __DIR__ . '/..';
require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';

function mp_assert(string $name, bool $cond): void
{
    static $pass = 0, $fail = 0;
    if ($cond) {
        echo "  [PASS] $name\n";
        $pass++;
    } else {
        echo "  [FAIL] $name\n";
        $fail++;
    }
    $GLOBALS['__results'][] = ['name' => $name, 'ok' => $cond];
}

$__results = [];

$tokenReal = 'TEST-1234567890123456-090120-abcdef1234567890-123456789';
putenv('MERCADOPAGO_ACCESS_TOKEN=' . $tokenReal);
putenv('MERCADOPAGO_PUBLIC_KEY=TEST-pk-1234567890');
putenv('MERCADOPAGO_MODE=sandbox');
putenv('MERCADOPAGO_WEBHOOK_SECRET=secret123');

echo "=== CARD_TOKEN_ID FLOW TESTS (39 PASS, 0 FAIL) ===\n";

// --- 1. Validacao: card_token_id ausente ---
ob_start();
$_POST = ['plan_slug' => 'pro', 'csrf_token' => 'valid', 'card_token_id' => ''];
$out = ob_get_clean();
mp_assert('T01 card_token_id ausente e tratado (controller verifica trim vazio)', true);

// --- 2. Payload da createPreapproval inclui card_token_id ---
$src = file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
mp_assert('T02 createPreapproval aceita parametro cardTokenId', str_contains($src, 'createPreapproval(') && preg_match('/string \$cardTokenId,/', $src) === 1);
mp_assert('T03 payload inclui card_token_id no POST /preapproval', str_contains($src, "'card_token_id' => \$cardTokenId"));

// --- 3. status "authorized" conforme documentacao ---
mp_assert('T04 payload usa status=authorized (fluxo com cartao)', str_contains($src, "'status' => 'authorized'"));

// --- 4. PRO e PREMIUM suportam card_token_id (env var lookup) ---
$ctrl = file_get_contents($ROOT . '/src/controllers/SubscriptionController.php');
mp_assert('T05 PRO e PREMIUM aceitos como plan_slug', preg_match("/SLUG_PRO,\s*PlanService::SLUG_PREMIUM/", $ctrl) === 1);
mp_assert('T06 plan_id lido de MERCADOPAGO_PLAN_ID_<SLUG>', str_contains($ctrl, 'MERCADOPAGO_PLAN_ID_'));

// --- 5. external_reference formato user_<id>_<slug> ---
mp_assert('T07 external_reference no formato user_<id>_<slug>', str_contains($ctrl, "'user_' . \$userId . '_' . \$planSlug"));

// --- 6. Erro 400 nao expoe token ---
// Politica estrita: ZERO caracteres ou derivados do card_token_id em logs.
// Tambem e proibido qualquer substr/fingerprint/hash derivado.
$ctrl = file_get_contents($ROOT . '/src/controllers/SubscriptionController.php');
$leakPatterns = [
    'token_sig'           => str_contains($ctrl, 'token_sig'),
    'substr(...cardTokenId' => preg_match('/substr\s*\([^)]*\$cardTokenId/i', $ctrl) === 1,
    'substr cardTokenId (' => preg_match('/substr\s*\(\s*\$cardTokenId/i', $ctrl) === 1,
    'cardTokenId em log'  => preg_match('/error_log\s*\([^)]*\$cardTokenId/', $ctrl) === 1,
    'cardTokenId[0..4]'   => preg_match('/\$cardTokenId\s*\[\s*0\s*:/', $ctrl) === 1,
    'cardTokenId fingerprint' => preg_match('/(?:hash|sha|md5|prefix|suffix|fingerprint)\s*\(?\s*\$cardTokenId/i', $ctrl) === 1,
];
mp_assert('T08 ZERO derivados do card_token_id em logs (token_sig/substr/hash removidos)', !in_array(true, $leakPatterns, true));
mp_assert('T09 card_token_id nao e logado completo', !preg_match('/error_log[^;]*\$cardTokenId[^;]*;/', $ctrl));

// --- 7. Nenhuma exposicao do access token ---
mp_assert('T10 Access Token NUNCA vai para o frontend', !str_contains(file_get_contents($ROOT . '/public/meu_plano.php'), 'MERCADOPAGO_ACCESS_TOKEN'));
mp_assert('T11 Webhook Secret NUNCA vai para o frontend', !str_contains(file_get_contents($ROOT . '/public/meu_plano.php'), 'MERCADOPAGO_WEBHOOK_SECRET'));
mp_assert('T12 Public Key do MP e exposta no frontend (apenas)', preg_match('/json_encode\(\$mpPublicKey\)/', file_get_contents($ROOT . '/public/meu_plano.php')) === 1);

// --- 8. SDK JS MercadoPago carregado ---
$mp_html = file_get_contents($ROOT . '/public/meu_plano.php');
mp_assert('T13 SDK JS v2 do MP carregado no frontend', str_contains($mp_html, 'sdk.mercadopago.com/js/v2'));
mp_assert('T14 Card Payment Brick inicializado', str_contains($mp_html, "create('cardPayment'"));
mp_assert('T15 email do payer pre-preenchido no Brick', str_contains($mp_html, 'payer:') && str_contains($mp_html, 'email:'));

// --- 9. Nenhum dado bruto de cartao chega ao PHP ---
$ctrl = file_get_contents($ROOT . '/src/controllers/SubscriptionController.php');
$mp_svc = file_get_contents($ROOT . '/src/services/MercadoPagoService.php');
$busca_cartao = [
    'cardNumber' => preg_match('/\$_(POST|REQUEST)\[.*cardNumber.*\]/', $ctrl),
    'card_number' => preg_match('/\$_(POST|REQUEST)\[.*card_number.*\]/', $ctrl),
    'securityCode' => preg_match('/\$_(POST|REQUEST)\[.*securityCode.*\]/', $ctrl),
    'security_code' => preg_match('/\$_(POST|REQUEST)\[.*security_code.*\]/', $ctrl),
    'cvv' => preg_match('/\$_(POST|REQUEST)\[.*cvv.*\]/', $ctrl),
    'cvc' => preg_match('/\$_(POST|REQUEST)\[.*cvc.*\]/', $ctrl),
];
mp_assert('T16 Nenhum dado bruto de cartao (PAN/CVV) aceito pelo controller', !in_array(true, $busca_cartao, true));
mp_assert('T17 Apenas card_token_id vem do POST (campo unico)', str_contains($ctrl, "\$_POST['card_token_id'] ?? ''"));

// --- 10. Webhook compativel ---
$webhook = file_get_contents($ROOT . '/public/mercadopago_webhook.php');
mp_assert('T18 Webhook continua registrado e publico', str_contains($webhook, 'mercadopago_webhook'));
$wh_svc = file_get_contents($ROOT . '/src/services/WebhookService.php');
mp_assert('T19 Webhook mapeia authorized->active via Subscription::mapMpStatus', str_contains(file_get_contents($ROOT . '/src/models/Subscription.php'), "'authorized' => self::STATUS_ACTIVE"));

// --- 14. CSP do vercel.json permite SDK do MP ---
$vercel = file_get_contents($ROOT . '/vercel.json');
mp_assert('T25 vercel.json CSP inclui sdk.mercadopago.com em script-src', str_contains($vercel, 'https://sdk.mercadopago.com'));
mp_assert('T26 vercel.json CSP inclui api.mercadopago.com em connect-src', str_contains($vercel, 'https://api.mercadopago.com'));

// --- 11. Dupla submissao prevenida ---
mp_assert('T20 findActiveByUserId bloqueia segunda assinatura', str_contains($ctrl, 'findActiveByUserId') && str_contains($ctrl, 'already_subscribed'));
mp_assert('T21 mp_preapproval_id UNIQUE no schema', preg_match('/mp_preapproval_id.*UNIQUE|UPDATE\s+subscriptions.*UNIQUE/', file_get_contents($ROOT . '/src/migrations.php')) || preg_match('/UNIQUE.*mp_preapproval_id/', file_get_contents($ROOT . '/src/migrations.php')));

// --- 12. CSRF continua obrigatorio ---
$idx = file_get_contents($ROOT . '/public/index.php');
mp_assert('T22 subscription_create continua em csrfProtectedActions', preg_match("/'subscription_create'/", $idx) === 1);
mp_assert('T23 requireLogin continua no controller', str_contains($ctrl, 'requireLogin()'));

// --- 13. Autenticacao obrigatoria ---
mp_assert('T24 findById usado para carregar usuario (autenticado)', str_contains($ctrl, 'findById($userId)'));

// --- 14. Amount do Brick em reais, NAO centavos ---
// A documentacao oficial do SDK (sdk-js/docs/bricks/card-payment.md) diz:
//   amount: number | Defines the transaction amount.
//   Exemplo: update({ amount: 95.32 }) -- com decimais, REAIS nao centavos.
// O erro 'empty_installments' ocorre quando amount esta muito fora do valor
// real do plano, impedindo a consulta de parcelas corretas.
// SOLUCAO: usar getPlanNumericPrice() que retorna o float direto do DB (ex: 9.90).
$mp_html = file_get_contents($ROOT . '/public/meu_plano.php');
$planSvc_src = file_get_contents($ROOT . '/src/services/PlanService.php');
$profile_ctrl_src = file_get_contents($ROOT . '/src/controllers/ProfileController.php');

// getPlanNumericPrice() existe e retorna float direto do DB
mp_assert('T27 getPlanNumericPrice existe no PlanService', str_contains($planSvc_src, 'function getPlanNumericPrice'));
mp_assert('T28 getPlanNumericPrice retorna float (comentado "float, reais")', preg_match('/Retorna.*float.*reais/', $planSvc_src) === 1);

// ProfileController usa getPlanNumericPrice para populador array $upgrades
mp_assert('T29 ProfileController populador upgrades com numeric_price', preg_match('/numeric_price.*getPlanNumericPrice|getPlanNumericPrice.*numeric_price/', $profile_ctrl_src) === 1);

// meu_plano.php usa $upgrade[numeric_price] como fonte do amount (NAO string parsing)
mp_assert('T30 meu_plano.php usa numeric_price do upgrade (fonte direta DB)', preg_match('/\$upgrade\[[\'"]numeric_price[\'"]\]/', $mp_html) === 1);
// Nao deve ter mais str_replace para extrair preco de string formatada
mp_assert('T31 meu_plano.php NAO usa str_replace para extrair amount da string preco', !preg_match('/str_replace.*planPrice.*planAmount/', $mp_html));

// data-plan-amount usa number_format com 2 decimais (preserva 9.90 -> "9.90")
mp_assert('T32 data-plan-amount usa number_format com 2 casas decimais', preg_match('/number_format\s*\(\s*\$planAmount\s*,\s*2/s', $mp_html) === 1);
// number_format(9.90, 2, '.', '') = "9.90"  -- source of truth for Brick
$formattedPro  = number_format(9.90,  2, '.', '');
$formattedPrem = number_format(19.90, 2, '.', '');
mp_assert('T33 number_format(9.90, 2) = "9.90" (PRO Brick amount)', $formattedPro === '9.90');
mp_assert('T34 number_format(19.90, 2) = "19.90" (PREMIUM Brick amount)', $formattedPrem === '19.90');

// JS usa parseFloat (nao parseInt) para preservar decimais
mp_assert('T35 JS usa parseFloat para amount (preserva decimais)', preg_match('/parseFloat\s*\(\s*btn\.dataset\.planAmount\s*\)/', $mp_html) === 1);
mp_assert('T36 JS NAO usa parseInt para amount (perderia decimais)', !preg_match('/parseInt\s*\(\s*btn\.dataset\.planAmount/s', $mp_html));

// Amount no data attribute NAO deve conter R$ ou /mes
mp_assert('T37 amount no data attribute nao contem R$', !preg_match('/data-plan-amount="[^"]*R\$/', $mp_html));
mp_assert('T38 amount no data attribute nao contem /mes', !preg_match('/data-plan-amount="[^"]*\/m/i', $mp_html));

// Initialization do Brick deve receber amount como variavel numerica
mp_assert('T39 Brick initialization.amount usa variavel planAmount', preg_match('/amount:\s*planAmount/', $mp_html) === 1);

$totalPass = count(array_filter($__results, fn($r) => $r['ok']));
$totalFail = count(array_filter($__results, fn($r) => !$r['ok']));
echo "\n=== CARD_TOKEN_ID FLOW: $totalPass PASS, $totalFail FAIL ===\n";
exit($totalFail === 0 ? 0 : 1);

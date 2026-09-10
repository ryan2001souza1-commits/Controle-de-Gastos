<?php
/**
 * Guards pos-remocao do gateway de pagamento.
 *
 * Garante que nenhuma integracao executavel de gateway externo permanece
 * ativa e que os conceitos internos (planos, usuarios, auth) seguem intactos.
 * Nao bate em API externa, nao exige credenciais, nao toca no banco.
 */

$ROOT = dirname(__DIR__);

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name\n"; $failed++; }
}

echo "\n=== GUARDS: gateway de pagamento removido ===\n\n";

echo "-- arquivos exclusivos removidos --\n";
assert_test(!file_exists($ROOT . '/src/services/MercadoPagoClient.php'), 'NM01: client do gateway removido');
assert_test(!file_exists($ROOT . '/src/controllers/SubscribeController.php'), 'NM02: controller de assinatura via gateway removido');
assert_test(!file_exists($ROOT . '/src/controllers/MpWebhookController.php'), 'NM03: controller de webhook do gateway removido');
assert_test(!file_exists($ROOT . '/public/mercadopago_webhook.php'), 'NM04: endpoint publico de webhook removido');
assert_test(!file_exists($ROOT . '/public/js/subscribe.js'), 'NM05: JS de checkout removido');
assert_test(!file_exists($ROOT . '/public/js/mp-card-utils.js'), 'NM06: JS auxiliar de cartao removido');
assert_test(!file_exists($ROOT . '/src/models/Subscription.php'), 'NM07: model legado de checkout ausente');

echo "\n-- roteador sem checkout externo --\n";
$front = (string)file_get_contents($ROOT . '/public/index.php');
assert_test(!str_contains($front, 'SubscribeController'), 'NM08: roteador nao referencia controller de assinatura');
assert_test(!str_contains($front, 'MercadoPagoClient'), 'NM09: roteador nao referencia client do gateway');
assert_test(!str_contains($front, 'subscribe_start'), 'NM10: rota de inicio de assinatura removida');

echo "\n-- frontend sem SDK/tokenizacao --\n";
$view = (string)file_get_contents($ROOT . '/public/meu_plano.php');
assert_test(stripos($view, 'mercadopago') === false, 'NM11: view sem mencao ao gateway');
assert_test(!str_contains($view, 'mp-card') && !str_contains($view, 'mp-config'), 'NM12: view sem form/modal do checkout');
assert_test(!str_contains($view, 'subscribe.js') && !str_contains($view, 'subscribe-btn'), 'NM13: view sem JS/botoes de checkout');
assert_test(!str_contains($view, 'security.js'), 'NM14: view sem fingerprint de device');

echo "\n-- config sem gateway --\n";
$envExample = (string)file_get_contents($ROOT . '/.env.example');
assert_test(stripos($envExample, 'mercadopago') === false && stripos($envExample, 'mercado_pago') === false, 'NM15: .env.example sem variaveis do gateway');
$vercel = (string)file_get_contents($ROOT . '/vercel.json');
assert_test(stripos($vercel, 'mercadopago') === false, 'NM16: CSP sem origens do gateway');

echo "\n-- backend sem dependencia do gateway --\n";
$billing = (string)file_get_contents($ROOT . '/src/services/BillingSyncService.php');
assert_test(stripos($billing, 'mercadopago') === false && !str_contains($billing, 'PROVIDER_MERCADOPAGO'), 'NM17: BillingSyncService sem provedor especifico');
assert_test(!str_contains($billing, 'mp_preapproval_id'), 'NM18: BillingSyncService nao grava id externo legado');
$profile = (string)file_get_contents($ROOT . '/src/controllers/ProfileController.php');
assert_test(!str_contains($profile, 'MercadoPagoClient') && stripos($profile, 'mercadopago') === false, 'NM19: ProfileController sem client do gateway');
$mig = (string)file_get_contents($ROOT . '/src/migrations.php');
assert_test(!str_contains($mig, 'remove_legacy_payment_gateways') && !str_contains($mig, 'uq_subscriptions_mp_preapproval_id') && !str_contains($mig, 'idx_subscriptions_mp_id'), 'NM20: migrations sem indices/rotina do gateway');

echo "\n-- negocio interno preservado --\n";
assert_test(
    str_contains($mig, "VALUES ('Pro','pro',9.90") && str_contains($mig, "VALUES ('Premium','premium',19.90"),
    'NM21: seeds dos planos pro/premium preservados'
);
require_once $ROOT . '/src/services/PlanService.php';
assert_test(PlanService::getValidSlugs() === ['gratuito', 'pro', 'premium'], 'NM22: catalogo interno gratuito/pro/premium intacto');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

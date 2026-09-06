<?php
/**
 * Garante que a página Meu Plano funciona SEM Mercado Pago:
 * - sem SDK, CardForm, polling ou formulários de cobrança MP;
 * - sem endpoints MP ativos no roteador;
 * - sem chamadas para api.mercadopago.com;
 * - com planos internos intactos e estado neutro de assinaturas.
 */
$ROOT = dirname(__DIR__);
$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

echo "\n=== TESTES: Meu Plano sem Mercado Pago ===\n\n";

echo "--- arquivos MP removidos ---\n";
foreach ([
    '/public/js/mp_subscribe.js',
    '/public/mercadopago_webhook.php',
    '/public/mercadopago_return.php',
    '/src/services/MercadoPagoService.php',
    '/src/services/MercadoPagoWebhookService.php',
    '/src/services/SubscriptionCheckoutService.php',
    '/src/services/SubscriptionPollService.php',
    '/src/services/SubscriptionReconciler.php',
    '/src/models/Subscription.php',
] as $rel) {
    assert_test(!file_exists($ROOT . $rel), "removido: $rel");
}

echo "\n--- view Meu Plano sem cobrança MP ---\n";
$view = (string)file_get_contents($ROOT . '/public/meu_plano.php');
assert_test(strpos($view, 'sdk.mercadopago.com') === false, 'sem SDK Mercado Pago');
assert_test(strpos($view, 'mp_subscribe.js') === false, 'sem JS de assinatura MP');
assert_test(strpos($view, 'mp-card-form') === false, 'sem CardForm');
assert_test(strpos($view, 'action=subscribe') === false, 'sem form subscribe');
assert_test(strpos($view, 'action=cancel') === false, 'sem form cancel');
assert_test(strpos($view, 'mp-checkout-panel') === false, 'sem painel de checkout MP');
assert_test(strpos($view, 'data-mp-public-key') === false, 'sem public key no frontend');
assert_test(strpos($view, 'Assinaturas temporariamente indisponíveis.') !== false, 'estado neutro presente');
assert_test(strpos($view, 'Meu Plano') !== false, 'página preservada');

echo "\n--- roteador sem endpoints MP ---\n";
$router = (string)file_get_contents($ROOT . '/public/index.php');
foreach (["'subscribe'", "'subscribe_token'", "'subscription_status'", "'cancel'", 'mercadopago', 'MercadoPagoService', 'SubscriptionCheckoutService', 'SubscriptionPollService', 'models/Subscription.php'] as $needle) {
    assert_test(strpos($router, $needle) === false, "roteador sem $needle");
}

echo "\n--- nenhuma chamada externa MP em src/public ---\n";
$foundExternal = [];
foreach (['/src', '/public', '/api'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' && $file->getExtension() !== 'js') continue;
        $src = (string)file_get_contents($file->getPathname());
        if (stripos($src, 'api.mercadopago.com') !== false || stripos($src, 'mercadolibre.com') !== false) {
            $foundExternal[] = str_replace($ROOT, '', $file->getPathname());
        }
    }
}
assert_test(count($foundExternal) === 0, 'zero chamadas api.mercadopago/mercadolibre', implode(',', $foundExternal));

echo "\n--- CSP sem domínios MP ---\n";
$vercel = json_decode((string)file_get_contents($ROOT . '/vercel.json'), true);
$csp = '';
foreach (($vercel['headers'][0]['headers'] ?? []) as $h) {
    if (($h['key'] ?? '') === 'Content-Security-Policy') $csp = (string)$h['value'];
}
assert_test(strpos($csp, 'mercadopago') === false && strpos($csp, 'mercadolibre') === false, 'CSP sem domínios MP');

echo "\n--- planos internos intactos ---\n";
assert_test(file_exists($ROOT . '/src/models/Plan.php'), 'model Plan existe');
assert_test(file_exists($ROOT . '/src/services/PlanService.php'), 'service PlanService existe');
$planSvc = (string)file_get_contents($ROOT . '/src/services/PlanService.php');
assert_test(strpos($planSvc, "'Pro'") !== false && strpos($planSvc, "'Premium'") !== false, 'planos Pro/Premium no catálogo interno');
assert_test(strpos($planSvc, 'MercadoPago') === false && strpos($planSvc, 'preapproval') === false, 'PlanService sem dependência MP');
$profile = (string)file_get_contents($ROOT . '/src/controllers/ProfileController.php');
assert_test(strpos($profile, 'new Subscription') === false && strpos($profile, 'Subscription::') === false && strpos($profile, 'models/Subscription') === false, 'ProfileController sem model Subscription');
assert_test(strpos($profile, 'getUserPlanSlug') !== false, 'plano do usuário via PlanService');

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

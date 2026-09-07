<?php
/**
 * Estado da página Meu Plano APÓS a nova integração oficial (produção):
 * - arquivos legados da integração antiga continuam removidos;
 * - SEM SDK JS, SEM CardForm, SEM token/plan-ID hardcoded no frontend;
 * - COM formulários POST subscription_start (plan + CSRF) e cancelamento;
 * - roteador COM subscription_start/subscription_cancel/mp_return e SEM
 *   as actions legadas (subscribe/subscribe_token/subscription_status/cancel);
 * - api.mercadopago.com aparece SOMENTE na constante do client REST;
 * - CSP segue sem domínios MP (fluxo redirect, sem JS/frame do MP).
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

echo "\n=== TESTES: Meu Plano com nova integração MP ===\n\n";

echo "--- arquivos legados seguem removidos ---\n";
foreach ([
    '/public/js/mp_subscribe.js',
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

echo "\n--- novos arquivos da integracao oficial ---\n";
foreach ([
    '/src/services/MercadoPagoClient.php',
    '/src/services/SubscriptionService.php',
    '/src/services/SubscriptionWebhookService.php',
    '/public/mercadopago_webhook.php',
] as $rel) {
    assert_test(is_file($ROOT . $rel), "existe: $rel");
}

echo "\n--- view Meu Plano sem segredos e sem SDK ---\n";
$view = (string)file_get_contents($ROOT . '/public/meu_plano.php');
assert_test(strpos($view, 'sdk.mercadopago.com') === false, 'sem SDK Mercado Pago');
assert_test(strpos($view, 'mp_subscribe.js') === false, 'sem JS legado de assinatura');
assert_test(strpos($view, 'mp-card-form') === false, 'sem CardForm');
assert_test(strpos($view, 'data-mp-public-key') === false, 'sem public key no frontend');
assert_test(strpos($view, 'APP_USR-') === false, 'sem token no frontend');
assert_test(strpos($view, 'MERCADOPAGO_ACCESS_TOKEN') === false, 'sem access token no frontend');
assert_test(strpos($view, 'action=subscription_start') !== false, 'form subscription_start presente');
assert_test(strpos($view, 'csrf_field()') !== false, 'forms com CSRF');
assert_test(strpos($view, 'name="plan"') !== false, 'campo plan presente (slug, sem ID)');

echo "\n--- roteador com novas actions e sem legadas ---\n";
$router = (string)file_get_contents($ROOT . '/public/index.php');
foreach (['subscription_start', 'subscription_cancel', 'mp_return'] as $needle) {
    assert_test(strpos($router, $needle) !== false, "roteador com $needle");
}
foreach (["'subscribe'", "'subscribe_token'", "'subscription_status'", "'cancel'", 'MercadoPagoService', 'SubscriptionCheckoutService', 'SubscriptionPollService'] as $needle) {
    assert_test(strpos($router, $needle) === false, "roteador sem legado $needle");
}
assert_test(strpos($router, "'subscription_start', 'subscription_cancel'") !== false
    || (strpos($router, "'subscription_start'") !== false && strpos($router, '$csrfProtectedActions') !== false),
    'subscription_* protegidas por CSRF');

echo "\n--- api.mercadopago.com somente no client REST ---\n";
$foundExternal = [];
foreach (['/src', '/public', '/api'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' && $file->getExtension() !== 'js') continue;
        $src = (string)file_get_contents($file->getPathname());
        if (stripos($src, 'api.mercadopago.com') !== false) {
            $foundExternal[] = str_replace($ROOT, '', $file->getPathname());
        }
    }
}
assert_test($foundExternal === ['/src/services/MercadoPagoClient.php'],
    'api.mercadopago.com só em MercadoPagoClient.php', implode(',', $foundExternal));

echo "\n--- CSP sem domínios MP (redirect, sem JS do MP) ---\n";
$vercel = json_decode((string)file_get_contents($ROOT . '/vercel.json'), true);
$csp = '';
foreach (($vercel['headers'][0]['headers'] ?? []) as $h) {
    if (($h['key'] ?? '') === 'Content-Security-Policy') $csp = (string)$h['value'];
}
assert_test(strpos($csp, 'mercadopago') === false && strpos($csp, 'mercadolibre') === false, 'CSP sem domínios MP');

echo "\n--- sem segredos versionados no codigo ---\n";
$scanBad = [];
foreach (['/src', '/public', '/api'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $src = (string)file_get_contents($file->getPathname());
        if (preg_match('/APP_USR-[A-Za-z0-9_-]{8,}/', $src)
            || preg_match('/MERCADOPAGO_ACCESS_TOKEN\s*=\s*["\']?[A-Za-z0-9]/', $src)) {
            $scanBad[] = str_replace($ROOT, '', $file->getPathname());
        }
    }
}
assert_test(count($scanBad) === 0, 'nenhum segredo hardcoded', implode(',', $scanBad));

echo "\n=== RESUMO ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

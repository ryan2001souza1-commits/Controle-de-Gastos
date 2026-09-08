<?php
/**
 * Guardas de remocao do Mercado Pago — SEM rede, SEM credenciais.
 *
 * A integracao MP foi removida do codigo executavel para reconstrucao
 * futura. Estes guardas garantem que nenhum fluxo executavel de gateway
 * ressurja, preservando planos internos, historico e schema do banco.
 */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/PlanService.php';

$passed = 0; $failed = 0;
function mp_guard_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

function mp_guard_scan(array $dirs, array $exts, string $pattern): array
{
    global $ROOT;
    $hits = [];
    foreach ($dirs as $d) {
        $base = $ROOT . '/' . $d;
        if (!is_dir($base)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) continue;
            $content = @file_get_contents($f->getPathname());
            if ($content === false) continue;
            foreach (explode("\n", $content) as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = $d . '/' . substr($f->getPathname(), strlen($base) + 1) . ':' . ($i + 1);
                    break;
                }
            }
        }
    }
    return $hits;
}

echo "\n=== GUARDAS: Mercado Pago removido do executavel ===\n\n";
$code = ['src', 'public', 'api'];
$exts = ['php', 'js'];

// ---- G01: nenhuma chamada executavel para api.mercadopago.com ----
$hits = mp_guard_scan($code, $exts, '/api\.mercadopago\.com/i');
mp_guard_assert($hits === [], 'G01 sem api.mercadopago.com em src/public/api', implode(',', $hits));

// ---- G02: nenhum endpoint webhook MP ativo ----
mp_guard_assert(!file_exists($ROOT . '/public/mercadopago_webhook.php'), 'G02a arquivo mercadopago_webhook.php removido');
$hits = mp_guard_scan($code, $exts, '/mercadopago_webhook/i');
mp_guard_assert($hits === [], 'G02b sem referencia a mercadopago_webhook', implode(',', $hits));

// ---- G03: nenhum botao/fluxo inicia pagamento ----
$hits = mp_guard_scan($code, $exts, '/subscription_start|subscription_cancel|action=mp_return|->subscriptionStart|->subscriptionCancel|->mpReturn/i');
mp_guard_assert($hits === [], 'G03 sem rota/metodo de assinatura ativa', implode(',', $hits));

// ---- G04: nenhuma classe/servico MP referenciado no executavel ----
$hits = mp_guard_scan($code, $exts, '/MercadoPagoClient|SubscriptionWebhookService|SubscriptionService/i');
mp_guard_assert($hits === [], 'G04 sem classes MP no executavel', implode(',', $hits));
mp_guard_assert(!file_exists($ROOT . '/src/services/MercadoPagoClient.php'), 'G04b MercadoPagoClient.php removido');
mp_guard_assert(!file_exists($ROOT . '/src/services/SubscriptionWebhookService.php'), 'G04c SubscriptionWebhookService.php removido');
mp_guard_assert(!file_exists($ROOT . '/src/services/SubscriptionService.php'), 'G04d SubscriptionService.php removido');

// ---- G05: sem tokens de gateway no executavel ----
$hits = mp_guard_scan($code, $exts, '/x-signature|card_token_id|init_point|authorized_payments|subscription_preapproval|subscription_authorized_payment/i');
mp_guard_assert($hits === [], 'G05 sem HMAC/token/init_point/preapproval no executavel', implode(',', $hits));

// ---- G06: nenhuma MERCADOPAGO_* exigida pelo app ----
$hits = mp_guard_scan($code, $exts, '/MERCADOPAGO_/');
$envExample = (string)@file_get_contents($ROOT . '/.env.example');
$hitsEnv = [];
foreach (explode("\n", $envExample) as $i => $line) {
    if (preg_match('/MERCADOPAGO_/', $line)) { $hitsEnv[] = '.env.example:' . ($i + 1); }
}
mp_guard_assert($hits === [], 'G06a sem MERCADOPAGO_* em src/public/api', implode(',', $hits));
mp_guard_assert($hitsEnv === [], 'G06b sem MERCADOPAGO_* no .env.example', implode(',', $hitsEnv));

// ---- G07: Meu Plano continua funcionando (cards + precos, sem pagamento) ----
$meuPlano = (string)@file_get_contents($ROOT . '/public/meu_plano.php');
mp_guard_assert(strpos($meuPlano, 'action=subscription_start') === false, 'G07a sem form de assinatura no Meu Plano');
mp_guard_assert(strpos($meuPlano, 'Assinaturas temporariamente indisponíveis.') !== false, 'G07b mensagem neutra presente');
mp_guard_assert(substr_count($meuPlano, 'disabled') >= 1, 'G07c botao desabilitado presente');
mp_guard_assert(strpos($meuPlano, '$planPrice') !== false && strpos($meuPlano, '$currentPrice') !== false, 'G07d precos Pro/Premium preservados (via PlanService)');

// ---- G08: planos internos Gratuito/Pro/Premium continuam existindo ----
$slugs = PlanService::getValidSlugs();
mp_guard_assert(in_array('gratuito', $slugs, true), 'G08a plano gratuito existe');
mp_guard_assert(in_array('pro', $slugs, true), 'G08b plano pro existe');
mp_guard_assert(in_array('premium', $slugs, true), 'G08c plano premium existe');

// ---- G09: historico/schema preservados (inerte, sem migration destrutiva) ----
$schema = (string)@file_get_contents($ROOT . '/database/schema.sql');
$migrations = (string)@file_get_contents($ROOT . '/src/migrations.php');
mp_guard_assert(strpos($schema, 'mp_preapproval_id') !== false, 'G09a coluna historica mp_preapproval_id preservada no schema');
mp_guard_assert(strpos($migrations, 'ADD COLUMN IF NOT EXISTS mp_preapproval_id') !== false, 'G09b migration mantem mp_preapproval_id');
mp_guard_assert(!preg_match('/DROP\s+COLUMN[^;]*mp_preapproval_id/i', $migrations), 'G09c sem DROP COLUMN em mp_preapproval_id');

echo "\n=== RESUMO GUARDAS ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

<?php
/**
 * Guardas — checkout hospedado do plano Mercado Pago (SEM rede, SEM credenciais).
 *
 * Evolucao: o inicio usa SOMENTE o checkout hospedado do plano:
 *   GET https://api.mercadopago.com/preapproval_plan/{PLAN_ID}
 *   src/services/MercadoPagoCheckoutStarter.php
 *   src/controllers/SubscribeController.php (POST + CSRF no site)
 *   botoes Assinar Pro/Premium no Meu Plano (form POST)
 *
 * POST /preapproval foi REMOVIDO (a API atual exige card_token_id e o
 * site nao possui CardForm por decisao do projeto).
 *
 * Estes guardas garantem que a integracao NAO ressuscite a arquitetura
 * antiga (webhook, ativacao por retorno, CardForm, Public Key, polling,
 * POST /preapproval, escrita no banco, novas migrations) e que os
 * segredos nao vazem.
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

function mp_guard_scan_except(string $file, array $dirs, array $exts, string $pattern): array
{
    global $ROOT;
    $hits = mp_guard_scan($dirs, $exts, $pattern);
    return array_values(array_filter($hits, fn($h) => strpos($h, $file) === false));
}

/** Retorna o fonte sem linhas de comentario/docblock. */
function mp_guard_code_only(string $src): string
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

/**
 * Scan que ignora linhas de comentario/docblock (para permitir mencoes
 * documentais como o motivo da remocao do POST /preapproval).
 */
function mp_guard_scan_code(array $dirs, array $exts, string $pattern): array
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
                $t = ltrim($line);
                if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '/*')) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $hits[] = $d . '/' . substr($f->getPathname(), strlen($base) + 1) . ':' . ($i + 1);
                    break;
                }
            }
        }
    }
    return $hits;
}

echo "\n=== GUARDAS etapa 1: checkout minimo controlado ===\n\n";
$code = ['src', 'public', 'api'];
$exts = ['php', 'js'];

// ---- G01: api.mercadopago.com SOMENTE no starter novo (GET preapproval_plan) ----
$hitsAll = mp_guard_scan($code, $exts, '/api\.mercadopago\.com/i');
$hitsOutside = array_values(array_filter($hitsAll, fn($h) => strpos($h, 'services/MercadoPagoCheckoutStarter.php') === false));
mp_guard_assert($hitsOutside === [], 'G01 api.mercadopago.com SOMENTE em MercadoPagoCheckoutStarter.php', implode(',', $hitsOutside));
$starterSrc = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoCheckoutStarter.php');
mp_guard_assert(strpos($starterSrc, 'https://api.mercadopago.com/preapproval_plan/') !== false, 'G01b starter consulta GET /preapproval_plan/{id}');
$ctrlSrcEarly = (string)@file_get_contents($ROOT . '/src/controllers/SubscribeController.php');

// ---- G02: endpoint minimo de webhook (etapa webhook-1, sem efeitos) ----
mp_guard_assert(file_exists($ROOT . '/public/mercadopago_webhook.php'), 'G02a endpoint mercadopago_webhook.php existe');
mp_guard_assert(file_exists($ROOT . '/src/services/MercadoPagoWebhookHandler.php'), 'G02b handler MercadoPagoWebhookHandler.php existe');
$hits = mp_guard_scan($code, $exts, '/mercadopago_webhook/i');
$hitsNew = array_values(array_filter($hits, fn($h) => strpos($h, 'tests/') === false));
$hitsAllowed = array_values(array_filter($hitsNew, fn($h) =>
    strpos($h, 'public/mercadopago_webhook.php') !== false
    || strpos($h, 'services/MercadoPagoWebhookHandler.php') !== false
    || strpos($h, 'mp_removal_guards.php') !== false
    || strpos($h, 'mp_webhook_tests.php') !== false
));
mp_guard_assert(count($hitsNew) === count($hitsAllowed), 'G02c mercadopago_webhook SOMENTE nos arquivos novos', implode(',', array_diff($hitsNew, $hitsAllowed)));
$whEndpoint = (string)@file_get_contents($ROOT . '/public/mercadopago_webhook.php');
$whHandler = (string)@file_get_contents($ROOT . '/src/services/MercadoPagoWebhookHandler.php');
mp_guard_assert(strpos($whEndpoint, 'getDBConnection') === false && strpos($whHandler, 'getDBConnection') === false, 'G02d webhook sem DB');

// ---- G03: arquitetura antiga NAO ressuscitada; rota nova permitida ----
$hits = mp_guard_scan($code, $exts, '/subscription_start|subscription_cancel|action=mp_return|->subscriptionStart|->subscriptionCancel|->mpReturn/i');
mp_guard_assert($hits === [], 'G03a sem rota/metodo da arquitetura antiga', implode(',', $hits));
$routerSrc = (string)@file_get_contents($ROOT . '/public/index.php');
mp_guard_assert(strpos($routerSrc, "'subscribe'") !== false, 'G03b rota action=subscribe existe');
mp_guard_assert(file_exists($ROOT . '/src/controllers/SubscribeController.php'), 'G03c SubscribeController.php existe');
mp_guard_assert(file_exists($ROOT . '/src/services/MercadoPagoCheckoutStarter.php'), 'G03d MercadoPagoCheckoutStarter.php existe');

// ---- G04: classes antigas NAO voltaram; classes novas permitidas ----
$hits = mp_guard_scan($code, $exts, '/MercadoPagoClient|SubscriptionWebhookService|SubscriptionService/i');
mp_guard_assert($hits === [], 'G04a sem classes antigas no executavel', implode(',', $hits));
mp_guard_assert(!file_exists($ROOT . '/src/services/MercadoPagoClient.php'), 'G04b MercadoPagoClient.php ausente');
mp_guard_assert(!file_exists($ROOT . '/src/services/SubscriptionWebhookService.php'), 'G04c SubscriptionWebhookService.php ausente');
mp_guard_assert(!file_exists($ROOT . '/src/services/SubscriptionService.php'), 'G04d SubscriptionService.php ausente');

// ---- G05: sem HMAC/cartao fora do webhook minimo; POST /preapproval REMOVIDO ----
$hitsBad = mp_guard_scan_code($code, $exts, '/x-signature|card_token_id|authorized_payments|subscription_preapproval|subscription_authorized_payment/i');
$hitsBadOutside = array_values(array_filter($hitsBad, fn($h) =>
    strpos($h, 'services/MercadoPagoWebhookHandler.php') === false
    && strpos($h, 'public/mercadopago_webhook.php') === false
));
mp_guard_assert($hitsBadOutside === [], 'G05a HMAC/cartao SOMENTE no webhook minimo', implode(',', $hitsBadOutside));
mp_guard_assert(strpos($starterSrc, 'CURLOPT_POSTFIELDS') === false, 'G05a2 starter sem POST (sem POST /preapproval)');
mp_guard_assert(strpos($starterSrc, '/preapproval\'') === false && strpos($starterSrc, '/preapproval"') === false, 'G05a3 starter sem endpoint POST /preapproval');
$hitsInitOutside = mp_guard_scan_except('services/MercadoPagoCheckoutStarter.php', $code, $exts, '/[\'"]init_point[\'"]/i');
$hitsInitOutside = array_values(array_filter($hitsInitOutside, fn($h) => strpos($h, 'tests/') === false && strpos($h, 'mp_checkout_start_tests.php') === false));
mp_guard_assert($hitsInitOutside === [], 'G05b init_point SOMENTE no starter novo', implode(',', $hitsInitOutside));
// Sem CardForm / MP.js / polling / Public Key em codigo executavel
$hitsCard = mp_guard_scan($code, $exts, '/CardForm\s*\(|createCardToken|sdk\.mercadopago\.com|MERCADOPAGO_PUBLIC_KEY/i');
mp_guard_assert($hitsCard === [], 'G05c sem CardForm/MP.js/Public Key', implode(',', $hitsCard));

// ---- G06: SOMENTE as vars MP desta integracao (+ placeholders comentados) ----
$hits = mp_guard_scan($code, $exts, '/MERCADOPAGO_/');
$allowedFiles = ['services/MercadoPagoCheckoutStarter.php', 'services/MercadoPagoWebhookHandler.php', 'public/mercadopago_webhook.php'];
$hitsOutside = array_values(array_filter($hits, function ($h) use ($allowedFiles) {
    foreach ($allowedFiles as $a) {
        if (strpos($h, $a) !== false) {
            return false;
        }
    }
    return true;
}));
mp_guard_assert($hitsOutside === [], 'G06a MERCADOPAGO_* SOMENTE no starter/webhook novos', implode(',', $hitsOutside));
mp_guard_assert(preg_match('/MERCADOPAGO_PUBLIC_KEY|MERCADOPAGO_CLIENT_/i', $starterSrc) !== 1, 'G06b starter NAO le Public Key/Client');
// .env.example: permite SOMENTE placeholders comentados e vazios das vars oficiais
$envExample = (string)@file_get_contents($ROOT . '/.env.example');
$envMpLines = [];
foreach (explode("\n", $envExample) as $i => $line) {
    if (preg_match('/MERCADOPAGO_/', $line)) {
        $envMpLines[] = ['n' => $i + 1, 'line' => $line];
    }
}
$envOk = true;
foreach ($envMpLines as $e) {
    $t = trim($e['line']);
    $isCommented = str_starts_with($t, '#');
    $isAllowedKey = str_contains($t, 'MERCADOPAGO_ACCESS_TOKEN') || str_contains($t, 'MERCADOPAGO_PLAN_ID_PRO') || str_contains($t, 'MERCADOPAGO_PLAN_ID_PREMIUM') || str_contains($t, 'MERCADOPAGO_WEBHOOK_SECRET');
    // placeholder deve ser comentado e sem valor real (termina com = ou vazio apos =)
    $hasRealValue = preg_match('/^#?\s*MERCADOPAGO_\w+\s*=\s*\S+/', $t) === 1;
    if (!$isCommented || !$isAllowedKey || $hasRealValue) {
        $envOk = false;
    }
}
mp_guard_assert($envOk && count($envMpLines) === 4, 'G06c .env.example tem SOMENTE 4 placeholders comentados/vazios', json_encode($envMpLines));
// View nunca referencia segredos
$meuPlano = (string)@file_get_contents($ROOT . '/public/meu_plano.php');
mp_guard_assert(strpos($meuPlano, 'MERCADOPAGO_') === false, 'G06d view sem MERCADOPAGO_*');

// ---- G07: Meu Plano etapa 1 (POST + CSRF, sem cartao, precos ok) ----
mp_guard_assert(strpos($meuPlano, 'action=subscribe') !== false, 'G07a form aponta para action=subscribe');
mp_guard_assert(preg_match('/<form[^>]*method="POST"[^>]*action="\/index\.php\?action=subscribe"/i', $meuPlano) === 1, 'G07b form POST para subscribe');
mp_guard_assert(strpos($meuPlano, 'csrf_field()') !== false, 'G07c form inclui csrf_field()');
mp_guard_assert(strpos($meuPlano, 'name="plan"') !== false, 'G07d form envia slug do plano');
mp_guard_assert(strpos($meuPlano, 'action=subscribe&amp;plan=') === false && strpos($meuPlano, 'action=subscribe&plan=') === false, 'G07e sem link GET criador de assinatura');
mp_guard_assert(stripos($meuPlano, 'cardform') === false && stripos($meuPlano, 'mercadopago.js') === false, 'G07f sem CardForm/MP.js na view');
mp_guard_assert(strpos($meuPlano, '$planPrice') !== false && strpos($meuPlano, '$currentPrice') !== false, 'G07d precos Pro/Premium preservados (via PlanService)');
mp_guard_assert(strpos($meuPlano, 'Assinaturas temporariamente indisponíveis.') === false, 'G07e mensagem de indisponibilidade removida (etapa 1 ativa)');

// ---- G11: revisao final — POST-only, CSRF, timeout, host oficial ----
mp_guard_assert(strpos($routerSrc, "'subscribe'") !== false && strpos($routerSrc, 'csrfProtectedActions') !== false, 'G11a subscribe no router com CSRF');
$csrfBlock = '';
if (preg_match("/\\\$csrfProtectedActions\s*=\s*\[(.*?)\];/s", $routerSrc, $m)) {
    $csrfBlock = $m[1];
}
mp_guard_assert(strpos($csrfBlock, "'subscribe'") !== false, 'G11b subscribe listado em csrfProtectedActions');
mp_guard_assert(strpos($ctrlSrcEarly, "REQUEST_METHOD") !== false && strpos($ctrlSrcEarly, "'POST'") !== false, 'G11c controller exige POST');
mp_guard_assert(strpos($ctrlSrcEarly, 'csrf') !== false && strpos($ctrlSrcEarly, 'invalid_csrf') !== false, 'G11d controller valida CSRF');
mp_guard_assert(strpos($ctrlSrcEarly, "\$_POST['plan']") !== false, 'G11e plano lido de $_POST');
mp_guard_assert(strpos($starterSrc, 'TIMEOUT_SECONDS = 7') !== false && strpos($starterSrc, 'CONNECT_TIMEOUT_SECONDS = 3') !== false, 'G11f timeouts 7s/3s (< 10s)');
mp_guard_assert(strpos($starterSrc, 'isOfficialCheckoutUrl') !== false, 'G11g validacao de host oficial presente');
mp_guard_assert(strpos($starterSrc, '.mercadopago.com') !== false, 'G11h allowlist mercadopago.com');
mp_guard_assert(strpos($starterSrc, 'resolveCheckoutUrl') !== false, 'G11i starter resolve via GET do plano');
mp_guard_assert(strpos($starterSrc, 'validatePlanResponse') !== false, 'G11j starter valida id/status/init_point');

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

// ---- G10: etapa 1 nao escreve no banco nem ativa plano ----
$ctrlSrc = (string)@file_get_contents($ROOT . '/src/controllers/SubscribeController.php');
mp_guard_assert(strpos($starterSrc, 'UPDATE usuarios') === false && strpos($starterSrc, 'INSERT INTO subscriptions') === false, 'G10a starter sem escrita no banco');
mp_guard_assert(strpos($ctrlSrc, 'UPDATE') === false && strpos($ctrlSrc, 'INSERT INTO') === false, 'G10b controller sem escrita no banco');
mp_guard_assert(stripos($ctrlSrc, 'CREATE TABLE') === false && stripos($starterSrc, 'CREATE TABLE') === false, 'G10c sem DDL/migration');
$profileSrc = (string)@file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$meuPlanoFn = '';
if (preg_match('/function meuPlano\(\).*?^    \}/ms', $profileSrc, $m)) {
    $meuPlanoFn = $m[0];
}
mp_guard_assert(stripos($meuPlanoFn, 'UPDATE usuarios SET plano') === false, 'G10d retorno NAO ativa plano');

// ---- G12: webhook minimo sem efeitos (sem token, sem banco, sem ativacao) ----
mp_guard_assert(strpos(mp_guard_code_only($whHandler), 'MERCADOPAGO_ACCESS_TOKEN') === false && strpos(mp_guard_code_only($whEndpoint), 'MERCADOPAGO_ACCESS_TOKEN') === false, 'G12a webhook NAO usa Access Token');
mp_guard_assert(strpos($whHandler, 'INSERT INTO') === false && strpos($whHandler, 'UPDATE ') === false && strpos($whHandler, 'DELETE FROM') === false, 'G12b handler sem escrita');
mp_guard_assert(strpos($whEndpoint, 'INSERT INTO') === false && strpos($whEndpoint, 'UPDATE ') === false && strpos($whEndpoint, 'DELETE FROM') === false, 'G12c endpoint sem escrita');
mp_guard_assert(strpos($whEndpoint, 'config.php') === false && strpos($whEndpoint, 'session_start') === false, 'G12d endpoint standalone (sem config/sessao)');
mp_guard_assert(strpos($whEndpoint, 'php://input') !== false, 'G12e endpoint le body bruto');
mp_guard_assert(stripos($whHandler . $whEndpoint, 'UPDATE usuarios SET plano') === false, 'G12f webhook nao ativa plano');

echo "\n=== RESUMO GUARDAS ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

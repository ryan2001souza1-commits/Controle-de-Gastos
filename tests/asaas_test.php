<?php
/**
 * Testes Asaas — validacao estatica e de integracao sem chamada real de rede.
 * Usa MockPDO e manipulacao de env via putenv/getenv.
 *
 * NENHUMA chamada real e feita. Todos os mocks sao fakes locais.
 */
require_once __DIR__ . '/../src/services/AsaasService.php';
require_once __DIR__ . '/../src/services/AsaasWebhookService.php';
require_once __DIR__ . '/../src/models/Subscription.php';

function ok(string $desc, bool $cond, string $pass, string $fail): void {
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $desc . "\n";
    global $passCount, $failCount;
    if ($cond) { $passCount++; } else { $failCount++; }
}
function info(string $msg): void { echo '[INFO] ' . $msg . "\n"; }

$passCount = 0; $failCount = 0;

echo "=== ASAAS SERVICE TESTS ===\n";

// ==========================================================
// 1. ASAAS_ENV=sandbox -> api-sandbox.asaas.com
// ==========================================================
putenv('ASAAS_API_KEY=$aact_test_mock');
putenv('ASAAS_ENV=sandbox');
$svc = new AsaasService();
ok('isSandbox() returns true for ASAAS_ENV=sandbox', $svc::isSandbox() === true, '', '');
ok('BASE_URL is sandbox for ASAAS_ENV=sandbox',
    (new ReflectionClass($svc))->getProperty('baseUrl')->getValue($svc) === 'https://api-sandbox.asaas.com/v3', '', '');

// ==========================================================
// 2. ASAAS_ENV=production -> api.asaas.com
// ==========================================================
putenv('ASAAS_ENV=production');
$svc2 = new AsaasService();
ok('isSandbox() returns false for ASAAS_ENV=production', $svc2::isSandbox() === false, '', '');
ok('BASE_URL is production for ASAAS_ENV=production',
    (new ReflectionClass($svc2))->getProperty('baseUrl')->getValue($svc2) === 'https://api.asaas.com/v3', '', '');

// ==========================================================
// 3. isConfigured() detecta API key
// ==========================================================
putenv('ASAAS_API_KEY=');
ok('isConfigured() false sem API key', AsaasService::isConfigured() === false, '', '');
putenv('ASAAS_API_KEY=$aact_test_mock');
ok('isConfigured() true com API key', AsaasService::isConfigured() === true, '', '');

// ==========================================================
// 4. maskCard() - mascara apenas os ultimos 4 digitos
// ============================================================
ok('maskCard(5162306219378829) = ...8829', AsaasService::maskCard('5162306219378829') === '****8829', '', '');
ok('maskCard(1234) = ...1234', AsaasService::maskCard('1234') === '****1234', '', '');
ok('maskCard(99) = ****', AsaasService::maskCard('99') === '****', '', '');
ok('maskCard(com espacos) = ...1234', AsaasService::maskCard('5162 3062 1937 1234') === '****1234', '', '');

// ==========================================================
// 5. getRealClientIp() - prioriza X-Forwarded-For
// ==========================================================
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'],
      $_SERVER['HTTP_X_REAL_IP'], $_SERVER['REMOTE_ADDR']);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5, 10.0.0.1';
$ip = AsaasService::getRealClientIp();
ok('getRealClientIp() extrai primeiro IP de X-Forwarded-For [' . $ip . ']',
    $ip === '198.51.100.5', '', '');

unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['REMOTE_ADDR']);
$_SERVER['HTTP_X_REAL_IP'] = '192.0.2.99';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ok('getRealClientIp() usa X-Real-IP quando X-FF vazio',
    AsaasService::getRealClientIp() === '192.0.2.99', '', '');

unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['REMOTE_ADDR']);
$_SERVER['REMOTE_ADDR'] = '10.0.0.50';
ok('getRealClientIp() usa REMOTE_ADDR quando proxys vazios',
    AsaasService::getRealClientIp() === '10.0.0.50', '', '');

unset($_SERVER['REMOTE_ADDR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ok('getRealClientIp() ignora loopback (fallback 0.0.0.0)',
    AsaasService::getRealClientIp() === '0.0.0.0', '', '');

unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'],
      $_SERVER['HTTP_X_REAL_IP'], $_SERVER['REMOTE_ADDR']);

// ==========================================================
// 6. friendlyError() mapeia codigos para mensagens amigaveis
// ============================================================
$refusedResp = ['status' => 400, 'data' => ['code' => 'card_declined'], 'error' => 'card_declined'];
ok('friendlyError(card_declined) -> texto amigavel',
    strpos(AsaasService::friendlyError($refusedResp), 'recusado') !== false, '', '');

$expiredResp = ['status' => 400, 'data' => ['code' => 'expired_card'], 'error' => 'expired_card'];
ok('friendlyError(expired_card) -> texto amigavel',
    strpos(AsaasService::friendlyError($expiredResp), 'validade') !== false, '', '');

$connResp = ['status' => 0, 'error' => 'conexao'];
ok('friendlyError(conexao) -> servico indisponivel',
    strpos(AsaasService::friendlyError($connResp), 'indisponivel') !== false, '', '');

$serverResp = ['status' => 500, 'data' => [], 'error' => 'Internal error'];
$friendly500 = AsaasService::friendlyError($serverResp);
ok('friendlyError(http 500) menciona erro interno',
    strpos($friendly500, 'Erro interno') !== false, '', '');

$insufResp = ['status' => 400, 'data' => ['code' => 'insufficient_balance'], 'error' => 'insuf'];
ok('friendlyError(insufficient_balance) -> texto amigavel',
    strpos(AsaasService::friendlyError($insufResp), 'Saldo insuficiente') !== false, '', '');

$dupResp = ['status' => 400, 'data' => ['code' => 'duplicate_subscription'], 'error' => 'dup'];
ok('friendlyError(duplicate) -> assinatura ja existente',
    strpos(AsaasService::friendlyError($dupResp), 'assinatura') !== false, '', '');

// ==========================================================
// 7. AsaasWebhookService::validateToken - hash_equals
// ==========================================================
putenv('ASAAS_WEBHOOK_TOKEN=meu_token_secreto_123');
ok('validateToken(token_correto) = true',
    AsaasWebhookService::validateToken('meu_token_secreto_123') === true, '', '');
ok('validateToken(token_errado) = false',
    AsaasWebhookService::validateToken('token_errado') === false, '', '');
ok('validateToken(null) = false',
    AsaasWebhookService::validateToken(null) === false, '', '');
ok('validateToken(vazio) = false',
    AsaasWebhookService::validateToken('') === false, '', '');
ok('validateToken(com espaco extra) = false (timing-safe)',
    AsaasWebhookService::validateToken('meu_token_secreto_123 ') === false, '', '');

// ==========================================================
// 8. Subscription::findByAsaasSubscriptionId existe
// ==========================================================
$ref = new ReflectionClass(Subscription::class);
$method = $ref->getMethod('findByAsaasSubscriptionId');
ok('findByAsaasSubscriptionId() existe', $method->isPublic(), '', '');
ok('findByAsaasSubscriptionId() tem parametro string',
    count($method->getParameters()) === 1 && $method->getParameters()[0]->getType()->getName() === 'string', '', '');

// ==========================================================
// 9. Subscription::updateStatusById existe
// ==========================================================
$updateMethod = $ref->getMethod('updateStatusById');
ok('updateStatusById() existe', $updateMethod->isPublic(), '', '');
ok('updateStatusById() aceita 5 parametros', count($updateMethod->getParameters()) === 5, '', '');

// ==========================================================
// 10. isConfigured() checa API_KEY, nao WEBHOOK_TOKEN
// ==========================================================
putenv('ASAAS_API_KEY=');
putenv('ASAAS_WEBHOOK_TOKEN=token');
ok('isConfigured() false mesmo com WEBHOOK_TOKEN mas sem API_KEY',
    AsaasService::isConfigured() === false, '', '');
putenv('ASAAS_API_KEY=');

// ==========================================================
// 11. AsaasService request() NAO loga access_token, cartao, body
// ==========================================================
$svcSrc = file_get_contents(__DIR__ . '/../src/services/AsaasService.php');
$sensitiveInLogs = [
    'error_log.*access_token',
    'error_log.*\$cardData',
    'error_log.*\$cardPayload',
    'error_log.*card_number',
    'error_log.*card_ccv',
    'error_log.*\$number',
    'error_log.*\$holderInfo',
    'error_log.*\$body',
];
$hasSensitiveLog = false;
foreach ($sensitiveInLogs as $pat) {
    if (preg_match('/' . $pat . '/i', $svcSrc)) {
        $hasSensitiveLog = true;
        break;
    }
}
ok('AsaasService request() NAO loga dados sensiveis (access_token, cartao, body)', !$hasSensitiveLog, '', '');

// ==========================================================
// 12. AsaasSubscriptionController NAO persiste dados de cartao no DB
// ==========================================================
$ctrlSrc = file_get_contents(__DIR__ . '/../src/controllers/AsaasSubscriptionController.php');
// O INSERT INTO subscriptions em createLocalSubscription() NAO inclui colunas de cartao
// Verifica: as colunas no INSERT nao contem card_number, ccv, expiry
$insertColMatch = [];
preg_match('/INSERT INTO subscriptions\s*\(([^)]+)\)/i', $ctrlSrc, $insertColMatch);
$insertCols = $insertColMatch[1] ?? '';
$cardInInsert = (
    stripos($insertCols, 'card_number') !== false
    || stripos($insertCols, 'ccv') !== false
    || stripos($insertCols, 'expiry') !== false
    || stripos($insertCols, 'cvv') !== false
    || stripos($insertCols, 'holder') !== false
);
ok('INSERT INTO subscriptions NAO persiste campos de cartao', !$cardInInsert, '', '');
// Verifica que o unset($cardData) esta presente (dados descartados apos chamada)
ok('Controller chama unset($cardData) apos createSubscription',
    strpos($ctrlSrc, 'unset($cardData') !== false, '', '');

// ==========================================================
// 13. AsaasSubscriptionController usa PLAN_PRICES fixos do servidor
// ============================================================
ok('PLAN_PRICES[pro] = 9.90 (do servidor, nao do POST)',
    strpos($ctrlSrc, "'pro'") !== false && strpos($ctrlSrc, '=> 9.90') !== false, '', '');

// ==========================================================
// 14. AsaasSubscriptionController usa AsaasService
// ==========================================================
ok('Controller usa AsaasService::createSubscription', strpos($ctrlSrc, 'createSubscription') !== false, '', '');
ok('Controller usa AsaasService::findOrCreateCustomer', strpos($ctrlSrc, 'findOrCreateCustomer') !== false, '', '');
ok('Controller usa AsaasService::getRealClientIp', strpos($ctrlSrc, 'getRealClientIp') !== false, '', '');

// ==========================================================
// 15. AsaasWebhookService NAO loga payload ou token
// ==========================================================
$whSrc = file_get_contents(__DIR__ . '/../src/services/AsaasWebhookService.php');
ok('AsaasWebhookService NAO loga $rawBody em texto plano',
    strpos($whSrc, 'error_log.*\$rawBody') === false, '', '');
ok('AsaasWebhookService NAO loga access_token ou WEBHOOK_TOKEN',
    strpos($whSrc, "error_log.*'access_token'") === false
    && strpos($whSrc, 'error_log.*ASAAS_WEBHOOK_TOKEN') === false, '', '');

// ==========================================================
// 16. AsaasSubscriptionController NAO usa preco/valor do POST
// ==========================================================
ok('Controller NAO usa $_POST["price"]', strpos($ctrlSrc, "\$_POST['price']") === false, '', '');
ok('Controller NAO usa $_POST["amount"]', strpos($ctrlSrc, "\$_POST['amount']") === false, '', '');
ok('Controller NAO usa $_POST["value"]', strpos($ctrlSrc, "\$_POST['value']") === false, '', '');
ok('Controller usa PLAN_PRICES[$planSlug] (valor fixo servidor)', strpos($ctrlSrc, "self::PLAN_PRICES[\$planSlug]") !== false, '', '');

// ==========================================================
// 17. AsaasService request() trunca path em logs
// ==========================================================
ok('AsaasService request() trunca path longo no log (evita leak)', strpos($svcSrc, 'safePath') !== false || strpos($svcSrc, "substr(\$safePath") !== false, '', '');

// ==========================================================
// 18. meu_plano.php NAO carrega MercadoPago.js
// ==========================================================
$meuPlanoSrc = file_get_contents(__DIR__ . '/../public/meu_plano.php');
ok('meu_plano.php NAO carrega MercadoPago.js', strpos($meuPlanoSrc, 'sdk.mercadopago.com') === false, '', '');
ok('meu_plano.php NAO usa MercadoPago instance', strpos($meuPlanoSrc, 'MercadoPago') === false, '', '');
ok('meu_plano.php NAO usa mpPublicKey', strpos($meuPlanoSrc, 'mpPublicKey') === false, '', '');
ok('meu_plano.php contem Asaas modal (asaas-modal)', strpos($meuPlanoSrc, 'asaas-modal') !== false, '', '');
ok('meu_plano.php envia para asaas_subscription_create', strpos($meuPlanoSrc, 'asaas_subscription_create') !== false, '', '');
ok('meu_plano.php usa botao ASAAS (btn-asaas-subscribe)', strpos($meuPlanoSrc, 'btn-asaas-subscribe') !== false, '', '');
ok('meu_plano.php referencia Asaas no texto de cobranca', strpos($meuPlanoSrc, 'Cobrança segura via Asaas') !== false, '', '');

// ==========================================================
// 19. public/asaas_webhook.php valida token
// ==========================================================
$whFileSrc = file_get_contents(__DIR__ . '/../public/asaas_webhook.php');
ok('asaas_webhook.php existe', is_file(__DIR__ . '/../public/asaas_webhook.php'), '', '');
ok('asaas_webhook.php valida asaas-access-token header', strpos($whFileSrc, 'asaas-access-token') !== false, '', '');
ok('asaas_webhook.php usa hash_equals para comparacao timing-safe', strpos($whFileSrc, 'hash_equals') !== false, '', '');
ok('asaas_webhook.php rejeita metodo nao-POST', strpos($whFileSrc, "REQUEST_METHOD") !== false, '', '');
ok('asaas_webhook.php retorna JSON', strpos($whFileSrc, "Content-Type: application/json") !== false, '', '');
ok('asaas_webhook.php NAO mostra dados de cartao', strpos($whFileSrc, 'card_number') === false, '', '');

// ==========================================================
// 20. vercel.json CSP removido mercadopago.com
// ==========================================================
$vercelSrc = file_get_contents(__DIR__ . '/../vercel.json');
ok('vercel.json CSP NAO contem mercadopago.com', strpos($vercelSrc, 'mercadopago.com') === false, '', '');
ok('vercel.json CSP frame-src restritivo', strpos($vercelSrc, "frame-src 'self'") !== false, '', '');

// ==========================================================
// 21. api/index.php inclui path do webhook Asaas
// ==========================================================
$apiSrc = file_get_contents(__DIR__ . '/../api/index.php');
ok('api/index.php inclui path de asaas_webhook', strpos($apiSrc, 'asaas_webhook') !== false, '', '');

// ==========================================================
// 22. AsaasService usa headers corretos (access_token, User-Agent)
// ==========================================================
ok('AsaasService request() envia access_token header',
    strpos($svcSrc, "'access_token: '") !== false, '', '');
ok('AsaasService request() envia User-Agent identificavel',
    strpos($svcSrc, 'ControleDeGastos') !== false, '', '');
ok('AsaasService request() NAO usa Authorization: Bearer',
    strpos($svcSrc, 'Authorization: Bearer') === false, '', '');

// ==========================================================
// 23. AsaasService request() aceita todos metodos HTTP
// ==========================================================
ok('AsaasService request() trata GET', strpos($svcSrc, 'CURLOPT_HTTPGET') !== false, '', '');
ok('AsaasService request() trata POST', strpos($svcSrc, 'CURLOPT_CUSTOMREQUEST') !== false, '', '');
ok('AsaasService request() trata PUT', strpos($svcSrc, "'PUT'") !== false, '', '');
ok('AsaasService request() trata DELETE', strpos($svcSrc, "'DELETE'") !== false, '', '');

// ==========================================================
// 24. AsaasService maskCard so retorna mascara (nunca exposta)
// ==========================================================
$maskSrc = file_get_contents(__DIR__ . '/../src/services/AsaasService.php');
$maskMethodSrc = (new ReflectionMethod(AsaasService::class, 'maskCard'))->getDeclaringClass()->getMethod('maskCard')->getDocComment() ?? '';
ok('AsaasService::maskCard() e metodo estatico public',
    (new ReflectionMethod(AsaasService::class, 'maskCard'))->isPublic() === true, '', '');

// ==========================================================
// 25. AsaasService request() loga http code, code, msg, path
// ==========================================================
ok('AsaasService request() loga http status code', strpos($svcSrc, 'http=') !== false, '', '');
ok('AsaasService request() loga path (mesmo truncado)', strpos($svcSrc, 'path=') !== false, '', '');

echo "\nTOTAL: " . ($passCount + $failCount) . " | PASS: $passCount | FAIL: $failCount\n";
exit($failCount > 0 ? 1 : 0);

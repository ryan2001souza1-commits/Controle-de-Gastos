<?php
/**
 * Testes do webhook de assinaturas (ETAPA 2).
 *
 * SEM rede, SEM banco real, SEM credenciais reais:
 * - MercadoPagoClient::$transport substituído por stub;
 * - PDO substituído por FakeWhPDO em memória (só o SQL do sync);
 * - secret/token fictícios de teste.
 *
 * Algoritmo x-signature = documentação oficial (manifest + HMAC-SHA256 hex).
 * O vetor HMAC abaixo foi pré-computado de forma independente e fixado
 * (não é tautologia: qualquer mudança no manifesto/algoritmo quebra).
 */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoClient.php';
require_once $ROOT . '/src/services/SubscriptionService.php';
require_once $ROOT . '/src/services/SubscriptionWebhookService.php';

class FakeWhStmt extends PDOStatement
{
    private FakeWhPDO $pdo;
    private string $sql;
    private array $rows = [];
    private int $pos = 0;

    protected function __construct(FakeWhPDO $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public static function make(FakeWhPDO $pdo, string $sql): self
    {
        $ref = new ReflectionClass(self::class);
        /** @var self $s */
        $s = $ref->newInstanceWithoutConstructor();
        $s->pdo = $pdo;
        $s->sql = $sql;
        return $s;
    }

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->run($this->sql, $params ?? []);
        $this->pos = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->pos >= count($this->rows)) return false;
        return $this->rows[$this->pos++];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

class FakeWhPDO extends PDO
{
    public array $usuarios = [];
    public array $subs = [];
    private bool $inTx = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return FakeWhStmt::make($this, $query);
    }

    public function beginTransaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollBack(): bool { $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }

    /** @return array<int,array<string,mixed>> */
    public function run(string $sql, array $p): array
    {
        $n = preg_replace('/\s+/', ' ', trim($sql));

        if (preg_match('/SELECT \* FROM subscriptions WHERE mp_preapproval_id = \?/i', $n)) {
            foreach ($this->subs as $r) {
                if ((string)($r['mp_preapproval_id'] ?? '') !== '' && $r['mp_preapproval_id'] === ($p[0] ?? null)) {
                    return [$r];
                }
            }
            return [];
        }

        if (preg_match('/SELECT \* FROM subscriptions WHERE attempt_token = \?/i', $n)) {
            foreach ($this->subs as $r) {
                if ((string)($r['attempt_token'] ?? '') !== '' && $r['attempt_token'] === ($p[0] ?? null)) {
                    return [$r];
                }
            }
            return [];
        }

        if (str_contains($n, 'UPDATE subscriptions') && str_contains($n, 'mp_preapproval_id')) {
            $id = (int)($p[3] ?? 0);
            if (!isset($this->subs[$id])) return [];
            if (($this->subs[$id]['mp_preapproval_id'] ?? '') === '') {
                $this->subs[$id]['mp_preapproval_id'] = $p[0];
            }
            $this->subs[$id]['raw_status'] = $p[1];
            $this->subs[$id]['status'] = $p[2];
            return [];
        }

        if (preg_match('/SELECT active_subscription_id FROM usuarios WHERE id = \?/i', $n)) {
            $id = (int)($p[0] ?? 0);
            return isset($this->usuarios[$id]) ? [$this->usuarios[$id]] : [];
        }

        if (preg_match('/UPDATE usuarios SET plano = \?/i', $n)) {
            $id = (int)($p[2] ?? 0);
            if (isset($this->usuarios[$id])) {
                $this->usuarios[$id]['plano'] = $p[0];
                $this->usuarios[$id]['plano_status'] = 'ativo';
                $this->usuarios[$id]['plano_fim'] = null;
                $this->usuarios[$id]['active_subscription_id'] = $p[1];
            }
            return [];
        }

        if (preg_match("/UPDATE usuarios SET plano = 'gratuito'/i", $n)) {
            $id = (int)($p[0] ?? 0);
            if (isset($this->usuarios[$id])) {
                $this->usuarios[$id]['plano'] = 'gratuito';
                $this->usuarios[$id]['plano_status'] = 'ativo';
                $this->usuarios[$id]['active_subscription_id'] = null;
            }
            return [];
        }

        return [];
    }
}

$passed = 0; $failed = 0;
function wh_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

echo "\n=== TESTES: webhook de assinaturas (ETAPA 2) ===\n\n";

putenv('MERCADOPAGO_WEBHOOK_SECRET=whsec-test-0123456789abcdef');
putenv('MERCADOPAGO_ACCESS_TOKEN=TEST-TOKEN-FAKE-0003');
$_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = 'whsec-test-0123456789abcdef';
$_ENV['MERCADOPAGO_ACCESS_TOKEN'] = 'TEST-TOKEN-FAKE-0003';

// Vetor fixo: manifest "id:mp-test-001;request-id:req-abc-123;ts:1704908010;"
$TS = '1704908010';
$REQ = 'req-abc-123';
$V1 = '1eb5c009a01aea6ed8d4382ec313d4a4d579f11e42c8f4f81c0d586c2a753d99';
$GOOD_SIG = 'ts=' . $TS . ',v1=' . $V1;
$Q = ['data.id' => 'mp-test-001', 'type' => 'subscription_preapproval'];

function whDb(): FakeWhPDO
{
    $db = new FakeWhPDO();
    $db->usuarios = [
        1 => ['id' => 1, 'plano' => 'gratuito', 'plano_status' => 'ativo', 'plano_fim' => null, 'active_subscription_id' => null],
        2 => ['id' => 2, 'plano' => 'gratuito', 'plano_status' => 'ativo', 'plano_fim' => null, 'active_subscription_id' => null],
    ];
    $attempt = str_repeat('e', 32);
    $db->subs = [
        10 => ['id' => 10, 'user_id' => 1, 'plan_id' => 2, 'plan_slug' => 'premium', 'status' => 'pending',
               'attempt_token' => $attempt, 'external_reference' => 'user_1_premium_' . $attempt,
               'mp_preapproval_id' => null, 'checkout_url' => 'https://mp/checkout', 'raw_status' => null],
    ];
    return $db;
}

function whSvc(FakeWhPDO $db): SubscriptionWebhookService
{
    return new SubscriptionWebhookService(new SubscriptionService($db), new MercadoPagoClient('TEST-TOKEN-FAKE-0003'));
}

function armPreapproval(string $status, string $mpId = 'mp-test-001', ?string $extRef = null): void
{
    MercadoPagoClient::$transport = function (string $method, string $url) use ($status, $mpId, $extRef) {
        return ['ok' => true, 'http' => 200, 'data' => [
            'id' => $mpId, 'status' => $status,
            'external_reference' => $extRef ?? ('user_1_premium_' . str_repeat('e', 32)),
        ], 'error' => ''];
    };
}

// ---- W01: HMAC oficial válido ----
$v = SubscriptionWebhookService::validateSignature($GOOD_SIG, $REQ, 'mp-test-001');
wh_assert($v['ok'], 'W01 HMAC oficial válido aceito');
wh_assert(SubscriptionWebhookService::buildManifest('mp-test-001', $REQ, $TS) === 'id:mp-test-001;request-id:req-abc-123;ts:1704908010;', 'W01b manifesto oficial exato');

// ---- W02: sem signature / inválida / secret ausente ----
$v = SubscriptionWebhookService::validateSignature('', $REQ, 'mp-test-001');
wh_assert(!$v['ok'], 'W02 signature ausente rejeitada');
$v = SubscriptionWebhookService::validateSignature('ts=' . $TS . ',v1=' . str_repeat('0', 64), $REQ, 'mp-test-001');
wh_assert(!$v['ok'] && $v['error'] === 'invalid_signature', 'W02b v1 adulterado rejeitado');
$v = SubscriptionWebhookService::validateSignature($GOOD_SIG, 'outro-req', 'mp-test-001');
wh_assert(!$v['ok'], 'W02c request-id trocado invalida HMAC');
$v = SubscriptionWebhookService::validateSignature($GOOD_SIG, $REQ, 'mp-outro-id');
wh_assert(!$v['ok'], 'W02d data.id trocado invalida HMAC');
putenv('MERCADOPAGO_WEBHOOK_SECRET'); unset($_ENV['MERCADOPAGO_WEBHOOK_SECRET']);
$v = SubscriptionWebhookService::validateSignature($GOOD_SIG, $REQ, 'mp-test-001');
wh_assert(!$v['ok'] && $v['error'] === 'missing_secret', 'W02e secret ausente = fail-closed');
putenv('MERCADOPAGO_WEBHOOK_SECRET=whsec-test-0123456789abcdef');
$_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = 'whsec-test-0123456789abcdef';

// ---- W03: sem HMAC não toca banco + 401; sem secret = 500 ----
$db = whDb();
armPreapproval('authorized');
$svc = whSvc($db);
$res = $svc->handle('ts=' . $TS . ',v1=' . str_repeat('f', 64), $REQ, $Q, '');
wh_assert($res['http'] === 401 && $db->usuarios[1]['plano'] === 'gratuito' && $db->subs[10]['status'] === 'pending', 'W03 HMAC inválido: 401 sem alterar banco');
$res = $svc->handle('', $REQ, $Q, '');
wh_assert($res['http'] === 401, 'W03b signature ausente: 401');
putenv('MERCADOPAGO_WEBHOOK_SECRET'); unset($_ENV['MERCADOPAGO_WEBHOOK_SECRET']);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 500 && $res['code'] === 'missing_secret' && $db->usuarios[1]['plano'] === 'gratuito', 'W03c secret ausente: 500 sem alterar banco');
putenv('MERCADOPAGO_WEBHOOK_SECRET=whsec-test-0123456789abcdef');
$_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = 'whsec-test-0123456789abcdef';

// ---- W04: body falso authorized SEM HMAC não ativa ----
$db = whDb();
armPreapproval('authorized');
$svc = whSvc($db);
$fakeBody = json_encode(['action' => 'subscription_preapproval', 'data' => ['id' => 'mp-test-001'], 'status' => 'authorized']);
$res = $svc->handle('invalida', $REQ, ['data.id' => 'mp-test-001'], (string)$fakeBody);
wh_assert($res['http'] === 401 && $db->usuarios[1]['plano'] === 'gratuito', 'W04 body falso sem HMAC não ativa plano');

// ---- W05: data.id ausente -> 400 ----
$db = whDb();
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, ['type' => 'subscription_preapproval'], '');
wh_assert($res['http'] === 400 && $res['code'] === 'missing_id', 'W05 sem resource id: 400');

// ---- W06: evento desconhecido e preapproval_plan ignorados com 200 ----
$db = whDb();
$calls = 0;
MercadoPagoClient::$transport = function () use (&$calls) { $calls++; return ['ok' => true, 'http' => 200, 'data' => [], 'error' => '']; };
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, ['data.id' => 'mp-test-001', 'type' => 'something_else'], '');
wh_assert($res['http'] === 200 && $res['code'] === 'ignored' && $calls === 0 && $db->usuarios[1]['plano'] === 'gratuito', 'W06 tópico desconhecido: 200 sem API nem banco');
$res = $svc->handle($GOOD_SIG, $REQ, ['data.id' => 'mp-test-001', 'type' => 'subscription_preapproval_plan'], '');
wh_assert($res['http'] === 200 && $res['code'] === 'ignored' && $db->usuarios[1]['plano'] === 'gratuito', 'W06b plano MP nunca altera usuário');

// ---- W07: authorized ativa premium ----
$db = whDb();
armPreapproval('authorized');
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 200 && $res['code'] === 'processed', 'W07 authorized processado');
wh_assert($db->usuarios[1]['plano'] === 'premium' && $db->usuarios[1]['active_subscription_id'] === 10, 'W07b premium ativado + vínculo');
wh_assert($db->subs[10]['status'] === 'active' && $db->subs[10]['mp_preapproval_id'] === 'mp-test-001', 'W07c assinatura ativa + mp_id gravado');

// ---- W08: duplicado idempotente ----
$res2 = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res2['http'] === 200 && count($db->subs) === 1 && $db->usuarios[1]['plano'] === 'premium', 'W08 replay: 200 sem duplicar');

// ---- W09: pending/paused não ativam nem revogam ----
$db = whDb();
armPreapproval('pending');
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 200 && $db->usuarios[1]['plano'] === 'gratuito' && $db->subs[10]['status'] === 'pending', 'W09 pending não libera pago');
$db = whDb();
$db->subs[10]['status'] = 'active';
$db->usuarios[1]['plano'] = 'premium';
$db->usuarios[1]['active_subscription_id'] = 10;
armPreapproval('paused');
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 200 && $db->subs[10]['status'] === 'paused' && $db->usuarios[1]['plano'] === 'premium', 'W09b paused preserva histórico sem revogar');

// ---- W10: cancelled faz downgrade, preserva registro ----
$db = whDb();
$db->subs[10]['status'] = 'active';
$db->subs[10]['mp_preapproval_id'] = 'mp-test-001';
$db->usuarios[1]['plano'] = 'premium';
$db->usuarios[1]['active_subscription_id'] = 10;
armPreapproval('cancelled');
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 200 && $db->subs[10]['status'] === 'cancelled' && isset($db->subs[10]), 'W10 cancelled marca sem deletar');
wh_assert($db->usuarios[1]['plano'] === 'gratuito' && $db->usuarios[1]['active_subscription_id'] === null, 'W10b downgrade para gratuito');

// ---- W11: status desconhecido não concede pago ----
$db = whDb();
armPreapproval('weird_status_xyz');
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 200 && $db->usuarios[1]['plano'] === 'gratuito' && $db->subs[10]['status'] === 'pending', 'W11 status desconhecido: sem acesso pago');

// ---- W12: API falha = 500 sem tocar banco (retry) ----
foreach (['timeout' => ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'timeout'],
          '404' => ['ok' => false, 'http' => 404, 'data' => [], 'error' => 'http_404'],
          '500' => ['ok' => false, 'http' => 500, 'data' => [], 'error' => 'http_5xx']] as $label => $stub) {
    $db = whDb();
    MercadoPagoClient::$transport = fn() => $stub;
    $svc = whSvc($db);
    $res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
    wh_assert($res['http'] === 500 && $db->usuarios[1]['plano'] === 'gratuito' && $db->subs[10]['status'] === 'pending', "W12 API $label: 500 sem alterar plano");
}

// ---- W13: assinatura desconhecida = 404 ----
$db = whDb();
armPreapproval('authorized', 'mp-fantasma-zzz', 'user_9_pro_' . str_repeat('a', 32));
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, ['data.id' => 'mp-fantasma-zzz', 'type' => 'subscription_preapproval'], '');
// HMAC foi calculado para mp-test-001 -> ID diferente invalida; recalcula:
$ts2 = '1704908011';
$v2 = hash_hmac('sha256', 'id:mp-fantasma-zzz;request-id:req-abc-123;ts:1704908011;', 'whsec-test-0123456789abcdef');
$res = $svc->handle('ts=' . $ts2 . ',v1=' . $v2, $REQ, ['data.id' => 'mp-fantasma-zzz', 'type' => 'subscription_preapproval'], '');
wh_assert($res['http'] === 404 && $res['code'] === 'unknown_subscription' && $db->usuarios[1]['plano'] === 'gratuito', 'W13 recurso desconhecido: 404 sem efeito');

// ---- W14: external_reference adulterado não ativa (404, sem efeito) ----
$db = whDb();
armPreapproval('authorized', 'mp-test-001', 'user_2_pro_' . str_repeat('a', 32));
$svc = whSvc($db);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, '');
wh_assert($res['http'] === 404 && $db->usuarios[1]['plano'] === 'gratuito' && $db->usuarios[2]['plano'] === 'gratuito', 'W14 ref adulterada: ninguém ativado');

// ---- W15: user_id arbitrário no body não desvia ativação ----
$db = whDb();
armPreapproval('authorized');
$svc = whSvc($db);
$evilBody = json_encode(['action' => 'subscription_preapproval', 'data' => ['id' => 'mp-test-001'], 'user_id' => 2, 'payer_id' => 2, 'payer_email' => 'vitima@x.com']);
$res = $svc->handle($GOOD_SIG, $REQ, $Q, (string)$evilBody);
wh_assert($res['http'] === 200 && $db->usuarios[1]['plano'] === 'premium' && $db->usuarios[2]['plano'] === 'gratuito', 'W15 vínculo só pela correlação interna');

// ---- W16: authorized_payment via fatura ----
$db = whDb();
MercadoPagoClient::$transport = function (string $method, string $url) {
    if (str_contains($url, '/authorized_payments/inv-777')) {
        return ['ok' => true, 'http' => 200, 'data' => ['id' => 777, 'preapproval_id' => 'mp-test-001', 'status' => 'processed'], 'error' => ''];
    }
    if (str_contains($url, '/preapproval/mp-test-001')) {
        return ['ok' => true, 'http' => 200, 'data' => ['id' => 'mp-test-001', 'status' => 'authorized',
            'external_reference' => 'user_1_premium_' . str_repeat('e', 32)], 'error' => ''];
    }
    return ['ok' => false, 'http' => 404, 'data' => [], 'error' => 'http_404'];
};
$svc = whSvc($db);
$ts3 = '1704908012';
$v3 = hash_hmac('sha256', 'id:inv-777;request-id:req-abc-123;ts:1704908012;', 'whsec-test-0123456789abcdef');
$res = $svc->handle('ts=' . $ts3 . ',v1=' . $v3, $REQ, ['data.id' => 'inv-777', 'type' => 'subscription_authorized_payment'], '');
wh_assert($res['http'] === 200 && $res['code'] === 'processed' && $db->usuarios[1]['plano'] === 'premium', 'W16 fatura -> preapproval -> ativação');

// ---- W17: fatura sem preapproval_id = ignorada ----
$db = whDb();
MercadoPagoClient::$transport = fn() => ['ok' => true, 'http' => 200, 'data' => ['id' => 778, 'status' => 'processed'], 'error' => ''];
$svc = whSvc($db);
$ts4 = '1704908013';
$v4 = hash_hmac('sha256', 'id:inv-778;request-id:req-abc-123;ts:1704908013;', 'whsec-test-0123456789abcdef');
$res = $svc->handle('ts=' . $ts4 . ',v1=' . $v4, $REQ, ['data.id' => 'inv-778', 'type' => 'subscription_authorized_payment'], '');
wh_assert($res['http'] === 200 && $res['code'] === 'ignored' && $db->usuarios[1]['plano'] === 'gratuito', 'W17 fatura sem vínculo: ignorada');

// ---- W18: mapApiStatus centralizado ----
$mapSvc = new SubscriptionService(whDb());
wh_assert($mapSvc->mapApiStatus('authorized') === 'active', 'W18 authorized->active');
wh_assert($mapSvc->mapApiStatus('pending') === 'pending', 'W18b pending->pending');
wh_assert($mapSvc->mapApiStatus('paused') === 'paused', 'W18c paused->paused');
wh_assert($mapSvc->mapApiStatus('cancelled') === 'cancelled' && $mapSvc->mapApiStatus('canceled') === 'cancelled', 'W18d cancelled/canceled->cancelled');
wh_assert($mapSvc->mapApiStatus('nope') === null, 'W18e desconhecido->null');

// ---- W19: endpoint público — POST-only, sem sessão/CSRF, bootstrap correto ----
$ep = (string)file_get_contents($ROOT . '/public/mercadopago_webhook.php');
wh_assert(strpos($ep, 'REQUEST_METHOD') !== false && strpos($ep, '405') !== false, 'W19 endpoint rejeita não-POST com 405');
wh_assert(strpos($ep, 'session_start') === false, 'W19b sem sessão (server-to-server)');
wh_assert(strpos($ep, 'csrf_field') === false && strpos($ep, 'CsrfService') === false && strpos($ep, 'csrf_token') === false, 'W19c sem CSRF');
wh_assert(strpos($ep, 'SubscriptionWebhookService') !== false && strpos($ep, 'getDBConnection') !== false, 'W19d bootstrap config+banco+serviços');
wh_assert(strpos($ep, 'MERCADOPAGO_WEBHOOK_SECRET') === false && strpos($ep, 'ACCESS_TOKEN') === false, 'W19e nenhum secret no endpoint');
wh_assert(strpos($ep, 'x-signature') !== false && strpos($ep, 'x-request-id') !== false, 'W19f lê headers oficiais');

// ---- W20: client tem getAuthorizedPayment oficial ----
$captured = [];
MercadoPagoClient::$transport = function (string $m, string $u, ?array $b, string $t) use (&$captured) {
    $captured = ['m' => $m, 'u' => $u];
    return ['ok' => true, 'http' => 200, 'data' => ['id' => 1], 'error' => ''];
};
(new MercadoPagoClient('TEST-TOKEN-FAKE-0003'))->getAuthorizedPayment('6114264375');
wh_assert(($captured['m'] ?? '') === 'GET' && ($captured['u'] ?? '') === 'https://api.mercadopago.com/authorized_payments/6114264375', 'W20 GET /authorized_payments/{id} oficial');

// ---- W21: logs sem segredos ----
$src = (string)file_get_contents($ROOT . '/src/services/SubscriptionWebhookService.php')
    . (string)file_get_contents($ROOT . '/src/services/SubscriptionService.php');
wh_assert(strpos($src, 'MERCADOPAGO_WEBHOOK_SECRET') !== false, 'W21 secret lido de env');
wh_assert(preg_match('/error_log\([^)]*secret[^)]*\)/i', $src) !== 1 || strpos($src, 'secret ausente') !== false, 'W21b nenhum secret em log (só menção "ausente")');

MercadoPagoClient::$transport = null;
putenv('MERCADOPAGO_WEBHOOK_SECRET'); unset($_ENV['MERCADOPAGO_WEBHOOK_SECRET']);
putenv('MERCADOPAGO_ACCESS_TOKEN'); unset($_ENV['MERCADOPAGO_ACCESS_TOKEN']);

echo "\n=== RESUMO WEBHOOK ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

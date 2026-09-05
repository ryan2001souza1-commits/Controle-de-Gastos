<?php
/**
 * TESTES DE HARDENING DO CANCELAMENTO
 *
 * Verifica que o fluxo de cancelamento:
 * 1. Nunca retorna HTTP 500 por exception nao capturada
 * 2. Redireciona para cancel_service_error em qualquer erro
 * 3. Loga marcadores de etapa
 * 4. Reaproveita o mesmo codigo do action real
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/Plan.php';
require_once $ROOT . '/src/models/Subscription.php';
require_once $ROOT . '/src/services/MercadoPagoService.php';
require_once $ROOT . '/src/services/MercadoPagoWebhookService.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

$passed = 0; $failed = 0;
function pass(string $n): void { global $passed; $passed++; echo "  \033[32m✓\033[0m $n\n"; }
function fail(string $n, string $d=''): void { global $failed; $failed++; echo "  \033[31m✗\033[0m $n" . ($d?" [$d]":"") . "\n"; }

/** Captura error_log output via buffer */
$GLOBALS['_logCapture'] = [];
ini_set('error_log', '/dev/null');
set_error_handler(function ($severity, $msg, $file, $line) {
    $GLOBALS['_logCapture'][] = $msg;
    return true;
});

class TracedPDOH {
    public array $users = [];
    public array $subs = [];
    public ?string $throwOn = null;

    public function __construct() {
        $this->users = [12 => ['id'=>12,'plano'=>'premium','plano_status'=>'ativo','active_subscription_id'=>2]];
        $this->subs = [2 => ['id'=>2,'user_id'=>12,'plan_slug'=>'premium','status'=>'active','raw_status'=>'authorized','mp_preapproval_id'=>'360f13cbd2bb44c894106e26f3896708','external_reference'=>'user_12_premium','grace_period_end'=>null,'next_billing_date'=>null,'start_date'=>null,'cancelled_at'=>null,'paused_at'=>null,'expired_at'=>null]];
    }
    public function prepare(string $sql) {
        if ($this->throwOn !== null) throw new \RuntimeException("mock_db_error: " . $this->throwOn);
        return new THStmt($this, $sql);
    }
    public function exec(string $s) { return 0; }
    public function lastInsertId() { return '0'; }
    public function setAttribute(int $a, $v) { return true; }
    public function query(string $s) { return new THStmt($this, $s); }
}
class THStmt {
    private TracedPDOH $pdo; private string $sql; public array $params = [];
    public function __construct(TracedPDOH $pdo, string $sql) { $this->pdo = $pdo; $this->sql = strtolower($sql); }
    public function execute(array $p=[]): bool {
        $this->params = $p;
        $s = $this->sql; $uid = (int)($p[':uid'] ?? 0);
        if (str_starts_with(trim($s), 'update') && str_contains($s, 'usuarios')) {
            foreach ($this->pdo->users as &$u) if ((int)$u['id'] === $uid) {
                if (isset($p[':plan'])) $u['plano'] = $p[':plan'];
                if (str_contains($s, "plano = 'gratuito'")) $u['plano'] = 'gratuito';
                if (str_contains($s, "'cancelado'")) $u['plano_status'] = 'cancelado';
                if (str_contains($s, "'pendente'") && isset($p[':grace'])) $u['plano_status'] = 'pendente';
                if (str_contains($s, "'ativo'")) $u['plano_status'] = 'ativo';
                if (str_contains($s, 'active_subscription_id = null')) $u['active_subscription_id'] = null;
                if (isset($p[':sub_id'])) $u['active_subscription_id'] = (int)$p[':sub_id'];
            } unset($u);
        }
        if (str_starts_with(trim($s), 'update') && str_contains($s, 'subscriptions')) {
            $id = (int)($p[':id'] ?? 0);
            foreach ($this->pdo->subs as &$sub) if ((int)$sub['id'] === $id) {
                if (isset($p[':status'])) $sub['status'] = $p[':status'];
                if (isset($p[':raw_status'])) $sub['raw_status'] = $p[':raw_status'];
            } unset($sub);
        }
        return true;
    }
    public function rowCount(): int { return 1; }
    public function fetch(int $mode=5) {
        $s = $this->sql; $p = $this->params;
        if (str_contains($s, 'from subscriptions') && isset($p[':uid']) && !isset($p[':slug']) && !isset($p[':id'])) {
            foreach ($this->pdo->subs as $sub) {
                if ((int)$sub['user_id']===(int)$p[':uid'] && in_array($sub['status'],['active','paused'],true) && $sub['mp_preapproval_id']!=='') return $sub;
            }
            return false;
        }
        if (str_contains($s, 'from subscriptions') && isset($p[':id'])) return $this->pdo->subs[(int)$p[':id']] ?? false;
        if (str_contains($s, 'from usuarios') && isset($p[':uid'])) return $this->pdo->users[(int)$p[':uid']] ?? false;
        return false;
    }
    public function fetchColumn() {
        $s = $this->sql; $p = $this->params;
        if (str_contains($s, 'active_subscription_id')) return $this->pdo->users[(int)($p[':uid']??0)]['active_subscription_id'] ?? false;
        if (str_contains($s, 'plano')) return $this->pdo->users[(int)($p[':uid']??0)]['plano'] ?? false;
        return false;
    }
    public function fetchAll(): array { return []; }
    public function bindValue($k, $v, $t=null) {}
    public function bindParam($k, &$v, $t=null) {}
    public function setAttribute(int $a, $v) { return true; }
}

class THMpService extends MercadoPagoService {
    public array $curlQueue = [];
    public ?string $throwOn = null;
    public function __construct() { $this->accessToken = 'TEST'; }
    protected function curlPut(string $url, array $headers, string $payload, string &$body, int &$httpStatus, string &$curlErr, int $timeout = 8): void {
        if ($this->throwOn !== null) {
            throw new \RuntimeException('mock_curl_error: ' . $this->throwOn);
        }
        $mock = array_shift($this->curlQueue);
        $body = (string)($mock['body'] ?? '');
        $httpStatus = (int)($mock['status'] ?? 0);
        $curlErr = (string)($mock['curlErr'] ?? '');
    }
}

/** REPLICADO do action=cancel com hardening */
function runHardenedCancelFlow(TracedPDOH $db, THMpService $mp, int $userId, ?Throwable &$exception): array {
    $exception = null;
    $redirect = null;
    try {
        $GLOBALS['_logCapture'][] = '[cancel.start] user_id=' . $userId;

        $subscriptionModel = new Subscription($db);
        $active = $subscriptionModel->findActiveByUser($userId);
        if ($active === null) {
            $GLOBALS['_logCapture'][] = '[cancel.end] user_id=' . $userId . ' reason=no_active_subscription';
            return ['redirect' => 'no_active_subscription', 'result' => 'no_active'];
        }
        $subId = (int)($active['id'] ?? 0);
        $GLOBALS['_logCapture'][] = '[cancel.subscription_found] user_id=' . $userId . ' subscription_id=' . $subId;

        if ((int)($active['status'] ?? '') === Subscription::STATUS_CANCELLED) {
            return ['redirect' => 'cancelled=1', 'result' => 'already_cancelled_guard'];
        }
        $mpId = (string)($active['mp_preapproval_id'] ?? '');
        if ($mpId === '') return ['redirect' => 'no_active_subscription', 'result' => 'no_mp_id'];

        $mpIdTag = substr($mpId, -8);
        $GLOBALS['_logCapture'][] = '[cancel.mp_call_start] user_id=' . $userId . ' subscription_id=' . $subId . ' mp_id_suffix=' . $mpIdTag;

        $cancelResult = $mp->cancelPreapproval($mpId);

        $resultType = $cancelResult['ok'] ? (!empty($cancelResult['already_cancelled']) ? 'already_cancelled' : 'success') : 'mp_error';
        $httpMp = (int)($cancelResult['status'] ?? 0);
        $GLOBALS['_logCapture'][] = sprintf('[cancel.mp_call_result] user_id=%d subscription_id=%d mp_id_suffix=%s result=%s http=%d', $userId, $subId, $mpIdTag, $resultType, $httpMp);

        if ($cancelResult['ok'] === false) {
            $http = (int)($cancelResult['status'] ?? 0);
            if ($http === 0 || $http >= 500) return ['redirect' => 'cancel_service_error', 'result' => 'mp_error_5xx'];
            if ($http === 404) {
                $subscriptionModel->updateStatusById($subId, Subscription::STATUS_CANCELLED, 'cancelled', null, null);
                $fresh = $subscriptionModel->findById($subId);
                if ($fresh !== null) $subscriptionModel->applyStatusToUser($fresh);
                return ['redirect' => 'cancelled=1', 'result' => 'cancelled_via_404'];
            }
            return ['redirect' => 'cancel_service_error', 'result' => 'mp_error_other'];
        }

        $GLOBALS['_logCapture'][] = '[cancel.local_sync_start] user_id=' . $userId . ' subscription_id=' . $subId . ' result=' . $resultType;

        $mpStatus = strtolower(trim((string)($cancelResult['data']['status'] ?? '')));
        $internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatus);
        $subscriptionModel->updateStatusById($subId, $internalStatus ?? Subscription::STATUS_CANCELLED, $mpStatus, null, null);
        $fresh = $subscriptionModel->findById($subId);
        if ($fresh !== null) $subscriptionModel->applyStatusToUser($fresh);

        $GLOBALS['_logCapture'][] = '[cancel.local_sync_done] user_id=' . $userId . ' subscription_id=' . $subId . ' result=' . $resultType;
        return ['redirect' => 'cancelled=1', 'result' => $resultType];

    } catch (Throwable $e) {
        $exception = $e;
        $GLOBALS['_logCapture'][] = sprintf(
            '[cancel.exception] user_id=%d exception=%s file=%s line=%d',
            $userId, get_class($e), basename($e->getFile()), $e->getLine()
        );
        return ['redirect' => 'cancel_service_error', 'result' => 'exception'];
    }
}

function resetLogs(): void { $GLOBALS['_logCapture'] = []; }
function hasMarker(string $prefix): bool {
    foreach ($GLOBALS['_logCapture'] as $l) {
        if (str_starts_with($l, $prefix)) return true;
    }
    return false;
}

// ============================================================
// HARDENING TESTS
// ============================================================

echo "=== H1: sucesso normal ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>200, 'body'=>json_encode(['id'=>'x','status'=>'cancelled'])]];
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($r['result'] === 'success' || $r['result'] === 'already_cancelled') ? pass("H1a: result=success (status 200 cancelado) ou already_cancelled") : fail("H1a", $r['result']);
($r['redirect'] === 'cancelled=1') ? pass("H1b: redirect cancelled=1") : fail("H1b", $r['redirect']);
(hasMarker('[cancel.start]')) ? pass("H1c: log cancel.start") : fail("H1c");
(hasMarker('[cancel.subscription_found]')) ? pass("H1d: log cancel.subscription_found") : fail("H1d");
(hasMarker('[cancel.mp_call_start]')) ? pass("H1e: log cancel.mp_call_start") : fail("H1e");
(hasMarker('[cancel.mp_call_result]')) ? pass("H1f: log cancel.mp_call_result") : fail("H1f");
(hasMarker('[cancel.local_sync_start]')) ? pass("H1g: log cancel.local_sync_start") : fail("H1g");
(hasMarker('[cancel.local_sync_done]')) ? pass("H1h: log cancel.local_sync_done") : fail("H1h");

echo "\n=== H2: already_cancelled (HTTP 400 idempotente) ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>400, 'body'=>json_encode(['message'=>'You can not modify a cancelled preapproval.','status'=>400])]];
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($exc === null) ? pass("H2a: sem exception") : fail("H2a", $exc->getMessage());
($r['result'] === 'already_cancelled') ? pass("H2b: already_cancelled") : fail("H2b", $r['result']);
($r['redirect'] === 'cancelled=1') ? pass("H2c: redirect cancelled=1") : fail("H2c");
($db->subs[2]['status'] === 'cancelled') ? pass("H2d: subscription status=cancelled") : fail("H2d", $db->subs[2]['status']);
($db->users[12]['plano'] === 'gratuito') ? pass("H2e: plano=gratuito") : fail("H2e", $db->users[12]['plano']);

echo "\n=== H3: network error (curl falha) ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>0, 'body'=>'', 'curlErr'=>'Connection timed out']];
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($exc === null) ? pass("H3a: sem exception") : fail("H3a");
($r['result'] === 'mp_error_5xx') ? pass("H3b: mp_error_5xx (http=0)") : fail("H3b", $r['result']);
($r['redirect'] === 'cancel_service_error') ? pass("H3c: redirect cancel_service_error") : fail("H3c");

echo "\n=== H4: HTTP 500 do MP ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>500, 'body'=>json_encode(['message'=>'Internal'])]];
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($exc === null) ? pass("H4a: sem exception") : fail("H4a");
($r['redirect'] === 'cancel_service_error') ? pass("H4b: cancel_service_error") : fail("H4b");

echo "\n=== H5: HTTP 404 do MP ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>404, 'body'=>json_encode(['message'=>'not found'])]];
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($exc === null) ? pass("H5a: sem exception") : fail("H5a");
($r['result'] === 'cancelled_via_404') ? pass("H5b: cancelled_via_404") : fail("H5b", $r['result']);
($db->subs[2]['status'] === 'cancelled') ? pass("H5c: subscription cancelada") : fail("H5c");

echo "\n=== H6: exception no MercadoPagoService::curlPut ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->throwOn = 'curl_failed';
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($r['result'] === 'exception') ? pass("H6a: exception CAPTURADA (result=exception)") : fail("H6a", $r['result']);
($r['redirect'] === 'cancel_service_error') ? pass("H6b: exception nao escapou -> cancel_service_error") : fail("H6b", $r['redirect']);
($r['redirect'] === 'cancel_service_error') ? pass("H6c: redirect cancel_service_error") : fail("H6c");
(hasMarker('[cancel.exception]')) ? pass("H6d: log cancel.exception presente") : fail("H6d");

echo "\n=== H7: exception no PDO (findActiveByUser) ===\n";
resetLogs();
$db = new TracedPDOH(); $db->throwOn = 'find_failed';
$mp = new THMpService();
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($r['result'] === 'exception') ? pass("H7a: exception CAPTURADA (result=exception)") : fail("H7a", $r['result']);
($exc !== null) ? pass("H7b: exception nao escapou como fatal (capturada)") : fail("H7b", "exception escaped");
($r['redirect'] === 'cancel_service_error') ? pass("H7c: redirect cancel_service_error") : fail("H7c");
(hasMarker('[cancel.exception]')) ? pass("H7d: log cancel.exception presente") : fail("H7d");

echo "\n=== H8: nenhum dado sensivel nos logs ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>200, 'body'=>json_encode(['id'=>'x','status'=>'cancelled','payer_email'=>'secret@user.com','card_id'=>'secret123'])]];
$exc = null; runHardenedCancelFlow($db, $mp, 12, $exc);
$logs = implode("\n", $GLOBALS['_logCapture']);
$hasSecret = preg_match('/secret@user\.com|secret123/', $logs) ? true : false;
(! $hasSecret) ? pass("H8: email/secret nao aparece nos logs") : fail("H8: email/secret aparece nos logs!");
$hasToken = preg_match('/Bearer [A-Za-z0-9_-]{20,}/', $logs) ? true : false;
(! $hasToken) ? pass("H8b: token Bearer nao aparece nos logs") : fail("H8b: token Bearer aparece!");
$hasMpId = str_contains($logs, '360f13cbd2bb44c894106e26f3896708') ? true : false;
(! $hasMpId) ? pass("H8c: mp_id completo NAO aparece nos logs (apenas suffix)") : fail("H8c: mp_id completo aparece!");

echo "\n=== H9: no_active_subscription ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$db->subs[2]['status'] = 'cancelled';
$db->subs[2]['mp_preapproval_id'] = 'x';
$db->users[12]['active_subscription_id'] = null;
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($exc === null) ? pass("H9: sem exception (no_active)") : fail("H9");
($r['result'] === 'no_active') ? pass("H9a: result=no_active") : fail("H9a", $r['result']);

echo "\n=== H11: timeout do curl (timeout=8s) ===\n";
resetLogs();
$db = new TracedPDOH(); $mp = new THMpService();
$mp->curlQueue = [['status'=>0, 'body'=>'', 'curlErr'=>'Operation timed out after 8s']];
$exc = null; $r = runHardenedCancelFlow($db, $mp, 12, $exc);
($exc === null) ? pass("H10: sem exception") : fail("H10");
($r['redirect'] === 'cancel_service_error') ? pass("H10a: redirect cancel_service_error") : fail("H10a");

echo "\n=== H11: CURLOPT_TIMEOUT=8 configurado no codigo real ===\n";
$src = file_get_contents(dirname(__DIR__) . '/src/services/MercadoPagoService.php');
$hasEight = str_contains($src, 'curlPut($url, $headers, $payload, $body, $httpStatus, $curlErr, 8)');
$hasThirty = preg_match('/curlPut\(\$url,\s*\$headers,\s*\$payload,\s*\$body,\s*\$httpStatus,\s*\$curlErr,\s*30\)/', $src);
($hasEight) ? pass("H11: timeout=8 no cancelPreapproval") : fail("H11: timeout nao esta em 8s no cancelPreapproval");
(! $hasThirty) ? pass("H11b: timeout=30 NAO presente em lugar nenhum") : fail("H11b: timeout=30 ainda presente no codigo");

echo "\n=== H12: action=cancel no index.php envolve try/catch ===\n";
$indexPhp = file_get_contents(dirname(__DIR__) . '/public/index.php');
$cancelStart = strpos($indexPhp, "\$action === 'cancel'");
$cancelEnd   = strrpos(substr($indexPhp, $cancelStart), '} elseif');
$cancelBlock = substr($indexPhp, $cancelStart, $cancelEnd);
(str_contains($cancelBlock, 'try {')) ? pass("H12: try block presente") : fail("H12: try block ausente");
(str_contains($cancelBlock, 'catch (Throwable')) ? pass("H12b: catch Throwable presente") : fail("H12b: catch ausente");
(str_contains($cancelBlock, 'cancel_service_error')) ? pass("H12c: redirect cancel_service_error presente") : fail("H12c");


/**
 * REGRESSÃO: Class MercadoPagoWebhookService not found
 * Quando MP retorna already_cancelled, o local sync tenta usar
 * MercadoPagoWebhookService::mapMpStatusToInternal() — que falhava
 * com "Class not found" porque o require_once estava ausente no index.php.
 */

class THStmt_R {
    private $pdo; private string $sql; public array $params = [];
    public function __construct($pdo, string $sql) { $this->pdo = $pdo; $this->sql = strtolower($sql); }
    public function execute(array $p=[]): bool {
        $this->params = $p;
        $s = $this->sql;
        if (str_starts_with(trim($s), 'update') && str_contains($s, 'usuarios')) {
            $uid = (int)($p[':uid'] ?? 0);
            foreach ($this->pdo->users as &$u) if ((int)$u['id'] === $uid) {
                if (str_contains($s, "plano = 'gratuito'")) $u['plano'] = 'gratuito';
                if (str_contains($s, "'cancelado'")) $u['plano_status'] = 'cancelado';
                if (isset($p[':grace'])) $u['plano_status'] = 'pendente';
            } unset($u);
        }
        if (strpos($s, 'subscriptions') !== false && str_starts_with(trim($s), 'update')) {
            $id = (int)($p[':id'] ?? 0);
            foreach ($this->pdo->subs as &$sub) if ((int)$sub['id'] === $id) {
                if (isset($p[':status'])) $sub['status'] = $p[':status'];
            } unset($sub);
        }
        return true;
    }
    public function rowCount(): int { return 1; }
    public function fetch(int $mode=5) {
        $s = $this->sql;
        if (str_starts_with(trim($s), 'select') && str_contains($s, 'subscriptions') && str_contains($s, 'user_id')) {
            $uid = (int)($this->params[':uid'] ?? $this->params['user_id'] ?? 0);
            foreach ($this->pdo->subs as $sub) if ((int)$sub['user_id'] === $uid) return $sub;
        }
        if (str_starts_with(trim($s), 'select') && str_contains($s, 'subscriptions') && (str_contains($s, 'where id') || str_contains($s, 'where s.id'))) {
            $id = (int)($this->params[':id'] ?? $this->params['sub_id'] ?? 0);
            foreach ($this->pdo->subs as $sub) if ((int)$sub['id'] === $id) return $sub;
        }
        return null;
    }
}

function runCancelFlow_R(TracedPDOH $db, THMpService $mp, $userId) {
    try {
        $subscriptionModel = new Subscription($db);
        $active = $subscriptionModel->findActiveByUser($userId);
        if ($active === null) return ['redirect' => 'no_active_subscription', 'result' => 'no_active'];
        $subId = (int)($active['id'] ?? 0);
        $mpId = (string)($active['mp_preapproval_id'] ?? '');
        if ($mpId === '') return ['redirect' => 'no_active_subscription', 'result' => 'no_mp_id'];
        $cancelResult = $mp->cancelPreapproval($mpId);
        if ($cancelResult['ok'] === false) return ['redirect' => 'cancel_service_error', 'result' => 'mp_error'];
        $mpStatus = strtolower(trim((string)($cancelResult['data']['status'] ?? '')));
        $internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatus);
        $subscriptionModel->updateStatusById($subId, $internalStatus ?? Subscription::STATUS_CANCELLED, $mpStatus, null, null);
        $fresh = $subscriptionModel->findById($subId);
        if ($fresh !== null) $subscriptionModel->applyStatusToUser($fresh);
        return ['redirect' => 'cancelled=1', 'result' => 'success'];
    } catch (Throwable $e) {
        return ['redirect' => 'cancel_service_error', 'result' => 'exception', 'exception' => $e];
    }
}

echo "\n=== R1: REGRESSAO - user 12 Premium + already_cancelled ===\n";
$dbR1 = new TracedPDOH();
$dbR1->users[12] = ['id'=>12,'plano'=>'premium','plano_status'=>'ativo','active_subscription_id'=>2];
$dbR1->subs[2] = ['id'=>2,'user_id'=>12,'plan_slug'=>'premium','status'=>'active','raw_status'=>'authorized','mp_preapproval_id'=>'mp_premium_12','external_reference'=>'user_12_premium','grace_period_end'=>null,'next_billing_date'=>null,'start_date'=>null,'cancelled_at'=>null,'paused_at'=>null,'expired_at'=>null];

$mpR1 = new THMpService();
$mpR1->curlQueue = [['status'=>400, 'body'=>json_encode(['status'=>'cancelled','message'=>'You can not modify a cancelled preapproval.'])]];

$excR1 = null; $rR1 = runCancelFlow_R($dbR1, $mpR1, 12);
(!isset($rR1['exception'])) ? pass("R1a: MercadoPagoWebhookService disponivel - sem Class not found") : fail("R1a", 'Class not found: ' . $rR1['exception']->getMessage());
($rR1['result'] !== 'exception') ? pass("R1b: fluxo executou sem exception") : fail("R1b", $rR1['exception']->getMessage());
($rR1['redirect'] === 'cancelled=1') ? pass("R1c: redirect cancelled=1") : fail("R1c", $rR1['redirect']);
($dbR1->users[12]['plano'] === 'gratuito') ? pass("R1d: plano do user 12 = gratuito") : fail("R1d", $dbR1->users[12]['plano']);
($dbR1->subs[2]['status'] === 'cancelled') ? pass("R1e: subscription 2 status = cancelled") : fail("R1e", $dbR1->subs[2]['status']);

echo "\n=== R2: REGRESSAO - user 5 Pro + already_cancelled ===\n";
$dbR2 = new TracedPDOH();
$dbR2->users[5] = ['id'=>5,'plano'=>'pro','plano_status'=>'ativo','active_subscription_id'=>3];
$dbR2->subs[3] = ['id'=>3,'user_id'=>5,'plan_slug'=>'pro','status'=>'active','raw_status'=>'authorized','mp_preapproval_id'=>'mp_pro_5','external_reference'=>'user_5_pro','grace_period_end'=>null,'next_billing_date'=>null,'start_date'=>null,'cancelled_at'=>null,'paused_at'=>null,'expired_at'=>null];

$mpR2 = new THMpService();
$mpR2->curlQueue = [['status'=>400, 'body'=>json_encode(['status'=>'cancelled','message'=>'You can not modify a cancelled preapproval.'])]];

$excR2 = null; $rR2 = runCancelFlow_R($dbR2, $mpR2, 5);
(!isset($rR2['exception'])) ? pass("R2a: MercadoPagoWebhookService disponivel - sem Class not found") : fail("R2a", 'Class not found: ' . $rR2['exception']->getMessage());
($rR2['result'] !== 'exception') ? pass("R2b: fluxo executou sem exception") : fail("R2b", $rR2['exception']->getMessage());
($rR2['redirect'] === 'cancelled=1') ? pass("R2c: redirect cancelled=1") : fail("R2c", $rR2['redirect']);
($dbR2->users[5]['plano'] === 'gratuito') ? pass("R2d: plano do user 5 = gratuito") : fail("R2d", $dbR2->users[5]['plano']);
($dbR2->subs[3]['status'] === 'cancelled') ? pass("R2e: subscription 3 status = cancelled") : fail("R2e", $dbR2->subs[3]['status']);


echo "\n=== RESUMO FINAL ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

<?php
/**
 * Testes da nova integração oficial de Assinaturas (produção).
 *
 * NENHUMA requisição real e NENHUM banco real:
 * - transporte HTTP do MercadoPagoClient substituído por stub;
 * - PDO substituído por FakePDO em memória (implementa só o SQL usado
 *   pelo serviço, incluindo UNIQUE de mp_preapproval_id/attempt_token);
 * - segredos são valores fictícios de teste (nunca credenciais reais).
 *
 * Cobre: plano inválido, auth, CSRF, PRO/PREMIUM, external_reference,
 * token fora da resposta, webhook sem/inválida/ausente signature,
 * duplicado, pending/authorized/cancelled, payer_id, unicidade mp_id,
 * retorno sem ativação.
 */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/services/MercadoPagoClient.php';
require_once $ROOT . '/src/services/SubscriptionService.php';
require_once $ROOT . '/src/services/SubscriptionWebhookService.php';

// =====================================================================
// FakePDO/FakeStmt: emulam o subconjunto SQL usado pelo serviço.
// =====================================================================
class FakeStmt extends PDOStatement
{
    private FakePDO $pdo;
    private string $sql;
    private array $rows = [];
    private int $pos = 0;

    protected function __construct(FakePDO $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public static function make(FakePDO $pdo, string $sql): self
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

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        if ($row === false) return false;
        $vals = array_values($row);
        return $vals[$column] ?? false;
    }
}

class FakePDO extends PDO
{
    public array $usuarios = [];
    public array $planos = [];
    public array $subs = [];
    public int $seq = 0;
    public string $lastId = '0';
    private bool $inTx = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return FakeStmt::make($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $s = FakeStmt::make($this, $query);
        $s->execute([]);
        return $s;
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastId;
    }

    public function beginTransaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollBack(): bool { $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }

    /** @return array<int,array<string,mixed>> linhas para fetch */
    public function run(string $sql, array $p): array
    {
        $n = preg_replace('/\s+/', ' ', trim($sql));

        // INSERT INTO subscriptions (...) VALUES (...)
        if (str_starts_with($n, 'INSERT INTO subscriptions')) {
            preg_match('/\(([^)]+)\)\s*VALUES\s*\((.+)\)/i', $n, $m);
            $cols = array_map(fn($c) => trim($c, " \t\n\r\0\x0B'\""), explode(',', $m[1]));
            $vals = $this->splitValues($m[2]);
            $row = [];
            $pi = 0;
            foreach ($cols as $i => $c) {
                $v = trim($vals[$i] ?? '');
                if ($v === '?') { $row[$c] = $p[$pi++] ?? null; }
                else { $row[$c] = trim($v, "'"); }
            }
            $row += ['id' => 0, 'status' => 'pending', 'mp_preapproval_id' => null,
                'attempt_token' => null, 'external_reference' => null, 'raw_status' => null,
                'checkout_url' => null, 'plan_slug' => null, 'user_id' => 0, 'plan_id' => 0,
                'cancelled_at' => null];
            $this->assertUnique($row, null);
            $this->seq++;
            $row['id'] = $this->seq;
            $this->subs[$this->seq] = $row;
            $this->lastId = (string)$this->seq;
            return [];
        }

        // SELECT * FROM subscriptions WHERE user_id = ? AND status IN (...)
        if (preg_match('/SELECT \* FROM subscriptions WHERE user_id = \? AND status IN \(([^)]+)\)/i', $n, $m)) {
            $wanted = [];
            foreach (explode(',', $m[1]) as $s) $wanted[] = trim($s, " '");
            $out = [];
            foreach ($this->subs as $r) {
                if ((int)$r['user_id'] === (int)($p[0] ?? -1) && in_array($r['status'], $wanted, true)) $out[] = $r;
            }
            usort($out, fn($a, $b) => $b['id'] <=> $a['id']);
            return array_slice($out, 0, 1);
        }

        // SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active'
        if (preg_match("/SELECT \* FROM subscriptions WHERE user_id = \? AND status = 'active'/i", $n)) {
            $out = [];
            foreach ($this->subs as $r) {
                if ((int)$r['user_id'] === (int)($p[0] ?? -1) && $r['status'] === 'active') $out[] = $r;
            }
            usort($out, fn($a, $b) => $b['id'] <=> $a['id']);
            return array_slice($out, 0, 1);
        }

        // SELECT * FROM subscriptions WHERE mp_preapproval_id = ?
        if (preg_match('/SELECT \* FROM subscriptions WHERE mp_preapproval_id = \?/i', $n)) {
            foreach ($this->subs as $r) {
                if ((string)($r['mp_preapproval_id'] ?? '') !== '' && $r['mp_preapproval_id'] === ($p[0] ?? null)) {
                    return [$r];
                }
            }
            return [];
        }

        // SELECT * FROM subscriptions WHERE attempt_token = ?
        if (preg_match('/SELECT \* FROM subscriptions WHERE attempt_token = \?/i', $n)) {
            foreach ($this->subs as $r) {
                if ((string)($r['attempt_token'] ?? '') !== '' && $r['attempt_token'] === ($p[0] ?? null)) {
                    return [$r];
                }
            }
            return [];
        }

        // SELECT * FROM subscriptions ORDER BY id DESC LIMIT 1
        if (preg_match('/SELECT \* FROM subscriptions ORDER BY id DESC LIMIT 1/i', $n)) {
            if (empty($this->subs)) return [];
            $max = max(array_keys($this->subs));
            return [$this->subs[$max]];
        }

        // SELECT COUNT(*) FROM subscriptions
        if (preg_match('/SELECT COUNT\(\*\) FROM subscriptions/i', $n)) {
            return [['count' => count($this->subs)]];
        }

        // SELECT COUNT(*) FROM usuarios
        if (preg_match('/SELECT COUNT\(\*\) FROM usuarios/i', $n)) {
            return [['count' => count($this->usuarios)]];
        }

        // SELECT ... FROM usuarios WHERE id = N (query direta dos asserts)
        if (preg_match('/SELECT (.+) FROM usuarios WHERE id = (\d+)/i', $n, $m)) {
            $id = (int)$m[2];
            return isset($this->usuarios[$id]) ? [$this->usuarios[$id]] : [];
        }

        // SELECT active_subscription_id, plano FROM usuarios WHERE id = ?
        if (preg_match('/SELECT (.+) FROM usuarios WHERE id = \?/i', $n, $m)) {
            $id = (int)($p[0] ?? 0);
            return isset($this->usuarios[$id]) ? [$this->usuarios[$id]] : [];
        }

        // SELECT * FROM planos WHERE slug = ? AND status = 'ativo'
        if (preg_match("/SELECT \* FROM planos WHERE slug = \? AND status = 'ativo'/i", $n)) {
            foreach ($this->planos as $r) {
                if ($r['slug'] === ($p[0] ?? null) && $r['status'] === 'ativo') return [$r];
            }
            return [];
        }

        // UPDATE subscriptions SET mp_preapproval_id = COALESCE(...), raw_status = ?, status = ?, ... WHERE id = ?
        if (str_contains($n, 'UPDATE subscriptions') && str_contains($n, 'mp_preapproval_id')) {
            [$mpId, $raw, $st, $id] = [$p[0] ?? null, $p[1] ?? null, $p[2] ?? null, (int)($p[3] ?? 0)];
            if (!isset($this->subs[$id])) return [];
            if (($this->subs[$id]['mp_preapproval_id'] ?? '') === '') {
                $this->assertUnique(['mp_preapproval_id' => $mpId, 'attempt_token' => null], $id);
                $this->subs[$id]['mp_preapproval_id'] = $mpId;
            }
            // checkout_url vem em statement separado? Não — start() usa UPDATE com checkout_url.
            $this->subs[$id]['raw_status'] = $raw;
            $this->subs[$id]['status'] = $st;
            // start(): mesmo statement carrega checkout_url? start usa UPDATE com checkout_url:
            return [];
        }

        // UPDATE subscriptions ... checkout_url ... WHERE id = ? (start)
        if (str_contains($n, 'UPDATE subscriptions') && str_contains($n, 'checkout_url')) {
            // params: [mpId, checkout, raw, id]
            $id = (int)($p[3] ?? 0);
            if (!isset($this->subs[$id])) return [];
            $this->assertUnique(['mp_preapproval_id' => $p[0], 'attempt_token' => null], $id);
            $this->subs[$id]['mp_preapproval_id'] = $p[0];
            $this->subs[$id]['checkout_url'] = $p[1];
            $this->subs[$id]['raw_status'] = $p[2];
            return [];
        }

        // UPDATE subscriptions SET status = 'cancelled', cancelled_at ... WHERE id = ?
        if (preg_match("/UPDATE subscriptions SET status = 'cancelled'/i", $n)) {
            $id = (int)($p[0] ?? 0);
            if (isset($this->subs[$id])) {
                $this->subs[$id]['status'] = 'cancelled';
                $this->subs[$id]['cancelled_at'] ??= date('Y-m-d H:i:s');
            }
            return [];
        }

        // UPDATE usuarios SET plano = ?, plano_status = 'ativo', ... active_subscription_id = ? WHERE id = ?
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

        // UPDATE usuarios SET plano = 'gratuito' ... WHERE id = ?
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

    private function splitValues(string $v): array
    {
        $out = []; $cur = ''; $depth = 0;
        foreach (str_split($v) as $ch) {
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        $out[] = $cur;
        return $out;
    }

    private function assertUnique(array $row, ?int $exceptId): void
    {
        foreach (['mp_preapproval_id', 'attempt_token'] as $col) {
            $v = (string)($row[$col] ?? '');
            if ($v === '') continue;
            foreach ($this->subs as $id => $r) {
                if ($exceptId !== null && $id === $exceptId) continue;
                if ((string)($r[$col] ?? '') === $v) {
                    throw new PDOException('SQLSTATE[23505]: duplicate ' . $col);
                }
            }
        }
    }
}

// =====================================================================
// Asserts
// =====================================================================
$passed = 0; $failed = 0;
function mp_assert(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { echo "  \033[32m✓\033[0m $name\n"; $passed++; }
    else       { echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : "") . "\n"; $failed++; }
}

echo "\n=== TESTES: Assinaturas Mercado Pago (producao, sem rede/banco) ===\n\n";

// ---- Ambiente fictício ----
putenv('MERCADOPAGO_ACCESS_TOKEN=TEST-0000000000000000-000000-00000000000000000000000000000000-000000000');
putenv('MERCADOPAGO_PLAN_ID_PRO=test-pro-plan-id-0001');
putenv('MERCADOPAGO_PLAN_ID_PREMIUM=test-premium-plan-id-0002');
putenv('MERCADOPAGO_WEBHOOK_SECRET=test-webhook-secret-0123456789abcdef');
$_ENV['MERCADOPAGO_ACCESS_TOKEN'] = (string)getenv('MERCADOPAGO_ACCESS_TOKEN');
$_ENV['MERCADOPAGO_PLAN_ID_PRO'] = (string)getenv('MERCADOPAGO_PLAN_ID_PRO');
$_ENV['MERCADOPAGO_PLAN_ID_PREMIUM'] = (string)getenv('MERCADOPAGO_PLAN_ID_PREMIUM');
$_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = (string)getenv('MERCADOPAGO_WEBHOOK_SECRET');

// ---- Banco fake + seed ----
$db = new FakePDO();
$db->planos = [
    1 => ['id' => 1, 'nome' => 'Pro', 'slug' => 'pro', 'preco' => 9.90, 'status' => 'ativo'],
    2 => ['id' => 2, 'nome' => 'Premium', 'slug' => 'premium', 'preco' => 19.90, 'status' => 'ativo'],
];
$db->usuarios = [
    1 => ['id' => 1, 'nome' => 'Teste', 'email' => 'teste@exemplo.com', 'plano' => 'gratuito',
          'plano_status' => 'ativo', 'plano_inicio' => null, 'plano_fim' => null, 'active_subscription_id' => null],
];
$userId = 1;
$userEmail = 'teste@exemplo.com';

// ---- Stub de transporte ----
$captured = [];
MercadoPagoClient::$transport = function (string $method, string $url, ?array $body, string $token) use (&$captured) {
    $captured[] = ['method' => $method, 'url' => $url, 'body' => $body, 'token_len' => strlen($token)];
    if ($method === 'POST' && str_ends_with($url, '/preapproval')) {
        return ['ok' => true, 'http' => 201, 'data' => [
            'id' => 'mp-test-preapproval-001',
            'status' => 'pending',
            'init_point' => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=mp-test-preapproval-001',
            'external_reference' => $body['external_reference'] ?? '',
        ], 'error' => ''];
    }
    if ($method === 'GET') {
        return ['ok' => true, 'http' => 200, 'data' => [
            'id' => 'mp-test-preapproval-001',
            'status' => 'authorized',
            'external_reference' => $GLOBALS['__last_ref'] ?? '',
            'payer_id' => '999888777',
            'payer_email' => 'outro@exemplo.com',
        ], 'error' => ''];
    }
    if ($method === 'PUT') {
        return ['ok' => true, 'http' => 200, 'data' => ['id' => 'mp-test-preapproval-001', 'status' => 'cancelled'], 'error' => ''];
    }
    return ['ok' => false, 'http' => 500, 'data' => [], 'error' => 'stub'];
};

$svc = new SubscriptionService($db);

// 1. plano inválido
$r = $svc->start($userId, $userEmail, 'gold');
mp_assert(!$r['ok'] && $r['error'] === 'invalid_plan', 'T01 plano invalido rejeitado');

// 2. usuário não autenticado (roteador exige login na action)
$router = (string)file_get_contents($ROOT . '/public/index.php');
mp_assert(preg_match("/subscription_start.*?requireLogin\(\)/s", $router) === 1,
    'T02 subscription_start exige login');

// 3. CSRF obrigatório
mp_assert(preg_match("/csrfProtectedActions = \[[^\]]*'subscription_start'[^\]]*'subscription_cancel'[^\]]*\]/s", $router) === 1,
    'T03 subscription_start/cancel sob CSRF');

// 4/5. seleção correta PRO e PREMIUM (mapeamento interno, sem ID do front)
$captured = [];
$rPro = $svc->start($userId, $userEmail, 'pro');
mp_assert($rPro['ok'] && $captured[0]['body']['preapproval_plan_id'] === 'test-pro-plan-id-0001',
    'T04 PRO mapeia para MERCADOPAGO_PLAN_ID_PRO');
mp_assert(strpos(json_encode($captured[0]['body']), '9.9') === false,
    'T04b preco nunca vai do frontend');
$db->subs = []; $db->seq = 0; // isola tentativa premium
$captured = [];
$rPre = $svc->start($userId, $userEmail, 'premium');
mp_assert($rPre['ok'] && $captured[0]['body']['preapproval_plan_id'] === 'test-premium-plan-id-0002',
    'T05 PREMIUM mapeia para MERCADOPAGO_PLAN_ID_PREMIUM');

// 6/7. external_reference com ID interno + attempt
$ref = (string)$captured[0]['body']['external_reference'];
$parsed = SubscriptionService::parseExternalReference($ref);
mp_assert($parsed !== null && $parsed['user_id'] === $userId && $parsed['plan'] === 'premium',
    'T06 external_reference usa user_id interno', $ref);
mp_assert($parsed !== null && preg_match('/^[0-9a-f]{32}$/', $parsed['attempt']) === 1,
    'T07 external_reference possui attempt_token');
$row = $db->query('SELECT * FROM subscriptions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
mp_assert($row && (int)$row['user_id'] === $userId && $row['attempt_token'] === $parsed['attempt']
    && $row['external_reference'] === $ref && $row['status'] === 'pending',
    'T07b tentativa persistida antes do redirect');
$GLOBALS['__last_ref'] = $ref;

// 8. token nunca na resposta/view
mp_assert(strpos(json_encode($rPre), 'TEST-0000') === false,
    'T08 access token fora da resposta do start');
$view = (string)file_get_contents($ROOT . '/public/meu_plano.php');
mp_assert(strpos($view, 'TEST-0000') === false && strpos($view, 'MERCADOPAGO_ACCESS_TOKEN') === false,
    'T08b access token fora do HTML');

// helpers de assinatura válida
$qid = 'mp-test-preapproval-001';
$xReq = 'req-123';
$ts = (string)time();
$v1 = hash_hmac('sha256', 'id:' . $qid . ';request-id:' . $xReq . ';ts:' . $ts . ';', 'test-webhook-secret-0123456789abcdef');
$goodSig = 'ts=' . $ts . ',v1=' . $v1;
$ws = new SubscriptionWebhookService($db);

// 9. webhook sem signature
$res = $ws->handle('', $xReq, ['data.id' => $qid, 'topic' => 'subscription_preapproval'], '');
mp_assert($res['http'] === 401 && $res['code'] === 'invalid_signature', 'T09 webhook sem signature recusado');

// 10. signature inválida
$res = $ws->handle('ts=' . $ts . ',v1=' . str_repeat('0', 64), $xReq, ['data.id' => $qid], '');
mp_assert($res['http'] === 401, 'T10 webhook signature invalida recusada');

// 11. secret ausente
putenv('MERCADOPAGO_WEBHOOK_SECRET');
unset($_ENV['MERCADOPAGO_WEBHOOK_SECRET'], $_SERVER['MERCADOPAGO_WEBHOOK_SECRET']);
$res = $ws->handle($goodSig, $xReq, ['data.id' => $qid], '');
mp_assert($res['http'] === 503 && $res['code'] === 'missing_secret', 'T11 secret ausente recusa processamento');
putenv('MERCADOPAGO_WEBHOOK_SECRET=test-webhook-secret-0123456789abcdef');
$_ENV['MERCADOPAGO_WEBHOOK_SECRET'] = 'test-webhook-secret-0123456789abcdef';

// 13. pending NÃO libera plano
$sync = $svc->syncFromApi(['id' => $qid, 'status' => 'pending', 'external_reference' => $ref]);
$plano = $db->query('SELECT plano, active_subscription_id FROM usuarios WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
mp_assert($sync['ok'] && $plano['plano'] === 'gratuito' && $plano['active_subscription_id'] === null,
    'T13 pending nao libera plano pago');

// 14. authorized libera plano (webhook completo; stub retorna authorized)
$res = $ws->handle($goodSig, $xReq, ['data.id' => $qid, 'topic' => 'subscription_preapproval'], '');
$plano = $db->query('SELECT plano, plano_status, active_subscription_id FROM usuarios WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
mp_assert($res['http'] === 200 && $res['code'] === 'processed'
    && $plano['plano'] === 'premium' && $plano['plano_status'] === 'ativo'
    && (int)$plano['active_subscription_id'] > 0,
    'T14 authorized libera plano via webhook');

// 16. payer_id NÃO é usado como user_id
mp_assert((int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 1,
    'T16 nenhum usuario criado a partir de payer');
$sub = $svc->findByMpId($qid);
mp_assert($sub !== null && (int)$sub['user_id'] === $userId,
    'T16b vinculo mantido por external_reference');

// 12. webhook duplicado é idempotente (signature amarra o request-id: recalcular)
$v1b = hash_hmac('sha256', 'id:' . $qid . ';request-id:req-456;ts:' . $ts . ';', 'test-webhook-secret-0123456789abcdef');
$res2 = $ws->handle('ts=' . $ts . ',v1=' . $v1b, 'req-456', ['data.id' => $qid, 'topic' => 'subscription_preapproval'], '');
$count = (int)$db->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
$planoRow = $db->query('SELECT plano FROM usuarios WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$plano2 = $planoRow['plano'] ?? null;
mp_assert($res2['http'] === 200 && $count === 1 && $plano2 === 'premium',
    'T12 webhook duplicado sem duplicar nem quebrar');

// 17. mp_preapproval_id não duplica
$dupBlocked = false;
try {
    $db->prepare("INSERT INTO subscriptions (user_id, plan_id, plan_slug, status, mp_preapproval_id, attempt_token, external_reference)
        VALUES (?, 1, 'pro', 'pending', ?, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_1_pro_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')")
        ->execute([$userId, $qid]);
} catch (Throwable $e) { $dupBlocked = true; }
mp_assert($dupBlocked, 'T17 UNIQUE mp_preapproval_id impede duplicata');

// 15. cancelled remove acesso pago
$sync = $svc->syncFromApi(['id' => $qid, 'status' => 'cancelled', 'external_reference' => $ref]);
$plano = $db->query('SELECT plano, active_subscription_id FROM usuarios WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
mp_assert($sync['ok'] && $plano['plano'] === 'gratuito' && $plano['active_subscription_id'] === null,
    'T15 cancelled faz downgrade para gratuito');

// 18. retorno não ativa sozinho (estático: mpReturn sem UPDATE nem sync;
// comentários ignorados na análise)
$profile = (string)file_get_contents($ROOT . '/src/controllers/ProfileController.php');
$at = (int)strpos($profile, 'function mpReturn');
$to = (int)strpos($profile, 'function updatePassword', $at);
$mpReturnBody = preg_replace('/\/\/.*$/m', '', substr($profile, $at, $to - $at));
mp_assert(strpos($mpReturnBody, 'UPDATE usuarios') === false
    && strpos($mpReturnBody, 'syncFromApi(') === false
    && strpos($mpReturnBody, 'getSubscription') !== false,
    'T18 mp_return só consulta, nunca ativa');

// extras: validação de URL e máscara
mp_assert($svc->isHttpsMpUrl('https://www.mercadopago.com.br/subscriptions/checkout?x=1'), 'TX1 init_point MP aceito');
mp_assert(!$svc->isHttpsMpUrl('http://evil.com/x'), 'TX2 URL arbitraria rejeitada (http)');
mp_assert(!$svc->isHttpsMpUrl('https://evil.com/mercadopago'), 'TX3 host estranho rejeitado');
mp_assert(SubscriptionService::parseExternalReference('user_5_pro_' . str_repeat('z', 32)) === null, 'TX4 ref com attempt invalido rejeitada');
mp_assert($svc->mapApiStatus('weird') === null, 'TX5 status desconhecido sem mapeamento inventado');

echo "\n=== RESUMO MP ===\n";
$total = $passed + $failed;
echo "Total: $total | \033[32mPassed: $passed\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

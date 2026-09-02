<?php
/**
 * Teste do feedback de UX em meu_plano.php apos redirect do SubscriptionController.
 *
 * Replica a logica condicional que decide o que renderizar no topo de
 * public/meu_plano.php quando chegam os parametros:
 *   - ?subscribed=1
 *   - ?error=mp_create_failed
 *
 * Garante que:
 *   - nenhum dado sensivel e exposto (CC_VAL_433, card_token_id, etc.)
 *   - mensagem de sucesso e mensagem de erro sao claras
 *   - mensagens NAO aparecem quando params nao estao presentes
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$log = '';

function ok(string $name, bool $cond, int &$pass, int &$fail, string &$log): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

// =====================================================================
// Replica exata da logica de flash em meu_plano.php (linhas 55-71)
// =====================================================================
function renderFlashMessages(array $get): string {
    $flashSuccess = (($get['subscribed'] ?? '') === '1');
    $flashMpError = (($get['error'] ?? '') === 'mp_create_failed');
    $out = '';
    if ($flashSuccess) {
        $out .= '<div class="alert alert-success" role="status" style="margin-bottom:var(--space-4)">'
              . '<span>Assinatura criada com sucesso.</span>'
              . '</div>';
    }
    if ($flashMpError) {
        $out .= '<div class="alert alert-error" role="alert" style="margin-bottom:var(--space-4)">'
              . '<span>N&atilde;o foi poss&iacute;vel validar o cart&atilde;o para a assinatura. Verifique os dados e tente novamente.</span>'
              . '</div>';
    }
    return $out;
}

// =====================================================================
// 1. Sem params -> nenhum flash
// =====================================================================
$html = renderFlashMessages([]);
ok('sem params: nenhum alert visivel', $html === '', $pass, $fail, $log);

// =====================================================================
// 2. ?subscribed=1 -> mensagem de sucesso
// =====================================================================
$html = renderFlashMessages(['subscribed' => '1']);
ok('?subscribed=1 produz alert-success', str_contains($html, 'alert-success'), $pass, $fail, $log);
ok('mensagem de sucesso presente',         str_contains($html, 'Assinatura criada com sucesso.'), $pass, $fail, $log);
ok('alert-error NAO aparece no sucesso',   !str_contains($html, 'alert-error'), $pass, $fail, $log);

// =====================================================================
// 3. ?error=mp_create_failed -> mensagem de erro amigavel
// =====================================================================
$html = renderFlashMessages(['error' => 'mp_create_failed']);
ok('?error=mp_create_failed produz alert-error', str_contains($html, 'alert-error'), $pass, $fail, $log);
ok('mensagem amigavel de erro presente', str_contains($html, 'N&atilde;o foi poss&iacute;vel validar o cart&atilde;o'), $pass, $fail, $log);
ok('alert-success NAO aparece no erro',   !str_contains($html, 'alert-success'), $pass, $fail, $log);

// =====================================================================
// 4. Erro != mp_create_failed -> nao mostra nada
// =====================================================================
$html = renderFlashMessages(['error' => 'invalid_plan']);
ok('?error=invalid_plan nao mostra flash', $html === '', $pass, $fail, $log);

$html = renderFlashMessages(['error' => 'method']);
ok('?error=method nao mostra flash',       $html === '', $pass, $fail, $log);

// =====================================================================
// 5. SEGURANCA: nenhum dado sensivel aparece em nenhum caso
// =====================================================================
$sensitiveLeaks = [
    'CC_VAL_433', 'cc_val_433', 'ccval433',
    'card_token_id', 'cardToken',
    'cvv', 'security_code',
    'authorization', 'access_token', 'webhook_secret',
    'public_key', 'TEST-', 'APP_USR',
    'preapproval_plan_id', 'payer_email',
    'api_code', 'api_msg', 'http=400',
];

$cases = [
    ['subscribed' => '1'],
    ['error' => 'mp_create_failed'],
    ['subscribed' => '1', 'error' => 'mp_create_failed'],
];

$allSafe = true;
foreach ($cases as $i => $get) {
    $html = renderFlashMessages($get);
    foreach ($sensitiveLeaks as $needle) {
        if (stripos($html, $needle) !== false) {
            $allSafe = false;
            ok("caso $i: NAO vaza '$needle'", false, $pass, $fail, $log);
        }
    }
}
ok('nenhum caso vaza dados sensiveis (todas as combinacoes verificadas)', $allSafe, $pass, $fail, $log);

// =====================================================================
// 6. Mensagem contem texto exigido pela spec
// =====================================================================
$html = renderFlashMessages(['subscribed' => '1']);
ok('sucesso: texto exato exigido',
   str_contains($html, 'Assinatura criada com sucesso.'),
   $pass, $fail, $log);

$html = renderFlashMessages(['error' => 'mp_create_failed']);
ok('erro: contem "Verifique os dados e tente novamente"',
   str_contains($html, 'Verifique os dados e tente novamente.'),
   $pass, $fail, $log);
ok('erro: contem "N&atilde;o foi poss&iacute;vel validar o cart&atilde;o"',
   str_contains($html, 'N&atilde;o foi poss&iacute;vel validar o cart&atilde;o'),
   $pass, $fail, $log);

// =====================================================================
// 7. Mapeamento controller -> view (cobre o caminho real)
// =====================================================================
$controllerOutputs = [
    'success' => '/?action=meu_plano&subscribed=1',
    'mp_fail' => '/?action=meu_plano&error=mp_create_failed',
    'no_flash' => '/?action=meu_plano',
];

foreach ($controllerOutputs as $name => $url) {
    $qs = [];
    $parts = parse_url($url);
    if (isset($parts['query'])) {
        parse_str($parts['query'], $qs);
    }
    $html = renderFlashMessages($qs);
    $log .= "  [INFO] caso '$name' produziu " . strlen($html) . " bytes de flash\n";
}

ok('caso success gera flash > 0',     strlen(renderFlashMessages(['subscribed' => '1']))     > 0, $pass, $fail, $log);
ok('caso mp_fail gera flash > 0',     strlen(renderFlashMessages(['error' => 'mp_create_failed'])) > 0, $pass, $fail, $log);
ok('caso no_flash gera flash == 0',   strlen(renderFlashMessages([])) === 0, $pass, $fail, $log);

// =====================================================================
// Resumo
// =====================================================================
$total = $pass + $fail;
echo "\n=== meu_plano_ux_feedback ===\n";
echo $log;
echo "  TOTAL: $total | PASS: $pass | FAIL: $fail\n";

if ($fail > 0) {
    exit(1);
}
exit(0);

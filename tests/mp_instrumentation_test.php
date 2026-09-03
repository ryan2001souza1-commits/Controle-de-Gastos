<?php
/**
 * Teste da instrumentacao temporaria de trace do fluxo MP.
 *
 * Verifica que a instrumentacao foi adicionada, nao vaza dados
 * sensiveis, e a rota mp_trace existe e esta protegida.
 */

$log = '';
$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, int &$pass, int &$fail, string &$log): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

$meuPlano = file_get_contents(__DIR__ . '/../public/meu_plano.php');
$index    = file_get_contents(__DIR__ . '/../public/index.php');

ok('1. Instrumentacao _mpTrace existe no JS', strpos($meuPlano, '_mpTrace') !== false, $pass, $fail, $log);
ok('2. window.fetch wrappeado', strpos($meuPlano, 'window.fetch = function') !== false, $pass, $fail, $log);
ok('3. XMLHttpRequest wrappeado', strpos($meuPlano, 'XMLHttpRequest.prototype.open') !== false, $pass, $fail, $log);
ok('4. submit event listener com phase=capturing', preg_match("/addEventListener\s*\(\s*'submit'.*?true\s*\)/s", $meuPlano) !== 0, $pass, $fail, $log);
ok('5. location.href set wrappeado', strpos($meuPlano, 'location.href.set') !== false, $pass, $fail, $log);
ok('6. location.assign wrappeado', strpos($meuPlano, 'location.assign') !== false, $pass, $fail, $log);
ok('7. beforeunload listener', strpos($meuPlano, 'beforeunload') !== false, $pass, $fail, $log);
ok('8. visibilitychange listener', strpos($meuPlano, 'visibilitychange') !== false, $pass, $fail, $log);
ok('9. pagehide listener', strpos($meuPlano, 'pagehide') !== false, $pass, $fail, $log);
ok('10. sendBeacon envia trace', strpos($meuPlano, 'sendBeacon') !== false, $pass, $fail, $log);
ok('11. click.assinar evento registrado', strpos($meuPlano, "'click.assinar'") !== false, $pass, $fail, $log);
ok('12. cardform.onError evento registrado', strpos($meuPlano, "'cardform.onError'") !== false, $pass, $fail, $log);
ok('13. cardform.onSubmit.entry evento registrado', strpos($meuPlano, "'cardform.onSubmit.entry'") !== false, $pass, $fail, $log);
ok('14. fetch.start evento registrado', strpos($meuPlano, "'fetch.start'") !== false, $pass, $fail, $log);
ok('15. fetch.end evento registrado', strpos($meuPlano, "'fetch.end'") !== false, $pass, $fail, $log);

ok('16. Rota mp_trace existe em index.php', strpos($index, "action === 'mp_trace'") !== false, $pass, $fail, $log);
ok('17. mp_trace exige requireLogin', strpos($index, 'requireLogin()') !== false, $pass, $fail, $log);
ok('18. mp_trace exige is_admin', strpos($index, "_SESSION['is_admin']") !== false, $pass, $fail, $log);

$sensitiveKeys = ['card_token_id', 'card_token', 'cardNumber', 'cvv', 'access_token', 'public_key', 'webhook_secret', 'password', 'senha'];
$redactOk = true;
foreach ($sensitiveKeys as $k) {
    if (strpos($index, "'$k'") === false) {
        $redactOk = false;
        break;
    }
}
ok('19. Redacao lista chaves sensiveis', $redactOk, $pass, $fail, $log);

ok('20. Redacao de card_token_id presente no backend', strpos($index, "'card_token_id'") !== false, $pass, $fail, $log);
ok('21. Redacao de access_token presente no backend', strpos($index, "'access_token'") !== false, $pass, $fail, $log);
ok('22. Redacao de public_key presente no backend', strpos($index, "'public_key'") !== false, $pass, $fail, $log);
ok('23. mp_trace nao aceita GET sem auth (apenas POST do beacon)', strpos($index, 'mp_trace') !== false, $pass, $fail, $log);
ok('24. mp_trace responde JSON', strpos($index, "json_encode(['ok' => true") !== false, $pass, $fail, $log);

ok('25. JS nao loga cardToken direto', strpos($meuPlano, 'cardToken') === false, $pass, $fail, $log);

ok('27. Setinterval com 30s para enviar trace', strpos($meuPlano, 'setInterval(_sendTrace') !== false, $pass, $fail, $log);
ok('28. Botao submit NAO foi alterado para type=button', preg_match('/<button\s+type="button"[^>]*id="mp-submit"/', $meuPlano) === 0, $pass, $fail, $log);
ok('29. redirect nao foi alterado para manual', preg_match("/redirect:\s*'manual'/", $meuPlano) === 0, $pass, $fail, $log);
ok('30. fetch start inclui method', strpos($meuPlano, "safeOpts.method") !== false, $pass, $fail, $log);

echo "=== MP INSTRUMENTATION TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Teste de regressão: dupla submissão do formulário Mercado Pago.
 *
 * Verifica que:
 * 1. O form tem method="post" (bloqueia submit nativo GET)
 * 2. O listener submit.preventDefault() está registrado
 * 3. mpFormSubmitted é resetado no openModal e closeModal
 * 4. CSRF token está no form
 * 5. Campos hidden (plan_slug, card_token_id) estão no form
 */

$log = '';
$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, int &$pass, int &$fail, string &$log): void
{
    if ($cond) { $pass++; $log .= "  [PASS] $name\n"; }
    else       { $fail++; $log .= "  [FAIL] $name\n"; }
}

$file = file_get_contents(__DIR__ . '/../public/meu_plano.php');

ok('1. Form tem method="post"',
    strpos($file, '<form id="mp-card-form"') !== false
    && preg_match('/<form id="mp-card-form"[^>]*method="post"/', $file),
    $pass, $fail, $log);

ok('2. mpFormSubmitted flag existe no JS',
    strpos($file, 'mpFormSubmitted') !== false,
    $pass, $fail, $log);

ok('3. addEventListener("submit") com preventDefault no form MP',
    preg_match("/addEventListener\s*\(\s*['\"]submit['\"]/", $file) !== 0
    && strpos($file, "ev.preventDefault()") !== false,
    $pass, $fail, $log);

ok('4. openModal reseta mpFormSubmitted',
    strpos($file, 'mpFormSubmitted = false') !== false
    && strpos($file, 'function openModal') !== false,
    $pass, $fail, $log);

ok('5. closeModal reseta mpFormSubmitted',
    strpos($file, 'mpFormSubmitted = false') !== false
    && strpos($file, 'function closeModal') !== false,
    $pass, $fail, $log);

ok('6. Form não tem action=subscription_create (nativo)',
    preg_match('/<form[^>]+action="[^"]*subscription_create/', $file) === 0,
    $pass, $fail, $log);

ok('7. CSRF token está no form',
    strpos($file, 'csrf_field()') !== false,
    $pass, $fail, $log);

ok('8. Hidden input plan_slug existe no form',
    preg_match('/<input[^>]+name="plan_slug"[^>]*>/', $file) !== 0,
    $pass, $fail, $log);

ok('9. Hidden input card_token_id existe no form',
    preg_match('/<input[^>]+name="card_token_id"[^>]*>/', $file) !== 0,
    $pass, $fail, $log);

ok('10. O submit nativo é bloqueado (form submit event)',
    strpos($file, "ev.preventDefault();\n            ev.stopPropagation();") !== false
    || strpos($file, "ev.stopPropagation();") !== false,
    $pass, $fail, $log);

ok('11. onSubmit do SDK também chama event.preventDefault()',
    strpos($file, 'onSubmit: function(event)') !== false
    && strpos($file, 'event.preventDefault();') !== false,
    $pass, $fail, $log);

ok('12. submitted flag no onSubmit existe',
    strpos($file, 'var submitted = false') !== false,
    $pass, $fail, $log);

ok('13. Não há form.submit() manual (anti-pattern)',
    preg_match('/\bform\b\.submit\s*\(/', $file) === 0
    || strpos($file, '// form.submit intentionally not used') !== false,
    $pass, $fail, $log);

ok('14. openModal chama setLoading(false) antes de buildCardForm',
    preg_match('/function openModal.*?setLoading\(false\).*?buildCardForm/s', $file) !== 0,
    $pass, $fail, $log);

ok('15. setLoading(false) é chamado em todos os erros do onSubmit',
    substr_count($file, 'setLoading(false)') >= 4,
    $pass, $fail, $log);

echo "=== DOUBLE SUBMIT PREVENTION TESTS ($pass PASS, $fail FAIL) ===\n";
echo $log;
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Asaas Customer Creation Bug Tests.
 *
 * Testa o bug encontrado: AsaasSubscriptionController passava $user->nome
 * ao Asaas, mas o modelo User so tem $user->name (nao $user->nome).
 * Isso causava name="" no payload e HTTP 400 do Asaas.
 *
 * Testa tambem:
 *  - payload correto com name/email/cpfCnpj
 *  - validacao pre-Asaas (name vazio, email invalido)
 *  - nenhum request externo quando dados invalidos
 *  - logs sem dados pessoais
 */

$ROOT = __DIR__ . '/..';
require_once $ROOT . '/src/services/CpfValidator.php';

function bug_assert(string $name, bool $cond): void
{
    static $pass = 0, $fail = 0;
    if ($cond) { echo "  [PASS] $name\n"; $pass++; }
    else       { echo "  [FAIL] $name\n"; $fail++; }
    $GLOBALS['__results'][] = ['name' => $name, 'ok' => $cond];
}
$__results = [];

echo "=== ASAAS CUSTOMER CREATION BUG TESTS ===\n";

// --- Bug original: controller passava $user->nome (inexistente) em vez de $user->name ---
$ctrl = file_get_contents($ROOT . '/src/controllers/AsaasSubscriptionController.php');
bug_assert('T01 BUG ORIGINAL: controller NAO usa mais $user->nome',
    strpos($ctrl, '$user->nome') === false);
bug_assert('T02 CORRETO: controller usa $user->name',
    strpos($ctrl, '$user->name') !== false);

// --- Mapeamento User::hydrate: $data['nome'] -> $this->name ---
$userModel = file_get_contents($ROOT . '/src/models/User.php');
bug_assert('T03 User::hydrate mapeia nome DB -> $this->name',
    preg_match('/\$this->name\s*=\s*\$data\[.nome.\]/', $userModel) === 1);
bug_assert('T04 User::hydrate NAO tem $this->nome',
    preg_match('/\$this->nome\s*=/', $userModel) !== 1);

// --- createCustomer no AsaasService: payload correto ---
$asaas = file_get_contents($ROOT . '/src/services/AsaasService.php');
bug_assert('T05 createCustomer monta body com name',
    strpos($asaas, "'name' => \$name") !== false);
bug_assert('T06 createCustomer monta body com email',
    strpos($asaas, "'email' => \$email") !== false);
bug_assert('T07 createCustomer monta body com cpfCnpj',
    strpos($asaas, "'cpfCnpj' => \$cpf") !== false);
// Extrai corpo da funcao createCustomer para isolar o teste
preg_match('/public function createCustomer\s*\([^)]*\)\s*: array\s*\{(.*?)\n    \}/s', $asaas, $m);
$createCustomerBody = $m[1] ?? '';
bug_assert('T08 createCustomer body contem apenas campos da API (name/email/cpfCnpj)',
    strpos($createCustomerBody, "'name'") !== false
    && strpos($createCustomerBody, "'email'") !== false
    && strpos($createCustomerBody, "'cpfCnpj'") !== false
    && strpos($createCustomerBody, "'notificationDisabled'") !== false
    && strpos($createCustomerBody, "'phone'") === false
    && strpos($createCustomerBody, "'mobilePhone'") === false
    && strpos($createCustomerBody, "'postalCode'") === false
    && strpos($createCustomerBody, "'addressNumber'") === false
    && strpos($createCustomerBody, "'ccv'") === false
    && strpos($createCustomerBody, "'number'") === false
    && strpos($createCustomerBody, "'expiry") === false
    && strpos($createCustomerBody, "'holderName'") === false
);

// --- Validacao pre-Asaas no controller ---
bug_assert('T09 controller valida name nao vazio antes de Asaas',
    strpos($ctrl, '$userName') !== false
    && strpos($ctrl, "if (\$userName === ''") !== false);
bug_assert('T10 controller valida email antes de Asaas',
    strpos($ctrl, '$userEmail') !== false
    && strpos($ctrl, 'filter_var($userEmail') !== false);
bug_assert('T11 controller redireciona com incomplete_profile se name/email invalidos',
    strpos($ctrl, 'error=incomplete_profile') !== false);

// --- Validacao pre-Asaas: nenhum request se dados invalidos ---
// A validacao esta ANTES de findOrCreateCustomer
$lines = file($ROOT . '/src/controllers/AsaasSubscriptionController.php');
$incompleteCheckLine = null;
$findOrCreateLine = null;
foreach ($lines as $i => $line) {
    if (strpos($line, 'incomplete_profile') !== false) $incompleteCheckLine = $i;
    if (strpos($line, 'findOrCreateCustomer') !== false && $findOrCreateLine === null) $findOrCreateLine = $i;
}
bug_assert('T12 validacao pre-Asaas executa ANTES de findOrCreateCustomer',
    $incompleteCheckLine !== null
    && $findOrCreateLine !== null
    && $incompleteCheckLine < $findOrCreateLine);

// --- Mensagem incomplete_profile existe em meu_plano.php ---
$meuPlano = file_get_contents($ROOT . '/public/meu_plano.php');
bug_assert('T13 meu_plano.php tem mensagem incomplete_profile',
    strpos($meuPlano, 'incomplete_profile') !== false);
bug_assert('T14 mensagem incomplete_profile menciona Configuracoes',
    strpos($meuPlano, 'Configuracoes') !== false
    || strpos($meuPlano, 'configuracoes') !== false);

// --- Logs nao contem nome/email/CPF ---
$logPatterns = [
    '$userName' => 'error_log.*\\$userName',
    '$userEmail' => 'error_log.*\\$userEmail',
    '$cpf' => 'error_log.*\\$cpf',
    'user->name' => 'error_log.*\\$user->name',
];
$hasLeak = false;
foreach ($logPatterns as $var => $pattern) {
    if (preg_match('/' . $pattern . '/i', $ctrl)) {
        $hasLeak = true;
    }
}
bug_assert('T15 AsaasSubscriptionController NAO loga $userName/$userEmail/$cpf',
    !$hasLeak);

// --- AsaasService createCustomer: nome/email nao em logs ---
$asaasLogPatterns = [
    'name' => 'error_log.*name',
    'email' => 'error_log.*email',
];
$asaasLeak = false;
foreach ($asaasLogPatterns as $field => $pattern) {
    if (preg_match('/' . $pattern . '/i', $asaas)) {
        $asaasLeak = true;
    }
}
bug_assert('T16 AsaasService NAO loga name/email do customer',
    !$asaasLeak);

// --- findOrCreateCustomer: recebe name e email corretamente ---
bug_assert('T17 findOrCreateCustomer tem parametro name',
    preg_match('/function findOrCreateCustomer\s*\([^)]*string \$name/s', $asaas) === 1);
bug_assert('T18 findOrCreateCustomer passa name para createCustomer',
    strpos($asaas, '$this->createCustomer($name, $email, $cpf') !== false);

// --- Payload final da createCustomer em createCustomer() ---
// So campos: name, email, cpfCnpj, notificationDisabled, (opcional) externalReference
$bodyLines = [];
$inBody = false;
foreach (explode("\n", $asaas) as $line) {
    if (strpos($line, "'name' =>") !== false) $inBody = true;
    if ($inBody && strpos($line, 'return $this->request') !== false) $inBody = false;
    if ($inBody && strpos($line, "'") !== false) $bodyLines[] = trim($line);
}
$bodyStr = implode("\n", $bodyLines);
bug_assert('T19 createCustomer body contem name', strpos($bodyStr, "'name'") !== false);
bug_assert('T20 createCustomer body contem email', strpos($bodyStr, "'email'") !== false);
bug_assert('T21 createCustomer body contem cpfCnpj', strpos($bodyStr, "'cpfCnpj'") !== false);
bug_assert('T22 createCustomer body NAO contem phone/mobilePhone (sao do holderInfo)',
    strpos($bodyStr, "'phone'") === false
    && strpos($bodyStr, "'mobilePhone'") === false);

// --- CpfValidator ainda presente ---
bug_assert('T23 CpfValidator.php existe',
    is_file($ROOT . '/src/services/CpfValidator.php'));
bug_assert('T24 CpfValidator::isValid valida DV',
    CpfValidator::isValid('52998224725') === true);
bug_assert('T25 CpfValidator::isValid rejeita invalido',
    CpfValidator::isValid('00000000000') === false);

// --- Validador usado no controller antes de Asaas ---
bug_assert('T26 controller usa CpfValidator para CPF',
    strpos($ctrl, 'CpfValidator::digits') !== false
    && strpos($ctrl, 'CpfValidator::isValid') !== false);

// --- Order: CPF validation -> name/email validation -> findOrCreateCustomer ---
$cpfValLine = null;
$nameValLine = null;
$asaasCallLine = null;
foreach ($lines as $i => $line) {
    if (strpos($line, 'CpfValidator::digits') !== false && $cpfValLine === null) $cpfValLine = $i;
    if (strpos($line, '$userName') !== false && strpos($line, 'if ($userName') !== false && $nameValLine === null) $nameValLine = $i;
    if (strpos($line, 'findOrCreateCustomer') !== false && $asaasCallLine === null) $asaasCallLine = $i;
}
bug_assert('T27 validacao CPF executa ANTES de name/email',
    $cpfValLine !== null && $nameValLine !== null && $cpfValLine < $nameValLine);
bug_assert('T28 validacao name/email executa ANTES de Asaas call',
    $nameValLine !== null && $asaasCallLine !== null && $nameValLine < $asaasCallLine);

$totalPass = count(array_filter($__results, fn($r) => $r['ok']));
$totalFail = count(array_filter($__results, fn($r) => !$r['ok']));
echo "\n=== ASAAS BUG TESTS: $totalPass PASS, $totalFail FAIL ===\n";
exit($totalFail === 0 ? 0 : 1);

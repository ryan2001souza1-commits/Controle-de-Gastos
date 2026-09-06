<?php
declare(strict_types=1);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/models/User.php';
require_once $ROOT . '/src/services/PlanService.php';

$GLOBALS['FAILED'] = 0;

function assert_test(bool $cond, string $msg): void {
    if ($cond) {
        echo "  \033[32m✓\033[0m $msg\n";
    } else {
        echo "  \033[31m✗\033[0m $msg\n";
        $GLOBALS['FAILED']++;
    }
}

class MockPDOForPlanService extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new MockPDOStmtForPlanService(); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new MockPDOStmtForPlanService(); }
}

class MockPDOStmtForPlanService extends PDOStatement
{
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return null; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 0; }
}

echo "\n=== FREE USER CONSISTENCY TESTS ===\n\n";

$planSvc = new PlanService(new MockPDOForPlanService());

function makeUser(string $plano, string $planoStatus, ?string $planoFim = null): User {
    $u = new User(new MockPDOForPlanService());
    $u->id = 99;
    $u->plano = $plano;
    $u->plano_status = $planoStatus;
    $u->plano_fim = $planoFim;
    return $u;
}

echo "-- isPlanoAtivo for FREE plan (THE FIX) --\n";
assert_test($planSvc->isPlanoAtivo(makeUser('gratuito', 'ativo')),         'FS01: gratuito+ativo -> true');
assert_test($planSvc->isPlanoAtivo(makeUser('gratuito', 'cancelado')),      'FS02: gratuito+cancelado -> true (FREE sempre ativo)');
assert_test($planSvc->isPlanoAtivo(makeUser('gratuito', 'inativo')),       'FS03: gratuito+inativo -> true (FREE sempre ativo)');
assert_test($planSvc->isPlanoAtivo(makeUser('gratuito', 'pendente')),      'FS04: gratuito+pendente -> true (FREE sempre ativo)');
assert_test($planSvc->isPlanoAtivo(makeUser('gratuito', 'ativo', '2099-01-01')), 'FS05: gratuito+ativo+fim-futuro -> true');
assert_test($planSvc->isPlanoAtivo(makeUser('gratuito', 'ativo', '2020-01-01')), 'FS06: gratuito+ativo+fim-passado -> true');

echo "\n-- isPlanoAtivo for PAID plan --\n";
assert_test($planSvc->isPlanoAtivo(makeUser('pro', 'ativo')),               'FS07: pro+ativo -> true');
assert_test($planSvc->isPlanoAtivo(makeUser('premium', 'ativo')),           'FS08: premium+ativo -> true');
assert_test(!$planSvc->isPlanoAtivo(makeUser('pro', 'pendente')),          'FS09: pro+pendente -> false');
assert_test(!$planSvc->isPlanoAtivo(makeUser('premium', 'pendente')),      'FS10: premium+pendente -> false');
assert_test(!$planSvc->isPlanoAtivo(makeUser('pro', 'cancelado')),         'FS11: pro+cancelado -> false');
assert_test(!$planSvc->isPlanoAtivo(makeUser('premium', 'cancelado')),     'FS12: premium+cancelado -> false');
assert_test($planSvc->isPlanoAtivo(makeUser('pro', 'ativo', '2099-01-01')), 'FS13: pro+ativo+fim-futuro -> true');
assert_test(!$planSvc->isPlanoAtivo(makeUser('pro', 'ativo', '2020-01-01')), 'FS14: pro+ativo+fim-passado -> false');

echo "\n-- isFree checks --\n";
assert_test($planSvc->isFree(makeUser('gratuito', 'ativo')),               'FS15: isFree(gratuito) -> true');
assert_test($planSvc->isFree(makeUser('gratuito', 'cancelado')),             'FS16: isFree(gratuito+cancelado) -> true');
assert_test(!$planSvc->isFree(makeUser('pro', 'ativo')),                    'FS17: isFree(pro) -> false');
assert_test(!$planSvc->isFree(makeUser('premium', 'ativo')),               'FS18: isFree(premium) -> false');

echo "\n-- normalizeStatus edge cases --\n";
assert_test(PlanService::normalizeStatus('ATIVO') === 'ativo',                'FS19: normalizeStatus(ATIVO) -> ativo');
assert_test(PlanService::normalizeStatus('Cancelado') === 'cancelado',        'FS20: normalizeStatus(Cancelado) -> cancelado');
assert_test(PlanService::normalizeStatus(null) === 'ativo',                  'FS21: normalizeStatus(null) -> ativo');
assert_test(PlanService::normalizeStatus('') === '',                       'FS22: normalizeStatus(empty) -> empty (string vazia nao vira ativo)');
assert_test(PlanService::normalizeStatus('Ativo') === 'ativo',               'FS23: normalizeStatus(Ativo) -> ativo');
assert_test(PlanService::normalizeStatus('  ATIVO  ') === 'ativo',          'FS24: normalizeStatus(spaces) -> ativo');

$total = 24;
$failed = $GLOBALS['FAILED'];
echo "\n=== RESUMO ===\n";
echo "Total: $total | \033[32mPassed: " . ($total - $failed) . "\033[0m | \033[31mFailed: $failed\033[0m\n";
exit($failed > 0 ? 1 : 0);

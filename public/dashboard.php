<?php
if (!isset($data) || !is_array($data)) {
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Dashboard';
$pageSubtitle = 'Bem-vindo de volta! Aqui está o resumo das suas finanças.';
$userName = $_SESSION['user_name'] ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));
$chartData = $data['chart_data'] ?? null;

$totalIncomes  = (float)($data['total_incomes'] ?? 0);
$totalExpenses = (float)($data['total_expenses'] ?? 0);
$balance       = (float)($data['balance'] ?? 0);
$txCount       = (int)($data['transactions_count'] ?? 0);
$incomeCount   = (int)($data['income_count'] ?? 0);
$expenseCount  = (int)($data['expense_count'] ?? 0);
$economyPct    = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100, 1) : 0.0;

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

$categoriesTable   = $data['expenses_by_category_table'] ?? [];
$recentTransactions = $data['recent_transactions'] ?? [];

$goals = $data['goals'] ?? [];
$goalsTop = array_slice($goals, 0, 2);

$budgetTotal  = (float)($data['budget_total']  ?? ($data['budget']['limit'] ?? 0));
$budgetSpent  = (float)($data['budget_spent']  ?? ($data['budget']['spent'] ?? 0));
$budgetRemain = $budgetTotal - $budgetSpent;
$budgetPct    = $budgetTotal > 0 ? min(100, round(($budgetSpent / $budgetTotal) * 100)) : 0;

$prevIncomes  = (float)($data['prev_total_incomes']  ?? 0);
$prevExpenses = (float)($data['prev_total_expenses'] ?? 0);
$prevBalance  = (float)($data['prev_balance']        ?? 0);

$deltaIncome  = $prevIncomes  > 0 ? round((($totalIncomes  - $prevIncomes ) / $prevIncomes ) * 100) : 0;
$deltaExpense = $prevExpenses > 0 ? round((($totalExpenses - $prevExpenses) / $prevExpenses) * 100) : 0;
$deltaBalance = $prevBalance  > 0 ? round((($balance       - $prevBalance ) / $prevBalance ) * 100) : 0;

function fmtBRL($value) {
    return number_format((float)$value, 2, ',', '.');
}

$activeMenu = 'dashboard';
$pagePeriodFrom = date('d/m/Y', strtotime($startDate));
$pagePeriodTo   = date('d/m/Y', strtotime($endDate));

// Cores das categorias (paleta consistente com a referência)
$catPalette = [
    '#10b981', '#3b82f6', '#f59e0b', '#a855f7', '#06b6d4',
    '#94a3b8', '#ec4899', '#22c55e', '#0ea5e9', '#f97316',
    '#14b8a6', '#6366f1', '#ef4444', '#84cc16', '#64748b',
];

// limita e normaliza tabela de categorias p/ donut + lista
$categoriesDisplay = array_slice($categoriesTable, 0, 6);
$otherTotal = 0;
foreach (array_slice($categoriesTable, 6) as $extra) {
    $otherTotal += (float)($extra['tx_total'] ?? 0);
}
$chartCategories = [];
foreach ($categoriesDisplay as $c) {
    $chartCategories[] = [
        'label' => $c['name'],
        'value' => (float)($c['tx_total'] ?? 0),
        'color' => $c['color'] ?? null,
    ];
}
if ($otherTotal > 0) {
    $chartCategories[] = [
        'label' => 'Outros',
        'value' => $otherTotal,
        'color' => null,
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" role="status">
            <?= render_icon('check', 13) ?><span>Operação realizada com sucesso!</span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error" role="alert">
            <?= render_icon('info', 13) ?><span>Ocorreu um erro. Tente novamente.</span>
        </div>
    <?php endif; ?>

    <!-- ===== 4 METRIC CARDS ===== -->
    <section class="metric-strip dash-metrics">
        <article class="metric-card">
            <div class="metric-card-icon is-success"><?= render_icon('trending-up', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Receitas</div>
                <div class="metric-card-value is-positive" data-counter="<?= fmtBRL($totalIncomes) ?>">R$ 0,00</div>
                <div class="metric-card-trend <?= $deltaIncome >= 0 ? 'is-up' : 'is-down' ?>">
                    <?= $deltaIncome >= 0 ? '↑' : '↓' ?> <?= abs($deltaIncome) ?>%
                    <span class="trend-caption">em relação ao mês anterior</span>
                </div>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-card-icon is-danger"><?= render_icon('trending-down', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Despesas</div>
                <div class="metric-card-value is-negative" data-counter="<?= fmtBRL($totalExpenses) ?>">R$ 0,00</div>
                <div class="metric-card-trend <?= $deltaExpense >= 0 ? 'is-up' : 'is-down' ?>">
                    <?= $deltaExpense >= 0 ? '↑' : '↓' ?> <?= abs($deltaExpense) ?>%
                    <span class="trend-caption">em relação ao mês anterior</span>
                </div>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-card-icon is-primary"><?= render_icon('wallet', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Saldo</div>
                <div class="metric-card-value" data-counter="<?= fmtBRL($balance) ?>">R$ 0,00</div>
                <div class="metric-card-trend <?= $deltaBalance >= 0 ? 'is-up' : 'is-down' ?>">
                    <?= $deltaBalance >= 0 ? '↑' : '↓' ?> <?= abs($deltaBalance) ?>%
                    <span class="trend-caption">em relação ao mês anterior</span>
                </div>
            </div>
        </article>

        <article class="metric-card is-block">
            <div class="metric-card-head">
                <div class="metric-card-label">Gasto do mês</div>
                <div class="metric-card-icon is-info"><?= render_icon('pie', 18) ?></div>
            </div>
            <div class="metric-card-value is-primary"><?= $budgetPct ?>%</div>
            <div class="text-muted text-xs" style="margin-top:2px">Do orçamento utilizado</div>
            <div class="progress-bar is-large" style="margin-top:12px">
                <div class="progress-fill" data-width="<?= $budgetPct ?>" style="width:0%"></div>
            </div>
        </article>
    </section>

    <!-- ===== EVOLUÇÃO MENSAL + DESPESAS POR CATEGORIA ===== -->
    <section class="charts-grid charts-grid-2">
        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Evolução mensal</div>
                </div>
                <div class="chart-legend">
                    <span class="legend-item"><span class="legend-swatch" style="background:#10b981"></span>Receitas</span>
                    <span class="legend-item"><span class="legend-swatch" style="background:#ef4444"></span>Despesas</span>
                </div>
                <div class="select-wrap dash-range-select">
                    <select aria-label="Período">
                        <option>6 meses</option>
                        <option>12 meses</option>
                    </select>
                </div>
            </header>
            <div class="chart-wrap" style="min-height:300px">
                <canvas id="chart-financial-flow"></canvas>
            </div>
            <div class="chart-empty" id="chart-flow-empty">Sem lançamentos no período.</div>
        </article>

        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Despesas por categoria</div>
                </div>
            </header>
            <div class="dash-donut-wrap">
                <div class="dash-donut">
                    <canvas id="chart-expenses-by-category"></canvas>
                    <?php if ($totalExpenses > 0): ?>
                    <div class="dash-donut-center" aria-hidden="true"></div>
                    <?php endif; ?>
                </div>
                <ul class="dash-category-list">
                    <?php if (empty($chartCategories)): ?>
                        <li class="dash-category-empty">Nenhuma despesa registrada.</li>
                    <?php else: foreach ($chartCategories as $idx => $c):
                        $pct = $totalExpenses > 0 ? round(($c['value'] / $totalExpenses) * 100, 1) : 0;
                        $dot = $c['color'] ?: $catPalette[$idx % count($catPalette)];
                    ?>
                        <li class="dash-category-item">
                            <span class="dash-cat-dot" style="background:<?= htmlspecialchars($dot) ?>"></span>
                            <span class="dash-cat-name"><?= htmlspecialchars($c['label']) ?></span>
                            <span class="dash-cat-value">R$ <?= fmtBRL($c['value']) ?></span>
                            <span class="dash-cat-pct"><?= number_format($pct, 1, ',', '.') ?>%</span>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
            <div class="dash-donut-footer">
                <span class="dash-donut-footer-label">Total</span>
                <span class="dash-donut-footer-value">R$ <?= fmtBRL($totalExpenses) ?></span>
            </div>
        </article>
    </section>

    <!-- ===== ORÇAMENTO DO MÊS + METAS ===== -->
    <section class="charts-grid charts-grid-2">
        <article class="panel">
            <header class="panel-header">
                <div class="chart-card-title">Orçamento do mês</div>
            </header>
            <div class="panel-body">
                <div class="dash-budget-grid">
                    <div>
                        <div class="metric-card-label">Orçamento total</div>
                        <div class="dash-budget-value" data-counter="<?= fmtBRL($budgetTotal) ?>">R$ 0,00</div>
                    </div>
                    <div>
                        <div class="metric-card-label">Utilizado</div>
                        <div class="dash-budget-value is-negative" data-counter="<?= fmtBRL($budgetSpent) ?>">R$ 0,00</div>
                    </div>
                    <div>
                        <div class="metric-card-label">Disponível</div>
                        <div class="dash-budget-value is-positive" data-counter="<?= fmtBRL(max(0, $budgetRemain)) ?>">R$ 0,00</div>
                    </div>
                </div>
                <div class="progress-with-label" style="margin-top:6px">
                    <div class="progress-bar is-large"><div class="progress-fill" data-width="<?= $budgetPct ?>" style="width:0%"></div></div>
                    <span class="progress-label"><?= $budgetPct ?>%</span>
                </div>
                <?php if ($budgetRemain > 0): ?>
                <div class="inline-info" style="margin-top:18px;margin-bottom:0">
                    <?= render_icon('check', 13) ?>
                    <span>Você ainda pode gastar <strong>R$ <?= fmtBRL($budgetRemain) ?></strong> este mês.</span>
                </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <header class="panel-header">
                <div class="chart-card-title">Metas</div>
                <a href="/index.php?action=metas" class="btn btn-link btn-xs">Ver todas</a>
            </header>
            <div class="panel-body" style="display:flex;flex-direction:column;gap:18px">
                <?php if (empty($goalsTop)): ?>
                    <div class="text-muted text-sm" style="padding:8px 0">Nenhuma meta cadastrada ainda.</div>
                <?php else: foreach ($goalsTop as $i => $g):
                    $pct = min(100, (int)($g['percentage'] ?? 0));
                    $barColor = ($g['status'] ?? '') === 'completed' ? '#10b981' : (($g['status'] ?? '') === 'overdue' ? '#ef4444' : '#10b981');
                    $iconName = !empty($g['icon']) ? $g['icon'] : 'target';
                    // amarelo p/ 1ª meta, azul p/ 2ª (como na referência)
                    $iconBg = $i === 0 ? '#fef3c7' : '#dbeafe';
                    $iconFg = $i === 0 ? '#d97706' : '#2563eb';
                ?>
                    <div class="dash-goal">
                        <div class="dash-goal-icon" style="background:<?= $iconBg ?>;color:<?= $iconFg ?>">
                            <?= render_icon($iconName, 20) ?>
                        </div>
                        <div class="dash-goal-body">
                            <div class="dash-goal-head">
                                <div class="dash-goal-name"><?= htmlspecialchars($g['name']) ?></div>
                                <div class="dash-goal-pct"><?= $pct ?>%</div>
                            </div>
                            <div class="dash-goal-values">R$ <?= fmtBRL($g['saved'] ?? 0) ?> de R$ <?= fmtBRL($g['target'] ?? 0) ?></div>
                            <div class="progress-bar" style="margin-top:6px">
                                <div class="progress-fill" data-width="<?= $pct ?>" style="width:0%;background:<?= $barColor ?>"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </article>
    </section>

    <!-- ===== INFO BANNER ===== -->
    <div class="info-banner">
        <?= render_icon('shield', 16) ?>
        <span>Mantenha seus dados seguros e faça backups regularmente das suas informações financeiras.</span>
        <a href="#" class="btn-link">Saiba mais <?= render_icon('arrow-right', 12) ?></a>
    </div>

<?php
$dashData = [
    'chart_data'         => $chartData,
    'categories_chart'   => $chartCategories,
    'total_expenses'     => $totalExpenses,
];
$extraScripts  = '<script src="/assets/chart.min.js"></script>' . "\n";
$extraScripts .= '<script>window.DASHBOARD_CHART_DATA = ' . json_encode($dashData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
$extraScripts .= '<script src="/js/charts.js"></script>' . "\n";
$extraScripts .= '<script src="/js/dashboard.js"></script>' . "\n";
include __DIR__ . '/partials/layout_end.php';
?>
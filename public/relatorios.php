<?php
$pageTitle = 'Relatórios';
$pageSubtitle = 'Análise detalhada das suas movimentações financeiras.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'relatorios';
$pageEyebrow = 'Análise';

$report = $report ?? [];
$startDate  = $startDate ?? date('Y-m-01');
$endDate    = $endDate   ?? date('Y-m-t');
$filterType = $filterType ?? '';
$pagePeriodFrom = date('d/m/Y', strtotime($startDate));
$pagePeriodTo   = date('d/m/Y', strtotime($endDate));

$totalIncomes  = (float)($report['total_incomes']  ?? 0);
$totalExpenses = (float)($report['total_expenses'] ?? 0);
$balance       = $totalIncomes - $totalExpenses;
$txCount       = (int)($report['transactions_count'] ?? 0);
$economyPct    = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100) : 0;
$transactions  = $transactions ?? [];

$showPeriodPicker = false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <!-- ===== METRIC CARDS ===== -->
    <section class="metric-strip">
        <article class="metric-card">
            <div class="metric-card-icon is-success"><?= render_icon('trending-up', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Receitas</div>
                <div class="metric-card-value is-positive">R$ <?= number_format($totalIncomes, 2, ',', '.') ?></div>
                <div class="metric-card-trend"><?= (int)($report['income_count'] ?? 0) ?> lançamento(s)</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-danger"><?= render_icon('trending-down', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Despesas</div>
                <div class="metric-card-value is-negative">R$ <?= number_format($totalExpenses, 2, ',', '.') ?></div>
                <div class="metric-card-trend"><?= (int)($report['expense_count'] ?? 0) ?> lançamento(s)</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon <?= $balance < 0 ? 'is-danger' : 'is-success' ?>"><?= render_icon('wallet', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Saldo</div>
                <div class="metric-card-value <?= $balance < 0 ? 'is-negative' : 'is-positive' ?>">R$ <?= number_format($balance, 2, ',', '.') ?></div>
                <div class="metric-card-trend"><?= $economyPct ?>% economia</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-info"><?= render_icon('list', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Transações</div>
                <div class="metric-card-value"><?= $txCount ?></div>
                <div class="metric-card-trend">no período</div>
            </div>
        </article>
    </section>

    <!-- ===== FILTER BAR ===== -->
    <form method="GET" action="/index.php" class="filter-row" id="filterForm">
        <input type="hidden" name="action" value="relatorios">
        <div class="select-wrap" style="width:auto;min-width:140px">
            <select name="type" onchange="this.form.submit()">
                <option value="">Todos os tipos</option>
                <option value="receita" <?= $filterType === 'receita' ? 'selected' : '' ?>>Receitas</option>
                <option value="despesa" <?= $filterType === 'despesa' ? 'selected' : '' ?>>Despesas</option>
            </select>
        </div>
        <div class="select-wrap" style="width:auto;min-width:160px">
            <select name="category_id" onchange="this.form.submit()">
                <option value="">Todas as categorias</option>
                <?php foreach (($expenseCategories ?? []) as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= ((int)($_GET['category_id'] ?? 0)) === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-input grow">
            <?= render_icon('search', 14) ?>
            <input type="text" name="search" placeholder="Buscar lançamento..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">
            <?= render_icon('chart', 13) ?>
            Gerar
        </button>
        <a href="/index.php?action=relatorios" class="btn btn-ghost btn-sm">Limpar</a>
    </form>

    <!-- ===== CHARTS ROW ===== -->
    <section class="charts-grid charts-grid-2" style="margin-bottom:var(--space-4)">
        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Receitas x Despesas</div>
                    <div class="chart-card-sub">Comparativo mensal no período.</div>
                </div>
                <div class="chart-legend">
                    <span class="legend-item"><span class="legend-swatch" style="background:#10b981"></span>Receitas</span>
                    <span class="legend-item"><span class="legend-swatch" style="background:#ef4444"></span>Despesas</span>
                </div>
            </header>
            <div class="chart-wrap" style="min-height:280px">
                <canvas id="chart-income-expense"></canvas>
            </div>
            <div class="chart-empty" id="chart-income-expense-empty">Nenhum lançamento no período.</div>
        </article>

        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Despesas por categoria</div>
                    <div class="chart-card-sub">Distribuição percentual.</div>
                </div>
            </header>
            <div class="chart-wrap" style="min-height:280px;position:relative">
                <canvas id="chart-category-report"></canvas>
                <div class="donut-center">
                    <div class="donut-center-label">Total</div>
                    <div class="donut-center-value">R$ <?= number_format($totalExpenses, 2, ',', '.') ?></div>
                </div>
            </div>
            <div class="chart-empty" id="chart-category-report-empty">Nenhuma despesa registrada.</div>
        </article>
    </section>

    <!-- ===== BALANCE EVOLUTION ===== -->
    <section class="chart-card" style="margin-bottom:var(--space-5)">
        <header class="chart-card-head">
            <div>
                <div class="chart-card-title">Evolução do saldo</div>
                <div class="chart-card-sub">Saldo acumulado ao longo do tempo.</div>
            </div>
            <div class="chart-legend">
                <span class="legend-item"><span class="legend-swatch" style="background:#10b981"></span>Saldo</span>
            </div>
        </header>
        <div class="chart-wrap" style="min-height:200px">
            <canvas id="chart-balance-evolution"></canvas>
        </div>
        <div class="chart-empty" id="chart-balance-empty">Dados insuficientes para evolução.</div>
    </section>

    <!-- ===== TRANSACTIONS TABLE ===== -->
    <section class="panel" style="margin-bottom:var(--space-5)">
        <header class="panel-header">
            <div>
                <div class="panel-title">
                    <?php if ($filterType === 'receita'): ?>Receitas
                    <?php elseif ($filterType === 'despesa'): ?>Despesas
                    <?php else: ?>Todos os Lançamentos
                    <?php endif; ?>
                </div>
                <div class="panel-subtitle"><?= count($transactions) ?> resultado(s) encontrado(s)</div>
            </div>
            <a href="#" class="btn btn-ghost btn-sm">
                <?= render_icon('download', 13) ?>
                Exportar
            </a>
        </header>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th class="th-numeric">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="4" class="empty-cell">Nenhum lançamento no período.</td></tr>
                    <?php else: foreach ($transactions as $t):
                        $txType  = $t['type'] ?? '';
                        $txClass = $txType === 'despesa' ? 'td-negative' : 'td-positive';
                        $sign    = $txType === 'despesa' ? '- ' : '+ ';
                    ?>
                    <tr>
                        <td class="td-mono td-muted" style="white-space:nowrap"><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                        <td class="td-strong"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                        <td class="td-muted"><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                        <td class="td-numeric <?= $txClass ?>"><?= $sign ?>R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($transactions) > 0): ?>
        <div class="pagination">
            <div class="pagination-info"><?= count($transactions) ?> lançamento(s)</div>
            <div class="pagination-controls">
                <button class="pagination-btn" disabled><?= render_icon('chevron-left', 12) ?></button>
                <button class="pagination-btn is-active">1</button>
                <button class="pagination-btn"><?= render_icon('chevron-right', 12) ?></button>
            </div>
            <div></div>
        </div>
        <?php endif; ?>
    </section>

<?php
$extraScripts = '<script src="/assets/chart.min.js"></script>' . "\n";
$extraScripts .= '<script>window.REPORT_CHART_DATA = ' . json_encode($report['chart_data'] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
$extraScripts .= '<script src="/js/charts-report.js"></script>' . "\n";
include __DIR__ . '/partials/layout_end.php';
?>
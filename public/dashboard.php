<?php
if (!isset($data) || !is_array($data)) {
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Dashboard - Controle de Gastos';
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

$categoriesTable = $data['expenses_by_category_table'] ?? [];
$recentTransactions = $data['recent_transactions'] ?? [];

$topCategory = !empty($categoriesTable) ? $categoriesTable[0] : null;
$largestExpense = $data['largest_expense'] ?? null;

function fmtBRL($value) {
    return number_format((float)$value, 2, ',', '.');
}

$activeMenu = 'dashboard';
$pageEyebrow = 'Visão geral';
$pageTitle = 'Dashboard';
$pagePeriodFrom = date('d/m/Y', strtotime($startDate));
$pagePeriodTo   = date('d/m/Y', strtotime($endDate));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <!-- ALERTS -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" role="status">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= htmlspecialchars(['1' => 'Operação realizada com sucesso!', 'updated' => 'Transação atualizada com sucesso!'][$_GET['success']] ?? 'Operação realizada com sucesso!') ?></span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error" role="alert">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars(['invalid_data' => 'Dados inválidos.', 'invalid_category' => 'Categoria inválida.', 'not_found' => 'Lançamento não encontrado.', 'update_failed' => 'Erro ao atualizar.'][$_GET['error']] ?? 'Ocorreu um erro.') ?></span>
        </div>
    <?php endif; ?>

    <!-- HERO + QUICK ACTIONS + M FILTERS -->
    <section class="hero-row">
        <article class="hero-card">
            <div class="hero-card-head">
                <span class="hero-card-label">Saldo do período</span>
                <span class="hero-card-icon" aria-hidden="true">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
            </div>
            <div class="hero-card-value <?= $balance < 0 ? 'is-negative' : ($balance > 0 ? 'is-positive' : '') ?>">
                R$ <?= fmtBRL($balance) ?>
            </div>
            <div class="hero-card-sub">
                <?= $txCount ?> lançamento(s) no período · taxa de economia
                <strong class="<?= $economyPct > 0 ? 'is-positive' : ($economyPct < 0 ? 'is-negative' : '') ?>">
                    <?= $economyPct > 0 ? '+' : '' ?><?= $economyPct ?>%
                </strong>
            </div>
        </article>

        <div class="quick-actions">
            <div class="quick-actions-head">
                <div class="quick-actions-title">Cadastrar transação</div>
                <div class="quick-actions-sub">Registre uma receita ou despesa rapidamente.</div>
            </div>
            <a href="#addTransactionFormCard" class="btn btn-primary">
                Ver detalhes
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </a>
        </div>
    </section>

    <!-- METRIC STRIP -->
    <section class="metrics-strip">
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Receitas</span>
                <span class="metric-card-icon is-success" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-positive">R$ <?= fmtBRL($totalIncomes) ?></div>
            <div class="metric-card-sub"><?= $incomeCount ?> receita(s) no período</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Despesas</span>
                <span class="metric-card-icon is-danger" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-negative">R$ <?= fmtBRL($totalExpenses) ?></div>
            <div class="metric-card-sub"><?= $expenseCount ?> despesa(s) no período</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Lançamentos</span>
                <span class="metric-card-icon is-primary" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </span>
            </div>
            <div class="metric-card-value"><?= $txCount ?></div>
            <div class="metric-card-sub">total no período</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Maior categoria</span>
                <span class="metric-card-icon is-warning" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                </span>
            </div>
            <div class="metric-card-value" style="font-size:16px">
                <?= $topCategory ? htmlspecialchars($topCategory['name']) : '—' ?>
            </div>
            <div class="metric-card-sub">
                <?= $topCategory ? 'R$ ' . fmtBRL($topCategory['total'] ?? 0) : 'Sem dados' ?>
            </div>
        </article>
    </section>

    <!-- INDICATORS -->
    <?php
    $topCat = $data['indicators']['top_category'] ?? null;
    $topCatName = $topCat['name'] ?? null;
    $topCatTotal = $topCat['total'] ?? 0;
    $committedPct = (float)($data['indicators']['committed_pct'] ?? 0);
    $avgExpense = (float)($data['indicators']['avg_expense'] ?? 0);
    $avgIncome = (float)($data['indicators']['avg_income'] ?? 0);
    ?>
    <section class="indicators">
        <div class="indicator">
            <div class="indicator-label">Maior categoria</div>
            <div class="indicator-value"><?= $topCatName ? htmlspecialchars($topCatName) : '—' ?></div>
            <div class="indicator-sub"><?= $topCatName ? 'R$ ' . fmtBRL($topCatTotal) : 'Sem dados' ?></div>
        </div>
        <div class="indicator">
            <div class="indicator-label">Maior despesa</div>
            <div class="indicator-value is-negative"><?= $largestExpense ? 'R$ ' . fmtBRL($largestExpense['amount']) : '—' ?></div>
            <div class="indicator-sub"><?= $largestExpense ? htmlspecialchars($largestExpense['description'] ?? '') : 'Sem dados' ?></div>
        </div>
        <div class="indicator">
            <div class="indicator-label">Lançamentos</div>
            <div class="indicator-value"><?= $txCount ?></div>
            <div class="indicator-sub">total no período</div>
        </div>
        <div class="indicator">
            <div class="indicator-label">Média despesas</div>
            <div class="indicator-value is-negative">R$ <?= fmtBRL($avgExpense) ?></div>
            <div class="indicator-sub">mensal</div>
        </div>
        <div class="indicator">
            <div class="indicator-label">Média receitas</div>
            <div class="indicator-value is-positive">R$ <?= fmtBRL($avgIncome) ?></div>
            <div class="indicator-sub">mensal</div>
        </div>
        <div class="indicator">
            <div class="indicator-label">Renda comprometida</div>
            <div class="indicator-value <?= $committedPct > 80 ? 'is-negative' : '' ?>"><?= $committedPct ?>%</div>
            <div class="indicator-sub">das receitas</div>
        </div>
    </section>

    <!-- FILTER BAR -->
    <section class="filter-bar">
        <form method="GET" action="/" class="filter-form" id="filterForm">
            <div class="filter-shortcuts">
                <button type="button" class="filter-shortcut" data-range="today">Hoje</button>
                <button type="button" class="filter-shortcut is-active" data-range="month">Este mês</button>
                <button type="button" class="filter-shortcut" data-range="last-month">Mês anterior</button>
            </div>
            <div class="filter-group">
                <label for="inputStartDate">De</label>
                <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="filter-group">
                <label for="inputEndDate">Até</label>
                <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
                <a href="/" class="btn btn-ghost btn-sm">Limpar</a>
            </div>
        </form>
    </section>

    <!-- CHARTS -->
    <section class="charts-grid">
        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Fluxo financeiro</div>
                    <div class="chart-card-sub">Receitas, despesas e saldo no período.</div>
                </div>
                <div class="chart-legend">
                    <span class="legend-item"><span class="legend-swatch" style="background:#10b981"></span>Receitas</span>
                    <span class="legend-item"><span class="legend-swatch" style="background:#ef4444"></span>Despesas</span>
                    <span class="legend-item"><span class="legend-swatch" style="background:#7c3aed"></span>Saldo</span>
                </div>
            </header>
            <div class="chart-wrap">
                <canvas id="chart-financial-flow"></canvas>
            </div>
            <div class="chart-empty" id="chart-flow-empty">Sem lançamentos no período.</div>
        </article>
    </section>

    <section class="charts-grid charts-grid-2">
        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Despesas por categoria</div>
                    <div class="chart-card-sub">Distribuição no período.</div>
                </div>
            </header>
            <div class="chart-wrap" style="min-height:220px">
                <canvas id="chart-expenses-by-category"></canvas>
            </div>
            <div class="chart-empty" id="chart-category-empty">Nenhuma despesa registrada.</div>
        </article>
        <article class="chart-card">
            <header class="chart-card-head">
                <div>
                    <div class="chart-card-title">Comparativo mensal</div>
                    <div class="chart-card-sub">Receitas vs despesas por mês.</div>
                </div>
            </header>
            <div class="chart-wrap" style="min-height:220px">
                <canvas id="chart-monthly-comparison"></canvas>
            </div>
            <div class="chart-empty" id="chart-monthly-empty">Sem dados mensais para comparar.</div>
        </article>
    </section>

    <!-- TABLE + FORM -->
    <section class="two-col">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Últimos lançamentos</div>
                    <div class="panel-subtitle">Movimentações mais recentes.</div>
                </div>
                <a href="/index.php?action=lancamentos" class="btn btn-ghost btn-xs">Ver todos</a>
            </header>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Data</th>
                            <th class="th-numeric">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTransactions)): ?>
                            <tr><td colspan="4" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($recentTransactions, 0, 5) as $t): ?>
                                <?php
                                    $txType = $t['type'] ?? '';
                                    $txClass = $txType === 'despesa' ? 'td-negative' : 'td-positive';
                                ?>
                                <tr>
                                    <td>
                                        <div class="td-strong"><?= htmlspecialchars($t['description'] ?? '') ?></div>
                                        <div class="td-muted text-xs"><?= $txType === 'despesa' ? 'Despesa' : 'Receita' ?></div>
                                    </td>
                                    <td class="td-muted"><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                    <td class="td-mono td-muted"><?= isset($t['date']) ? date('d/m', strtotime($t['date'])) : '—' ?></td>
                                    <td class="td-numeric <?= $txClass ?>">R$ <?= fmtBRL($t['amount'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel" id="addTransactionFormCard">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Novo lançamento</div>
                    <div class="panel-subtitle">Registre uma receita ou despesa.</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=store" method="POST" id="addTransactionForm" novalidate>
                    <div class="form-stack">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="type">Tipo</label>
                                <div class="select-wrap">
                                    <select name="type" id="type" required>
                                        <option value="despesa" selected>Despesa</option>
                                        <option value="receita">Receita</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="date">Data</label>
                                <input type="date" name="date" id="date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Descrição</label>
                            <input type="text" name="description" id="description" placeholder="Ex: Supermercado" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="amount">Valor (R$)</label>
                                <input type="number" name="amount" id="amount" step="0.01" min="0.01" placeholder="0,00" required>
                            </div>
                            <div class="form-group">
                                <label for="category_id">Categoria</label>
                                <div class="select-wrap">
                                    <select name="category_id" id="category_id">
                                        <option value="">Sem categoria</option>
                                        <?php foreach ($expenseCategories as $cat): ?>
                                            <option value="<?= (int)$cat['id'] ?>" data-type="despesa"><?= htmlspecialchars($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                        <?php foreach ($incomeCategories as $cat): ?>
                                            <option value="<?= (int)$cat['id'] ?>" data-type="receita"><?= htmlspecialchars($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            Adicionar lançamento
                        </button>
                    </div>
                </form>
            </div>
        </article>
    </section>

    <!-- CATEGORY TABLE + FORM -->
    <?php if (!empty($categoriesTable)): ?>
    <section class="two-col">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Despesas por categoria</div>
                    <div class="panel-subtitle">Resumo no período.</div>
                </div>
            </header>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th class="th-numeric">Qtd</th>
                            <th class="th-numeric">Total</th>
                            <th class="th-numeric">%</th>
                            <th>Participação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $catColors = ['#7c3aed','#10b981','#ef4444','#f59e0b','#3b82f6','#8b5cf6','#ec4899','#06b6d4','#84cc16','#64748b'];
                        foreach ($categoriesTable as $i => $cat):
                            $pct = (float)($cat['percentage'] ?? 0);
                            $color = $catColors[$i % count($catColors)];
                        ?>
                            <tr>
                                <td><div class="cat-cell"><span class="cat-dot" style="background:<?= $color ?>"></span><?= htmlspecialchars($cat['name']) ?></div></td>
                                <td class="td-numeric"><?= (int)($cat['count'] ?? 0) ?></td>
                                <td class="td-numeric td-negative">R$ <?= fmtBRL($cat['total'] ?? 0) ?></td>
                                <td class="td-numeric"><?= $pct ?>%</td>
                                <td class="bar-cell">
                                    <div class="progress-with-label">
                                        <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                                        <span class="progress-label">—</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Nova categoria</div>
                    <div class="panel-subtitle">Adicione despesa ou receita.</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=store_category" method="POST" id="addCategoryForm" novalidate>
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="cat-name">Nome</label>
                            <input type="text" name="name" id="cat-name" placeholder="Ex: Educação, Transporte" required>
                        </div>
                        <div class="form-group">
                            <label for="cat-type">Tipo</label>
                            <div class="select-wrap">
                                <select name="type" id="cat-type">
                                    <option value="despesa">Despesa</option>
                                    <option value="receita">Receita</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            Adicionar
                        </button>
                    </div>
                </form>
            </div>
        </article>
    </section>
    <?php endif; ?>

<?php
$extraScripts = '<script src="/assets/chart.min.js"></script>' . "\n";
$extraScripts .= '<script>window.DASHBOARD_CHART_DATA = ' . json_encode($chartData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
$extraScripts .= '<script>window.DASHBOARD_MONTHLY_COMPARISON = ' . json_encode($data['monthly_comparison'] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
$extraScripts .= '<script src="/js/charts.js"></script>' . "\n";
include __DIR__ . '/partials/layout_end.php';
?>
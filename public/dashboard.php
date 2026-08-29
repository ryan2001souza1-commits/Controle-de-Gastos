<?php
if (!isset($data) || !is_array($data)) {
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Dashboard - Controle de Gastos';
$userName = $_SESSION['user_name'] ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));
$chartData = $data['chart_data'] ?? null;

$totalIncomes   = (float)($data['total_incomes'] ?? 0);
$totalExpenses  = (float)($data['total_expenses'] ?? 0);
$balance        = (float)($data['balance'] ?? 0);
$txCount        = (int)($data['transactions_count'] ?? 0);
$incomeCount    = (int)($data['income_count'] ?? 0);
$expenseCount   = (int)($data['expense_count'] ?? 0);
$economyPct     = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100, 1) : 0.0;

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

$categoriesTable = $data['expenses_by_category_table'] ?? [];
$recentTransactions = $data['recent_transactions'] ?? [];

$topCategory = !empty($categoriesTable) ? $categoriesTable[0] : null;
$topCategoryName = $topCategory['name'] ?? null;
$topCategoryTotal = $topCategory['total'] ?? 0;

$largestExpense = $data['largest_expense'] ?? null;

function fmtBRL($value) {
    return number_format((float)$value, 2, ',', '.');
}
$activeMenu = 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/dashboard.css">
</head>
<body>
<div class="app-wrapper">

    <!-- ========== SIDEBAR ========== -->
    <aside class="sidebar" id="sidebar" aria-label="Navegação principal">
        <div class="sidebar-header">
            <div class="sidebar-logo" aria-hidden="true">CG</div>
            <span class="sidebar-brand">Controle de Gastos</span>
        </div>
        <nav class="sidebar-nav" aria-label="Menu principal">
            <div class="sidebar-section-label">Menu</div>
            <a href="/index.php" class="sidebar-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>" aria-current="<?= $activeMenu === 'dashboard' ? 'page' : 'false' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                Dashboard
            </a>
            <a href="/index.php?action=lancamentos" class="sidebar-link <?= $activeMenu === 'lancamentos' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Lançamentos
            </a>
            <a href="/index.php?action=categorias" class="sidebar-link <?= $activeMenu === 'categorias' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                Categorias
            </a>
            <a href="/index.php?action=relatorios" class="sidebar-link <?= $activeMenu === 'relatorios' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Relatórios
            </a>
            <a href="/index.php?action=orcamentos" class="sidebar-link <?= $activeMenu === 'orcamentos' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link <?= $activeMenu === 'metas' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                Metas
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="/index.php?action=logout" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sair
            </a>
        </div>
    </aside>

    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

    <!-- ========== MAIN ========== -->
    <main class="main-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <div class="topbar-eyebrow">Visão geral</div>
                <h1 class="topbar-title">Dashboard</h1>
                <span class="topbar-period">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= htmlspecialchars(date('d/m/Y', strtotime($startDate))) ?> — <?= htmlspecialchars(date('d/m/Y', strtotime($endDate))) ?>
                </span>
            </div>
            <div class="topbar-right">
                <button type="button" class="icon-btn" aria-label="Notificações">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    <span class="icon-btn-dot" aria-hidden="true"></span>
                </button>
                <div class="topbar-divider" aria-hidden="true"></div>
                <div class="topbar-user">
                    <div class="topbar-avatar" aria-hidden="true"><?= $userInitials ?></div>
                    <div class="topbar-user-meta">
                        <span class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></span>
                        <span class="topbar-role">Conta pessoal</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success" role="status">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= htmlspecialchars(['1' => 'Operação realizada com sucesso!', 'updated' => 'Transação atualizada com sucesso!'][$_GET['success']] ?? 'Operação realizada com sucesso!') ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error" role="alert">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= htmlspecialchars(['invalid_data' => 'Dados inválidos. Verifique e tente novamente.', 'invalid_category' => 'Categoria inválida.', 'not_found' => 'Lançamento não encontrado.', 'update_failed' => 'Erro ao atualizar.'][$_GET['error']] ?? 'Ocorreu um erro.') ?></span>
            </div>
        <?php endif; ?>

        <!-- ========== FILTER ========== -->
        <section class="filter-bar" aria-label="Filtros de período">
            <form method="GET" action="/index.php" class="filter-form" id="filterForm">
                <div class="filter-shortcuts" role="tablist" aria-label="Atalhos de período">
                    <button type="button" class="filter-shortcut" data-range="today" role="tab">Hoje</button>
                    <button type="button" class="filter-shortcut" data-range="month" role="tab">Este mês</button>
                    <button type="button" class="filter-shortcut" data-range="last-month" role="tab">Mês anterior</button>
                </div>
                <div class="filter-fields">
                    <div class="filter-group">
                        <label for="inputStartDate">De</label>
                        <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="filter-separator" aria-hidden="true">até</div>
                    <div class="filter-group">
                        <label for="inputEndDate">Até</label>
                        <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Aplicar
                        </button>
                        <a href="/index.php" class="btn btn-ghost btn-sm">Limpar</a>
                    </div>
                </div>
            </form>
        </section>

        <!-- ========== KPI CARDS ========== -->
        <section class="kpi-grid" aria-label="Indicadores financeiros">
            <article class="kpi-card kpi-card-balance">
                <header class="kpi-header">
                    <span class="kpi-label">Saldo atual</span>
                    <div class="kpi-icon" aria-hidden="true">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                </header>
                <div class="kpi-body">
                    <span class="kpi-value <?= $balance < 0 ? 'is-negative' : ($balance > 0 ? 'is-positive' : '') ?>">R$ <?= fmtBRL($balance) ?></span>
                    <span class="kpi-sub"><?= $txCount ?> lançamento(s) no período</span>
                </div>
                <footer class="kpi-foot">
                    <span class="kpi-foot-dot"></span>
                    Atualizado agora
                </footer>
            </article>

            <article class="kpi-card kpi-card-income">
                <header class="kpi-header">
                    <span class="kpi-label">Receitas</span>
                    <div class="kpi-icon" aria-hidden="true">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                </header>
                <div class="kpi-body">
                    <span class="kpi-value">R$ <?= fmtBRL($totalIncomes) ?></span>
                    <span class="kpi-sub"><?= $incomeCount ?> receita(s) confirmada(s)</span>
                </div>
                <div class="kpi-trend kpi-trend-up" aria-hidden="true">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>
                    Entradas
                </div>
            </article>

            <article class="kpi-card kpi-card-expense">
                <header class="kpi-header">
                    <span class="kpi-label">Despesas</span>
                    <div class="kpi-icon" aria-hidden="true">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                    </div>
                </header>
                <div class="kpi-body">
                    <span class="kpi-value">R$ <?= fmtBRL($totalExpenses) ?></span>
                    <span class="kpi-sub"><?= $expenseCount ?> despesa(s) registrada(s)</span>
                </div>
                <div class="kpi-trend kpi-trend-down" aria-hidden="true">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                    Saídas
                </div>
            </article>

            <article class="kpi-card kpi-card-economy">
                <header class="kpi-header">
                    <span class="kpi-label">Taxa de economia</span>
                    <div class="kpi-icon" aria-hidden="true">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                </header>
                <div class="kpi-body">
                    <span class="kpi-value <?= $economyPct > 0 ? 'is-positive' : ($economyPct < 0 ? 'is-negative' : '') ?>"><?= $economyPct > 0 ? '+' : '' ?><?= $economyPct ?>%</span>
                    <span class="kpi-sub"><?= $totalIncomes > 0 ? 'do lucro retido' : 'sem receitas no período' ?></span>
                </div>
                <div class="kpi-progress" aria-hidden="true">
                    <div class="kpi-progress-track">
                        <div class="kpi-progress-fill" style="width: <?= max(0, min(100, (float)$economyPct)) ?>%"></div>
                    </div>
                </div>
            </article>
        </section>

        <!-- ========== INDICATORS ========== -->
        <?php
        $topCat = $data['indicators']['top_category'] ?? null;
        $topCatName = $topCat['name'] ?? null;
        $topCatTotal = $topCat['total'] ?? 0;
        $committedPct = (float)($data['indicators']['committed_pct'] ?? 0);
        $economyVal = (float)($data['indicators']['economy'] ?? 0);
        $avgExpense = (float)($data['indicators']['avg_expense'] ?? 0);
        $avgIncome = (float)($data['indicators']['avg_income'] ?? 0);
        ?>
        <section class="indicators" aria-label="Indicadores complementares">
            <div class="indicator">
                <span class="indicator-label">Maior categoria</span>
                <span class="indicator-value"><?= $topCatName ? htmlspecialchars($topCatName) : '—' ?></span>
                <span class="indicator-sub"><?= $topCatName ? 'R$ ' . fmtBRL($topCatTotal) : 'Sem dados' ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Maior despesa</span>
                <span class="indicator-value is-negative"><?= $largestExpense ? 'R$ ' . fmtBRL($largestExpense['amount']) : '—' ?></span>
                <span class="indicator-sub"><?= $largestExpense ? htmlspecialchars($largestExpense['description'] ?? '') : 'Sem dados' ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Lançamentos</span>
                <span class="indicator-value"><?= $txCount ?></span>
                <span class="indicator-sub">total no período</span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Média mensal — despesas</span>
                <span class="indicator-value is-negative">R$ <?= fmtBRL($avgExpense) ?></span>
                <span class="indicator-sub">mês a mês</span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Média mensal — receitas</span>
                <span class="indicator-value is-positive">R$ <?= fmtBRL($avgIncome) ?></span>
                <span class="indicator-sub">mês a mês</span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Renda comprometida</span>
                <span class="indicator-value <?= $committedPct > 80 ? 'is-negative' : '' ?>"><?= $committedPct ?>%</span>
                <span class="indicator-sub">das receitas em despesas</span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Economizado</span>
                <span class="indicator-value <?= $economyVal >= 0 ? 'is-positive' : 'is-negative' ?>">R$ <?= fmtBRL($economyVal) ?></span>
                <span class="indicator-sub">saldo do período</span>
            </div>
        </section>

        <!-- ========== FILTER ========== -->
        <section class="filter-bar" aria-label="Filtros de período">
            <form method="GET" action="/index.php" class="filter-form" id="filterForm">
                <div class="filter-shortcuts" role="tablist" aria-label="Atalhos de período">
                    <button type="button" class="filter-shortcut" data-range="today" role="tab">Hoje</button>
                    <button type="button" class="filter-shortcut" data-range="month" role="tab">Este mês</button>
                    <button type="button" class="filter-shortcut" data-range="last-month" role="tab">Mês anterior</button>
                </div>
                <div class="filter-fields">
                    <div class="filter-group">
                        <label for="inputStartDate">De</label>
                        <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="filter-separator" aria-hidden="true">até</div>
                    <div class="filter-group">
                        <label for="inputEndDate">Até</label>
                        <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Aplicar
                        </button>
                        <a href="/index.php" class="btn btn-ghost btn-sm">Limpar</a>
                    </div>
                </div>
            </form>
        </section>

        <!-- ========== CHARTS ========== -->
        <section class="charts-section">
            <!-- Row 1: Fluxo Financeiro (full) -->
            <div class="chart-full">
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div class="chart-card-titles">
                            <h3>Fluxo financeiro</h3>
                            <p>Receitas, despesas e saldo diário no período.</p>
                        </div>
                        <div class="chart-legend">
                            <span class="legend-item"><span class="legend-swatch swatch-income"></span>Receitas</span>
                            <span class="legend-item"><span class="legend-swatch swatch-expense"></span>Despesas</span>
                            <span class="legend-item"><span class="legend-swatch swatch-balance"></span>Saldo</span>
                        </div>
                    </header>
                    <div class="chart-wrap">
                        <canvas id="chart-financial-flow"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-flow-empty">Sem lançamentos no período.</div>
                </article>
            </div>
            <!-- Row 2: Categorias + Comparativo -->
            <div class="charts-row-2">
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div class="chart-card-titles">
                            <h3>Despesas por categoria</h3>
                            <p>Distribuição dos gastos no período.</p>
                        </div>
                    </header>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-expenses-by-category"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-category-empty">Nenhuma despesa registrada no período.</div>
                </article>
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div class="chart-card-titles">
                            <h3>Receitas × despesas</h3>
                            <p>Comparativo por período selecionado.</p>
                        </div>
                    </header>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-income-vs-expense"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-period-empty">Nenhum lançamento no período.</div>
                </article>
            </div>
            <!-- Row 3: Evolução do Saldo (full) -->
            <div class="chart-full">
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div class="chart-card-titles">
                            <h3>Evolução do saldo</h3>
                            <p>Saldo acumulado ao longo do tempo.</p>
                        </div>
                    </header>
                    <div class="chart-wrap">
                        <canvas id="chart-balance-evolution"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-balance-empty">Dados insuficientes para evolução.</div>
                </article>
            </div>
            <!-- Row 4: Comparativo Mensal (full) -->
            <div class="chart-full">
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div class="chart-card-titles">
                            <h3>Comparativo mensal</h3>
                            <p>Receitas e despesas dos últimos meses.</p>
                        </div>
                    </header>
                    <div class="chart-wrap">
                        <canvas id="chart-monthly-comparison"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-monthly-empty">Sem dados para comparar.</div>
                </article>
            </div>
        </section>

        <!-- ========== BOTTOM ROW ========== -->
        <section class="bottom-row">
            <!-- Novo Lançamento -->
            <article class="panel">
                <header class="panel-header">
                    <div>
                        <h3 class="panel-title">Novo lançamento</h3>
                        <p class="panel-subtitle">Registre uma receita ou despesa.</p>
                    </div>
                </header>
                <form action="/index.php?action=store" method="POST" class="form-grid" id="addTransactionForm" novalidate>
                    <div class="form-group" id="grp-type">
                        <label for="type">Tipo</label>
                        <div class="select-wrap">
                            <select name="type" id="type" required>
                                <option value="despesa" selected>Despesa</option>
                                <option value="receita">Receita</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group form-group-wide" id="grp-description">
                        <label for="description">Descrição</label>
                        <input type="text" name="description" id="description" placeholder="Ex: Supermercado" required>
                        <span class="form-error">Informe a descrição.</span>
                    </div>
                    <div class="form-group" id="grp-amount">
                        <label for="amount">Valor</label>
                        <div class="input-prefix">
                            <span class="prefix">R$</span>
                            <input type="number" name="amount" id="amount" step="0.01" min="0.01" placeholder="0,00" required>
                        </div>
                        <span class="form-error">Informe um valor válido.</span>
                    </div>
                    <div class="form-group" id="grp-date">
                        <label for="date">Data</label>
                        <input type="date" name="date" id="date" value="<?= date('Y-m-d') ?>" required>
                        <span class="form-error">Informe a data.</span>
                    </div>
                    <div class="form-group form-group-full" id="grp-category">
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
                    <div class="form-group form-group-full form-group-actions">
                        <button type="submit" class="btn btn-primary btn-block" id="btnSubmit">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Adicionar lançamento
                        </button>
                    </div>
                </form>
            </article>

            <!-- Últimos Lançamentos -->
            <article class="panel panel-table">
                <header class="panel-header">
                    <div>
                        <h3 class="panel-title">Últimos lançamentos</h3>
                        <p class="panel-subtitle">Movimentações mais recentes.</p>
                    </div>
                    <span class="panel-meta"><?= count($recentTransactions) ?> registro(s)</span>
                </header>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Tipo</th>
                                <th scope="col">Descrição</th>
                                <th scope="col">Categoria</th>
                                <th scope="col">Data</th>
                                <th scope="col" class="th-numeric">Valor</th>
                                <th scope="col" class="th-actions">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransactions)): ?>
                                <tr><td colspan="6" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentTransactions as $t): ?>
                                    <?php
                                        $txType = $t['type'] ?? '';
                                        $txLabel = $txType === 'despesa' ? 'Despesa' : ($txType === 'receita' ? 'Receita' : '');
                                        $txBadge = $txType === 'despesa' ? 'badge-danger' : 'badge-success';
                                        $txClass = $txType === 'despesa' ? 'is-negative' : 'is-positive';
                                    ?>
                                    <tr>
                                        <td><span class="badge <?= $txBadge ?>"><span class="badge-dot"></span><?= $txLabel ?></span></td>
                                        <td class="td-strong"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                        <td class="td-muted"><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                        <td class="td-numeric <?= $txClass ?>"><strong>R$ <?= fmtBRL($t['amount'] ?? 0) ?></strong></td>
                                        <td class="actions-cell">
                                            <a href="/index.php?action=edit&id=<?= (int)($t['id'] ?? 0) ?>&type=<?= htmlspecialchars($txType) ?>" class="btn btn-ghost btn-xs">Editar</a>
                                            <form action="/index.php?action=delete" method="POST" class="delete-form">
                                                <input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
                                                <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>">
                                                <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <!-- ========== SUMMARY ========== -->
        <section class="summary-section">
            <!-- Resumo por Categoria -->
            <article class="panel panel-table">
                <header class="panel-header">
                    <div>
                        <h3 class="panel-title">Despesas por categoria</h3>
                        <p class="panel-subtitle">Resumo consolidado no período.</p>
                    </div>
                </header>
                <?php if (empty($categoriesTable)): ?>
                    <div class="empty-msg">Nenhuma despesa registrada no período.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table data-table-distribution">
                            <thead>
                                <tr>
                                    <th scope="col">Categoria</th>
                                    <th scope="col" class="th-numeric">Qtd</th>
                                    <th scope="col" class="th-numeric">Total</th>
                                    <th scope="col" class="th-numeric">%</th>
                                    <th scope="col">Distribuição</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $catColors = ['#4f46e5','#10b981','#ef4444','#f59e0b','#0ea5e9','#a855f7','#ec4899','#14b8a6'];
                                foreach ($categoriesTable as $i => $cat):
                                    $pct = (float)($cat['percentage'] ?? 0);
                                    $color = $catColors[$i % count($catColors)];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="cat-cell">
                                                <span class="cat-color-dot" style="background:<?= $color ?>"></span>
                                                <span><?= htmlspecialchars($cat['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="td-numeric"><?= (int)($cat['count'] ?? 0) ?></td>
                                        <td class="td-numeric is-negative">R$ <?= fmtBRL($cat['total'] ?? 0) ?></td>
                                        <td class="td-numeric"><?= $pct ?>%</td>
                                        <td class="bar-cell">
                                            <div class="progress-bar" title="<?= $pct ?>%">
                                                <div class="progress-fill" style="width:<?= $pct ?>%; background:<?= $color ?>"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>

            <!-- Nova Categoria -->
            <article class="panel">
                <header class="panel-header">
                    <div>
                        <h3 class="panel-title">Nova categoria</h3>
                        <p class="panel-subtitle">Adicione uma categoria de despesa ou receita.</p>
                    </div>
                </header>
                <form action="/index.php?action=store_category" method="POST" class="form-stack" id="addCategoryForm" novalidate>
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
                    <div class="form-group form-group-actions">
                        <button type="submit" class="btn btn-primary btn-block">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Adicionar categoria
                        </button>
                    </div>
                </form>
            </article>
        </section>

    </main>
</div>

<script src="/assets/chart.min.js"></script>
<script>
    window.DASHBOARD_CHART_DATA = <?= json_encode($chartData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>;
    window.DASHBOARD_MONTHLY_COMPARISON = <?= json_encode($data['monthly_comparison'] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/js/charts.js"></script>
<script src="/js/app.js"></script>
</body>
</html>

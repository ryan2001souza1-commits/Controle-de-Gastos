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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">

    <!-- ========== SIDEBAR ========== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">CG</div>
            <span class="sidebar-brand">Controle de Gastos</span>
        </div>
        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Menu</div>
            <a href="/index.php" class="sidebar-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                Dashboard
            </a>
            <a href="/index.php?action=lancamentos" class="sidebar-link <?= $activeMenu === 'lancamentos' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Lançamentos
            </a>
            <a href="/index.php?action=categorias" class="sidebar-link <?= $activeMenu === 'categorias' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                Categorias
            </a>
            <a href="/index.php?action=relatorios" class="sidebar-link <?= $activeMenu === 'relatorios' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Relatórios
            </a>
            <a href="/index.php?action=orcamentos" class="sidebar-link <?= $activeMenu === 'orcamentos' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link <?= $activeMenu === 'metas' ? 'active' : '' ?>">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                Metas
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="/index.php?action=logout" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sair
            </a>
        </div>
    </aside>

    <!-- ========== TOGGLE (mobile) ========== -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>

    <!-- ========== MAIN ========== -->
    <main class="main-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <h2 class="topbar-title">Dashboard</h2>
                <span class="topbar-period">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= date('01/m/Y') ?> — <?= date('t/m/Y') ?>
                </span>
            </div>
            <div class="topbar-right">
                <span class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></span>
                <div class="topbar-avatar"><?= $userInitials ?></div>
            </div>
        </header>

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars(['1' => 'Operação realizada com sucesso!', 'updated' => 'Transação atualizada com sucesso!'][$_GET['success']] ?? 'Operação realizada com sucesso!') ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars(['invalid_data' => 'Dados inválidos. Verifique e tente novamente.', 'invalid_category' => 'Categoria inválida.', 'not_found' => 'Lançamento não encontrado.', 'update_failed' => 'Erro ao atualizar.'][$_GET['error']] ?? 'Ocorreu um erro.') ?>
            </div>
        <?php endif; ?>

        <!-- ========== CARDS ========== -->
        <section class="cards-grid">
            <div class="fin-card fin-card-balance">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Saldo Atual</span>
                    <span class="fin-card-value <?= $balance < 0 ? 'text-danger' : ($balance > 0 ? 'text-primary' : 'text-muted') ?>">R$ <?= fmtBRL($balance) ?></span>
                    <span class="fin-card-sub"><?= $txCount ?> lançamento(s) no período</span>
                </div>
            </div>
            <div class="fin-card fin-card-income">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Receitas</span>
                    <span class="fin-card-value text-income">R$ <?= fmtBRL($totalIncomes) ?></span>
                    <span class="fin-card-sub"><?= $incomeCount ?> receita(s)</span>
                </div>
            </div>
            <div class="fin-card fin-card-expense">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Despesas</span>
                    <span class="fin-card-value text-expense">R$ <?= fmtBRL($totalExpenses) ?></span>
                    <span class="fin-card-sub"><?= $expenseCount ?> despesa(s)</span>
                </div>
            </div>
            <div class="fin-card fin-card-economy">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Economia</span>
                    <span class="fin-card-value <?= $economyPct > 0 ? 'text-income' : ($economyPct < 0 ? 'text-danger' : 'text-muted') ?>"><?= $economyPct > 0 ? '+' : '' ?><?= $economyPct ?>%</span>
                    <span class="fin-card-sub"><?= $totalIncomes > 0 ? 'do lucro retido' : 'sem receitas' ?></span>
                </div>
            </div>
        </section>

        <!-- ========== INDICATORS ========== -->
        <section class="indicators-grid">
            <?php
            $topCat = $data['indicators']['top_category'] ?? null;
            $topCatName = $topCat['name'] ?? null;
            $topCatTotal = $topCat['total'] ?? 0;
            ?>
            <div class="indicator">
                <span class="indicator-label">Maior Categoria</span>
                <span class="indicator-value"><?= $topCatName ? htmlspecialchars($topCatName) : '—' ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Valor Maior Categoria</span>
                <span class="indicator-value text-expense"><?= $topCatName ? 'R$ ' . fmtBRL($topCatTotal) : '—' ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Maior Despesa</span>
                <span class="indicator-value text-expense"><?= $largestExpense ? 'R$ ' . fmtBRL($largestExpense['amount']) : '—' ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Total Lançamentos</span>
                <span class="indicator-value"><?= $txCount ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Média Despesas/Mês</span>
                <span class="indicator-value text-expense">R$ <?= fmtBRL($data['indicators']['avg_expense'] ?? 0) ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Média Receitas/Mês</span>
                <span class="indicator-value text-income">R$ <?= fmtBRL($data['indicators']['avg_income'] ?? 0) ?></span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Renda Comprometida</span>
                <span class="indicator-value <?= ($data['indicators']['committed_pct'] ?? 0) > 80 ? 'text-danger' : 'text-primary' ?>"><?= $data['indicators']['committed_pct'] ?? 0 ?>%</span>
            </div>
            <div class="indicator">
                <span class="indicator-label">Economizado</span>
                <span class="indicator-value <?= ($data['indicators']['economy'] ?? 0) >= 0 ? 'text-income' : 'text-danger' ?>">R$ <?= fmtBRL($data['indicators']['economy'] ?? 0) ?></span>
            </div>
        </section>

        <!-- ========== FILTER ========== -->
        <section class="filter-bar">
            <form method="GET" action="/index.php" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label>Data Início</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" id="inputStartDate">
                </div>
                <div class="filter-group">
                    <label>Data Fim</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" id="inputEndDate">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filtrar
                    </button>
                    <a href="/index.php" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
                <div class="filter-shortcuts">
                    <button type="button" class="filter-shortcut" data-range="today">Hoje</button>
                    <button type="button" class="filter-shortcut" data-range="month">Este mês</button>
                    <button type="button" class="filter-shortcut" data-range="last-month">Mês anterior</button>
                </div>
            </form>
        </section>

        <!-- ========== CHARTS ========== -->
        <section class="charts-section">
            <!-- Row 1: Fluxo Financeiro (full) -->
            <div class="chart-full">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Fluxo Financeiro</h3>
                            <span class="chart-subtitle">Receitas, despesas e saldo por dia no período</span>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="chart-financial-flow"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-flow-empty">Sem lançamentos no período.</div>
                </div>
            </div>
            <!-- Row 2: Categorias + Comparativo -->
            <div class="charts-row-2">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Despesas por Categoria</h3>
                            <span class="chart-subtitle">Distribuição das despesas no período</span>
                        </div>
                    </div>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-expenses-by-category"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-category-empty">Nenhuma despesa registrada no período.</div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Receitas x Despesas</h3>
                            <span class="chart-subtitle">Comparativo por período</span>
                        </div>
                    </div>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-income-vs-expense"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-period-empty">Nenhum lançamento no período.</div>
                </div>
            </div>
            <!-- Row 3: Evolução do Saldo (full) -->
            <div class="chart-full">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Evolução do Saldo</h3>
                            <span class="chart-subtitle">Saldo acumulado ao longo do tempo</span>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="chart-balance-evolution"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-balance-empty">Dados insuficientes para evolução.</div>
                </div>
            </div>
            <!-- Row 4: Comparativo Mensal (full) -->
            <div class="chart-full">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Comparativo Mensal</h3>
                            <span class="chart-subtitle">Receitas x Despesas nos últimos meses</span>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="chart-monthly-comparison"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-monthly-empty">Sem dados para comparar.</div>
                </div>
            </div>
        </section>

        <!-- ========== BOTTOM ROW ========== -->
        <section class="bottom-row">
            <!-- Novo Lançamento -->
            <div class="bottom-card">
                <h3 class="bottom-card-title">Novo Lançamento</h3>
                <form action="/index.php?action=store" method="POST" class="form-grid" id="addTransactionForm" novalidate>
                    <div class="form-group" id="grp-type">
                        <label for="type">Tipo</label>
                        <select name="type" id="type" required>
                            <option value="despesa" selected>Despesa</option>
                            <option value="receita">Receita</option>
                        </select>
                    </div>
                    <div class="form-group" id="grp-description">
                        <label for="description">Descrição</label>
                        <input type="text" name="description" id="description" placeholder="Ex: Supermercado" required>
                        <span class="form-error">Informe a descrição.</span>
                    </div>
                    <div class="form-group" id="grp-amount">
                        <label for="amount">Valor (R$)</label>
                        <input type="number" name="amount" id="amount" step="0.01" min="0.01" placeholder="0,00" required>
                        <span class="form-error">Informe um valor válido.</span>
                    </div>
                    <div class="form-group" id="grp-date">
                        <label for="date">Data</label>
                        <input type="date" name="date" id="date" value="<?= date('Y-m-d') ?>" required>
                        <span class="form-error">Informe a data.</span>
                    </div>
                    <div class="form-group form-group-full" id="grp-category">
                        <label for="category_id">Categoria</label>
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
                    <div class="form-group-full">
                        <button type="submit" class="btn btn-primary btn-block" id="btnSubmit">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Adicionar Lançamento
                        </button>
                    </div>
                </form>
            </div>

            <!-- Últimos Lançamentos -->
            <div class="bottom-card">
                <div class="bottom-card-header">
                    <h3 class="bottom-card-title" style="margin-bottom:0">Últimos Lançamentos</h3>
                    <span class="bottom-card-count"><?= count($recentTransactions) ?> registro(s)</span>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Data</th>
                                <th>Valor</th>
                                <th>Ações</th>
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
                                        $txClass = $txType === 'despesa' ? 'text-expense' : 'text-income';
                                    ?>
                                    <tr>
                                        <td><span class="badge <?= $txBadge ?>"><span class="badge-dot"></span><?= $txLabel ?></span></td>
                                        <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                        <td><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                        <td class="<?= $txClass ?>"><strong>R$ <?= fmtBRL($t['amount'] ?? 0) ?></strong></td>
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
            </div>
        </section>

        <!-- ========== SUMMARY ========== -->
        <section class="summary-section">
            <!-- Resumo por Categoria -->
            <div class="bottom-card">
                <h3 class="bottom-card-title">Despesas por Categoria</h3>
                <?php if (empty($categoriesTable)): ?>
                    <div class="empty-msg">Nenhuma despesa registrada no período.</div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>Qtd</th>
                                <th>Total</th>
                                <th>%</th>
                                <th>Distribuição</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $catColors = ['#4f46e5','#16a34a','#dc2626','#f59e0b','#0ea5e9','#a855f7','#ec4899','#14b8a6'];
                            foreach ($categoriesTable as $i => $cat):
                                $pct = (float)($cat['percentage'] ?? 0);
                                $color = $catColors[$i % count($catColors)];
                            ?>
                                <tr>
                                    <td>
                                        <span class="cat-color-dot" style="background:<?= $color ?>"></span>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </td>
                                    <td><?= (int)($cat['count'] ?? 0) ?></td>
                                    <td class="text-expense">R$ <?= fmtBRL($cat['total'] ?? 0) ?></td>
                                    <td><?= $pct ?>%</td>
                                    <td class="bar-cell">
                                        <div class="progress-bar" title="<?= $pct ?>%">
                                            <div class="progress-fill" style="width:<?= $pct ?>%; background: linear-gradient(90deg, <?= $color ?>, <?= $color ?>88)"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Nova Categoria -->
            <div class="bottom-card">
                <h3 class="bottom-card-title">Nova Categoria</h3>
                <form action="/index.php?action=store_category" method="POST" class="form-stack" id="addCategoryForm" novalidate>
                    <div class="form-group">
                        <label>Nome</label>
                        <input type="text" name="name" placeholder="Ex: Educação, Transporte" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="type">
                            <option value="despesa">Despesa</option>
                            <option value="receita">Receita</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Adicionar Categoria
                    </button>
                </form>
            </div>
        </section>

    </main>
</div>

<script src="/assets/chart.min.js"></script>
<script>
    window.DASHBOARD_CHART_DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
    window.DASHBOARD_MONTHLY_COMPARISON = <?= json_encode($data['monthly_comparison'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/js/charts.js"></script>
<script src="/js/app.js"></script>
</body>
</html>

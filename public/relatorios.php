<?php
$pageTitle = $pageTitle ?? 'Relatórios - Controle de Gastos';
$userName  = $userName  ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'relatorios';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">CG</div>
            <span class="sidebar-brand">Controle de Gastos</span>
        </div>
        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Menu</div>
            <a href="/index.php" class="sidebar-link">
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
            <a href="/index.php?action=orcamentos" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link">
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

    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>

    <main class="main-content">

        <header class="topbar">
            <div class="topbar-left">
                <h2 class="topbar-title">Relatórios</h2>
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

        <!-- SUMMARY CARDS -->
        <section class="cards-grid">
            <div class="fin-card fin-card-income">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Total Receitas</span>
                    <span class="fin-card-value text-income">R$ <?= number_format($report['total_incomes'], 2, ',', '.') ?></span>
                    <span class="fin-card-sub"><?= $report['income_count'] ?> lançamento(s)</span>
                </div>
            </div>
            <div class="fin-card fin-card-expense">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Total Despesas</span>
                    <span class="fin-card-value text-expense">R$ <?= number_format($report['total_expenses'], 2, ',', '.') ?></span>
                    <span class="fin-card-sub"><?= $report['expense_count'] ?> lançamento(s)</span>
                </div>
            </div>
            <div class="fin-card fin-card-balance">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Saldo</span>
                    <span class="fin-card-value <?= $report['balance'] < 0 ? 'text-danger' : ($report['balance'] > 0 ? 'text-primary' : 'text-muted') ?>">R$ <?= number_format($report['balance'], 2, ',', '.') ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-economy">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Total Lançamentos</span>
                    <span class="fin-card-value text-primary"><?= $report['transactions_count'] ?></span>
                </div>
            </div>
        </section>

        <!-- FILTER -->
        <section class="filter-bar">
            <form method="GET" action="/index.php?action=relatorios" class="filter-form" id="filterForm">
                <input type="hidden" name="action" value="relatorios">
                <div class="filter-group">
                    <label>Data Início</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" id="inputStartDate">
                </div>
                <div class="filter-group">
                    <label>Data Fim</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" id="inputEndDate">
                </div>
                <div class="filter-group">
                    <label>Tipo</label>
                    <select name="type">
                        <option value="">Todos</option>
                        <option value="receita" <?= $filterType === 'receita' ? 'selected' : '' ?>>Receitas</option>
                        <option value="despesa" <?= $filterType === 'despesa' ? 'selected' : '' ?>>Despesas</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Categoria</label>
                    <select name="category_id">
                        <option value="">Todas</option>
                        <?php foreach ($expenseCategories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Gerar Relatório
                    </button>
                    <a href="/index.php?action=relatorios" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <!-- CHARTS -->
        <section class="charts-section">
            <div class="charts-row-2">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Receitas x Despesas</h3>
                            <span class="chart-subtitle">Comparativo por mês</span>
                        </div>
                    </div>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-income-vs-expense"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-period-empty">Nenhum lançamento no período.</div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <h3>Despesas por Categoria</h3>
                            <span class="chart-subtitle">Distribuição das despesas</span>
                        </div>
                    </div>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-expenses-by-category"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-category-empty">Nenhuma despesa registrada.</div>
                </div>
            </div>
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
        </section>

        <!-- TABLE -->
        <section class="bottom-card">
            <div class="bottom-card-header">
                <h3 class="bottom-card-title" style="margin-bottom:0">
                    <?php if ($filterType === 'receita'): ?>Receitas
                    <?php elseif ($filterType === 'despesa'): ?>Despesas
                    <?php else: ?>Todos os Lançamentos
                    <?php endif; ?>
                    no Período
                </h3>
                <span class="bottom-card-count"><?= count($transactions) ?> registro(s)</span>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tipo</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="5" class="empty-cell">Nenhum lançamento no período.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <?php
                                $txType = $t['type'] ?? '';
                                $txLabel = $txType === 'despesa' ? 'Despesa' : ($txType === 'receita' ? 'Receita' : '');
                                $txBadge = $txType === 'despesa' ? 'badge-danger' : 'badge-success';
                                $txClass = $txType === 'despesa' ? 'text-expense' : 'text-income';
                                ?>
                                <tr>
                                    <td><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                    <td><span class="badge <?= $txBadge ?>"><span class="badge-dot"></span><?= $txLabel ?></span></td>
                                    <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                    <td class="<?= $txClass ?>"><strong>R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script src="/assets/chart.min.js"></script>
<script>
    window.DASHBOARD_CHART_DATA = <?= json_encode($report['chart_data'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/js/charts.js"></script>
<script src="/js/app.js"></script>
</body>
</html>

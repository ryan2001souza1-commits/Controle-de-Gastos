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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" aria-label="Navegação principal">
        <div class="sidebar-header">
            <div class="sidebar-logo" aria-hidden="true">CG</div>
            <span class="sidebar-brand">Controle de Gastos</span>
        </div>
        <nav class="sidebar-nav" aria-label="Menu principal">
            <div class="sidebar-section-label">Visão geral</div>
            <a href="/index.php" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                Dashboard
            </a>
            <div class="sidebar-section-label">Gestão</div>
            <a href="/index.php?action=lancamentos" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Lançamentos
            </a>
            <a href="/index.php?action=categorias" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                Categorias
            </a>
            <a href="/index.php?action=orcamentos" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                Metas
            </a>
            <div class="sidebar-section-label">Análise</div>
            <a href="/index.php?action=relatorios" class="sidebar-link <?= $activeMenu === 'relatorios' ? 'active' : '' ?>">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Relatórios
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="/index.php?action=logout" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sair
            </a>
        </div>
    </aside>

    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

    <main class="main-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <div class="topbar-eyebrow">Análise</div>
                <h1 class="topbar-title">Relatórios</h1>
                <div class="topbar-period">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= date('01/m/Y') ?> — <?= date('t/m/Y') ?>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-user">
                    <div class="topbar-avatar" aria-hidden="true"><?= $userInitials ?></div>
                    <div class="topbar-user-meta">
                        <div class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></div>
                        <div class="topbar-role">Conta pessoal</div>
                    </div>
                </div>
            </div>
        </header>

        <!-- METRIC STRIP -->
        <section class="m-strip">
            <div class="m-card">
                <div class="m-card-label">Receitas</div>
                <div class="m-card-value positive">R$ <?= number_format($report['total_incomes'], 2, ',', '.') ?></div>
                <div class="m-card-sub"><?= $report['income_count'] ?> lançamento(s)</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Despesas</div>
                <div class="m-card-value negative">R$ <?= number_format($report['total_expenses'], 2, ',', '.') ?></div>
                <div class="m-card-sub"><?= $report['expense_count'] ?> lançamento(s)</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Saldo</div>
                <div class="m-card-value <?= $report['balance'] < 0 ? 'negative' : ($report['balance'] > 0 ? 'positive' : '') ?>">R$ <?= number_format($report['balance'], 2, ',', '.') ?></div>
                <div class="m-card-sub">no período</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Total lançamentos</div>
                <div class="m-card-value"><?= $report['transactions_count'] ?></div>
                <div class="m-card-sub">no período</div>
            </div>
        </section>

        <!-- FILTER -->
        <section class="filter-bar">
            <form method="GET" action="/index.php?action=relatorios" class="filter-form" id="filterForm">
                <input type="hidden" name="action" value="relatorios">
                <div class="filter-group">
                    <label for="inputStartDate">De</label>
                    <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="filter-group">
                    <label for="inputEndDate">Até</label>
                    <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="filter-group">
                    <label for="filterType">Tipo</label>
                    <div class="select-wrap">
                        <select name="type" id="filterType">
                            <option value="">Todos</option>
                            <option value="receita" <?= ($filterType ?? '') === 'receita' ? 'selected' : '' ?>>Receitas</option>
                            <option value="despesa" <?= ($filterType ?? '') === 'despesa' ? 'selected' : '' ?>>Despesas</option>
                        </select>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="filterCategory">Categoria</label>
                    <div class="select-wrap">
                        <select name="category_id" id="filterCategory">
                            <option value="">Todas</option>
                            <?php foreach (($expenseCategories ?? []) as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
                    <a href="/index.php?action=relatorios" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <!-- CHARTS -->
        <section class="charts-section">
            <div class="charts-row charts-row-2">
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Receitas x Despesas</div>
                            <div class="chart-card-sub">Comparativo por mês.</div>
                        </div>
                    </header>
                    <div class="chart-wrap" style="min-height:220px">
                        <canvas id="chart-income-vs-expense"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-period-empty">Nenhum lançamento no período.</div>
                </article>
                <article class="chart-card">
                    <header class="chart-card-header">
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
            </div>
            <div class="chart-full">
                <article class="chart-card">
                    <header class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Evolução do saldo</div>
                            <div class="chart-card-sub">Saldo acumulado ao longo do tempo.</div>
                        </div>
                    </header>
                    <div class="chart-wrap">
                        <canvas id="chart-balance-evolution"></canvas>
                    </div>
                    <div class="chart-empty" id="chart-balance-empty">Dados insuficientes para evolução.</div>
                </article>
            </div>
        </section>

        <!-- TABLE -->
        <section class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">
                        <?php if (($filterType ?? '') === 'receita'): ?>Receitas
                        <?php elseif (($filterType ?? '') === 'despesa'): ?>Despesas
                        <?php else: ?>Todos os Lançamentos
                        <?php endif; ?>
                        no Período
                    </div>
                    <div class="panel-subtitle"><?= count($transactions) ?> resultado(s) encontrado(s)</div>
                </div>
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
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <?php
                                $txType = $t['type'] ?? '';
                                $txClass = $txType === 'despesa' ? 'td-negative' : 'td-positive';
                                ?>
                                <tr>
                                    <td class="td-mono td-muted"><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                    <td class="td-strong"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                                    <td class="td-muted"><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                    <td class="td-numeric <?= $txClass ?>">R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></td>
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
    window.DASHBOARD_CHART_DATA = <?= json_encode($report['chart_data'] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/js/charts.js"></script>
<script src="/js/app.js"></script>
</body>
</html>

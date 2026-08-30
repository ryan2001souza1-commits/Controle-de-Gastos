<?php
$pageTitle = $pageTitle ?? 'Relatórios - Controle de Gastos';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'relatorios';
$pageEyebrow = 'Análise';
$pageTitle = 'Relatórios';
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

    <?php
    $startDate = $startDate ?? date('Y-m-01');
    $endDate = $endDate ?? date('Y-m-t');
    $filterType = $filterType ?? '';
    ?>

    <section class="metrics-strip">
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Receitas</span>
                <span class="metric-card-icon is-success" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-positive">R$ <?= number_format($report['total_incomes'] ?? 0, 2, ',', '.') ?></div>
            <div class="metric-card-sub"><?= ($report['income_count'] ?? 0) ?> lançamento(s)</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Despesas</span>
                <span class="metric-card-icon is-danger" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-negative">R$ <?= number_format($report['total_expenses'] ?? 0, 2, ',', '.') ?></div>
            <div class="metric-card-sub"><?= ($report['expense_count'] ?? 0) ?> lançamento(s)</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Saldo</span>
                <span class="metric-card-icon <?= ($report['balance'] ?? 0) < 0 ? 'is-danger' : 'is-success' ?>" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </span>
            </div>
            <div class="metric-card-value <?= ($report['balance'] ?? 0) < 0 ? 'is-negative' : 'is-positive' ?>">R$ <?= number_format($report['balance'] ?? 0, 2, ',', '.') ?></div>
            <div class="metric-card-sub">no período</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Total lançamentos</span>
                <span class="metric-card-icon is-primary" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3" y2="6"/><line x1="3" y1="12" x2="3" y2="12"/><line x1="3" y1="18" x2="3" y2="18"/></svg>
                </span>
            </div>
            <div class="metric-card-value"><?= ($report['transactions_count'] ?? 0) ?></div>
            <div class="metric-card-sub">no período</div>
        </article>
    </section>

    <section class="filter-bar">
        <form method="GET" action="/index.php?action=relatorios" class="filter-form" id="filterForm">
            <input type="hidden" name="action" value="relatorios">
            <div class="filter-group">
                <label for="inputStartDate">De</label>
                <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate ?? '') ?>">
            </div>
            <div class="filter-group">
                <label for="inputEndDate">Até</label>
                <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate ?? '') ?>">
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

    <section class="charts-grid charts-grid-2">
        <article class="chart-card">
            <header class="chart-card-head">
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
    </section>

    <section class="chart-card" style="margin-bottom: var(--space-5)">
        <header class="chart-card-head">
            <div>
                <div class="chart-card-title">Evolução do saldo</div>
                <div class="chart-card-sub">Saldo acumulado ao longo do tempo.</div>
            </div>
        </header>
        <div class="chart-wrap">
            <canvas id="chart-balance-evolution"></canvas>
        </div>
        <div class="chart-empty" id="chart-balance-empty">Dados insuficientes para evolução.</div>
    </section>

    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">
                    <?php if (($filterType ?? '') === 'receita'): ?>Receitas
                    <?php elseif (($filterType ?? '') === 'despesa'): ?>Despesas
                    <?php else: ?>Todos os Lançamentos
                    <?php endif; ?> no Período
                </div>
                <div class="panel-subtitle"><?= count($transactions ?? []) ?> resultado(s) encontrado(s)</div>
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
                    <?php if (empty($transactions ?? [])): ?>
                        <tr><td colspan="4" class="empty-cell">Nenhum lançamento no período.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions ?? [] as $t): ?>
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

<?php
$extraScripts = '<script src="/assets/chart.min.js"></script>' . "\n";
$extraScripts .= '<script>window.DASHBOARD_CHART_DATA = ' . json_encode($report['chart_data'] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
include __DIR__ . '/partials/layout_end.php';
?>
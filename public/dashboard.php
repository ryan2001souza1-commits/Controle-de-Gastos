<?php
if (!isset($data) || !is_array($data)) {
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Dashboard - Controle de Gastos';
$userName = $_SESSION['user_name'] ?? 'Usuário';
$chartData = $data['chart_data'] ?? null;
$currentMonth = date('01/m/Y') . ' a ' . date('t/m/Y');
$currentMonthShort = date('F/Y');

$totalIncomes   = (float)($data['total_incomes'] ?? 0);
$totalExpenses  = (float)($data['total_expenses'] ?? 0);
$balance        = (float)($data['balance'] ?? 0);
$economyPct    = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100, 1) : 0.0;

function fmt($value) {
    return number_format((float)$value, 2, ',', '.');
}

$categoriesTable = $data['expenses_by_category_table'] ?? [];
$recentTransactions = $data['recent_transactions'] ?? [];
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1 class="sidebar-logo">CG</h1>
            <span class="sidebar-brand">Controle de Gastos</span>
        </div>
        <nav class="sidebar-nav">
            <a href="/index.php" class="sidebar-link active">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Dashboard
            </a>
            <a href="#" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Lançamentos
            </a>
            <a href="#" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                Categorias
            </a>
            <a href="#" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Relatórios
            </a>
            <a href="#" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>
                Configurações
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="/index.php?action=logout" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sair
            </a>
        </div>
    </aside>

    <!-- TOGGLE SIDEBAR (mobile) -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu">
        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOP BAR -->
        <header class="topbar">
            <div class="topbar-left">
                <h2 class="topbar-title">Dashboard</h2>
                <span class="topbar-period">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= date('01/m/Y') . ' — ' . date('t/m/Y') ?>
                </span>
            </div>
            <div class="topbar-right">
                <span class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></span>
            </div>
        </header>

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])): ?>
            <?php $msgs = ['1' => 'Operação realizada com sucesso!', 'updated' => 'Transação atualizada com sucesso!']; ?>
            <div class="alert alert-success"><?= htmlspecialchars($msgs[$_GET['success']] ?? 'Operação realizada com sucesso!') ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <?php $errs = ['invalid_data' => 'Dados inválidos.', 'invalid_category' => 'Categoria inválida.', 'not_found' => 'Lançamento não encontrado.', 'update_failed' => 'Erro ao atualizar.']; ?>
            <div class="alert alert-error"><?= htmlspecialchars($errs[$_GET['error']] ?? 'Erro desconhecido.') ?></div>
        <?php endif; ?>

        <!-- CARDS -->
        <section class="cards-grid">
            <div class="fin-card fin-card-balance">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Saldo Atual</span>
                    <span class="fin-card-value <?= $balance < 0 ? 'text-danger' : ($balance > 0 ? 'text-primary' : 'text-muted') ?>">R$ <?= fmt($balance) ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-income">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Receitas</span>
                    <span class="fin-card-value text-income">R$ <?= fmt($totalIncomes) ?></span>
                    <span class="fin-card-sub"><?= (int)($data['income_count'] ?? 0) ?> lançamento(s)</span>
                </div>
            </div>
            <div class="fin-card fin-card-expense">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Despesas</span>
                    <span class="fin-card-value text-expense">R$ <?= fmt($totalExpenses) ?></span>
                    <span class="fin-card-sub"><?= (int)($data['expense_count'] ?? 0) ?> lançamento(s)</span>
                </div>
            </div>
            <div class="fin-card fin-card-economy">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Economia</span>
                    <span class="fin-card-value <?= $economyPct > 0 ? 'text-income' : ($economyPct < 0 ? 'text-expense' : 'text-muted') ?>"><?= $economyPct > 0 ? '+' : '' ?><?= $economyPct ?>%</span>
                    <span class="fin-card-sub"><?= $totalIncomes > 0 ? 'do lucro retido' : 'sem receitas' ?></span>
                </div>
            </div>
        </section>

        <!-- FILTER BAR -->
        <section class="filter-bar">
            <form method="GET" action="/index.php" class="filter-form">
                <div class="filter-group">
                    <label>Início</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="filter-group">
                    <label>Fim</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                    <a href="/index.php" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <!-- CHARTS GRID -->
        <section class="charts-section">
            <!-- Row 1: Financial Flow (full width) -->
            <div class="chart-full">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Fluxo Financeiro</h3>
                        <span class="chart-subtitle">Receitas, despesas e saldo por dia</span>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="chart-financial-flow"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-flow-empty" data-msg="Sem lançamentos no período.">Sem lançamentos no período.</p>
                </div>
            </div>
            <!-- Row 2: Categories + Monthly comparison -->
            <div class="charts-row-2">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Despesas por Categoria</h3>
                        <span class="chart-subtitle">Distribuição das despesas</span>
                    </div>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-expenses-by-category"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-category-empty" data-msg="Nenhuma despesa registrada no período.">Nenhuma despesa registrada no período.</p>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Receitas x Despesas</h3>
                        <span class="chart-subtitle">Comparativo mensal</span>
                    </div>
                    <div class="chart-wrap chart-wrap-sm">
                        <canvas id="chart-income-vs-expense"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-period-empty" data-msg="Nenhum lançamento no período.">Nenhum lançamento no período.</p>
                </div>
            </div>
            <!-- Row 3: Balance Evolution (full width) -->
            <div class="chart-full">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Evolução do Saldo</h3>
                        <span class="chart-subtitle">Saldo acumulado ao longo do tempo</span>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="chart-balance-evolution"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-balance-empty" data-msg="Dados insuficientes.">Dados insuficientes.</p>
                </div>
            </div>
        </section>

        <!-- BOTTOM ROW: Form + Table -->
        <section class="bottom-row">
            <!-- ADD TRANSACTION -->
            <div class="bottom-card">
                <h3 class="bottom-card-title">Novo Lançamento</h3>
                <form action="/index.php?action=store" method="POST" class="form-grid">
                    <div class="form-group">
                        <label for="type">Tipo</label>
                        <select name="type" id="type" required>
                            <option value="despesa">Despesa</option>
                            <option value="receita">Receita</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="description">Descrição</label>
                        <input type="text" name="description" id="description" placeholder="Ex: Supermercado" required>
                    </div>
                    <div class="form-group">
                        <label for="amount">Valor (R$)</label>
                        <input type="number" name="amount" id="amount" step="0.01" min="0.01" placeholder="0,00" required>
                    </div>
                    <div class="form-group">
                        <label for="date">Data</label>
                        <input type="date" name="date" id="date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group form-group-full">
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
                        <button type="submit" class="btn btn-primary btn-block">Adicionar</button>
                    </div>
                </form>
            </div>

            <!-- RECENT TRANSACTIONS -->
            <div class="bottom-card bottom-card-wide">
                <div class="bottom-card-header">
                    <h3 class="bottom-card-title">Últimos Lançamentos</h3>
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
                                        <td><span class="badge <?= $txBadge ?>"><?= $txLabel ?></span></td>
                                        <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                        <td><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                        <td class="<?= $txClass ?>"><strong>R$ <?= fmt($t['amount'] ?? 0) ?></strong></td>
                                        <td class="actions-cell">
                                            <a href="/index.php?action=edit&id=<?= (int)($t['id'] ?? 0) ?>&type=<?= htmlspecialchars($txType) ?>" class="btn btn-ghost btn-xs">Editar</a>
                                            <form action="/index.php?action=delete" method="POST" style="display:inline">
                                                <input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
                                                <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>">
                                                <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Excluir este lançamento?')">Excluir</button>
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

        <!-- CATEGORY SUMMARY -->
        <section class="summary-section">
            <div class="bottom-card">
                <h3 class="bottom-card-title">Resumo de Despesas por Categoria</h3>
                <?php if (empty($categoriesTable)): ?>
                    <p class="empty-msg">Nenhuma despesa no período.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>Qtd</th>
                                <th>Total</th>
                                <th>%</th>
                                <th>Barra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoriesTable as $cat): ?>
                                <?php $pct = (float)($cat['percentage'] ?? 0); ?>
                                <tr>
                                    <td><?= htmlspecialchars($cat['name']) ?></td>
                                    <td><?= (int)($cat['count'] ?? 0) ?></td>
                                    <td class="text-expense">R$ <?= fmt($cat['total'] ?? 0) ?></td>
                                    <td><?= $pct ?>%</td>
                                    <td class="bar-cell">
                                        <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- NEW CATEGORY -->
            <div class="bottom-card bottom-card-sm">
                <h3 class="bottom-card-title">Nova Categoria</h3>
                <form action="/index.php?action=store_category" method="POST" class="form-stack">
                    <div class="form-group">
                        <label>Nome</label>
                        <input type="text" name="name" placeholder="Ex: Educação" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="type">
                            <option value="despesa">Despesa</option>
                            <option value="receita">Receita</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Adicionar</button>
                </form>
            </div>
        </section>

    </main>
</div>

<script src="/js/app.js"></script>
<script src="/assets/chart.min.js"></script>
<script>
    window.DASHBOARD_CHART_DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/js/charts.js"></script>
</body>
</html>

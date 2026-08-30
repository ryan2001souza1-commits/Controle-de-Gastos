<?php
$pageTitle = $pageTitle ?? 'Orçamentos - Controle de Gastos';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'orcamentos';
$pageEyebrow = 'Planejamento';
$pageTitle = 'Orçamentos';

$budgets = $budgetData['budgets'] ?? [];
$totals  = $budgetData['totals']  ?? ['limit' => 0, 'spent' => 0, 'remaining' => 0, 'percentage' => 0];
$counts  = $budgetData['counts']  ?? ['over' => 0, 'warn' => 0, 'ok' => 0];

$errors = [
    'invalid_data'       => 'Dados inválidos. Verifique o valor.',
    'invalid_category'   => 'Categoria inválida.',
    'invalid_date'       => 'Data inválida.',
    'not_found'          => 'Orçamento não encontrado.',
    'invalid_id'         => 'ID inválido.',
];
$successMsgs = [
    'saved'   => 'Orçamento salvo com sucesso!',
    'deleted' => 'Orçamento removido!',
];

$meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$pagePeriodFrom = ($meses[(int)$month] ?? '') . ' / ' . (int)$year;
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

    <?php if (isset($_GET['success']) && isset($successMsgs[$_GET['success']])): ?>
        <div class="alert alert-success" role="status">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= htmlspecialchars($successMsgs[$_GET['success']]) ?></span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && isset($errors[$_GET['error']])): ?>
        <div class="alert alert-error" role="alert">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errors[$_GET['error']]) ?></span>
        </div>
    <?php endif; ?>

    <section class="metrics-strip">
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Limite total</span>
                <span class="metric-card-icon is-info" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </span>
            </div>
            <div class="metric-card-value">R$ <?= number_format($totals['limit'], 2, ',', '.') ?></div>
            <div class="metric-card-sub">no mês</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Gasto atual</span>
                <span class="metric-card-icon is-danger" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-negative">R$ <?= number_format($totals['spent'], 2, ',', '.') ?></div>
            <div class="metric-card-sub"><?= $totals['percentage'] ?>% do limite</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Restante</span>
                <span class="metric-card-icon <?= $totals['remaining'] < 0 ? 'is-danger' : 'is-success' ?>" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </span>
            </div>
            <div class="metric-card-value <?= $totals['remaining'] < 0 ? 'is-negative' : 'is-positive' ?>">R$ <?= number_format($totals['remaining'], 2, ',', '.') ?></div>
            <div class="metric-card-sub">disponível no mês</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Status</span>
                <span class="metric-card-icon is-primary" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
            </div>
            <div class="metric-card-value"><?= $counts['over'] ?>/<?= $counts['warn'] ?>/<?= $counts['ok'] ?></div>
            <div class="metric-card-sub">excedido / atenção / ok</div>
        </article>
    </section>

    <section class="filter-bar">
        <form method="GET" action="/index.php" class="filter-form">
            <input type="hidden" name="action" value="orcamentos">
            <div class="filter-group">
                <label for="filterMonth">Mês</label>
                <div class="select-wrap">
                    <select name="month" id="filterMonth">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="filter-group">
                <label for="filterYear">Ano</label>
                <div class="select-wrap">
                    <select name="year" id="filterYear">
                        <?php $cy = (int)date('Y'); for ($y = $cy - 2; $y <= $cy + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">Orçamentos do período</div>
                <div class="panel-subtitle"><?= count($budgets) ?> categoria(s) com limite definido</div>
            </div>
        </header>
        <?php if (empty($budgets)): ?>
            <div class="panel-body">
                <div class="empty-state">Nenhum orçamento definido para este período.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th class="th-numeric">Limite</th>
                            <th class="th-numeric">Gasto</th>
                            <th class="th-numeric">Restante</th>
                            <th>% Usado</th>
                            <th>Status</th>
                            <th class="th-actions">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($budgets as $b):
                            $color = $b['status'] === 'over' ? '#ef4444' : ($b['status'] === 'warn' ? '#f59e0b' : '#10b981');
                            $badgeClass = $b['status'] === 'over' ? 'badge-danger' : ($b['status'] === 'warn' ? 'badge-warning' : 'badge-success');
                            $statusLabel = $b['status'] === 'over' ? 'Excedido' : ($b['status'] === 'warn' ? 'Atenção' : 'Normal');
                        ?>
                            <tr>
                                <td class="td-strong"><?= htmlspecialchars($b['category_name']) ?></td>
                                <td class="td-numeric">R$ <?= number_format($b['limit_amount'], 2, ',', '.') ?></td>
                                <td class="td-numeric td-negative">R$ <?= number_format($b['spent_amount'], 2, ',', '.') ?></td>
                                <td class="td-numeric <?= $b['remaining'] < 0 ? 'td-negative' : 'td-positive' ?>">R$ <?= number_format($b['remaining'], 2, ',', '.') ?></td>
                                <td>
                                    <div class="progress-with-label">
                                        <div class="progress-bar"><div class="progress-fill" style="width:<?= min(100, $b['percentage']) ?>%;background:<?= $color ?>"></div></div>
                                        <span class="progress-label"><?= $b['percentage'] ?>%</span>
                                    </div>
                                </td>
                                <td><span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= $statusLabel ?></span></td>
                                <td class="actions-cell">
                                    <form action="/index.php?action=delete_budget" method="POST">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <input type="hidden" name="year" value="<?= (int)$year ?>">
                                        <input type="hidden" name="month" value="<?= (int)$month ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="charts-grid charts-grid-2">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Definir orçamento</div>
                    <div class="panel-subtitle">Limite mensal por categoria de despesa.</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=store_budget" method="POST" id="budgetForm" novalidate>
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="budget-category">Categoria</label>
                            <div class="select-wrap">
                                <select name="category_id" id="budget-category" required>
                                    <option value="">Selecione uma categoria</option>
                                    <?php foreach (($expenseCategories ?? []) as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="budget-month">Mês</label>
                                <div class="select-wrap">
                                    <select name="month" id="budget-month" required>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="budget-year">Ano</label>
                                <div class="select-wrap">
                                    <select name="year" id="budget-year" required>
                                        <?php $cy = (int)date('Y'); for ($y = $cy - 2; $y <= $cy + 1; $y++): ?>
                                        <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="budget-limit">Valor limite (R$)</label>
                            <input type="number" name="limit_amount" id="budget-limit" step="0.01" min="0.01" placeholder="0,00" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Salvar orçamento</button>
                    </div>
                </form>
            </div>
        </article>

        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Legenda de status</div>
                    <div class="panel-subtitle">Como os status são calculados.</div>
                </div>
            </header>
            <div class="panel-body">
                <div style="display:flex;flex-direction:column;gap:var(--space-3)">
                    <div class="status-legend-row">
                        <span class="badge badge-success" style="min-width:80px;justify-content:center"><span class="badge-dot"></span>Normal</span>
                        <span style="font-size:var(--text-sm);color:var(--color-text-2)">Gasto abaixo de 80% do limite.</span>
                    </div>
                    <div class="status-legend-row">
                        <span class="badge badge-warning" style="min-width:80px;justify-content:center"><span class="badge-dot"></span>Atenção</span>
                        <span style="font-size:var(--text-sm);color:var(--color-text-2)">Gasto entre 80% e 99% do limite.</span>
                    </div>
                    <div class="status-legend-row">
                        <span class="badge badge-danger" style="min-width:80px;justify-content:center"><span class="badge-dot"></span>Excedido</span>
                        <span style="font-size:var(--text-sm);color:var(--color-text-2)">Gasto igualou ou ultrapassou o limite.</span>
                    </div>
                </div>
            </div>
        </article>
    </section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
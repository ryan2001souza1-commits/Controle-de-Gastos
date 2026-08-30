<?php
$pageTitle = 'Orçamentos';
$pageSubtitle = 'Controle seus gastos definindo limites mensais por categoria.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'orcamentos';
$pageEyebrow = 'Planejamento';

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
$month = (int)($month ?? date('n'));
$year  = (int)($year  ?? date('Y'));
$periodLabel = $meses[$month] . ' / ' . $year;
$showPeriodPicker = false;
$activeBudgetTab = $_GET['budget_tab'] ?? 'list';

$palette = [
    '#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899',
    '#06b6d4', '#ef4444', '#22c55e', '#0ea5e9', '#a855f7',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamentos - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success']) && isset($successMsgs[$_GET['success']])): ?>
        <div class="alert alert-success" role="status"><?= render_icon('check', 13) ?><span><?= htmlspecialchars($successMsgs[$_GET['success']]) ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && isset($errors[$_GET['error']])): ?>
        <div class="alert alert-error" role="alert"><?= render_icon('info', 13) ?><span><?= htmlspecialchars($errors[$_GET['error']]) ?></span></div>
    <?php endif; ?>

    <!-- ===== METRIC CARDS ===== -->
    <section class="metric-strip">
        <article class="metric-card">
            <div class="metric-card-icon is-info"><?= render_icon('credit-card', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Limite total</div>
                <div class="metric-card-value">R$ <?= number_format($totals['limit'], 2, ',', '.') ?></div>
                <div class="metric-card-trend">no mês</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-danger"><?= render_icon('trending-down', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Gasto atual</div>
                <div class="metric-card-value is-negative">R$ <?= number_format($totals['spent'], 2, ',', '.') ?></div>
                <div class="metric-card-trend"><?= $totals['percentage'] ?>% do limite</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon <?= $totals['remaining'] < 0 ? 'is-danger' : 'is-success' ?>"><?= render_icon('wallet', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Restante</div>
                <div class="metric-card-value <?= $totals['remaining'] < 0 ? 'is-negative' : 'is-positive' ?>">R$ <?= number_format($totals['remaining'], 2, ',', '.') ?></div>
                <div class="metric-card-trend">disponível</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-warning"><?= render_icon('alert', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Status geral</div>
                <div class="metric-card-value">
                    <?php if ($counts['over'] > 0): ?><span style="color:var(--color-danger)"><?= $counts['over'] ?> excedido(s)</span>
                    <?php elseif ($counts['warn'] > 0): ?><span style="color:var(--color-warning)"><?= $counts['warn'] ?> atenção</span>
                    <?php else: ?><span style="color:var(--color-success)">Tudo ok</span>
                    <?php endif; ?>
                </div>
                <div class="metric-card-trend">
                    <span style="color:var(--color-danger)"><?= $counts['over'] ?></span> /
                    <span style="color:var(--color-warning)"><?= $counts['warn'] ?></span> /
                    <span style="color:var(--color-success)"><?= $counts['ok'] ?></span>
                    excedido/atenção/ok
                </div>
            </div>
        </article>
    </section>

    <!-- ===== PERIOD SELECTOR ===== -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-5);flex-wrap:wrap;gap:var(--space-3)">
        <div style="font-size:13px;color:var(--color-text-2);font-weight:500">
            <?= $periodLabel ?>
        </div>
        <form method="GET" action="/index.php" style="display:flex;gap:var(--space-3);align-items:center">
            <input type="hidden" name="action" value="orcamentos">
            <div class="select-wrap" style="width:auto;min-width:130px">
                <select name="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="select-wrap" style="width:auto;min-width:100px">
                <select name="year" onchange="this.form.submit()">
                    <?php $cy = (int)date('Y'); for ($y = $cy - 2; $y <= $cy + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>

    <!-- ===== TABS ===== -->
    <div class="tabs" role="tablist">
        <a href="?action=orcamentos&budget_tab=list&month=<?= $month ?>&year=<?= $year ?>" class="tab-item <?= $activeBudgetTab === 'list' ? 'is-active' : '' ?>" role="tab">
            <?= render_icon('list', 13) ?>
            Orçamentos
            <span class="tab-badge"><?= count($budgets) ?></span>
        </a>
        <a href="?action=orcamentos&budget_tab=new&month=<?= $month ?>&year=<?= $year ?>" class="tab-item <?= $activeBudgetTab === 'new' ? 'is-active' : '' ?>" role="tab">
            <?= render_icon('plus', 13) ?>
            Novo orçamento
        </a>
    </div>

    <?php if ($activeBudgetTab === 'new'): ?>
    <!-- ===== NEW BUDGET FORM ===== -->
    <section class="two-col" style="margin-bottom:var(--space-5)">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Definir orçamento</div>
                    <div class="panel-subtitle">Limite mensal por categoria de despesa.</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=store_budget" method="POST" novalidate>
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="b-cat">Categoria</label>
                            <div class="select-wrap">
                                <select name="category_id" id="b-cat" required>
                                    <option value="">Selecione uma categoria</option>
                                    <?php foreach (($expenseCategories ?? []) as $i => $cat):
                                        $cid = (int)$cat['id'];
                                        $ccolor = $cat['cor'] ?? $palette[$i % count($palette)];
                                    ?>
                                        <option value="<?= $cid ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="b-limit">Valor limite (R$)</label>
                            <input type="number" name="limit_amount" id="b-limit" step="0.01" min="0.01" placeholder="0,00" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <?= render_icon('check', 14) ?>
                            Salvar orçamento
                        </button>
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
                <?php if ($totals['spent'] > 0): ?>
                <div style="margin-top:var(--space-4);padding:14px 16px;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md)">
                    <div style="font-size:12px;font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Resumo do período</div>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:12px;color:var(--color-text-2)">Consumo geral</span>
                        <span style="font-weight:700;color:var(--color-text-1);font-family:var(--font-mono)"><?= $totals['percentage'] ?>%</span>
                    </div>
                    <div class="progress-bar is-large" style="margin-top:8px">
                        <div class="progress-fill" style="width:<?= min(100, $totals['percentage']) ?>%;background:<?= $totals['percentage'] >= 100 ? 'var(--color-danger)' : ($totals['percentage'] >= 80 ? 'var(--color-warning)' : 'var(--color-success)') ?>"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php endif; ?>

    <!-- ===== BUDGET TABLE ===== -->
    <section class="panel" style="margin-bottom:var(--space-5)">
        <header class="panel-header">
            <div>
                <div class="panel-title">Orçamentos do período</div>
                <div class="panel-subtitle"><?= count($budgets) ?> categoria(s) com limite definido</div>
            </div>
            <a href="?action=orcamentos&budget_tab=new&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-primary btn-sm">
                <?= render_icon('plus', 13) ?>
                Novo orçamento
            </a>
        </header>
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
                    <?php if (empty($budgets)): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhum orçamento definido para este período.</td></tr>
                    <?php else: foreach ($budgets as $i => $b):
                        $statusColor = $b['status'] === 'over' ? 'var(--color-danger)' : ($b['status'] === 'warn' ? 'var(--color-warning)' : 'var(--color-success)');
                        $badgeClass  = $b['status'] === 'over' ? 'badge-danger' : ($b['status'] === 'warn' ? 'badge-warning' : 'badge-success');
                        $statusLabel = $b['status'] === 'over' ? 'Excedido' : ($b['status'] === 'warn' ? 'Atenção' : 'Normal');
                        $cid = (int)($b['category_id'] ?? 0);
                        $ccolor = $palette[$i % count($palette)];
                    ?>
                    <tr>
                        <td>
                            <div class="cat-cell">
                                <div class="cat-icon" style="background:<?= $ccolor ?>"><?= render_icon('folder', 14) ?></div>
                                <span class="cat-cell-name"><?= htmlspecialchars($b['category_name']) ?></span>
                            </div>
                        </td>
                        <td class="td-numeric td-mono">R$ <?= number_format($b['limit_amount'], 2, ',', '.') ?></td>
                        <td class="td-numeric td-mono td-negative">R$ <?= number_format($b['spent_amount'], 2, ',', '.') ?></td>
                        <td class="td-numeric td-mono <?= $b['remaining'] < 0 ? 'td-negative' : 'td-positive' ?>">R$ <?= number_format($b['remaining'], 2, ',', '.') ?></td>
                        <td>
                            <div class="progress-with-label">
                                <div class="progress-bar" style="width:110px"><div class="progress-fill" style="width:<?= min(100, $b['percentage']) ?>%;background:<?= $statusColor ?>"></div></div>
                                <span class="progress-label"><?= $b['percentage'] ?>%</span>
                            </div>
                        </td>
                        <td><span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= $statusLabel ?></span></td>
                        <td>
                            <div class="row-actions">
                                <form action="/index.php?action=delete_budget" method="POST" style="display:inline">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <input type="hidden" name="year" value="<?= $year ?>">
                                    <input type="hidden" name="month" value="<?= $month ?>">
                                    <button type="submit" class="row-action-btn is-danger" title="Excluir">
                                        <?= render_icon('trash', 13) ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($budgets)): ?>
        <div class="pagination">
            <div class="pagination-info"><?= count($budgets) ?> orçamento(s) neste período</div>
            <div class="pagination-controls"></div>
            <div></div>
        </div>
        <?php endif; ?>
    </section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
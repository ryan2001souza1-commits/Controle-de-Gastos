<?php
$pageTitle = 'Lançamentos';
$pageSubtitle = 'Gerencie todas as suas receitas e despesas.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'lancamentos';
$periodPickerAction = 'lancamentos';

$msgs = [
    '1' => 'Lançamento adicionado com sucesso!',
    'updated' => 'Lançamento atualizado!',
    'deleted' => 'Lançamento excluído!',
];
$errs = [
    'invalid_data'    => 'Dados inválidos. Preencha corretamente.',
    'not_found'       => 'Lançamento não encontrado.',
    'update_failed'   => 'Erro ao atualizar.',
    'duplicate_category' => 'Já existe uma categoria com esse nome.',
    'invalid_category'   => 'Categoria inválida.',
];

$totalIncomes  = $totalIncomes  ?? 0;
$totalExpenses = $totalExpenses ?? 0;
$balance       = $balance       ?? 0;
$rows          = $rows          ?? [];
$economyPct    = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100, 1) : 0.0;
$txCount       = count($rows);

// date filters + pagination
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');
$pagePeriodFrom = date('d/m/Y', strtotime($startDate));
$pagePeriodTo   = date('d/m/Y', strtotime($endDate));
$filterType    = $_GET['type'] ?? '';
$categoryId    = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$search        = $_GET['search'] ?? '';
$pageNum       = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 10;

$palette = [
    '#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899',
    '#06b6d4', '#ef4444', '#22c55e', '#0ea5e9', '#a855f7',
];

// category quick lookup by id for icons/colors
$catLookup = [];
foreach (($expenseCategories ?? []) as $i => $c) {
    $catLookup[(int)$c['id']] = ['color' => $c['cor'] ?? $palette[$i % count($palette)], 'icon' => $c['icone'] ?? 'tag', 'name' => $c['name']];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançamentos - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" role="status">
            <?= render_icon('check', 13) ?>
            <span><?= htmlspecialchars($msgs[$_GET['success']] ?? 'Operação realizada com sucesso!') ?></span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error" role="alert">
            <?= render_icon('info', 13) ?>
            <span><?= htmlspecialchars($errs[$_GET['error']] ?? 'Ocorreu um erro.') ?></span>
        </div>
    <?php endif; ?>

    <!-- ===== 4 METRIC CARDS ===== -->
    <section class="metric-strip">
        <article class="metric-card">
            <div class="metric-card-icon is-success"><?= render_icon('trending-up', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Receitas</div>
                <div class="metric-card-value is-positive">R$ <?= number_format($totalIncomes, 2, ',', '.') ?></div>
                <div class="metric-card-trend" style="color:var(--color-text-3)">Total no período</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-danger"><?= render_icon('trending-down', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Despesas</div>
                <div class="metric-card-value is-negative">R$ <?= number_format($totalExpenses, 2, ',', '.') ?></div>
                <div class="metric-card-trend" style="color:var(--color-text-3)">Total no período</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-primary"><?= render_icon('wallet', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Saldo</div>
                <div class="metric-card-value <?= $balance < 0 ? 'is-negative' : 'is-positive' ?>">R$ <?= number_format($balance, 2, ',', '.') ?></div>
                <div class="metric-card-trend" style="color:var(--color-text-3)">Total no período</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-info"><?= render_icon('list', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Transações</div>
                <div class="metric-card-value"><?= $txCount ?></div>
                <div class="metric-card-trend" style="color:var(--color-text-3)">No período</div>
            </div>
        </article>
    </section>

    <!-- ===== FILTER BAR ===== -->
    <form method="GET" action="/index.php" class="filter-row" id="filterForm">
        <input type="hidden" name="action" value="lancamentos">
        <input type="hidden" name="page" value="1">

        <div class="select-wrap" style="width:auto;min-width:140px">
            <select name="type" onchange="this.form.submit()">
                <option value="">Todos os tipos</option>
                <option value="receita" <?= $filterType === 'receita' ? 'selected' : '' ?>>Receitas</option>
                <option value="despesa" <?= $filterType === 'despesa' ? 'selected' : '' ?>>Despesas</option>
            </select>
        </div>

        <div class="select-wrap" style="width:auto;min-width:160px">
            <select name="category_id" onchange="this.form.submit()">
                <option value="0">Todas as categorias</option>
                <?php foreach (($expenseCategories ?? []) as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="select-wrap" style="width:auto;min-width:170px">
            <select name="payment" disabled>
                <option value="">Forma de pagamento</option>
            </select>
        </div>

        <div class="search-input grow">
            <?= render_icon('search', 14) ?>
            <input type="text" name="search" placeholder="Buscar lançamento..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        </div>

        <button type="button" class="btn btn-ghost btn-sm" onclick="window.location='/index.php?action=lancamentos'">Filtros</button>
        <button type="submit" class="btn btn-ghost btn-sm" style="display:none">Filtrar</button>
    </form>

    <!-- ===== ACTIONS ROW ===== -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-4)">
        <a href="#newTxModal" class="btn btn-primary">
            <?= render_icon('plus', 14) ?>
            Novo lançamento
            <?= render_icon('chevron-down', 14) ?>
        </a>
    </div>

    <!-- ===== TABLE ===== -->
    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">
                    <?php if ($filterType === 'receita'): ?>Receitas
                    <?php elseif ($filterType === 'despesa'): ?>Despesas
                    <?php else: ?>Todos os Lançamentos
                    <?php endif; ?>
                </div>
                <div class="panel-subtitle"><?= $txCount ?> resultado(s) encontrado(s)</div>
            </div>
            <div class="pagination-select">
                <?= $txCount ?> itens · página <?= $pageNum ?>
            </div>
        </header>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Tipo</th>
                        <th class="th-numeric">Valor</th>
                        <th>Forma de pagamento</th>
                        <th class="th-actions">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $t):
                            $txType  = $t['type'] ?? '';
                            $txBadge = $txType === 'despesa' ? 'badge-danger' : 'badge-success';
                            $txLabel = $txType === 'despesa' ? 'Despesa' : 'Receita';
                            $txClass = $txType === 'despesa' ? 'td-negative' : 'td-positive';
                            $cid     = (int)($t['category_id'] ?? 0);
                            $cinfo   = $catLookup[$cid] ?? null;
                            $cname   = $t['category_name'] ?? '—';
                            $cicon   = $cinfo['icon'] ?? 'tag';
                            $ccolor  = $cinfo['color'] ?? '#94a3b8';
                            $sign    = $txType === 'despesa' ? '- ' : '';
                        ?>
                        <tr>
                            <td class="td-mono td-muted" style="white-space:nowrap"><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                            <td>
                                <div class="cat-cell">
                                    <div class="cat-icon" style="background:<?= htmlspecialchars($ccolor) ?>"><?= category_icon_svg($cicon, 14) ?></div>
                                    <div class="cat-cell-meta">
                                        <span class="cat-cell-name"><?= htmlspecialchars($t['description'] ?? '') ?></span>
                                        <span class="cat-cell-desc">Sem descrição</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--color-text-2);background:var(--color-surface-3);padding:3px 9px;border-radius:6px;border:1px solid var(--color-border)">
                                    <span style="width:6px;height:6px;border-radius:50%;background:<?= htmlspecialchars($ccolor) ?>"></span>
                                    <?= htmlspecialchars($cname) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $txBadge ?>">
                                    <?= render_icon($txType === 'despesa' ? 'arrow-down' : 'arrow-up', 10) ?>
                                    <?= $txLabel ?>
                                </span>
                            </td>
                            <td class="td-numeric <?= $txClass ?>"><?= $sign ?>R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></td>
                            <td class="td-muted" style="font-size:12px">Cartão de Crédito</td>
                            <td>
                                <div class="row-actions">
                                    <a href="/index.php?action=edit&id=<?= (int)($t['id'] ?? 0) ?>&type=<?= htmlspecialchars($txType) ?>" class="row-action-btn is-edit" aria-label="Editar" title="Editar">
                                        <?= render_icon('edit', 13) ?>
                                    </a>
                                    <form action="/index.php?action=delete" method="POST" style="display:inline">
                                        <input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>">
                                        <button type="submit" class="row-action-btn is-danger" aria-label="Excluir" title="Excluir">
                                            <?= render_icon('trash', 13) ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <div class="pagination-info">Mostrando 1 a <?= min($txCount, $perPage) ?> de <?= $txCount ?> lançamentos</div>
            <div class="pagination-controls">
                <button class="pagination-btn" disabled><?= render_icon('chevron-left', 12) ?></button>
                <button class="pagination-btn is-active">1</button>
                <button class="pagination-btn">2</button>
                <button class="pagination-btn">3</button>
                <button class="pagination-btn"><?= render_icon('chevron-right', 12) ?></button>
            </div>
            <div class="pagination-select">
                <select>
                    <option>10 por página</option>
                    <option>25 por página</option>
                    <option>50 por página</option>
                </select>
            </div>
        </div>
    </section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
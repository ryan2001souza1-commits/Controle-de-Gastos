<?php
$pageTitle = $pageTitle ?? 'Lançamentos - Controle de Gastos';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'lancamentos';
$pageEyebrow = 'Movimentações';
$pageTitle = 'Lançamentos';
$pagePeriodFrom = isset($startDate) ? date('d/m/Y', strtotime($startDate)) : null;
$pagePeriodTo   = isset($endDate)   ? date('d/m/Y', strtotime($endDate))   : null;

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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" role="status">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= htmlspecialchars($msgs[$_GET['success']] ?? 'Operação realizada com sucesso!') ?></span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error" role="alert">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errs[$_GET['error']] ?? 'Ocorreu um erro.') ?></span>
        </div>
    <?php endif; ?>

    <section class="metrics-strip">
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Saldo</span>
                <span class="metric-card-icon <?= $balance < 0 ? 'is-danger' : 'is-success' ?>" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
            </div>
            <div class="metric-card-value <?= $balance < 0 ? 'is-negative' : ($balance > 0 ? 'is-positive' : '') ?>">R$ <?= number_format($balance, 2, ',', '.') ?></div>
            <div class="metric-card-sub"><?= count($rows) ?> lançamento(s)</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Receitas</span>
                <span class="metric-card-icon is-success" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-positive">R$ <?= number_format($totalIncomes, 2, ',', '.') ?></div>
            <div class="metric-card-sub">no período</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Despesas</span>
                <span class="metric-card-icon is-danger" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-negative">R$ <?= number_format($totalExpenses, 2, ',', '.') ?></div>
            <div class="metric-card-sub">no período</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Taxa de economia</span>
                <span class="metric-card-icon is-primary" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
            </div>
            <div class="metric-card-value <?= $economyPct > 0 ? 'is-positive' : ($economyPct < 0 ? 'is-negative' : '') ?>"><?= $economyPct > 0 ? '+' : '' ?><?= $economyPct ?>%</div>
            <div class="metric-card-sub"><?= $totalIncomes > 0 ? 'do lucro retido' : 'sem receitas' ?></div>
        </article>
    </section>

    <section class="filter-bar">
        <form method="GET" action="/index.php?action=lancamentos" class="filter-form" id="filterForm">
            <input type="hidden" name="action" value="lancamentos">
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
                            <option value="<?= (int)$cat['id'] ?>" <?= ($categoryId ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filter-group" style="flex:1; min-width:160px;">
                <label for="filterSearch">Buscar</label>
                <input type="text" name="search" id="filterSearch" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="Descrição...">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <a href="/index.php?action=lancamentos" class="btn btn-ghost btn-sm">Limpar</a>
            </div>
        </form>
    </section>

    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">
                    <?php if (($filterType ?? '') === 'receita'): ?>Receitas
                    <?php elseif (($filterType ?? '') === 'despesa'): ?>Despesas
                    <?php else: ?>Todos os Lançamentos
                    <?php endif; ?>
                </div>
                <div class="panel-subtitle"><?= count($rows) ?> resultado(s) encontrado(s)</div>
            </div>
        </header>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Data</th>
                        <th class="th-numeric">Valor</th>
                        <th class="th-actions">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $t): ?>
                            <?php
                            $txType = $t['type'] ?? '';
                            $txBadge = $txType === 'despesa' ? 'badge-danger' : 'badge-success';
                            $txClass = $txType === 'despesa' ? 'td-negative' : 'td-positive';
                            ?>
                            <tr>
                                <td>
                                    <div class="td-strong"><?= htmlspecialchars($t['description'] ?? '') ?></div>
                                    <div class="td-muted text-xs"><span class="badge <?= $txBadge ?>" style="padding:1px 6px;font-size:10px"><span class="badge-dot"></span><?= $txType === 'despesa' ? 'Despesa' : 'Receita' ?></span></div>
                                </td>
                                <td class="td-muted"><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                <td class="td-mono td-muted"><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                <td class="td-numeric <?= $txClass ?>">R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></td>
                                <td class="actions-cell">
                                    <a href="/index.php?action=edit&id=<?= (int)($t['id'] ?? 0) ?>&type=<?= htmlspecialchars($txType) ?>" class="btn btn-ghost btn-xs">Editar</a>
                                    <form action="/index.php?action=delete" method="POST">
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
    </section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
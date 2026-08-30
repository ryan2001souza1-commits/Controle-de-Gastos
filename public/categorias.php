<?php
$pageTitle = $pageTitle ?? 'Categorias - Controle de Gastos';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'categorias';
$pageEyebrow = 'Configurações';
$pageTitle = 'Categorias';
$pagePeriodFrom = isset($startDate) ? date('d/m/Y', strtotime($startDate)) : null;
$pagePeriodTo   = isset($endDate)   ? date('d/m/Y', strtotime($endDate))   : null;

$msgs = [
    '1' => 'Categoria adicionada!',
    'updated' => 'Categoria atualizada!',
    'deleted' => 'Categoria excluída!',
];
$errs = [
    'invalid_category'  => 'Dados inválidos. Verifique e tente novamente.',
    'duplicate_category'=> 'Já existe uma categoria com esse nome para esse tipo.',
    'not_found'         => 'Categoria não encontrada.',
    'category_in_use'   => 'Não é possível excluir: existem lançamentos vinculados a esta categoria.',
];
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
                <span class="metric-card-label">Categ. Despesa</span>
                <span class="metric-card-icon is-danger" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-negative"><?= count($expenseCats) ?></div>
            <div class="metric-card-sub">cadastradas</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Categ. Receita</span>
                <span class="metric-card-icon is-success" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
            </div>
            <div class="metric-card-value is-positive"><?= count($incomeCats) ?></div>
            <div class="metric-card-sub">cadastradas</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Total</span>
                <span class="metric-card-icon is-primary" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/></svg>
                </span>
            </div>
            <div class="metric-card-value"><?= count($expenseCats) + count($incomeCats) ?></div>
            <div class="metric-card-sub">categorias</div>
        </article>
        <article class="metric-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Período</span>
                <span class="metric-card-icon is-info" aria-hidden="true">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
            </div>
            <div class="metric-card-value"><?= htmlspecialchars(date('m/Y', strtotime($startDate))) ?></div>
            <div class="metric-card-sub">atual</div>
        </article>
    </section>

    <section class="filter-bar">
        <form method="GET" action="/index.php?action=categorias" class="filter-form" id="filterForm">
            <input type="hidden" name="action" value="categorias">
            <div class="filter-group">
                <label for="inputStartDate">De</label>
                <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="filter-group">
                <label for="inputEndDate">Até</label>
                <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <a href="/index.php?action=categorias" class="btn btn-ghost btn-sm">Limpar</a>
            </div>
        </form>
    </section>

    <section class="charts-grid charts-grid-2">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Categorias de Despesa</div>
                    <div class="panel-subtitle"><?= count($expenseCats) ?> cadastrada(s)</div>
                </div>
            </header>
            <?php if (empty($expenseCats)): ?>
                <div class="panel-body">
                    <div class="empty-state">Nenhuma categoria de despesa cadastrada.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th class="th-numeric">Lançamentos</th>
                                <th class="th-numeric">Total</th>
                                <th class="th-actions">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseCats as $c): ?>
                                <tr>
                                    <td class="td-strong"><?= htmlspecialchars($c['name']) ?></td>
                                    <td class="td-numeric"><?= (int)($c['tx_count'] ?? 0) ?></td>
                                    <td class="td-numeric td-negative">R$ <?= number_format((float)($c['tx_total'] ?? 0), 2, ',', '.') ?></td>
                                    <td class="actions-cell">
                                        <button class="btn btn-ghost btn-xs" type="button" onclick="editCategory(<?= (int)$c['id'] ?>, <?= json_encode($c['name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, 'despesa')">Editar</button>
                                        <form action="/index.php?action=delete_category" method="POST">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Categorias de Receita</div>
                    <div class="panel-subtitle"><?= count($incomeCats) ?> cadastrada(s)</div>
                </div>
            </header>
            <?php if (empty($incomeCats)): ?>
                <div class="panel-body">
                    <div class="empty-state">Nenhuma categoria de receita cadastrada.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th class="th-numeric">Lançamentos</th>
                                <th class="th-numeric">Total</th>
                                <th class="th-actions">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeCats as $c): ?>
                                <tr>
                                    <td class="td-strong"><?= htmlspecialchars($c['name']) ?></td>
                                    <td class="td-numeric"><?= (int)($c['tx_count'] ?? 0) ?></td>
                                    <td class="td-numeric td-positive">R$ <?= number_format((float)($c['tx_total'] ?? 0), 2, ',', '.') ?></td>
                                    <td class="actions-cell">
                                        <button class="btn btn-ghost btn-xs" type="button" onclick="editCategory(<?= (int)$c['id'] ?>, <?= json_encode($c['name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, 'receita')">Editar</button>
                                        <form action="/index.php?action=delete_category" method="POST">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="charts-grid charts-grid-2">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Nova categoria</div>
                    <div class="panel-subtitle">Adicione despesa ou receita.</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=store_category" method="POST" id="addCategoryForm" novalidate>
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="cat-name">Nome</label>
                            <input type="text" name="name" id="cat-name" placeholder="Ex: Educação" required>
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
                        <button type="submit" class="btn btn-primary btn-block">Adicionar</button>
                    </div>
                </form>
            </div>
        </article>

        <article class="panel" id="editCategoryCard" style="display:none">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Editar categoria</div>
                    <div class="panel-subtitle">Altere os dados da categoria.</div>
                </div>
                <button type="button" class="btn btn-ghost btn-xs" onclick="cancelEditCategory()">Cancelar</button>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=update_category" method="POST" id="editCategoryForm">
                    <div class="form-stack">
                        <input type="hidden" name="id" id="editCatId">
                        <div class="form-group">
                            <label for="editCatName">Nome</label>
                            <input type="text" name="name" id="editCatName" required>
                        </div>
                        <div class="form-group">
                            <label for="editCatType">Tipo</label>
                            <div class="select-wrap">
                                <select name="type" id="editCatType">
                                    <option value="despesa">Despesa</option>
                                    <option value="receita">Receita</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Salvar alterações</button>
                    </div>
                </form>
            </div>
        </article>
    </section>

<?php
$extraScripts = '<script>
function editCategory(id, name, type) {
    document.getElementById("editCatId").value = id;
    document.getElementById("editCatName").value = name;
    document.getElementById("editCatType").value = type;
    document.getElementById("editCategoryCard").style.display = "block";
    document.getElementById("editCategoryCard").scrollIntoView({ behavior: "smooth", block: "center" });
}
function cancelEditCategory() {
    document.getElementById("editCategoryCard").style.display = "none";
}
</script>';
include __DIR__ . '/partials/layout_end.php';
?>
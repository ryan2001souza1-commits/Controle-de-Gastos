<?php
$pageTitle = $pageTitle ?? 'Categorias - Controle de Gastos';
$userName  = $userName  ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'categorias';
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
            <a href="/index.php?action=categorias" class="sidebar-link <?= $activeMenu === 'categorias' ? 'active' : '' ?>">
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
            <a href="/index.php?action=relatorios" class="sidebar-link">
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
                <div class="topbar-eyebrow">Configurações</div>
                <h1 class="topbar-title">Categorias</h1>
                <div class="topbar-period">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= htmlspecialchars(date('d/m/Y', strtotime($startDate))) ?> — <?= htmlspecialchars(date('d/m/Y', strtotime($endDate))) ?>
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

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])): ?>
            <?php
            $s = $_GET['success'];
            $msgs = ['1' => 'Categoria adicionada!', 'updated' => 'Categoria atualizada!', 'deleted' => 'Categoria excluída!'];
            ?>
            <div class="alert alert-success" role="status">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= htmlspecialchars($msgs[$s] ?? 'Operação realizada com sucesso!') ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <?php
            $e = $_GET['error'];
            $errs = [
                'invalid_category'  => 'Dados inválidos. Verifique e tente novamente.',
                'duplicate_category'=> 'Já existe uma categoria com esse nome para esse tipo.',
                'not_found'         => 'Categoria não encontrada.',
                'category_in_use'   => 'Não é possível excluir: existem lançamentos vinculados a esta categoria.',
            ];
            ?>
            <div class="alert alert-error" role="alert">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= htmlspecialchars($errs[$e] ?? 'Ocorreu um erro.') ?></span>
            </div>
        <?php endif; ?>

        <!-- METRIC STRIP -->
        <section class="m-strip">
            <div class="m-card">
                <div class="m-card-label">Categ. Despesa</div>
                <div class="m-card-value negative"><?= count($expenseCats) ?></div>
                <div class="m-card-sub">cadastradas</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Categ. Receita</div>
                <div class="m-card-value positive"><?= count($incomeCats) ?></div>
                <div class="m-card-sub">cadastradas</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Total</div>
                <div class="m-card-value"><?= count($expenseCats) + count($incomeCats) ?></div>
                <div class="m-card-sub">categorias</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Período</div>
                <div class="m-card-value"><?= htmlspecialchars(date('m/Y', strtotime($startDate))) ?></div>
                <div class="m-card-sub">atual</div>
            </div>
        </section>

        <!-- FILTER BAR -->
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

        <!-- CATEGORY TABLES -->
        <section class="two-col two-col-eq">
            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title">Categorias de Despesa</div>
                        <div class="panel-subtitle"><?= count($expenseCats) ?> cadastrada(s)</div>
                    </div>
                </header>
                <?php if (empty($expenseCats)): ?>
                    <div class="panel-body">
                        <div class="empty-msg">Nenhuma categoria de despesa cadastrada.</div>
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
                        <div class="empty-msg">Nenhuma categoria de receita cadastrada.</div>
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

        <!-- FORMS -->
        <section class="two-col two-col-eq">
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

    </main>
</div>

<script src="/js/app.js"></script>
<script>
function editCategory(id, name, type) {
    document.getElementById('editCatId').value = id;
    document.getElementById('editCatName').value = name;
    document.getElementById('editCatType').value = type;
    document.getElementById('editCategoryCard').style.display = 'block';
    document.getElementById('editCategoryCard').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function cancelEditCategory() {
    document.getElementById('editCategoryCard').style.display = 'none';
}
</script>
</body>
</html>

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
                <h2 class="topbar-title">Categorias</h2>
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

        <?php if (isset($_GET['success'])): ?>
            <?php
            $s = $_GET['success'];
            $msgs = ['1' => 'Categoria adicionada!', 'updated' => 'Categoria atualizada!', 'deleted' => 'Categoria excluída!'];
            ?>
            <div class="alert alert-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars($msgs[$s] ?? 'Operação realizada com sucesso!') ?>
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
            <div class="alert alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($errs[$e] ?? 'Ocorreu um erro.') ?>
            </div>
        <?php endif; ?>

        <!-- SUMMARY -->
        <section class="cards-grid">
            <div class="fin-card fin-card-expense">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Categ. Despesa</span>
                    <span class="fin-card-value text-expense"><?= count($expenseCats) ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-income">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Categ. Receita</span>
                    <span class="fin-card-value text-income"><?= count($incomeCats) ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-balance">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Total Categorias</span>
                    <span class="fin-card-value text-primary"><?= count($expenseCats) + count($incomeCats) ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-economy">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">No Período</span>
                    <span class="fin-card-value text-primary"><?= htmlspecialchars(date('m/Y', strtotime($startDate))) ?></span>
                </div>
            </div>
        </section>

        <!-- FILTER -->
        <section class="filter-bar">
            <form method="GET" action="/index.php?action=categorias" class="filter-form" id="filterForm">
                <input type="hidden" name="action" value="categorias">
                <div class="filter-group">
                    <label>Data Início</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" id="inputStartDate">
                </div>
                <div class="filter-group">
                    <label>Data Fim</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" id="inputEndDate">
                </div>
                <div class="filter-actions" style="margin-left:auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filtrar
                    </button>
                    <a href="/index.php?action=categorias" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <!-- TWO CARDS: DESPESAS + RECEITAS -->
        <section class="summary-section" style="grid-template-columns: 1fr 1fr;">
            <!-- DESPESAS -->
            <div class="bottom-card">
                <div class="bottom-card-header">
                    <h3 class="bottom-card-title" style="margin-bottom:0">Categorias de Despesa</h3>
                    <span class="bottom-card-count"><?= count($expenseCats) ?> categoria(s)</span>
                </div>
                <?php if (empty($expenseCats)): ?>
                    <div class="empty-msg">Nenhuma categoria de despesa cadastrada.</div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Lançamentos</th>
                                <th>Total no Período</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseCats as $c): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                    <td><?= (int)($c['tx_count'] ?? 0) ?></td>
                                    <td class="text-expense"><strong>R$ <?= number_format((float)($c['tx_total'] ?? 0), 2, ',', '.') ?></strong></td>
                                    <td class="actions-cell">
                                        <button class="btn btn-ghost btn-xs" type="button" onclick="editCategory(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name']), ENT_QUOTES) ?>', 'despesa')">Editar</button>
                                        <form action="/index.php?action=delete_category" method="POST" class="delete-form">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- RECEITAS -->
            <div class="bottom-card">
                <div class="bottom-card-header">
                    <h3 class="bottom-card-title" style="margin-bottom:0">Categorias de Receita</h3>
                    <span class="bottom-card-count"><?= count($incomeCats) ?> categoria(s)</span>
                </div>
                <?php if (empty($incomeCats)): ?>
                    <div class="empty-msg">Nenhuma categoria de receita cadastrada.</div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Lançamentos</th>
                                <th>Total no Período</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeCats as $c): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                    <td><?= (int)($c['tx_count'] ?? 0) ?></td>
                                    <td class="text-income"><strong>R$ <?= number_format((float)($c['tx_total'] ?? 0), 2, ',', '.') ?></strong></td>
                                    <td class="actions-cell">
                                        <button class="btn btn-ghost btn-xs" type="button" onclick="editCategory(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name']), ENT_QUOTES) ?>', 'receita')">Editar</button>
                                        <form action="/index.php?action=delete_category" method="POST" class="delete-form">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <!-- FORMS: CREATE + EDIT -->
        <section class="summary-section" style="margin-top:1rem">
            <div class="bottom-card">
                <h3 class="bottom-card-title">Nova Categoria</h3>
                <form action="/index.php?action=store_category" method="POST" class="form-stack" id="addCategoryForm" novalidate>
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
                    <button type="submit" class="btn btn-primary btn-block">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Adicionar Categoria
                    </button>
                </form>
            </div>

            <div class="bottom-card" id="editCategoryCard" style="display:none">
                <h3 class="bottom-card-title">Editar Categoria</h3>
                <form action="/index.php?action=update_category" method="POST" class="form-stack" id="editCategoryForm">
                    <input type="hidden" name="id" id="editCatId">
                    <div class="form-group">
                        <label>Nome</label>
                        <input type="text" name="name" id="editCatName" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="type" id="editCatType">
                            <option value="despesa">Despesa</option>
                            <option value="receita">Receita</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:0.5rem">
                        <button type="submit" class="btn btn-primary" style="flex:1">Salvar</button>
                        <button type="button" class="btn btn-ghost" onclick="cancelEditCategory()">Cancelar</button>
                    </div>
                </form>
            </div>
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

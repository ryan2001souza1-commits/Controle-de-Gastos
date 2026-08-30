<?php
$pageTitle = 'Categorias';
$pageSubtitle = 'Gerencie suas categorias de receitas e despesas.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'categorias';
$pageEyebrow = 'Configurações';

$msgs = [
    '1' => 'Categoria adicionada!',
    'created' => 'Categoria criada com sucesso!',
    'updated' => 'Categoria atualizada!',
    'deleted' => 'Categoria excluída!',
];
$errs = [
    'invalid_category'  => 'Dados inválidos. Verifique e tente novamente.',
    'duplicate_category'=> 'Já existe uma categoria com esse nome para esse tipo.',
    'not_found'         => 'Categoria não encontrada.',
    'category_in_use'   => 'Não é possível excluir: existem lançamentos vinculados a esta categoria.',
];

$palette = [
    '#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899',
    '#06b6d4', '#ef4444', '#22c55e', '#0ea5e9', '#a855f7',
];

$activeTab = $_GET['tab'] ?? 'despesa';
$categories = $activeTab === 'despesa' ? ($expenseCats ?? []) : ($incomeCats ?? []);
$totalSpent = array_sum(array_column($categories, 'tx_total'));
$totalCount = count($categories);

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');
$pagePeriodFrom = date('d/m/Y', strtotime($startDate));
$pagePeriodTo   = date('d/m/Y', strtotime($endDate));

$searchFilter = trim($_GET['search'] ?? '');
if ($searchFilter !== '') {
    $categories = array_filter($categories, fn($c) => stripos($c['name'], $searchFilter) !== false);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" role="status"><?= render_icon('check', 13) ?><span><?= htmlspecialchars($msgs[$_GET['success']] ?? 'Operação realizada com sucesso!') ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error" role="alert"><?= render_icon('info', 13) ?><span><?= htmlspecialchars($errs[$_GET['error']] ?? 'Ocorreu um erro.') ?></span></div>
    <?php endif; ?>

    <!-- ===== 4 METRIC CARDS ===== -->
    <section class="metric-strip">
        <article class="metric-card">
            <div class="metric-card-icon is-danger"><?= render_icon('folder', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Categorias de Despesa</div>
                <div class="metric-card-value is-negative"><?= count($expenseCats ?? []) ?></div>
                <div class="metric-card-trend">cadastradas</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-success"><?= render_icon('folder', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Categorias de Receita</div>
                <div class="metric-card-value is-positive"><?= count($incomeCats ?? []) ?></div>
                <div class="metric-card-trend">cadastradas</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-primary"><?= render_icon('layers', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Total</div>
                <div class="metric-card-value"><?= count($expenseCats ?? []) + count($incomeCats ?? []) ?></div>
                <div class="metric-card-trend">categorias</div>
            </div>
        </article>
        <article class="metric-card is-block">
            <div class="metric-card-head">
                <div class="metric-card-label">Gasto no período</div>
                <div class="metric-card-icon is-info"><?= render_icon('pie', 18) ?></div>
            </div>
            <div class="metric-card-value is-primary">R$ <?= number_format($totalSpent, 2, ',', '.') ?></div>
            <div class="text-muted text-xs" style="margin-top:2px">Total de gastos</div>
        </article>
    </section>

    <!-- ===== TABS ===== -->
    <div class="tabs" role="tablist">
        <a href="?action=categorias&tab=despesa" class="tab-item <?= $activeTab === 'despesa' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'despesa' ? 'true' : 'false' ?>">
            <?= render_icon('trending-down', 14) ?>
            Despesas
            <span class="tab-badge"><?= count($expenseCats ?? []) ?></span>
        </a>
        <a href="?action=categorias&tab=receita" class="tab-item <?= $activeTab === 'receita' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'receita' ? 'true' : 'false' ?>">
            <?= render_icon('trending-up', 14) ?>
            Receitas
            <span class="tab-badge"><?= count($incomeCats ?? []) ?></span>
        </a>
    </div>

    <!-- ===== TABLE PANEL ===== -->
    <section class="panel" style="margin-bottom:var(--space-5)">
        <header class="panel-header">
            <div>
                <div class="panel-title"><?= $activeTab === 'despesa' ? 'Categorias de Despesa' : 'Categorias de Receita' ?></div>
                <div class="panel-subtitle"><?= count($categories) ?> categoria(s)</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <form method="GET" action="" class="search-input" style="width:220px;flex:none">
                    <input type="hidden" name="action" value="categorias">
                    <input type="hidden" name="tab" value="<?= $activeTab ?>">
                    <?= render_icon('search', 13) ?>
                    <input type="text" name="search" placeholder="Buscar..." value="<?= htmlspecialchars($searchFilter) ?>" autocomplete="off">
                </form>
                <button type="button" class="btn btn-primary btn-sm" onclick="openNewCategory()">
                    <?= render_icon('plus', 13) ?>
                    Nova categoria
                </button>
            </div>
        </header>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th>Cor</th>
                        <th class="th-numeric">Gasto no mês</th>
                        <th class="th-numeric">% do total</th>
                        <th>Status</th>
                        <th class="th-actions">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="6" class="empty-cell">Nenhuma categoria encontrada.</td></tr>
                    <?php else: foreach ($categories as $c):
                        $cid    = (int)($c['id'] ?? 0);
                        $cname  = $c['name'] ?? '';
                        $ctype  = $c['type'] ?? 'despesa';
                        $ctotal = (float)($c['tx_total'] ?? 0);
                        $ccount = (int)($c['tx_count'] ?? 0);
                        $cpct   = $totalSpent > 0 ? round(($ctotal / $totalSpent) * 100) : 0;
                        $cicon  = $c['icon'] ?? 'tag';
                        $ccolor = $c['color'] ?? $palette[array_search($cid, array_column($categories, 'id')) % count($palette)];
                        $cactive = (bool)($c['active'] ?? true);
                        $statusBadge = $cactive ? 'badge-success' : 'badge-muted';
                        $statusLabel = $cactive ? 'Ativa' : 'Inativa';
                    ?>
                    <tr data-id="<?= $cid ?>">
                        <td>
                            <div class="cat-cell">
                                <div class="cat-icon" style="background:<?= htmlspecialchars($ccolor) ?>">
                                    <?= category_icon_svg($cicon, 14) ?>
                                </div>
                                <div class="cat-cell-meta">
                                    <span class="cat-cell-name"><?= htmlspecialchars($cname) ?></span>
                                    <span class="cat-cell-desc"><?= $ccount ?> lançamento(s)</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <span style="width:20px;height:20px;border-radius:50%;background:<?= htmlspecialchars($ccolor) ?>;display:inline-block;border:2px solid rgba(255,255,255,0.15)"></span>
                                <span class="text-mono text-xs" style="color:var(--color-text-3)"><?= strtoupper($ccolor) ?></span>
                            </div>
                        </td>
                        <td class="td-numeric td-negative">R$ <?= number_format($ctotal, 2, ',', '.') ?></td>
                        <td>
                            <div class="progress-with-label">
                                <div class="progress-bar"><div class="progress-fill" style="width:<?= $cpct ?>%"></div></div>
                                <span class="progress-label"><?= $cpct ?>%</span>
                            </div>
                        </td>
                        <td><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="row-action-btn is-edit" title="Editar" onclick='openEditCategory(<?= json_encode(['id'=>$cid,'name'=>$cname,'type'=>$ctype,'color'=>$ccolor,'icon'=>$cicon], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    <?= render_icon('edit', 13) ?>
                                </button>
                                <form action="/index.php?action=delete_category" method="POST" style="display:inline" onsubmit="return confirm('Deseja realmente excluir esta categoria?')">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
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
    </section>

    <!-- ===== MODAL: Nova / Editar Categoria ===== -->
    <div class="modal-overlay" id="categoryModal" role="dialog" aria-modal="true" aria-labelledby="modal-title" style="display:none">
        <div class="modal">
            <header class="modal-header">
                <div class="modal-title" id="modal-title">Nova categoria</div>
                <button type="button" class="modal-close" onclick="closeCategoryModal()" aria-label="Fechar"><?= render_icon('x', 16) ?></button>
            </header>
            <div class="modal-body">
                <form action="/index.php?action=store_category" method="POST" id="categoryForm" novalidate>
                    <input type="hidden" name="id" id="modalCatId" value="">
                    <input type="hidden" name="_method" id="modalMethod" value="store">

                    <div class="form-stack">
                        <div class="form-group">
                            <label for="modalCatName">Nome da categoria</label>
                            <input type="text" name="name" id="modalCatName" placeholder="Ex: Alimentação" required maxlength="80">
                        </div>

                        <div class="form-group">
                            <label for="modalCatType">Tipo</label>
                            <div class="select-wrap">
                                <select name="type" id="modalCatType">
                                    <option value="despesa" <?= $activeTab === 'despesa' ? 'selected' : '' ?>>Despesa</option>
                                    <option value="receita" <?= $activeTab === 'receita' ? 'selected' : '' ?>>Receita</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="modalCatColor">Cor</label>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <input type="color" name="cor" id="modalCatColor" value="#10b981" style="width:44px;height:36px;border-radius:8px;border:1px solid var(--color-border);padding:2px;cursor:pointer;background:var(--color-surface)">
                                    <span class="text-mono text-xs" id="colorHex" style="color:var(--color-text-3)">#10B981</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="modalCatIcon">Ícone</label>
                                <div class="select-wrap">
                                    <select name="icone" id="modalCatIcon">
                                        <option value="tag">tag</option>
                                        <option value="home">home</option>
                                        <option value="car">car</option>
                                        <option value="heart">heart</option>
                                        <option value="gift">gift</option>
                                        <option value="coffee">coffee</option>
                                        <option value="briefcase">briefcase</option>
                                        <option value="dollar">dollar</option>
                                        <option value="shopping">shopping</option>
                                        <option value="music">music</option>
                                        <option value="book">book</option>
                                        <option value="phone">phone</option>
                                        <option value="wifi">wifi</option>
                                        <option value="tool">tool</option>
                                        <option value="health">health</option>
                                        <option value="target">target</option>
                                        <option value="globe">globe</option>
                                        <option value="star">star</option>
                                        <option value="flag">flag</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <footer class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeCategoryModal()">Cancelar</button>
                <button type="submit" form="categoryForm" class="btn btn-primary" id="modalSubmitBtn">Adicionar</button>
            </footer>
        </div>
    </div>

<?php
$extraScripts = '<script>
function openNewCategory() {
    document.getElementById("modal-title").textContent = "Nova categoria";
    document.getElementById("modalMethod").value = "store";
    document.getElementById("categoryForm").action = "/index.php?action=store_category";
    document.getElementById("modalSubmitBtn").textContent = "Adicionar";
    document.getElementById("modalCatId").value = "";
    document.getElementById("modalCatName").value = "";
    document.getElementById("modalCatColor").value = "#10b981";
    document.getElementById("colorHex").textContent = "#10B981";
    document.getElementById("modalCatIcon").value = "tag";
    openCategoryModal();
}
function openEditCategory(data) {
    document.getElementById("modal-title").textContent = "Editar categoria";
    document.getElementById("modalMethod").value = "update";
    document.getElementById("categoryForm").action = "/index.php?action=update_category";
    document.getElementById("modalSubmitBtn").textContent = "Salvar alterações";
    document.getElementById("modalCatId").value = data.id;
    document.getElementById("modalCatName").value = data.name;
    document.getElementById("modalCatType").value = data.type;
    document.getElementById("modalCatColor").value = data.color || "#10b981";
    document.getElementById("colorHex").textContent = (data.color || "#10b981").toUpperCase();
    document.getElementById("modalCatIcon").value = data.icon || "tag";
    openCategoryModal();
}
function openCategoryModal() {
    var m = document.getElementById("categoryModal");
    m.style.display = "flex";
    document.getElementById("modalCatName").focus();
    document.body.style.overflow = "hidden";
}
function closeCategoryModal() {
    var m = document.getElementById("categoryModal");
    m.style.display = "none";
    document.body.style.overflow = "";
}
document.getElementById("modalCatColor").addEventListener("input", function() {
    document.getElementById("colorHex").textContent = this.value.toUpperCase();
});
document.getElementById("categoryModal").addEventListener("click", function(e) {
    if (e.target === this) closeCategoryModal();
});
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") closeCategoryModal();
});
</script>';
include __DIR__ . '/partials/layout_end.php';
?>
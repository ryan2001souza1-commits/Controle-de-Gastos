<?php
if (!isset($transaction) || !is_array($transaction)) {
    header('Location: /index.php');
    exit;
}

if (!isset($expenseCategories) || !is_array($expenseCategories)) {
    $expenseCategories = [];
}
if (!isset($incomeCategories) || !is_array($incomeCategories)) {
    $incomeCategories = [];
}

$txType = $transaction['type'] ?? 'despesa';
$txLabel = $txType === 'despesa' ? 'Despesa' : 'Receita';

$pageTitle = $pageTitle ?? 'Editar Lançamento - Controle de Gastos';
$userName  = $userName  ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'lancamentos';
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
            <a href="/index.php?action=categorias" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                Categorias
            </a>
            <a href="/index.php?action=relatorios" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Relatórios
            </a>
            <a href="/index.php?action=orcamentos" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                Metas
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
                <h2 class="topbar-title">Editar Lançamento</h2>
                <a href="/index.php?action=lancamentos" class="btn btn-ghost btn-sm" style="margin-left:0.5rem">← Voltar</a>
            </div>
            <div class="topbar-right">
                <span class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></span>
                <div class="topbar-avatar"><?= $userInitials ?></div>
            </div>
        </header>

        <?php if (isset($error)): ?>
            <?php
            $errorMessages = [
                'invalid_data'  => 'Preencha todos os campos corretamente.',
                'invalid_date'  => 'Data inválida.',
                'update_failed' => 'Não foi possível atualizar a transação.',
                'invalid_id'    => 'Lançamento inválido.',
                'not_found'     => 'Lançamento não encontrado.',
            ];
            $msg = $errorMessages[$error] ?? 'Erro desconhecido.';
            ?>
            <div class="alert alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <section class="summary-section" style="grid-template-columns: 480px 1fr;">
            <div class="bottom-card">
                <h3 class="bottom-card-title">Editar Lançamento</h3>
                <form action="/index.php?action=update" method="POST" id="editTxForm" novalidate
                      data-expense-categories='<?= htmlspecialchars(json_encode($expenseCategories, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'
                      data-income-categories='<?= htmlspecialchars(json_encode($incomeCategories, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'>
                    <input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>" id="type">

                    <div class="form-stack">
                        <div class="form-group">
                            <label>Tipo</label>
                            <input type="text" value="<?= htmlspecialchars($txLabel) ?>" disabled style="background:var(--bg-soft); color:var(--text-light)">
                        </div>
                        <div class="form-group">
                            <label for="description">Descrição</label>
                            <input type="text" name="description" id="description"
                                   value="<?= htmlspecialchars($transaction['description'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="amount">Valor</label>
                            <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                                   value="<?= htmlspecialchars(number_format((float)($transaction['amount'] ?? 0), 2, '.', '')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="date">Data</label>
                            <input type="date" name="date" id="date"
                                   value="<?= htmlspecialchars($transaction['date'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="category_id">Categoria</label>
                            <select name="category_id" id="category_id">
                                <option value="">Sem categoria</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:0.5rem">
                            <button type="submit" class="btn btn-primary" style="flex:1">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                Salvar
                            </button>
                            <a href="/index.php?action=lancamentos" class="btn btn-ghost">Cancelar</a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="bottom-card">
                <h3 class="bottom-card-title">Resumo</h3>
                <div class="form-stack">
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">ID do Lançamento</span>
                        <span class="indicator-value text-primary">#<?= (int)$transaction['id'] ?></span>
                    </div>
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Tipo</span>
                        <span class="indicator-value <?= $txType === 'despesa' ? 'text-expense' : 'text-income' ?>">
                            <?= $txLabel ?>
                        </span>
                    </div>
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Data Atual</span>
                        <span class="indicator-value"><?= isset($transaction['date']) ? date('d/m/Y', strtotime($transaction['date'])) : '—' ?></span>
                    </div>
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Valor Atual</span>
                        <span class="indicator-value <?= $txType === 'despesa' ? 'text-expense' : 'text-income' ?>">
                            R$ <?= number_format((float)($transaction['amount'] ?? 0), 2, ',', '.') ?>
                        </span>
                    </div>
                </div>
            </div>
        </section>

    </main>
</div>

<script src="/js/app.js"></script>
<script>
window.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('editTxForm');
    if (!form) return;

    const categorySelect = document.getElementById('category_id');
    const typeInput = document.getElementById('type');

    let expenseCategories = [];
    let incomeCategories = [];
    try {
        expenseCategories = JSON.parse(form.dataset.expenseCategories || '[]');
        incomeCategories  = JSON.parse(form.dataset.incomeCategories  || '[]');
    } catch (err) {
        console.error('Erro ao carregar categorias:', err);
    }

    const currentCategoryId = <?= json_encode($transaction['category_id'] ?? null) ?>;
    const selectedType = typeInput.value;

    function populateCategories(type) {
        categorySelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Sem categoria';
        categorySelect.appendChild(placeholder);

        const list = type === 'receita' ? incomeCategories : expenseCategories;
        list.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat.id;
            opt.textContent = cat.name;
            if (currentCategoryId !== null && String(cat.id) === String(currentCategoryId)) {
                opt.selected = true;
            }
            categorySelect.appendChild(opt);
        });
    }

    populateCategories(selectedType);

    form.addEventListener('submit', (e) => {
        const desc = form.querySelector('#description');
        const amt  = form.querySelector('#amount');
        const date = form.querySelector('#date');
        let valid = true;

        if (!desc.value.trim()) { desc.style.borderColor = 'var(--danger)'; valid = false; }
        else { desc.style.borderColor = ''; }

        if (parseFloat(amt.value) <= 0) { amt.style.borderColor = 'var(--danger)'; valid = false; }
        else { amt.style.borderColor = ''; }

        if (!date.value) { date.style.borderColor = 'var(--danger)'; valid = false; }
        else { date.style.borderColor = ''; }

        if (!valid) e.preventDefault();
    });
});
</script>
</body>
</html>

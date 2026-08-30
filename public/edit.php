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
            <a href="/index.php?action=lancamentos" class="sidebar-link <?= $activeMenu === 'lancamentos' ? 'active' : '' ?>">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Lançamentos
            </a>
            <a href="/index.php?action=categorias" class="sidebar-link">
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
                <div class="topbar-eyebrow">Movimentações</div>
                <h1 class="topbar-title">Editar lançamento</h1>
                <div class="topbar-period">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= isset($transaction['date']) ? date('d/m/Y', strtotime($transaction['date'])) : '' ?>
                </div>
            </div>
            <div class="topbar-right">
                <a href="/index.php?action=lancamentos" class="btn btn-ghost btn-sm">← Voltar</a>
                <div class="topbar-user">
                    <div class="topbar-avatar" aria-hidden="true"><?= $userInitials ?></div>
                    <div class="topbar-user-meta">
                        <div class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></div>
                        <div class="topbar-role">Conta pessoal</div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ERROR -->
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
            <div class="alert alert-error" role="alert">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- FORM + SUMMARY -->
        <section class="two-col two-col-form">
            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title">Editar lançamento</div>
                        <div class="panel-subtitle">Atualize os dados da transação.</div>
                    </div>
                    <span class="badge <?= $txType === 'despesa' ? 'badge-danger' : 'badge-success' ?>"><span class="badge-dot"></span><?= $txLabel ?></span>
                </header>
                <div class="panel-body-sm">
                    <form action="/index.php?action=update" method="POST" id="editTxForm" novalidate
                          data-expense-categories='<?= htmlspecialchars(json_encode($expenseCategories, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'
                          data-income-categories='<?= htmlspecialchars(json_encode($incomeCategories, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'>
                        <input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>" id="type">
                        <div class="form-stack">
                            <div class="form-group">
                                <label for="description">Descrição</label>
                                <input type="text" name="description" id="description"
                                       value="<?= htmlspecialchars($transaction['description'] ?? '') ?>" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="amount">Valor (R$)</label>
                                    <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                                           value="<?= htmlspecialchars(number_format((float)($transaction['amount'] ?? 0), 2, '.', '')) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="date">Data</label>
                                    <input type="date" name="date" id="date"
                                           value="<?= htmlspecialchars($transaction['date'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="category_id">Categoria</label>
                                <div class="select-wrap">
                                    <select name="category_id" id="category_id">
                                        <option value="">Sem categoria</option>
                                    </select>
                                </div>
                            </div>
                            <div style="display:flex;gap:var(--space-2)">
                                <button type="submit" class="btn btn-primary" style="flex:1">Salvar alterações</button>
                                <a href="/index.php?action=lancamentos" class="btn btn-ghost">Cancelar</a>
                            </div>
                        </div>
                    </form>
                </div>
            </article>

            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title">Resumo</div>
                        <div class="panel-subtitle">Dados atuais do lançamento.</div>
                    </div>
                </header>
                <div class="panel-body">
                    <div class="form-stack">
                        <div class="indicator">
                            <div class="indicator-label">ID do lançamento</div>
                            <div class="indicator-value">#<?= (int)$transaction['id'] ?></div>
                        </div>
                        <div class="indicator">
                            <div class="indicator-label">Tipo</div>
                            <div class="indicator-value <?= $txType === 'despesa' ? 'negative' : 'positive' ?>"><?= $txLabel ?></div>
                        </div>
                        <div class="indicator">
                            <div class="indicator-label">Data atual</div>
                            <div class="indicator-value"><?= isset($transaction['date']) ? date('d/m/Y', strtotime($transaction['date'])) : '—' ?></div>
                        </div>
                        <div class="indicator">
                            <div class="indicator-label">Valor atual</div>
                            <div class="indicator-value <?= $txType === 'despesa' ? 'negative' : 'positive' ?>">R$ <?= number_format((float)($transaction['amount'] ?? 0), 2, ',', '.') ?></div>
                        </div>
                    </div>
                </div>
            </article>
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
});
</script>
</body>
</html>

<?php
if (!isset($transaction) || !is_array($transaction)) {
    header('Location: /index.php');
    exit;
}

if (!isset($expenseCategories) || !is_array($expenseCategories)) { $expenseCategories = []; }
if (!isset($incomeCategories) || !is_array($incomeCategories)) { $incomeCategories = []; }

$txType = $transaction['type'] ?? 'despesa';
$txLabel = $txType === 'despesa' ? 'Despesa' : 'Receita';

$pageTitle = $pageTitle ?? 'Editar Lançamento - Controle de Gastos';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'lancamentos';
$pageEyebrow = 'Movimentações';
$pageTitle = 'Editar lançamento';
$topbarActions = '<a href="/index.php?action=lancamentos" class="btn btn-ghost btn-sm">← Voltar</a>';

$extraScripts = '<script>
window.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("editTxForm");
    if (!form) return;
    const categorySelect = document.getElementById("category_id");
    const typeInput = document.getElementById("type");
    let expenseCategories = [];
    let incomeCategories = [];
    try {
        expenseCategories = JSON.parse(form.dataset.expenseCategories || "[]");
        incomeCategories  = JSON.parse(form.dataset.incomeCategories  || "[]");
    } catch (err) { console.error("Erro ao carregar categorias:", err); }
    const currentCategoryId = ' . json_encode($transaction['category_id'] ?? null) . ';
    const selectedType = typeInput.value;
    function populateCategories(type) {
        categorySelect.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Sem categoria";
        categorySelect.appendChild(placeholder);
        const list = type === "receita" ? incomeCategories : expenseCategories;
        list.forEach(cat => {
            const opt = document.createElement("option");
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
</script>';
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

    <section class="charts-grid charts-grid-2" style="margin-top:var(--space-3)">
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
                        <div class="indicator-value <?= $txType === 'despesa' ? 'is-negative' : 'is-positive' ?>"><?= $txLabel ?></div>
                    </div>
                    <div class="indicator">
                        <div class="indicator-label">Data atual</div>
                        <div class="indicator-value"><?= isset($transaction['date']) ? date('d/m/Y', strtotime($transaction['date'])) : '—' ?></div>
                    </div>
                    <div class="indicator">
                        <div class="indicator-label">Valor atual</div>
                        <div class="indicator-value <?= $txType === 'despesa' ? 'is-negative' : 'is-positive' ?>">R$ <?= number_format((float)($transaction['amount'] ?? 0), 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>
        </article>
    </section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
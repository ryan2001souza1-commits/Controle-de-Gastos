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

$pageTitle = 'Editar Lançamento - Controle de Gastos';
$userName = $_SESSION['user_name'] ?? 'Usuário';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>Controle de Gastos</h1>
            <nav class="nav">
                <span>Olá, <?= htmlspecialchars($userName) ?></span>
                <a href="/index.php?action=logout" class="btn btn-secondary">Sair</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if (isset($error)): ?>
            <?php
                $errorMessages = [
                    'invalid_data'    => 'Preencha todos os campos corretamente.',
                    'invalid_date'    => 'Data inválida.',
                    'update_failed'   => 'Não foi possível atualizar a transação.',
                    'invalid_id'      => 'Lançamento inválido.',
                    'not_found'       => 'Lançamento não encontrado.',
                ];
                $msg = $errorMessages[$error] ?? 'Erro desconhecido.';
            ?>
            <div class="alert alert-error"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <section class="edit-transaction">
            <h2>Editar Lançamento</h2>
            <form action="/index.php?action=update" method="POST" class="transaction-form"
                  data-expense-categories='<?= htmlspecialchars(json_encode($expenseCategories, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'
                  data-income-categories='<?= htmlspecialchars(json_encode($incomeCategories, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'>
                <input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>">
                <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>" id="type">

                <label>Tipo</label>
                <input type="text" value="<?= htmlspecialchars($txLabel) ?>" disabled>

                <label for="description">Descrição</label>
                <input type="text" name="description" id="description"
                       value="<?= htmlspecialchars($transaction['description'] ?? '') ?>" required>

                <label for="amount">Valor</label>
                <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                       value="<?= htmlspecialchars(number_format((float)($transaction['amount'] ?? 0), 2, '.', '')) ?>" required>

                <label for="date">Data</label>
                <input type="date" name="date" id="date"
                       value="<?= htmlspecialchars($transaction['date'] ?? '') ?>" required>

                <label for="category_id">Categoria</label>
                <select name="category_id" id="category_id">
                    <option value="">Sem categoria</option>
                </select>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Salvar alterações</button>
                    <a href="/index.php" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </section>
    </main>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('.transaction-form');
            const categorySelect = document.getElementById('category_id');
            const typeInput = document.getElementById('type');
            if (!form || !categorySelect || !typeInput) return;

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

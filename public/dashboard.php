<?php
if (!isset($data) || !is_array($data)) {
    header('Location: /index.php');
    exit;
}

if (!isset($expenseCategories) || !is_array($expenseCategories)) {
    $expenseCategories = [];
}

if (!isset($incomeCategories) || !is_array($incomeCategories)) {
    $incomeCategories = [];
}

$chartData = $data['chart_data'] ?? null;

$pageTitle = 'Dashboard - Controle de Gastos';
$userName = $_SESSION['user_name'] ?? 'Usuário';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
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
        <?php if (isset($_GET['success'])): ?>
            <?php
                $successMessages = [
                    '1'        => 'Operação realizada com sucesso!',
                    'updated'  => 'Transação atualizada com sucesso!',
                ];
                $successKey = (string)($_GET['success']);
                $successMsg = $successMessages[$successKey] ?? 'Operação realizada com sucesso!';
            ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <section class="summary">
            <div class="card card-income">
                <h3>Receitas</h3>
                <p class="amount">R$ <?= number_format((float)($data['total_incomes'] ?? 0), 2, ',', '.') ?></p>
                <span class="card-sub"><?= (int)($data['income_count'] ?? 0) ?> lançamento(s)</span>
            </div>
            <div class="card card-expense">
                <h3>Despesas</h3>
                <p class="amount">R$ <?= number_format((float)($data['total_expenses'] ?? 0), 2, ',', '.') ?></p>
                <span class="card-sub"><?= (int)($data['expense_count'] ?? 0) ?> lançamento(s)</span>
            </div>
            <div class="card card-balance <?= ($data['balance'] ?? 0) < 0 ? 'negative' : (($data['balance'] ?? 0) > 0 ? 'positive' : 'neutral') ?>">
                <h3>Saldo</h3>
                <p class="amount">R$ <?= number_format((float)($data['balance'] ?? 0), 2, ',', '.') ?></p>
            </div>
            <div class="card card-count">
                <h3>Total de Lançamentos</h3>
                <p class="amount"><?= (int)($data['transactions_count'] ?? 0) ?></p>
                <span class="card-sub">no período</span>
            </div>
        </section>

        <section class="filters">
            <form method="GET" action="/index.php" class="filter-form">
                <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>" placeholder="Data inicial">
                <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>" placeholder="Data final">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="/index.php" class="btn btn-secondary">Limpar</a>
            </form>
        </section>

        <section class="analytics">
            <h2>Análises</h2>
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>Despesas por Categoria</h3>
                    <div class="chart-wrapper">
                        <canvas id="chart-expenses-by-category"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-category-empty" data-msg="Nenhuma despesa registrada no período.">
                        Nenhuma despesa registrada no período.
                    </p>
                </div>
                <div class="chart-card">
                    <h3>Receitas x Despesas</h3>
                    <div class="chart-wrapper">
                        <canvas id="chart-income-vs-expense"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-period-empty" data-msg="Nenhum lançamento encontrado no período.">
                        Nenhum lançamento encontrado no período.
                    </p>
                </div>
            </div>
            <div class="charts-grid charts-grid-single">
                <div class="chart-card">
                    <h3>Evolução do Saldo</h3>
                    <div class="chart-wrapper">
                        <canvas id="chart-balance-evolution"></canvas>
                    </div>
                    <p class="chart-empty" id="chart-balance-empty" data-msg="Sem dados suficientes para calcular a evolução do saldo.">
                        Sem dados suficientes para calcular a evolução do saldo.
                    </p>
                </div>
            </div>
        </section>

        <section class="add-transaction">
            <h2>Adicionar Lançamento</h2>
            <form action="/index.php?action=store" method="POST" class="transaction-form">
                <select name="type" id="type" required>
                    <option value="despesa">Despesa</option>
                    <option value="receita">Receita</option>
                </select>
                <input type="text" name="description" placeholder="Descrição" required>
                <input type="number" name="amount" placeholder="Valor" step="0.01" min="0.01" required>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                <select name="category_id" id="category_id">
                    <option value="">Sem categoria</option>
                    <?php foreach ($expenseCategories as $cat): ?>
                        <option
                            value="<?= (int)$cat['id'] ?>"
                            data-type="despesa"
                        >
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($incomeCategories as $cat): ?>
                        <option
                            value="<?= (int)$cat['id'] ?>"
                            data-type="receita"
                        >
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Adicionar</button>
            </form>
        </section>

        <section class="add-category">
            <h2>Nova Categoria</h2>
            <form action="/index.php?action=store_category" method="POST" class="category-form">
                <input type="text" name="name" placeholder="Nome da categoria" required>
                <select name="type" required>
                    <option value="despesa">Despesa</option>
                    <option value="receita">Receita</option>
                </select>
                <button type="submit" class="btn btn-primary">Adicionar Categoria</button>
            </form>
        </section>

        <section class="categories-summary">
            <h2>Despesas por Categoria</h2>
            <div class="category-list">
                <?php foreach ($data['expenses_by_category'] as $cat): ?>
                    <?php if ($cat['total'] > 0): ?>
                        <div class="category-item">
                            <span class="category-name"><?= htmlspecialchars($cat['name']) ?></span>
                            <span class="category-total">R$ <?= number_format($cat['total'], 2, ',', '.') ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="recent-transactions">
            <h2>Últimos Lançamentos</h2>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Data</th>
                        <th>Valor</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['recent_transactions'] as $transaction): ?>
                        <?php
                            $txType = $transaction['type'] ?? '';
                            $txLabel = $txType === 'despesa' ? 'Despesa' : ($txType === 'receita' ? 'Receita' : '');
                            $txBadge = $txType === 'despesa' ? 'expense' : 'income';
                        ?>
                        <tr>
                            <td><span class="badge badge-<?= $txBadge ?>"><?= htmlspecialchars($txLabel) ?></span></td>
                            <td><?= htmlspecialchars($transaction['description'] ?? '') ?></td>
                            <td><?= htmlspecialchars($transaction['category_name'] ?? '-') ?></td>
                            <td><?= isset($transaction['date']) ? date('d/m/Y', strtotime($transaction['date'])) : '-' ?></td>
                            <td class="amount-cell <?= $txBadge ?>">R$ <?= number_format((float)($transaction['amount'] ?? 0), 2, ',', '.') ?></td>
                            <td>
                                <a href="/index.php?action=edit&id=<?= (int)$transaction['id'] ?>&type=<?= htmlspecialchars($txType) ?>" class="btn btn-secondary btn-sm">Editar</a>
                                <form action="/index.php?action=delete" method="POST" style="display:inline">
                                    <input type="hidden" name="id" value="<?= (int)($transaction['id'] ?? 0) ?>">
                                    <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Excluir?')">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($data['recent_transactions'])): ?>
                        <tr><td colspan="6" class="empty">Nenhum lançamento encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>

    <script src="/js/app.js"></script>
    <script src="/assets/chart.min.js"></script>
    <script>
        window.DASHBOARD_CHART_DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="/js/charts.js"></script>
</body>
</html>

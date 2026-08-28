<?php
if (!isset($data) || !is_array($data)) {
    header('Location: /index.php');
    exit;
}

if (!isset($categories) || !is_array($categories)) {
    $categories = [];
}

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
            <div class="alert alert-success">Operação realizada com sucesso!</div>
        <?php endif; ?>

        <section class="summary">
            <div class="card card-income">
                <h3>Receitas</h3>
                <p class="amount">R$ <?= number_format($data['total_incomes'], 2, ',', '.') ?></p>
            </div>
            <div class="card card-expense">
                <h3>Despesas</h3>
                <p class="amount">R$ <?= number_format($data['total_expenses'], 2, ',', '.') ?></p>
            </div>
            <div class="card card-balance <?= $data['balance'] < 0 ? 'negative' : 'positive' ?>">
                <h3>Saldo</h3>
                <p class="amount">R$ <?= number_format($data['balance'], 2, ',', '.') ?></p>
            </div>
        </section>

        <section class="filters">
            <form method="GET" action="/dashboard.php" class="filter-form">
                <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>" placeholder="Data inicial">
                <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>" placeholder="Data final">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="/dashboard.php" class="btn btn-secondary">Limpar</a>
            </form>
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
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
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
</body>
</html>

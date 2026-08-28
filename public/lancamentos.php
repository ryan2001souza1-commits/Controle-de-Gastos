<?php
$pageTitle = $pageTitle ?? 'Lançamentos - Controle de Gastos';
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">

    <!-- SIDEBAR -->
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

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <h2 class="topbar-title">Lançamentos</h2>
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

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])): ?>
            <?php
            $s = $_GET['success'];
            $msgs = ['1' => 'Lançamento adicionado com sucesso!', 'updated' => 'Lançamento atualizado!', 'deleted' => 'Lançamento excluído!'];
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
                'invalid_data'    => 'Dados inválidos. Preencha corretamente.',
                'not_found'       => 'Lançamento não encontrado.',
                'update_failed'   => 'Erro ao atualizar.',
                'duplicate_category' => 'Já existe uma categoria com esse nome.',
                'invalid_category'   => 'Categoria inválida.',
            ];
            ?>
            <div class="alert alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($errs[$e] ?? 'Ocorreu um erro.') ?>
            </div>
        <?php endif; ?>

        <!-- SUMMARY CARDS -->
        <section class="cards-grid">
            <div class="fin-card fin-card-income">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Total Receitas</span>
                    <span class="fin-card-value text-income">R$ <?= number_format($totalIncomes, 2, ',', '.') ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-expense">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Total Despesas</span>
                    <span class="fin-card-value text-expense">R$ <?= number_format($totalExpenses, 2, ',', '.') ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-balance">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Saldo</span>
                    <span class="fin-card-value <?= $balance < 0 ? 'text-danger' : ($balance > 0 ? 'text-primary' : 'text-muted') ?>">R$ <?= number_format($balance, 2, ',', '.') ?></span>
                </div>
            </div>
            <div class="fin-card fin-card-economy">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Registros</span>
                    <span class="fin-card-value text-primary"><?= count($rows) ?></span>
                </div>
            </div>
        </section>

        <!-- FILTER BAR -->
        <section class="filter-bar">
            <form method="GET" action="/index.php?action=lancamentos" class="filter-form" id="filterForm">
                <input type="hidden" name="action" value="lancamentos">
                <div class="filter-group">
                    <label>Data Início</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" id="inputStartDate">
                </div>
                <div class="filter-group">
                    <label>Data Fim</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" id="inputEndDate">
                </div>
                <div class="filter-group">
                    <label>Tipo</label>
                    <select name="type" id="filterType">
                        <option value="">Todos</option>
                        <option value="receita" <?= $filterType === 'receita' ? 'selected' : '' ?>>Receitas</option>
                        <option value="despesa" <?= $filterType === 'despesa' ? 'selected' : '' ?>>Despesas</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Categoria</label>
                    <select name="category_id" id="filterCategory">
                        <option value="">Todas</option>
                        <?php foreach ($expenseCategories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex:1; min-width:180px;">
                    <label>Buscar</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por descrição...">
                </div>
                <div class="filter-actions" style="margin-left:0">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filtrar
                    </button>
                    <a href="/index.php?action=lancamentos" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <!-- TABLE -->
        <section class="bottom-card" style="margin-bottom:1.5rem">
            <div class="bottom-card-header">
                <h3 class="bottom-card-title" style="margin-bottom:0">
                    <?php if ($filterType === 'receita'): ?>Receitas
                    <?php elseif ($filterType === 'despesa'): ?>Despesas
                    <?php else: ?>Todos os Lançamentos
                    <?php endif; ?>
                </h3>
                <span class="bottom-card-count"><?= count($rows) ?> registro(s)</span>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
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
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="6" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $t): ?>
                                <?php
                                $txType = $t['type'] ?? '';
                                $txLabel = $txType === 'despesa' ? 'Despesa' : ($txType === 'receita' ? 'Receita' : '');
                                $txBadge = $txType === 'despesa' ? 'badge-danger' : 'badge-success';
                                $txClass = $txType === 'despesa' ? 'text-expense' : 'text-income';
                                ?>
                                <tr>
                                    <td><span class="badge <?= $txBadge ?>"><span class="badge-dot"></span><?= $txLabel ?></span></td>
                                    <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                    <td><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                    <td class="<?= $txClass ?>"><strong>R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></strong></td>
                                    <td class="actions-cell">
                                        <a href="/index.php?action=edit&id=<?= (int)($t['id'] ?? 0) ?>&type=<?= htmlspecialchars($txType) ?>" class="btn btn-ghost btn-xs">Editar</a>
                                        <form action="/index.php?action=delete" method="POST" class="delete-form">
                                            <input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
                                            <input type="hidden" name="type" value="<?= htmlspecialchars($txType) ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script src="/js/app.js"></script>
</body>
</html>

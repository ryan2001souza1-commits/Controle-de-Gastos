<?php
$pageTitle = $pageTitle ?? 'Orçamentos - Controle de Gastos';
$userName  = $userName  ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'orcamentos';
$budgets = $budgetData['budgets'] ?? [];
$totals  = $budgetData['totals']  ?? ['limit' => 0, 'spent' => 0, 'remaining' => 0, 'percentage' => 0];
$counts  = $budgetData['counts']  ?? ['over' => 0, 'warn' => 0, 'ok' => 0];

$errors = [
    'invalid_data'       => 'Dados inválidos. Verifique o valor.',
    'invalid_category'   => 'Categoria inválida.',
    'invalid_date'       => 'Data inválida.',
    'not_found'          => 'Orçamento não encontrado.',
    'invalid_id'         => 'ID inválido.',
];
$successMsgs = [
    'saved'   => 'Orçamento salvo com sucesso!',
    'deleted' => 'Orçamento removido!',
];

$meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
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
            <a href="/index.php?action=lancamentos" class="sidebar-link">
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
            <a href="/index.php?action=orcamentos" class="sidebar-link <?= $activeMenu === 'orcamentos' ? 'active' : '' ?>">
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
                <h2 class="topbar-title">Orçamentos por Categoria</h2>
                <span class="topbar-period">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= $meses[(int)$month] ?? '' ?> / <?= (int)$year ?>
                </span>
            </div>
            <div class="topbar-right">
                <span class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></span>
                <div class="topbar-avatar"><?= $userInitials ?></div>
            </div>
        </header>

        <?php if (isset($_GET['success']) && isset($successMsgs[$_GET['success']])): ?>
            <div class="alert alert-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars($successMsgs[$_GET['success']]) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && isset($errors[$_GET['error']])): ?>
            <div class="alert alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($errors[$_GET['error']]) ?>
            </div>
        <?php endif; ?>

        <section class="cards-grid">
            <div class="fin-card fin-card-balance">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Limite Total</span>
                    <span class="fin-card-value text-primary">R$ <?= number_format($totals['limit'], 2, ',', '.') ?></span>
                    <span class="fin-card-sub">no mês</span>
                </div>
            </div>
            <div class="fin-card fin-card-expense">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Gasto Atual</span>
                    <span class="fin-card-value text-expense">R$ <?= number_format($totals['spent'], 2, ',', '.') ?></span>
                    <span class="fin-card-sub"><?= $totals['percentage'] ?>% do limite</span>
                </div>
            </div>
            <div class="fin-card fin-card-income">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Restante</span>
                    <span class="fin-card-value <?= $totals['remaining'] < 0 ? 'text-danger' : 'text-income' ?>">R$ <?= number_format($totals['remaining'], 2, ',', '.') ?></span>
                    <span class="fin-card-sub">disponível no mês</span>
                </div>
            </div>
            <div class="fin-card fin-card-economy">
                <div class="fin-card-icon">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="fin-card-body">
                    <span class="fin-card-label">Status</span>
                    <span class="fin-card-value text-muted"><?= $counts['over'] ?>/<?= $counts['warn'] ?>/<?= $counts['ok'] ?></span>
                    <span class="fin-card-sub">ultrapassado / atenção / ok</span>
                </div>
            </div>
        </section>

        <section class="filter-bar">
            <form method="GET" action="/index.php" class="filter-form" id="filterForm">
                <input type="hidden" name="action" value="orcamentos">
                <div class="filter-group">
                    <label>Mês</label>
                    <select name="month" id="filterMonth">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Ano</label>
                    <select name="year" id="filterYear">
                        <?php
                        $currentYear = (int)date('Y');
                        for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Aplicar
                    </button>
                    <a href="/index.php?action=orcamentos" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <section class="bottom-card" style="margin-bottom:1.5rem">
            <div class="bottom-card-header">
                <h3 class="bottom-card-title" style="margin-bottom:0">Orçamentos do Período</h3>
                <span class="bottom-card-count"><?= count($budgets) ?> categoria(s)</span>
            </div>
            <?php if (empty($budgets)): ?>
            <div class="empty-msg">Nenhum orçamento definido para este período.</div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th>Limite</th>
                            <th>Gasto</th>
                            <th>Restante</th>
                            <th>% Usado</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($budgets as $b):
                        $color = $b['status'] === 'over' ? '#dc2626' : ($b['status'] === 'warn' ? '#f59e0b' : '#16a34a');
                        $statusLabel = $b['status'] === 'over' ? 'Ultrapassado' : ($b['status'] === 'warn' ? 'Atenção' : 'Normal');
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['category_name']) ?></strong></td>
                            <td>R$ <?= number_format($b['limit_amount'], 2, ',', '.') ?></td>
                            <td class="text-expense">R$ <?= number_format($b['spent_amount'], 2, ',', '.') ?></td>
                            <td class="<?= $b['remaining'] < 0 ? 'text-danger' : 'text-income' ?>">
                                R$ <?= number_format($b['remaining'], 2, ',', '.') ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:0.5rem">
                                    <div style="flex:1;background:var(--bg-soft);border-radius:4px;height:6px;overflow:hidden">
                                        <div style="height:100%;width:<?= min(100, $b['percentage']) ?>%;background:<?= $color ?>;border-radius:4px"></div>
                                    </div>
                                    <span style="font-size:0.8rem;min-width:42px"><?= $b['percentage'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $b['status'] === 'over' ? 'badge-danger' : ($b['status'] === 'warn' ? 'badge-warning' : 'badge-success') ?>">
                                    <?= $statusLabel ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <form action="/index.php?action=delete_budget" method="POST" class="delete-form">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <input type="hidden" name="year" value="<?= (int)$year ?>">
                                    <input type="hidden" name="month" value="<?= (int)$month ?>">
                                    <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <section class="summary-section" style="margin-top:1rem; grid-template-columns: 480px 1fr;">
            <div class="bottom-card">
                <h3 class="bottom-card-title">Definir Orçamento</h3>
                <form action="/index.php?action=store_budget" method="POST" class="form-stack" id="budgetForm" novalidate>
                    <div class="form-group">
                        <label>Categoria (somente despesa)</label>
                        <select name="category_id" required>
                            <option value="">Selecione uma categoria</option>
                            <?php foreach ($expenseCategories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Valor limite (R$)</label>
                        <input type="number" name="limit_amount" step="0.01" min="0.01" placeholder="0,00" required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem">
                        <div class="form-group">
                            <label>Mês</label>
                            <select name="month" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ano</label>
                            <select name="year" required>
                                <?php
                                $currentYear = (int)date('Y');
                                for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++): ?>
                                <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Salvar Orçamento
                    </button>
                </form>
            </div>

            <div class="bottom-card">
                <h3 class="bottom-card-title">Legenda</h3>
                <div class="form-stack">
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem;border:1px solid var(--border-color);border-radius:6px">
                        <span class="badge badge-success" style="min-width:90px;justify-content:center"><span class="badge-dot"></span>Normal</span>
                        <span style="font-size:0.875rem;color:var(--text-light)">Gasto abaixo de 80% do limite.</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem;border:1px solid var(--border-color);border-radius:6px">
                        <span class="badge badge-warning" style="min-width:90px;justify-content:center"><span class="badge-dot"></span>Atenção</span>
                        <span style="font-size:0.875rem;color:var(--text-light)">Gasto entre 80% e 99% do limite.</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem;border:1px solid var(--border-color);border-radius:6px">
                        <span class="badge badge-danger" style="min-width:90px;justify-content:center"><span class="badge-dot"></span>Ultrapassado</span>
                        <span style="font-size:0.875rem;color:var(--text-light)">Gasto igualou ou ultrapassou o limite.</span>
                    </div>
                </div>
            </div>
        </section>

    </main>
</div>

<script src="/js/app.js"></script>
</body>
</html>

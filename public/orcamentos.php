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
            <a href="/index.php?action=lancamentos" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Lançamentos
            </a>
            <a href="/index.php?action=categorias" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                Categorias
            </a>
            <a href="/index.php?action=orcamentos" class="sidebar-link <?= $activeMenu === 'orcamentos' ? 'active' : '' ?>">
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
                <div class="topbar-eyebrow">Planejamento</div>
                <h1 class="topbar-title">Orçamentos</h1>
                <div class="topbar-period">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= $meses[(int)$month] ?? '' ?> / <?= (int)$year ?>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-user">
                    <div class="topbar-avatar" aria-hidden="true"><?= $userInitials ?></div>
                    <div class="topbar-user-meta">
                        <div class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></div>
                        <div class="topbar-role">Conta pessoal</div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ALERTS -->
        <?php if (isset($_GET['success']) && isset($successMsgs[$_GET['success']])): ?>
            <div class="alert alert-success" role="status">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= htmlspecialchars($successMsgs[$_GET['success']]) ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && isset($errors[$_GET['error']])): ?>
            <div class="alert alert-error" role="alert">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= htmlspecialchars($errors[$_GET['error']]) ?></span>
            </div>
        <?php endif; ?>

        <!-- METRIC STRIP -->
        <section class="m-strip">
            <div class="m-card">
                <div class="m-card-label">Limite total</div>
                <div class="m-card-value">R$ <?= number_format($totals['limit'], 2, ',', '.') ?></div>
                <div class="m-card-sub">no mês</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Gasto atual</div>
                <div class="m-card-value negative">R$ <?= number_format($totals['spent'], 2, ',', '.') ?></div>
                <div class="m-card-sub"><?= $totals['percentage'] ?>% do limite</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Restante</div>
                <div class="m-card-value <?= $totals['remaining'] < 0 ? 'negative' : 'positive' ?>">R$ <?= number_format($totals['remaining'], 2, ',', '.') ?></div>
                <div class="m-card-sub">disponível no mês</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Status</div>
                <div class="m-card-value"><?= $counts['over'] ?>/<?= $counts['warn'] ?>/<?= $counts['ok'] ?></div>
                <div class="m-card-sub">excedido / atenção / ok</div>
            </div>
        </section>

        <!-- FILTER -->
        <section class="filter-bar">
            <form method="GET" action="/index.php" class="filter-form">
                <input type="hidden" name="action" value="orcamentos">
                <div class="filter-group">
                    <label for="filterMonth">Mês</label>
                    <div class="select-wrap">
                        <select name="month" id="filterMonth">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="filterYear">Ano</label>
                    <div class="select-wrap">
                        <select name="year" id="filterYear">
                            <?php $cy = (int)date('Y'); for ($y = $cy - 2; $y <= $cy + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
                </div>
            </form>
        </section>

        <!-- BUDGETS TABLE -->
        <section class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Orçamentos do período</div>
                    <div class="panel-subtitle"><?= count($budgets) ?> categoria(s) com limite definido</div>
                </div>
            </header>
            <?php if (empty($budgets)): ?>
                <div class="panel-body">
                    <div class="empty-msg">Nenhum orçamento definido para este período.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th class="th-numeric">Limite</th>
                                <th class="th-numeric">Gasto</th>
                                <th class="th-numeric">Restante</th>
                                <th>% Usado</th>
                                <th>Status</th>
                                <th class="th-actions">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($budgets as $b):
                            $color = $b['status'] === 'over' ? '#dc2626' : ($b['status'] === 'warn' ? '#d97706' : '#15803d');
                            $badgeClass = $b['status'] === 'over' ? 'badge-danger' : ($b['status'] === 'warn' ? 'badge-warning' : 'badge-success');
                            $statusLabel = $b['status'] === 'over' ? 'Excedido' : ($b['status'] === 'warn' ? 'Atenção' : 'Normal');
                        ?>
                            <tr>
                                <td class="td-strong"><?= htmlspecialchars($b['category_name']) ?></td>
                                <td class="td-numeric">R$ <?= number_format($b['limit_amount'], 2, ',', '.') ?></td>
                                <td class="td-numeric td-negative">R$ <?= number_format($b['spent_amount'], 2, ',', '.') ?></td>
                                <td class="td-numeric <?= $b['remaining'] < 0 ? 'td-negative' : 'td-positive' ?>">R$ <?= number_format($b['remaining'], 2, ',', '.') ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <div class="progress-bar" style="flex:1"><div class="progress-fill" style="width:<?= min(100, $b['percentage']) ?>%;background:<?= $color ?>"></div></div>
                                        <span style="font-size:var(--text-xs);font-weight:600;color:var(--color-text-2);min-width:36px"><?= $b['percentage'] ?>%</span>
                                    </div>
                                </td>
                                <td><span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= $statusLabel ?></span></td>
                                <td class="actions-cell">
                                    <form action="/index.php?action=delete_budget" method="POST">
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

        <!-- FORM + LEGEND -->
        <section class="two-col two-col-form" style="margin-top:var(--space-5)">
            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title">Definir orçamento</div>
                        <div class="panel-subtitle">Limite mensal por categoria de despesa.</div>
                    </div>
                </header>
                <div class="panel-body-sm">
                    <form action="/index.php?action=store_budget" method="POST" id="budgetForm" novalidate>
                        <div class="form-stack">
                            <div class="form-group">
                                <label for="budget-category">Categoria</label>
                                <div class="select-wrap">
                                    <select name="category_id" id="budget-category" required>
                                        <option value="">Selecione uma categoria</option>
                                        <?php foreach (($expenseCategories ?? []) as $cat): ?>
                                        <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="budget-month">Mês</label>
                                    <div class="select-wrap">
                                        <select name="month" id="budget-month" required>
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?= $m ?>" <?= $m === (int)$month ? 'selected' : '' ?>><?= $meses[$m] ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="budget-year">Ano</label>
                                    <div class="select-wrap">
                                        <select name="year" id="budget-year" required>
                                            <?php $cy = (int)date('Y'); for ($y = $cy - 2; $y <= $cy + 1; $y++): ?>
                                            <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="budget-limit">Valor limite (R$)</label>
                                <input type="number" name="limit_amount" id="budget-limit" step="0.01" min="0.01" placeholder="0,00" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">Salvar orçamento</button>
                        </div>
                    </form>
                </div>
            </article>

            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title">Legenda de status</div>
                        <div class="panel-subtitle">Como os status são calculados.</div>
                    </div>
                </header>
                <div class="panel-body">
                    <div style="display:flex;flex-direction:column;gap:var(--space-3)">
                        <div style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3);border:1px solid var(--color-border);border-radius:var(--radius-md)">
                            <span class="badge badge-success" style="min-width:80px;justify-content:center"><span class="badge-dot"></span>Normal</span>
                            <span style="font-size:var(--text-sm);color:var(--color-text-2)">Gasto abaixo de 80% do limite.</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3);border:1px solid var(--color-border);border-radius:var(--radius-md)">
                            <span class="badge badge-warning" style="min-width:80px;justify-content:center"><span class="badge-dot"></span>Atenção</span>
                            <span style="font-size:var(--text-sm);color:var(--color-text-2)">Gasto entre 80% e 99% do limite.</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3);border:1px solid var(--color-border);border-radius:var(--radius-md)">
                            <span class="badge badge-danger" style="min-width:80px;justify-content:center"><span class="badge-dot"></span>Excedido</span>
                            <span style="font-size:var(--text-sm);color:var(--color-text-2)">Gasto igualou ou ultrapassou o limite.</span>
                        </div>
                    </div>
                </div>
            </article>
        </section>

    </main>
</div>

<script src="/js/app.js"></script>
</body>
</html>

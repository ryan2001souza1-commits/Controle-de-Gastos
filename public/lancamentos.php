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
            <a href="/index.php" class="sidebar-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
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
                <h1 class="topbar-title">Lançamentos</h1>
                <div class="topbar-period">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= htmlspecialchars(date('d/m/Y', strtotime($startDate))) ?> — <?= htmlspecialchars(date('d/m/Y', strtotime($endDate))) ?>
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
        <?php if (isset($_GET['success'])): ?>
            <?php
            $s = $_GET['success'];
            $msgs = ['1' => 'Lançamento adicionado com sucesso!', 'updated' => 'Lançamento atualizado!', 'deleted' => 'Lançamento excluído!'];
            ?>
            <div class="alert alert-success" role="status">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= htmlspecialchars($msgs[$s] ?? 'Operação realizada com sucesso!') ?></span>
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
            <div class="alert alert-error" role="alert">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= htmlspecialchars($errs[$e] ?? 'Ocorreu um erro.') ?></span>
            </div>
        <?php endif; ?>

        <!-- METRIC STRIP -->
        <?php
        $totalIncomes  = $totalIncomes  ?? 0;
        $totalExpenses = $totalExpenses ?? 0;
        $balance       = $balance       ?? 0;
        $rows          = $rows          ?? [];
        $economyPct    = $totalIncomes > 0 ? round((($totalIncomes - $totalExpenses) / $totalIncomes) * 100, 1) : 0.0;
        ?>
        <section class="m-strip">
            <div class="m-card">
                <div class="m-card-label">Saldo</div>
                <div class="m-card-value <?= $balance < 0 ? 'negative' : ($balance > 0 ? 'positive' : '') ?>">R$ <?= number_format($balance, 2, ',', '.') ?></div>
                <div class="m-card-sub"><?= count($rows) ?> lançamento(s)</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Receitas</div>
                <div class="m-card-value positive">R$ <?= number_format($totalIncomes, 2, ',', '.') ?></div>
                <div class="m-card-sub">no período</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Despesas</div>
                <div class="m-card-value negative">R$ <?= number_format($totalExpenses, 2, ',', '.') ?></div>
                <div class="m-card-sub">no período</div>
            </div>
            <div class="m-card">
                <div class="m-card-label">Taxa de economia</div>
                <div class="m-card-value <?= $economyPct > 0 ? 'positive' : ($economyPct < 0 ? 'negative' : '') ?>"><?= $economyPct > 0 ? '+' : '' ?><?= $economyPct ?>%</div>
                <div class="m-card-sub"><?= $totalIncomes > 0 ? 'do lucro retido' : 'sem receitas' ?></div>
            </div>
        </section>

        <!-- FILTER BAR -->
        <section class="filter-bar">
            <form method="GET" action="/index.php?action=lancamentos" class="filter-form" id="filterForm">
                <input type="hidden" name="action" value="lancamentos">
                <div class="filter-group">
                    <label for="inputStartDate">De</label>
                    <input type="date" name="start_date" id="inputStartDate" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="filter-group">
                    <label for="inputEndDate">Até</label>
                    <input type="date" name="end_date" id="inputEndDate" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="filter-group">
                    <label for="filterType">Tipo</label>
                    <div class="select-wrap">
                        <select name="type" id="filterType">
                            <option value="">Todos</option>
                            <option value="receita" <?= ($filterType ?? '') === 'receita' ? 'selected' : '' ?>>Receitas</option>
                            <option value="despesa" <?= ($filterType ?? '') === 'despesa' ? 'selected' : '' ?>>Despesas</option>
                        </select>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="filterCategory">Categoria</label>
                    <div class="select-wrap">
                        <select name="category_id" id="filterCategory">
                            <option value="">Todas</option>
                            <?php foreach (($expenseCategories ?? []) as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= ($categoryId ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-group" style="flex:1; min-width:160px;">
                    <label for="filterSearch">Buscar</label>
                    <input type="text" name="search" id="filterSearch" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="Descrição...">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                    <a href="/index.php?action=lancamentos" class="btn btn-ghost btn-sm">Limpar</a>
                </div>
            </form>
        </section>

        <!-- TABLE -->
        <section class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">
                        <?php if (($filterType ?? '') === 'receita'): ?>Receitas
                        <?php elseif (($filterType ?? '') === 'despesa'): ?>Despesas
                        <?php else: ?>Todos os Lançamentos
                        <?php endif; ?>
                    </div>
                    <div class="panel-subtitle"><?= count($rows) ?> resultado(s) encontrado(s)</div>
                </div>
            </header>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Data</th>
                            <th class="th-numeric">Valor</th>
                            <th class="th-actions">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="5" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $t): ?>
                                <?php
                                $txType = $t['type'] ?? '';
                                $txBadge = $txType === 'despesa' ? 'badge-danger' : 'badge-success';
                                $txClass = $txType === 'despesa' ? 'td-negative' : 'td-positive';
                                ?>
                                <tr>
                                    <td>
                                        <div class="td-strong"><?= htmlspecialchars($t['description'] ?? '') ?></div>
                                        <div class="td-muted text-xs"><span class="badge <?= $txBadge ?>" style="padding:1px 6px;font-size:10px"><span class="badge-dot"></span><?= $txType === 'despesa' ? 'Despesa' : 'Receita' ?></span></div>
                                    </td>
                                    <td class="td-muted"><?= htmlspecialchars($t['category_name'] ?? '—') ?></td>
                                    <td class="td-mono td-muted"><?= isset($t['date']) ? date('d/m/Y', strtotime($t['date'])) : '—' ?></td>
                                    <td class="td-numeric <?= $txClass ?>">R$ <?= number_format((float)($t['amount'] ?? 0), 2, ',', '.') ?></td>
                                    <td class="actions-cell">
                                        <a href="/index.php?action=edit&id=<?= (int)($t['id'] ?? 0) ?>&type=<?= htmlspecialchars($txType) ?>" class="btn btn-ghost btn-xs">Editar</a>
                                        <form action="/index.php?action=delete" method="POST">
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

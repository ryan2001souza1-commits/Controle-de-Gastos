<?php
$pageTitle = $pageTitle ?? 'Metas Financeiras - Controle de Gastos';
$userName  = $userName  ?? 'Usuário';
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'metas';
$goals = $data['goals'] ?? [];
$errors = [
    'invalid_data'    => 'Dados inválidos. Preencha corretamente.',
    'invalid_date'    => 'Data inválida.',
    'not_found'       => 'Meta não encontrada.',
    'duplicate_name'  => 'Já existe uma meta com esse nome.',
];
$successMsgs = [
    'created'  => 'Meta criada com sucesso!',
    'updated'  => 'Meta atualizada!',
    'deleted'  => 'Meta excluída!',
];
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
            <a href="/index.php?action=orcamentos" class="sidebar-link">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link <?= $activeMenu === 'metas' ? 'active' : '' ?>">
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
                <h1 class="topbar-title">Metas</h1>
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

        <!-- GOAL CARDS -->
        <?php if (empty($goals)): ?>
            <section class="panel">
                <div class="panel-body">
                    <div class="empty-msg">
                        Nenhuma meta financeira cadastrada. Use o formulário abaixo para criar a primeira.
                    </div>
                </div>
            </section>
        <?php else: ?>
            <section class="charts-row charts-row-2" style="margin-bottom:var(--space-5)">
                <?php foreach ($goals as $g):
                    $badgeClass = 'badge-warning';
                    $statusLabel = 'No prazo';
                    if ($g['status'] === 'completed') { $badgeClass = 'badge-success'; $statusLabel = 'Concluída'; }
                    elseif ($g['status'] === 'overdue') { $badgeClass = 'badge-danger'; $statusLabel = 'Atrasada'; }
                    elseif ($g['status'] === 'near')    { $badgeClass = 'badge-warning'; $statusLabel = 'No prazo'; }

                    $barColor = '#0f766e';
                    if ($g['status'] === 'completed') $barColor = '#15803d';
                    elseif ($g['status'] === 'overdue') $barColor = '#b91c1c';
                ?>
                <article class="panel">
                    <header class="panel-header">
                        <div>
                            <div class="panel-title"><?= htmlspecialchars($g['name']) ?></div>
                            <div class="panel-subtitle"><?= $g['description'] ? htmlspecialchars($g['description']) : 'Sem descrição' ?></div>
                        </div>
                        <span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= $statusLabel ?></span>
                    </header>
                    <div class="panel-body">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">
                            <span style="font-size:var(--text-xs);font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:0.05em">Progresso</span>
                            <span style="font-size:var(--text-sm);font-weight:700;color:var(--color-text-1)"><?= $g['percentage'] ?>%</span>
                        </div>
                        <div class="progress-bar" style="height:6px;margin-bottom:var(--space-4)">
                            <div class="progress-fill" style="width:<?= min(100, $g['percentage']) ?>%;background:<?= $barColor ?>"></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                            <div>
                                <div style="font-size:var(--text-xs);font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Acumulado</div>
                                <div style="color:var(--color-success);font-weight:700;font-family:var(--font-mono);font-size:var(--text-md)">R$ <?= number_format($g['saved'], 2, ',', '.') ?></div>
                            </div>
                            <div>
                                <div style="font-size:var(--text-xs);font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Objetivo</div>
                                <div style="color:var(--color-text-1);font-weight:700;font-family:var(--font-mono);font-size:var(--text-md)">R$ <?= number_format($g['target'], 2, ',', '.') ?></div>
                            </div>
                            <div>
                                <div style="font-size:var(--text-xs);font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Restante</div>
                                <div style="color:var(--color-text-2);font-weight:600;font-family:var(--font-mono);font-size:var(--text-md)">R$ <?= number_format($g['remaining'], 2, ',', '.') ?></div>
                            </div>
                            <div>
                                <div style="font-size:var(--text-xs);font-weight:600;color:var(--color-text-3);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Prazo</div>
                                <div style="color:var(--color-text-1);font-weight:600;font-size:var(--text-md)"><?= $g['deadline'] ? date('d/m/Y', strtotime($g['deadline'])) : '—' ?></div>
                            </div>
                        </div>
                        <div style="margin-top:var(--space-4);display:flex;gap:var(--space-2);padding-top:var(--space-3);border-top:1px solid var(--color-border)">
                            <button class="btn btn-ghost btn-xs" type="button" onclick="editGoal(<?= (int)$g['id'] ?>, <?= json_encode($g['name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= $g['target'] ?>, <?= $g['saved'] ?>, <?= json_encode($g['deadline'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($g['description'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)">Editar</button>
                            <form action="/index.php?action=delete_goal" method="POST" style="display:inline">
                                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                            </form>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- FORM + SUMMARY -->
        <section class="two-col two-col-form">
            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title" id="formTitle">Nova meta</div>
                        <div class="panel-subtitle">Crie ou edite uma meta financeira.</div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-xs" id="cancelGoalBtn" onclick="cancelEditGoal()" style="display:none">Cancelar</button>
                </header>
                <div class="panel-body-sm">
                    <form action="/index.php?action=store_goal" method="POST" id="goalForm" novalidate>
                        <input type="hidden" name="id" id="goalId" value="">
                        <div class="form-stack">
                            <div class="form-group">
                                <label for="goalName">Nome da meta</label>
                                <input type="text" name="name" id="goalName" placeholder="Ex: Reserva de emergência" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="targetAmount">Valor objetivo (R$)</label>
                                    <input type="number" name="target_amount" id="targetAmount" step="0.01" min="0.01" placeholder="0,00" required>
                                </div>
                                <div class="form-group">
                                    <label for="savedAmount">Valor acumulado (R$)</label>
                                    <input type="number" name="saved_amount" id="savedAmount" step="0.01" min="0" placeholder="0,00" value="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="goalDeadline">Data limite</label>
                                <input type="date" name="deadline" id="goalDeadline">
                            </div>
                            <div class="form-group">
                                <label for="goalDesc">Descrição (opcional)</label>
                                <textarea name="description" id="goalDesc" rows="2" placeholder="Detalhes sobre esta meta..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block" id="goalBtn">
                                <span id="goalBtnText">Criar meta</span>
                            </button>
                        </div>
                    </form>
                </div>
            </article>

            <article class="panel">
                <header class="panel-header">
                    <div>
                        <div class="panel-title">Resumo</div>
                        <div class="panel-subtitle">Visão consolidada das metas.</div>
                    </div>
                </header>
                <div class="panel-body">
                    <?php
                    $totalObj = array_sum(array_column($goals, 'target'));
                    $totalAcum = array_sum(array_column($goals, 'saved'));
                    $completas = count(array_filter($goals, fn($g) => ($g['percentage'] ?? 0) >= 100));
                    $emAndamento = count($goals) - $completas;
                    ?>
                    <div class="indicators" style="grid-template-columns:repeat(2,1fr);border-radius:var(--radius-md)">
                        <div class="indicator">
                            <div class="indicator-label">Total de metas</div>
                            <div class="indicator-value"><?= count($goals) ?></div>
                            <div class="indicator-sub">cadastradas</div>
                        </div>
                        <div class="indicator">
                            <div class="indicator-label">Concluídas</div>
                            <div class="indicator-value positive"><?= $completas ?></div>
                            <div class="indicator-sub">100% atingido</div>
                        </div>
                        <div class="indicator">
                            <div class="indicator-label">Em andamento</div>
                            <div class="indicator-value"><?= $emAndamento ?></div>
                            <div class="indicator-sub">em progresso</div>
                        </div>
                        <div class="indicator">
                            <div class="indicator-label">Total acumulado</div>
                            <div class="indicator-value positive">R$ <?= number_format($totalAcum, 2, ',', '.') ?></div>
                            <div class="indicator-sub">economizado</div>
                        </div>
                    </div>
                </div>
            </article>
        </section>

    </main>
</div>

<script src="/js/app.js"></script>
<script>
function editGoal(id, name, target, saved, deadline, desc) {
    document.getElementById('goalId').value = id;
    document.getElementById('goalName').value = name;
    document.getElementById('targetAmount').value = target;
    document.getElementById('savedAmount').value = saved;
    document.getElementById('goalDeadline').value = deadline || '';
    document.getElementById('goalDesc').value = desc || '';
    document.getElementById('goalForm').action = '/index.php?action=update_goal';
    document.getElementById('goalBtnText').textContent = 'Salvar alterações';
    document.getElementById('cancelGoalBtn').style.display = '';
    document.getElementById('formTitle').textContent = 'Editar meta';
    document.getElementById('goalName').focus();
}
function cancelEditGoal() {
    document.getElementById('goalForm').reset();
    document.getElementById('goalId').value = '';
    document.getElementById('goalForm').action = '/index.php?action=store_goal';
    document.getElementById('goalBtnText').textContent = 'Criar meta';
    document.getElementById('cancelGoalBtn').style.display = 'none';
    document.getElementById('formTitle').textContent = 'Nova meta';
}
</script>
</body>
</html>

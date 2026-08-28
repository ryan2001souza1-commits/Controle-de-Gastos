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
            <a href="/index.php?action=orcamentos" class="sidebar-link">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Orçamentos
            </a>
            <a href="/index.php?action=metas" class="sidebar-link <?= $activeMenu === 'metas' ? 'active' : '' ?>">
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
                <h2 class="topbar-title">Metas Financeiras</h2>
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

        <?php if (empty($goals)): ?>
        <section class="bottom-card" style="margin-bottom:1.5rem">
            <div class="empty-msg" style="padding:2rem;text-align:center">
                <p>Nenhuma meta financeira cadastrada.</p>
                <p style="font-size:0.875rem;color:var(--text-light)">Use o formulário abaixo para criar sua primeira meta.</p>
            </div>
        </section>
        <?php endif; ?>

        <section class="summary-section" style="grid-template-columns:1fr 1fr; margin-bottom:1.5rem">
            <?php foreach ($goals as $g):
                $badgeClass = 'badge-info';
                $statusLabel = 'Em andamento';
                if ($g['status'] === 'completed') { $badgeClass = 'badge-success'; $statusLabel = 'Concluída'; }
                elseif ($g['status'] === 'overdue') { $badgeClass = 'badge-danger'; $statusLabel = 'Atrasada'; }
                elseif ($g['status'] === 'near')    { $badgeClass = 'badge-warning'; $statusLabel = 'No prazo'; }

                $barColor = '#4f46e5';
                if ($g['status'] === 'completed') $barColor = '#16a34a';
                elseif ($g['status'] === 'overdue') $barColor = '#dc2626';
            ?>
            <div class="bottom-card">
                <div class="bottom-card-header">
                    <h3 class="bottom-card-title" style="margin-bottom:0"><?= htmlspecialchars($g['name']) ?></h3>
                    <span class="badge <?= $badgeClass ?>">
                        <?= $statusLabel ?>
                    </span>
                </div>
                <div style="padding:0.75rem 1rem">
                    <div style="display:flex;justify-content:space-between;margin-bottom:0.25rem">
                        <span style="color:var(--text-light);font-size:0.8rem">Progresso</span>
                        <span style="font-size:0.8rem;font-weight:600"><?= $g['percentage'] ?>%</span>
                    </div>
                    <div style="background:var(--bg-soft);border-radius:6px;height:8px;overflow:hidden;margin-bottom:0.75rem">
                        <div style="height:100%;width:<?= $g['percentage'] ?>%;background:<?= $barColor ?>;border-radius:6px"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;font-size:0.8rem">
                        <div>
                            <span style="color:var(--text-light)">Acumulado</span><br>
                            <strong style="color:var(--color-income)">R$ <?= number_format($g['saved'], 2, ',', '.') ?></strong>
                        </div>
                        <div>
                            <span style="color:var(--text-light)">Objetivo</span><br>
                            <strong>R$ <?= number_format($g['target'], 2, ',', '.') ?></strong>
                        </div>
                        <div>
                            <span style="color:var(--text-light)">Restante</span><br>
                            <strong style="color:var(--text-muted)">R$ <?= number_format($g['remaining'], 2, ',', '.') ?></strong>
                        </div>
                        <div>
                            <span style="color:var(--text-light)">Prazo</span><br>
                            <strong><?= $g['deadline'] ? date('d/m/Y', strtotime($g['deadline'])) : 'Sem prazo' ?></strong>
                        </div>
                    </div>
                    <?php if ($g['description']): ?>
                    <p style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-light)"><?= htmlspecialchars($g['description']) ?></p>
                    <?php endif; ?>
                    <div style="margin-top:0.75rem;display:flex;gap:0.5rem">
                        <button class="btn btn-ghost btn-xs" type="button" onclick="editGoal(<?= (int)$g['id'] ?>, '<?= htmlspecialchars(addslashes($g['name']), ENT_QUOTES) ?>', <?= $g['target'] ?>, <?= $g['saved'] ?>, '<?= $g['deadline'] ? htmlspecialchars($g['deadline']) : '' ?>', '<?= htmlspecialchars(addslashes($g['description'] ?? ''), ENT_QUOTES) ?>')">Editar</button>
                        <form action="/index.php?action=delete_goal" method="POST" class="delete-form" style="display:inline">
                            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs">Excluir</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <section class="summary-section" style="margin-top:1rem; grid-template-columns:480px 1fr;">
            <div class="bottom-card">
                <h3 class="bottom-card-title" id="formTitle">Nova Meta</h3>
                <form action="/index.php?action=store_goal" method="POST" id="goalForm" novalidate>
                    <input type="hidden" name="id" id="goalId" value="">
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="goalName">Nome da meta</label>
                            <input type="text" name="name" id="goalName" placeholder="Ex: Reserva de emergência" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
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
                        <div style="display:flex;gap:0.5rem">
                            <button type="submit" class="btn btn-primary" id="goalBtn" style="flex:1">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <span id="goalBtnText">Criar Meta</span>
                            </button>
                            <button type="button" class="btn btn-ghost" id="cancelGoalBtn" onclick="cancelEditGoal()" style="display:none">Cancelar</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="bottom-card">
                <h3 class="bottom-card-title">Resumo das Metas</h3>
                <?php
                $totalObj = array_sum(array_column($goals, 'target'));
                $totalAcum = array_sum(array_column($goals, 'saved'));
                $completas = count(array_filter($goals, fn($g) => ($g['percentage'] ?? 0) >= 100));
                $emAndamento = count($goals) - $completas;
                ?>
                <div class="indicators-grid" style="gap:0.75rem">
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Total de Metas</span>
                        <span class="indicator-value"><?= count($goals) ?></span>
                    </div>
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Concluídas</span>
                        <span class="indicator-value" style="color:var(--color-income)"><?= $completas ?></span>
                    </div>
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Em Andamento</span>
                        <span class="indicator-value"><?= $emAndamento ?></span>
                    </div>
                    <div class="indicator" style="padding:0.875rem 1rem">
                        <span class="indicator-label">Total Acumulado</span>
                        <span class="indicator-value" style="color:var(--color-income)">R$ <?= number_format($totalAcum, 2, ',', '.') ?></span>
                    </div>
                </div>
            </div>
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
    document.getElementById('goalBtnText').textContent = 'Salvar';
    document.getElementById('cancelGoalBtn').style.display = '';
    document.getElementById('goalBtn').querySelector('svg').innerHTML = '<polyline points="20 6 9 17 4 12"/>';
    document.getElementById('formTitle').textContent = 'Editar Meta';
    document.getElementById('goalName').focus();
}
function cancelEditGoal() {
    document.getElementById('goalForm').reset();
    document.getElementById('goalId').value = '';
    document.getElementById('goalForm').action = '/index.php?action=store_goal';
    document.getElementById('goalBtnText').textContent = 'Criar Meta';
    document.getElementById('cancelGoalBtn').style.display = 'none';
    document.getElementById('formTitle').textContent = 'Nova Meta';
}
</script>
</body>
</html>

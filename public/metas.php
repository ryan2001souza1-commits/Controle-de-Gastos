<?php
$pageTitle = 'Metas';
$pageSubtitle = 'Acompanhe suas metas financeiras e evolução patrimonial.';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'metas';
$pageEyebrow = 'Planejamento';

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

$totalObj   = array_sum(array_column($goals, 'target'));
$totalAcum  = array_sum(array_column($goals, 'saved'));
$completas  = count(array_filter($goals, fn($g) => (int)($g['percentage'] ?? 0) >= 100));
$emAndamento = count($goals) - $completas;
$activeGoalTab = $_GET['goal_tab'] ?? 'list';

$palette = ['#10b981','#3b82f6','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#ef4444','#22c55e'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metas - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <?php if (isset($_GET['success']) && isset($successMsgs[$_GET['success']])): ?>
        <div class="alert alert-success" role="status"><?= render_icon('check', 13) ?><span><?= htmlspecialchars($successMsgs[$_GET['success']]) ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && isset($errors[$_GET['error']])): ?>
        <div class="alert alert-error" role="alert"><?= render_icon('info', 13) ?><span><?= htmlspecialchars($errors[$_GET['error']]) ?></span></div>
    <?php endif; ?>

    <!-- ===== METRIC CARDS ===== -->
    <section class="metric-strip">
        <article class="metric-card">
            <div class="metric-card-icon is-primary"><?= render_icon('target', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Total de metas</div>
                <div class="metric-card-value"><?= count($goals) ?></div>
                <div class="metric-card-trend">cadastradas</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-success"><?= render_icon('check', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Concluídas</div>
                <div class="metric-card-value is-positive"><?= $completas ?></div>
                <div class="metric-card-trend">100% atingido</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-info"><?= render_icon('trending-up', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Em andamento</div>
                <div class="metric-card-value is-primary"><?= $emAndamento ?></div>
                <div class="metric-card-trend">em progresso</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-card-icon is-success"><?= render_icon('dollar', 18) ?></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Total acumulado</div>
                <div class="metric-card-value is-positive">R$ <?= number_format($totalAcum, 2, ',', '.') ?></div>
                <div class="metric-card-trend">de R$ <?= number_format($totalObj, 2, ',', '.') ?> objetivo</div>
            </div>
        </article>
    </section>

    <!-- ===== TABS ===== -->
    <div class="tabs" role="tablist">
        <a href="?action=metas&goal_tab=list" class="tab-item <?= $activeGoalTab === 'list' ? 'is-active' : '' ?>" role="tab">
            <?= render_icon('layers', 13) ?>
            Minhas metas
            <span class="tab-badge"><?= count($goals) ?></span>
        </a>
        <a href="?action=metas&goal_tab=new" class="tab-item <?= $activeGoalTab === 'new' ? 'is-active' : '' ?>" role="tab">
            <?= render_icon('plus', 13) ?>
            Nova meta
        </a>
    </div>

    <?php if ($activeGoalTab === 'new'): ?>
    <!-- ===== NEW GOAL FORM ===== -->
    <section class="two-col" style="margin-bottom:var(--space-5)">
        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Criar nova meta</div>
                    <div class="panel-subtitle">Defina um objetivo financeiro.</div>
                </div>
            </header>
            <div class="panel-body-sm">
                <form action="/index.php?action=store_goal" method="POST" id="goalFormNew" novalidate>
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="goalNameNew">Nome da meta</label>
                            <input type="text" name="name" id="goalNameNew" placeholder="Ex: Reserva de emergência" required maxlength="120">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="targetNew">Valor objetivo (R$)</label>
                                <input type="number" name="target_amount" id="targetNew" step="0.01" min="0.01" placeholder="0,00" required>
                            </div>
                            <div class="form-group">
                                <label for="savedNew">Valor acumulado (R$)</label>
                                <input type="number" name="saved_amount" id="savedNew" step="0.01" min="0" placeholder="0,00" value="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="deadlineNew">Data limite</label>
                            <input type="date" name="deadline" id="deadlineNew">
                        </div>
                        <div class="form-group">
                            <label for="descNew">Descrição (opcional)</label>
                            <textarea name="description" id="descNew" rows="2" placeholder="Detalhes sobre esta meta..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <?= render_icon('check', 14) ?>
                            Criar meta
                        </button>
                    </div>
                </form>
            </div>
        </article>

        <article class="panel">
            <header class="panel-header">
                <div>
                    <div class="panel-title">Resumo</div>
                    <div class="panel-subtitle">Visão consolidada.</div>
                </div>
            </header>
            <div class="panel-body">
                <div class="goal-grid">
                    <div>
                        <div class="goal-key">Total de metas</div>
                        <div class="goal-val"><?= count($goals) ?></div>
                    </div>
                    <div>
                        <div class="goal-key">Concluídas</div>
                        <div class="goal-val is-positive"><?= $completas ?></div>
                    </div>
                    <div>
                        <div class="goal-key">Em andamento</div>
                        <div class="goal-val"><?= $emAndamento ?></div>
                    </div>
                    <div>
                        <div class="goal-key">Total acumulado</div>
                        <div class="goal-val is-positive">R$ <?= number_format($totalAcum, 2, ',', '.') ?></div>
                    </div>
                </div>
                <?php if ($totalObj > 0): ?>
                <div style="margin-top:var(--space-4);padding:14px 16px;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md)">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <span style="font-size:12px;color:var(--color-text-2)">Progresso geral</span>
                        <span style="font-weight:700;color:var(--color-success);font-family:var(--font-mono)"><?= round(($totalAcum / $totalObj) * 100) ?>%</span>
                    </div>
                    <div class="progress-bar is-large">
                        <div class="progress-fill" style="width:<?= min(100, round(($totalAcum / $totalObj) * 100)) ?>%"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:6px">
                        <span style="font-size:11px;color:var(--color-text-3)">R$ <?= number_format($totalAcum, 2, ',', '.') ?></span>
                        <span style="font-size:11px;color:var(--color-text-3)">R$ <?= number_format($totalObj, 2, ',', '.') ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php endif; ?>

    <!-- ===== GOALS LIST ===== -->
    <?php if (empty($goals) && $activeGoalTab === 'list'): ?>
    <section class="panel" style="margin-bottom:var(--space-5)">
        <div class="panel-body">
            <div class="empty-state">
                <div style="font-size:32px;margin-bottom:var(--space-3)"><?= render_icon('target', 28) ?></div>
                <strong style="display:block;color:var(--color-text-2);font-size:14px;margin-bottom:6px">Nenhuma meta financeira cadastrada</strong>
                <span style="font-size:13px">Use o botão abaixo para criar sua primeira meta.</span>
                <a href="?action=metas&goal_tab=new" class="btn btn-primary" style="margin-top:var(--space-4)">
                    <?= render_icon('plus', 13) ?>
                    Criar primeira meta
                </a>
            </div>
        </div>
    </section>
    <?php elseif ($activeGoalTab === 'list'): ?>
    <section class="goals-grid" style="margin-bottom:var(--space-5)">
        <?php foreach ($goals as $i => $g):
            $pct     = (int)($g['percentage'] ?? 0);
            $status  = $g['status'] ?? 'near';
            $barColor = $status === 'completed' ? 'var(--color-success)' : ($status === 'overdue' ? 'var(--color-danger)' : 'var(--color-primary)');
            $badgeClass = $status === 'completed' ? 'badge-success' : ($status === 'overdue' ? 'badge-danger' : 'badge-warning');
            $badgeLabel = $status === 'completed' ? 'Concluída' : ($status === 'overdue' ? 'Atrasada' : 'No prazo');
            $gicon  = !empty($g['icon']) ? $g['icon'] : 'target';
            $gcolor = $palette[$i % count($palette)];
            $ringPct = min(100, $pct);
            $ringCirc = 2 * M_PI * 56;
            $ringDash = ($ringPct / 100) * $ringCirc;
        ?>
        <article class="panel goal-card">
            <header class="goal-card-head">
                <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
                    <div class="goal-card-icon" style="background:<?= $gcolor ?>20;color:<?= $gcolor ?>">
                        <?= render_icon($gicon, 20) ?>
                    </div>
                    <div class="goal-card-info">
                        <div class="goal-card-name"><?= htmlspecialchars($g['name']) ?></div>
                        <?php if (!empty($g['description'])): ?>
                        <div class="goal-card-desc"><?= htmlspecialchars($g['description']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= $badgeLabel ?></span>
            </header>
            <div class="goal-card-body">
                <div style="display:flex;gap:var(--space-5);align-items:center">
                    <div class="goal-ring" style="flex-shrink:0">
                        <svg width="120" height="120" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="56" fill="none" stroke="var(--color-surface-3)" stroke-width="10"/>
                            <circle cx="60" cy="60" r="56" fill="none" stroke="<?= $barColor ?>" stroke-width="10" stroke-linecap="round"
                                stroke-dasharray="<?= $ringDash ?> <?= $ringCirc ?>"
                                style="transition:stroke-dasharray .9s var(--ease-out)"/>
                        </svg>
                        <div class="goal-ring-center">
                            <div class="goal-ring-pct" style="color:<?= $barColor ?>"><?= $pct ?>%</div>
                            <div class="goal-ring-label">concluído</div>
                        </div>
                    </div>
                    <div class="goal-grid" style="flex:1">
                        <div>
                            <div class="goal-key">Acumulado</div>
                            <div class="goal-val is-positive">R$ <?= number_format($g['saved'] ?? 0, 2, ',', '.') ?></div>
                        </div>
                        <div>
                            <div class="goal-key">Objetivo</div>
                            <div class="goal-val">R$ <?= number_format($g['target'] ?? 0, 2, ',', '.') ?></div>
                        </div>
                        <div>
                            <div class="goal-key">Restante</div>
                            <div class="goal-val <?= ($g['remaining'] ?? 0) <= 0 ? 'is-positive' : '' ?>">R$ <?= number_format(max(0, $g['remaining'] ?? 0), 2, ',', '.') ?></div>
                        </div>
                        <div>
                            <div class="goal-key">Prazo</div>
                            <div class="goal-val"><?= !empty($g['deadline']) ? date('d/m/Y', strtotime($g['deadline'])) : '—' ?></div>
                        </div>
                    </div>
                </div>
                <div class="progress-bar is-large" style="margin-top:var(--space-4)">
                    <div class="progress-fill" style="width:<?= min(100, $pct) ?>%;background:<?= $barColor ?>"></div>
                </div>
                <div class="goal-actions">
                    <button type="button" class="btn btn-ghost btn-xs" onclick='openEditGoal(<?= json_encode($g, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                        <?= render_icon('edit', 12) ?>
                        Editar
                    </button>
                    <form action="/index.php?action=delete_goal" method="POST" style="display:inline">
                        <input type="hidden" name="id" value="<?= (int)($g['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Deseja excluir esta meta?')">
                            <?= render_icon('trash', 12) ?>
                            Excluir
                        </button>
                    </form>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <!-- ===== MODAL: Editar Meta ===== -->
    <div class="modal-overlay" id="editGoalModal" role="dialog" aria-modal="true" style="display:none">
        <div class="modal">
            <header class="modal-header">
                <div class="modal-title">Editar meta</div>
                <button type="button" class="modal-close" onclick="closeEditGoalModal()" aria-label="Fechar"><?= render_icon('x', 16) ?></button>
            </header>
            <div class="modal-body">
                <form action="/index.php?action=update_goal" method="POST" id="editGoalForm" novalidate>
                    <input type="hidden" name="id" id="modalGoalId" value="">
                    <div class="form-stack">
                        <div class="form-group">
                            <label for="modalGoalName">Nome da meta</label>
                            <input type="text" name="name" id="modalGoalName" required maxlength="120">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="modalGoalTarget">Valor objetivo (R$)</label>
                                <input type="number" name="target_amount" id="modalGoalTarget" step="0.01" min="0.01" required>
                            </div>
                            <div class="form-group">
                                <label for="modalGoalSaved">Valor acumulado (R$)</label>
                                <input type="number" name="saved_amount" id="modalGoalSaved" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="modalGoalDeadline">Data limite</label>
                            <input type="date" name="deadline" id="modalGoalDeadline">
                        </div>
                        <div class="form-group">
                            <label for="modalGoalDesc">Descrição (opcional)</label>
                            <textarea name="description" id="modalGoalDesc" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <footer class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeEditGoalModal()">Cancelar</button>
                <button type="submit" form="editGoalForm" class="btn btn-primary">Salvar alterações</button>
            </footer>
        </div>
    </div>

<?php
$extraScripts = '<script>
function openEditGoal(data) {
    document.getElementById("modalGoalId").value = data.id || 0;
    document.getElementById("modalGoalName").value = data.name || "";
    document.getElementById("modalGoalTarget").value = data.target || 0;
    document.getElementById("modalGoalSaved").value = data.saved || 0;
    document.getElementById("modalGoalDeadline").value = data.deadline || "";
    document.getElementById("modalGoalDesc").value = data.description || "";
    var m = document.getElementById("editGoalModal");
    m.style.display = "flex";
    document.getElementById("modalGoalName").focus();
    document.body.style.overflow = "hidden";
}
function closeEditGoalModal() {
    var m = document.getElementById("editGoalModal");
    m.style.display = "none";
    document.body.style.overflow = "";
}
document.getElementById("editGoalModal").addEventListener("click", function(e) {
    if (e.target === this) closeEditGoalModal();
});
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") closeEditGoalModal();
});
</script>';
include __DIR__ . '/partials/layout_end.php';
?>
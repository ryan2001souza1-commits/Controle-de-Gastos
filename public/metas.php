<?php
$pageTitle = $pageTitle ?? 'Metas Financeiras - Controle de Gastos';
$userName  = $userName  ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = strtoupper(substr($userName, 0, 1));

$activeMenu = 'metas';
$pageEyebrow = 'Planejamento';
$pageTitle = 'Metas';

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
    <title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

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

    <?php if (empty($goals)): ?>
        <section class="panel">
            <div class="panel-body">
                <div class="empty-state">
                    <strong style="display:block; color:var(--color-text-2); font-size:14px;">Nenhuma meta financeira cadastrada</strong>
                    <span style="margin-top:6px; display:block;">Use o formulário abaixo para criar a primeira.</span>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="goals-grid">
            <?php foreach ($goals as $g):
                $badgeClass = 'badge-warning';
                $statusLabel = 'No prazo';
                if ($g['status'] === 'completed') { $badgeClass = 'badge-success'; $statusLabel = 'Concluída'; }
                elseif ($g['status'] === 'overdue') { $badgeClass = 'badge-danger'; $statusLabel = 'Atrasada'; }
                elseif ($g['status'] === 'near')    { $badgeClass = 'badge-warning'; $statusLabel = 'No prazo'; }

                $barColor = '#7c3aed';
                if ($g['status'] === 'completed') $barColor = '#10b981';
                elseif ($g['status'] === 'overdue') $barColor = '#ef4444';
            ?>
            <article class="panel goal-card">
                <header class="panel-header">
                    <div>
                        <div class="panel-title"><?= htmlspecialchars($g['name']) ?></div>
                        <div class="panel-subtitle"><?= $g['description'] ? htmlspecialchars($g['description']) : 'Sem descrição' ?></div>
                    </div>
                    <span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= $statusLabel ?></span>
                </header>
                <div class="panel-body">
                    <div class="goal-progress-head">
                        <span class="goal-progress-label">Progresso</span>
                        <span class="goal-progress-value"><?= $g['percentage'] ?>%</span>
                    </div>
                    <div class="progress-bar is-large" style="margin-bottom:var(--space-4)">
                        <div class="progress-fill" style="width:<?= min(100, $g['percentage']) ?>%;background:<?= $barColor ?>"></div>
                    </div>
                    <div class="goal-grid">
                        <div>
                            <div class="goal-key">Acumulado</div>
                            <div class="goal-val is-positive">R$ <?= number_format($g['saved'], 2, ',', '.') ?></div>
                        </div>
                        <div>
                            <div class="goal-key">Objetivo</div>
                            <div class="goal-val">R$ <?= number_format($g['target'], 2, ',', '.') ?></div>
                        </div>
                        <div>
                            <div class="goal-key">Restante</div>
                            <div class="goal-val">R$ <?= number_format($g['remaining'], 2, ',', '.') ?></div>
                        </div>
                        <div>
                            <div class="goal-key">Prazo</div>
                            <div class="goal-val"><?= $g['deadline'] ? date('d/m/Y', strtotime($g['deadline'])) : '—' ?></div>
                        </div>
                    </div>
                    <div class="goal-actions">
                        <button class="btn btn-ghost btn-xs" type="button" onclick='editGoal(<?= (int)$g['id'] ?>, <?= json_encode($g['name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= $g['target'] ?>, <?= $g['saved'] ?>, <?= json_encode($g['deadline'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($g['description'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>Editar</button>
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

    <section class="charts-grid charts-grid-2">
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
                <div class="indicators" style="grid-template-columns:repeat(2,1fr);border-radius:var(--radius-md); margin-bottom:0">
                    <div class="indicator">
                        <div class="indicator-label">Total de metas</div>
                        <div class="indicator-value"><?= count($goals) ?></div>
                        <div class="indicator-sub">cadastradas</div>
                    </div>
                    <div class="indicator">
                        <div class="indicator-label">Concluídas</div>
                        <div class="indicator-value is-positive"><?= $completas ?></div>
                        <div class="indicator-sub">100% atingido</div>
                    </div>
                    <div class="indicator">
                        <div class="indicator-label">Em andamento</div>
                        <div class="indicator-value"><?= $emAndamento ?></div>
                        <div class="indicator-sub">em progresso</div>
                    </div>
                    <div class="indicator">
                        <div class="indicator-label">Total acumulado</div>
                        <div class="indicator-value is-positive">R$ <?= number_format($totalAcum, 2, ',', '.') ?></div>
                        <div class="indicator-sub">economizado</div>
                    </div>
                </div>
            </div>
        </article>
    </section>

<?php
$extraScripts = '<script>
function editGoal(id, name, target, saved, deadline, desc) {
    document.getElementById("goalId").value = id;
    document.getElementById("goalName").value = name;
    document.getElementById("targetAmount").value = target;
    document.getElementById("savedAmount").value = saved;
    document.getElementById("goalDeadline").value = deadline || "";
    document.getElementById("goalDesc").value = desc || "";
    document.getElementById("goalForm").action = "/index.php?action=update_goal";
    document.getElementById("goalBtnText").textContent = "Salvar alterações";
    document.getElementById("cancelGoalBtn").style.display = "";
    document.getElementById("formTitle").textContent = "Editar meta";
    document.getElementById("goalName").focus();
}
function cancelEditGoal() {
    document.getElementById("goalForm").reset();
    document.getElementById("goalId").value = "";
    document.getElementById("goalForm").action = "/index.php?action=store_goal";
    document.getElementById("goalBtnText").textContent = "Criar meta";
    document.getElementById("cancelGoalBtn").style.display = "none";
    document.getElementById("formTitle").textContent = "Nova meta";
}
</script>';
include __DIR__ . '/partials/layout_end.php';
?>
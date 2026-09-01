<?php
$bug = $bug ?? [];
$statusOptions = [
    'novo' => 'Novo',
    'recebido' => 'Recebido',
    'em_analise' => 'Em análise',
    'em_desenvolvimento' => 'Em desenvolvimento',
    'resolvido' => 'Resolvido',
    'fechado' => 'Fechado',
    'nao_reproduzido' => 'Não reproduzido',
];
$currentStatusLabel = $statusOptions[$bug['status']] ?? $bug['status'];
$statusClass = in_array($bug['status'], ['resolvido', 'fechado']) ? 'green' : (in_array($bug['status'], ['em_analise', 'em_desenvolvimento']) ? 'amber' : 'blue');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bug #<?= (int)$bug['id'] ?> — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/admin-system.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/admin-system.css') ?: time() ?>">
</head>
<body>
<div class="admin-app-wrapper">
<?php
$pageTitle = 'Bug #' . (int)$bug['id'];
$pageSubtitle = htmlspecialchars($bug['titulo']);
$activeMenu = 'admin_bugs';
include __DIR__ . '/../partials/admin_layout_start.php';
?>

<?php if (!empty($_GET['success'])): ?>
    <div class="admin-alert admin-alert-success" data-auto-dismiss="4000">Status atualizado com sucesso.</div>
<?php endif; ?>

<div class="admin-grid-2" style="align-items:flex-start">
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title">#<?= (int)$bug['id'] ?></div>
            <span class="admin-badge admin-badge-<?= $statusClass ?>"><?= htmlspecialchars($currentStatusLabel) ?></span>
        </div>
        <div class="admin-card-body" style="display:flex;flex-direction:column;gap:18px">
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:6px">Título</div>
                <div style="font-size:15px;font-weight:700;color:var(--admin-text)"><?= htmlspecialchars($bug['titulo']) ?></div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:6px">Descrição</div>
                <div style="font-size:13.5px;color:var(--admin-text);line-height:1.65;white-space:pre-wrap;background:#fafbfc;padding:12px;border-radius:6px;border:1px solid var(--admin-border)"><?= htmlspecialchars($bug['descricao']) ?></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px">
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:4px">Usuário</div>
                    <div style="font-weight:600;font-size:13.5px"><?= htmlspecialchars($bug['usuario_nome'] ?? '—') ?></div>
                    <div style="font-size:12px;color:var(--admin-text-soft)"><?= htmlspecialchars($bug['usuario_email'] ?? '') ?></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:4px">Enviado em</div>
                    <div style="font-size:13.5px"><?= date('d/m/Y H:i', strtotime($bug['created_at'])) ?></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:4px">Página / URL</div>
                    <div style="font-size:12px"><?= htmlspecialchars($bug['pagina'] ?? '—') ?></div>
                    <?php if (!empty($bug['url'])): ?>
                        <div style="font-size:11px;color:var(--admin-text-muted);word-break:break-all"><?= htmlspecialchars($bug['url']) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:4px">Categoria / Prioridade</div>
                    <div style="display:flex;gap:6px">
                        <span class="admin-badge admin-badge-neutral"><?= htmlspecialchars(ucfirst($bug['categoria'])) ?></span>
                        <span class="admin-badge admin-badge-<?= ['alta' => 'red', 'media' => 'amber', 'baixa' => 'neutral'][$bug['prioridade']] ?? 'neutral' ?>"><?= htmlspecialchars(ucfirst($bug['prioridade'])) ?></span>
                    </div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:4px">Navegador</div>
                    <div style="font-size:12px;word-break:break-word"><?= htmlspecialchars($bug['navegador'] ?? '—') ?></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:4px">Sistema operacional</div>
                    <div style="font-size:12px"><?= htmlspecialchars($bug['sistema_operacional'] ?? '—') ?></div>
                </div>
            </div>

            <?php if (!empty($bug['screenshot'])): ?>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:6px">Screenshot</div>
                    <a href="<?= htmlspecialchars($bug['screenshot']) ?>" target="_blank" rel="noopener">
                        <img src="<?= htmlspecialchars($bug['screenshot']) ?>" alt="Screenshot do bug" style="max-width:100%;border:1px solid var(--admin-border);border-radius:8px;max-height:350px;display:block">
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($bug['resposta_admin'])): ?>
                <div style="background:var(--admin-primary-soft);border:1px solid rgba(16,185,129,0.2);border-radius:8px;padding:14px">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-primary-dark);margin-bottom:6px">Resposta ao cliente</div>
                    <div style="font-size:13.5px;color:var(--admin-text);white-space:pre-wrap"><?= htmlspecialchars($bug['resposta_admin']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($bug['observacao_interna'])): ?>
                <div style="background:var(--admin-accent-soft);border:1px solid rgba(245,158,11,0.2);border-radius:8px;padding:14px">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#92400e;margin-bottom:6px">Observação interna</div>
                    <div style="font-size:13.5px;color:var(--admin-text);white-space:pre-wrap"><?= htmlspecialchars($bug['observacao_interna']) ?></div>
                </div>
            <?php endif; ?>

            <div style="font-size:11.5px;color:var(--admin-text-muted);border-top:1px solid var(--admin-border);padding-top:12px">
                Criado: <?= date('d/m/Y H:i', strtotime($bug['created_at'])) ?> · Atualizado: <?= date('d/m/Y H:i', strtotime($bug['updated_at'])) ?>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title">Atualizar relato</div>
        </div>
        <div class="admin-card-body">
            <form method="POST" action="/index.php?action=admin_bug_update">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$bug['id'] ?>">
                <div style="display:flex;flex-direction:column;gap:14px">
                    <div>
                        <label class="admin-label">Status</label>
                        <select name="status" class="admin-select" style="width:100%">
                            <?php foreach ($statusOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $bug['status'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="admin-label">Resposta ao cliente <span style="color:var(--admin-text-muted);font-weight:400">(visível em Meus relatos)</span></label>
                        <textarea name="resposta_admin" class="admin-input" rows="3" placeholder="Ex: Problema corrigido na versão X. Obrigado pelo report!"><?= htmlspecialchars($bug['resposta_admin'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="admin-label">Observação interna <span style="color:var(--admin-text-muted);font-weight:400">(não visível ao cliente)</span></label>
                        <textarea name="observacao_interna" class="admin-input" rows="2" placeholder="Notas técnicas para a equipe..."><?= htmlspecialchars($bug['observacao_interna'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="admin-btn admin-btn-primary" style="width:100%;justify-content:center">
                        <?= render_icon('check', 14) ?> Salvar atualização
                    </button>
                </div>
            </form>
            <div style="margin-top:16px;display:flex;justify-content:flex-end">
                <a href="/index.php?action=admin_bugs" class="admin-btn admin-btn-secondary admin-btn-sm">Voltar para lista</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/admin_layout_end.php'; ?>

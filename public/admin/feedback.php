<?php
$feedbacks = $feedbacks ?? [];
$total = (int)($total ?? 0);
$stats = $stats ?? [];
$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$totalPages = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Feedback</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/admin-system.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/admin-system.css') ?: time() ?>">
</head>
<body>
<div class="admin-app-wrapper">
<?php
$pageTitle = 'Feedback dos clientes';
$pageSubtitle = 'Sugestões, melhorias e críticas';
$activeMenu = 'admin_feedback';
include __DIR__ . '/../partials/admin_layout_start.php';
?>

<?php if (!empty($_GET['success'])): ?>
    <div class="admin-alert admin-alert-success" data-auto-dismiss="4000">Status atualizado com sucesso.</div>
<?php endif; ?>

<section class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-blue"><?= render_icon('star', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Total</div>
            <div class="admin-stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
            <div class="admin-stat-meta">todos os feedbacks</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-amber"><?= render_icon('info', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Novos</div>
            <div class="admin-stat-value"><?= (int)($stats['novos'] ?? 0) ?></div>
            <div class="admin-stat-meta">aguardando análise</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-purple"><?= render_icon('activity', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Em análise</div>
            <div class="admin-stat-value"><?= (int)($stats['em_analise'] ?? 0) ?></div>
            <div class="admin-stat-meta">sendo avaliados</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-green"><?= render_icon('check', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Implementados</div>
            <div class="admin-stat-value"><?= (int)($stats['implementados'] ?? 0) ?></div>
            <div class="admin-stat-meta">sugestões aceitas</div>
        </div>
    </div>
</section>

<section class="admin-card">
    <div class="admin-card-header">
        <div class="admin-card-title">Lista de feedback</div>
        <div style="font-size:12px;color:var(--admin-text-soft)"><?= $total ?> no total</div>
    </div>
    <div class="admin-card-body" style="padding-bottom:8px">
        <form method="GET" class="admin-form-row">
            <input type="hidden" name="action" value="admin_feedback">
            <select name="status" class="admin-select" onchange="this.form.submit()">
                <option value="">Todos os status</option>
                <option value="novo" <?= $status === 'novo' ? 'selected' : '' ?>>Novo</option>
                <option value="em_analise" <?= $status === 'em_analise' ? 'selected' : '' ?>>Em análise</option>
                <option value="implementado" <?= $status === 'implementado' ? 'selected' : '' ?>>Implementado</option>
                <option value="recusado" <?= $status === 'recusado' ? 'selected' : '' ?>>Recusado</option>
            </select>
            <div class="admin-search-box">
                <?= render_icon('search', 14) ?>
                <input type="text" name="q" placeholder="Buscar por título, descrição ou usuário" value="<?= htmlspecialchars($q) ?>">
            </div>
            <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">Filtrar</button>
        </form>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Título</th>
                    <th>Usuário</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($feedbacks)): ?>
                <tr><td colspan="6"><div class="admin-table-empty"><div class="admin-table-empty-text">Nenhum feedback encontrado</div></div></td></tr>
            <?php endif; ?>
            <?php foreach ($feedbacks as $f):
                $tipoBadge = ['sugestao' => 'blue', 'melhoria' => 'amber', 'critica' => 'red', 'elogio' => 'green', 'outro' => 'neutral'][$f['tipo']] ?? 'neutral';
                $statusBadge = ['novo' => 'blue', 'em_analise' => 'amber', 'implementado' => 'green', 'recusado' => 'neutral'][$f['status']] ?? 'neutral';
                $statusLabel = ['novo' => 'Novo', 'em_analise' => 'Em análise', 'implementado' => 'Implementado', 'recusado' => 'Recusado'][$f['status']] ?? $f['status'];
            ?>
                <tr>
                    <td><span class="admin-badge admin-badge-<?= $tipoBadge ?>"><?= htmlspecialchars(ucfirst($f['tipo'])) ?></span></td>
                    <td>
                        <div style="font-weight:600;max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($f['titulo']) ?></div>
                        <div style="font-size:11.5px;color:var(--admin-text-soft);margin-top:2px;max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(mb_substr($f['descricao'], 0, 80)) ?>...</div>
                    </td>
                    <td>
                        <div class="admin-user-meta">
                            <span class="admin-user-name"><?= htmlspecialchars($f['usuario_nome'] ?? '—') ?></span>
                            <span class="admin-user-email"><?= htmlspecialchars($f['usuario_email'] ?? '') ?></span>
                        </div>
                    </td>
                    <td><span class="admin-badge admin-badge-<?= $statusBadge ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                    <td style="font-size:12px;color:var(--admin-text-soft);white-space:nowrap"><?= date('d/m/Y H:i', strtotime($f['created_at'])) ?></td>
                    <td>
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-xs" onclick="document.getElementById('fb-<?= (int)$f['id'] ?>').classList.toggle('open')">
                            <?= render_icon('settings', 12) ?> Gerenciar
                        </button>
                    </td>
                </tr>
                <tr id="fb-<?= (int)$f['id'] ?>" style="display:none;background:#fafbfc">
                    <td colspan="6" style="padding:16px 20px">
                        <div class="admin-grid-2" style="gap:20px;align-items:flex-start">
                            <div>
                                <div class="admin-label">Descrição completa</div>
                                <div style="font-size:13px;color:var(--admin-text);line-height:1.6;background:#fff;padding:12px;border:1px solid var(--admin-border);border-radius:6px"><?= nl2br(htmlspecialchars($f['descricao'])) ?></div>
                            </div>
                            <form method="POST" action="/index.php?action=admin_feedback_update">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <div class="form-group" style="margin-bottom:10px">
                                    <label class="admin-label">Status</label>
                                    <select name="status" class="admin-select" style="width:100%">
                                        <option value="novo" <?= $f['status'] === 'novo' ? 'selected' : '' ?>>Novo</option>
                                        <option value="em_analise" <?= $f['status'] === 'em_analise' ? 'selected' : '' ?>>Em análise</option>
                                        <option value="implementado" <?= $f['status'] === 'implementado' ? 'selected' : '' ?>>Implementado</option>
                                        <option value="recusado" <?= $f['status'] === 'recusado' ? 'selected' : '' ?>>Recusado</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom:10px">
                                    <label class="admin-label">Resposta ao cliente (opcional)</label>
                                    <textarea name="resposta_admin" class="admin-input" rows="3" placeholder="Comentário interno ou resposta visível ao cliente..."><?= htmlspecialchars($f['resposta_admin'] ?? '') ?></textarea>
                                </div>
                                <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">Salvar</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="admin-pagination">
            <div class="admin-pagination-info">Página <?= $page ?> de <?= $totalPages ?></div>
            <div class="admin-pagination-controls">
                <?php if ($page > 1): ?>
                    <a class="admin-pagination-btn" href="?action=admin_feedback&status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">‹</a>
                <?php endif; ?>
                <span class="admin-pagination-btn admin-pagination-btn-active"><?= $page ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="admin-pagination-btn" href="?action=admin_feedback&status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../partials/admin_layout_end.php'; ?>

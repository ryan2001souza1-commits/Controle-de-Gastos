<?php
$bugs = $bugs ?? [];
$total = (int)($total ?? 0);
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
    <title>Admin — Relatos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/admin-system.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/admin-system.css') ?: time() ?>">
</head>
<body>
<div class="admin-app-wrapper">
<?php
$pageTitle = 'Relatos de bugs';
$pageSubtitle = $total . ' relatos dos clientes';
$activeMenu = 'admin_bugs';
include __DIR__ . '/../partials/admin_layout_start.php';
?>

<section class="admin-card">
    <div class="admin-card-header">
        <form method="GET" class="admin-form-row" style="flex:1;margin:0">
            <input type="hidden" name="action" value="admin_bugs">
            <select name="status" class="admin-select" onchange="this.form.submit()">
                <option value="">Todos os status</option>
                <?php foreach (['novo', 'recebido', 'em_analise', 'em_desenvolvimento', 'resolvido', 'fechado', 'nao_reproduzido'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $s))) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="admin-search-box">
                <?= render_icon('search', 14) ?>
                <input type="text" name="q" placeholder="Buscar por título, descrição ou e-mail" value="<?= htmlspecialchars($q) ?>">
            </div>
            <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">Filtrar</button>
        </form>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Usuário</th>
                    <th>Categoria</th>
                    <th>Prioridade</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($bugs)): ?>
                <tr><td colspan="7"><div class="admin-table-empty"><div class="admin-table-empty-text">Nenhum relato encontrado</div></div></td></tr>
            <?php endif; ?>
            <?php foreach ($bugs as $b):
                $statusClass = in_array($b['status'], ['resolvido', 'fechado']) ? 'green' : (in_array($b['status'], ['em_analise', 'em_desenvolvimento']) ? 'amber' : 'blue');
                $statusLabel = ['novo' => 'Novo', 'recebido' => 'Recebido', 'em_analise' => 'Em análise', 'em_desenvolvimento' => 'Em dev', 'resolvido' => 'Resolvido', 'fechado' => 'Fechado', 'nao_reproduzido' => 'Não reproduzido'][$b['status']] ?? $b['status'];
                $prioClass = ['alta' => 'red', 'media' => 'amber', 'baixa' => 'neutral'][$b['prioridade']] ?? 'neutral';
            ?>
                <tr>
                    <td>
                        <div style="font-weight:600;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($b['titulo']) ?></div>
                        <div style="font-size:11.5px;color:var(--admin-text-soft);margin-top:2px;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(mb_substr($b['descricao'], 0, 70)) ?>...</div>
                    </td>
                    <td>
                        <div class="admin-user-meta">
                            <span class="admin-user-name"><?= htmlspecialchars($b['usuario_nome'] ?? '—') ?></span>
                            <span class="admin-user-email"><?= htmlspecialchars($b['usuario_email'] ?? '') ?></span>
                        </div>
                    </td>
                    <td><span class="admin-badge admin-badge-neutral"><?= htmlspecialchars(ucfirst($b['categoria'])) ?></span></td>
                    <td><span class="admin-badge admin-badge-<?= $prioClass ?>"><?= htmlspecialchars(ucfirst($b['prioridade'])) ?></span></td>
                    <td><span class="admin-badge admin-badge-<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                    <td style="font-size:12px;color:var(--admin-text-soft);white-space:nowrap"><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></td>
                    <td>
                        <a href="/index.php?action=admin_bug_detail&id=<?= (int)$b['id'] ?>" class="admin-btn admin-btn-secondary admin-btn-xs">Abrir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="admin-pagination">
            <div class="admin-pagination-info"><?= count($bugs) ?> de <?= $total ?> relatos</div>
            <div class="admin-pagination-controls">
                <?php if ($page > 1): ?>
                    <a class="admin-pagination-btn" href="?action=admin_bugs&status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">‹</a>
                <?php endif; ?>
                <span class="admin-pagination-btn admin-pagination-btn-active"><?= $page ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="admin-pagination-btn" href="?action=admin_bugs&status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../partials/admin_layout_end.php'; ?>

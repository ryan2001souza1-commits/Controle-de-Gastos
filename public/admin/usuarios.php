<?php
$usuarios = $usuarios ?? [];
$total = (int)($total ?? 0);
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$totalPages = max(1, (int)ceil($total / $perPage));
$planoStatus = $_GET['plano'] ?? '';
$isAdmin = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Clientes</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/admin-system.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/admin-system.css') ?: time() ?>">
</head>
<body>
<div class="admin-app-wrapper">
<?php
$pageTitle = 'Clientes';
$pageSubtitle = $total . ' usuários cadastrados';
$activeMenu = 'admin_usuarios';
include __DIR__ . '/../partials/admin_layout_start.php';
?>

<section class="admin-card">
    <div class="admin-card-header">
        <div class="admin-form-row" style="flex:1">
            <form method="GET" class="admin-form-row" style="flex:1;margin:0">
                <input type="hidden" name="action" value="admin_usuarios">
                <div class="admin-search-box">
                    <?= render_icon('search', 14) ?>
                    <input type="text" name="q" placeholder="Buscar por nome ou e-mail" value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">Pesquisar</button>
            </form>
        </div>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Usuário</th>
                    <th>E-mail</th>
                    <th>Plano</th>
                    <th>Status</th>
                    <th>Cadastro</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($usuarios)): ?>
                <tr><td colspan="5"><div class="admin-table-empty"><div class="admin-table-empty-text">Nenhum usuário encontrado</div></div></td></tr>
            <?php endif; ?>
            <?php foreach ($usuarios as $u):
                $plano = $u['plano'] ?? 'gratuito';
                $planoBadge = ['gratuito' => 'green', 'pro' => 'amber', 'premium' => 'purple'][$plano] ?? 'neutral';
                $statusBadge = ($u['plano_status'] ?? 'ativo') === 'ativo' ? 'green' : 'amber';
            ?>
                <tr>
                    <td>
                        <div class="admin-user-meta">
                            <span class="admin-user-name">
                                <?= htmlspecialchars($u['nome']) ?>
                                <?php if (!empty($u['is_admin'])): ?>
                                    <span class="admin-badge admin-badge-amber" style="font-size:9px;padding:1px 6px;margin-left:4px">Admin</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </td>
                    <td><span class="admin-user-email"><?= htmlspecialchars($u['email']) ?></span></td>
                    <td><span class="admin-badge admin-badge-<?= $planoBadge ?>"><?= htmlspecialchars(ucfirst($plano)) ?></span></td>
                    <td><span class="admin-badge admin-badge-<?= $statusBadge ?>"><?= htmlspecialchars($u['plano_status'] ?? 'ativo') ?></span></td>
                    <td style="font-size:12px;color:var(--admin-text-soft);white-space:nowrap"><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="admin-pagination">
            <div class="admin-pagination-info"><?= count($usuarios) ?> de <?= $total ?> usuários</div>
            <div class="admin-pagination-controls">
                <?php if ($page > 1): ?>
                    <a class="admin-pagination-btn" href="?action=admin_usuarios&q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">‹</a>
                <?php endif; ?>
                <span class="admin-pagination-btn admin-pagination-btn-active"><?= $page ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="admin-pagination-btn" href="?action=admin_usuarios&q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../partials/admin_layout_end.php'; ?>

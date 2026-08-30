<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin — Clientes</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/../css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/../partials/admin_layout_start.php'; ?>
<form method="GET" class="filter-row" style="margin-bottom:16px">
    <input type="hidden" name="action" value="admin_usuarios">
    <div class="search-input" style="flex:1"><?= render_icon('search',14) ?><input type="text" name="q" placeholder="Buscar por nome ou e-mail" value="<?= htmlspecialchars($_GET['q']??'') ?>"></div>
    <button class="btn btn-primary btn-sm" style="background:#0f172a">Pesquisar</button>
</form>
<section class="panel"><header class="panel-header"><div class="panel-title">Clientes (<?= (int)$total ?>)</div><div style="font-size:12px;color:#64748b">Ordenado por cadastro recente</div></header>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Nome</th><th>E-mail</th><th>Plano</th><th>Status</th><th>Cadastro</th></tr></thead><tbody>
<?php foreach(($usuarios??[]) as $u): ?>
<tr><td style="font-weight:600"><?= htmlspecialchars($u['nome']) ?></td><td style="font-size:12px"><?= htmlspecialchars($u['email']) ?></td><td><span class="badge badge-info" style="font-size:11px"><?= htmlspecialchars($u['plano']) ?></span></td><td><span class="badge <?= $u['plano_status']==='ativo'?'badge-success':'badge-warning' ?>" style="font-size:11px"><?= htmlspecialchars($u['plano_status']) ?></span> <?= $u['is_admin']?'<span class="badge badge-warning" style="font-size:10px">Admin</span>':'' ?></td><td style="font-size:12px;color:#64748b"><?= date('d/m/Y H:i',strtotime($u['created_at'])) ?></td></tr>
<?php endforeach; ?>
<?php if(empty($usuarios)): ?><tr><td colspan="5" class="empty-cell">Nenhum usuário encontrado</td></tr><?php endif; ?>
</tbody></table></div>
<div class="pagination"><div class="pagination-info"><?= count($usuarios) ?> de <?= $total ?> usuários</div><div class="pagination-controls">
<?php $page=(int)($_GET['page']??1); if($page>1): ?><a href="?action=admin_usuarios&q=<?= urlencode($_GET['q']??'') ?>&page=<?= $page-1 ?>" class="pagination-btn">‹</a><?php endif; ?><span class="pagination-btn is-active"><?= $page ?></span><a href="?action=admin_usuarios&q=<?= urlencode($_GET['q']??'') ?>&page=<?= $page+1 ?>" class="pagination-btn">›</a>
</div></div>
</section>
<?php include __DIR__.'/../partials/layout_end.php'; ?>
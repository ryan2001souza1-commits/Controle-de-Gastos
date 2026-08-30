<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin — Bugs</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/../css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/../partials/admin_layout_start.php'; ?>
<form method="GET" class="filter-row">
    <input type="hidden" name="action" value="admin_bugs">
    <div class="select-wrap" style="min-width:160px"><select name="status" onchange="this.form.submit()"><option value="">Todos os status</option><?php foreach(['novo','recebido','em_analise','em_desenvolvimento','resolvido','fechado','nao_reproduzido'] as $s): ?><option value="<?= $s ?>" <?= ($_GET['status']??'')===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?></select></div>
    <div class="search-input" style="flex:1"><?= render_icon('search',14) ?><input type="text" name="q" placeholder="Buscar por título, descrição ou e-mail" value="<?= htmlspecialchars($_GET['q']??'') ?>"></div>
    <button class="btn btn-primary btn-sm" style="background:#0f172a">Filtrar</button>
</form>
<section class="panel" style="margin-top:16px"><div class="table-wrap"><table class="data-table"><thead><tr><th>Título</th><th>Usuário</th><th>Categoria</th><th>Status</th><th>Data</th><th></th></tr></thead><tbody>
<?php foreach(($bugs??[]) as $b): ?>
<tr><td style="font-weight:600;max-width:280px"><div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($b['titulo']) ?></div><div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars(mb_substr($b['descricao'],0,60)) ?>...</div></td><td style="font-size:12px"><?= htmlspecialchars($b['usuario_nome']??'') ?><div style="font-size:11px;color:#64748b"><?= htmlspecialchars($b['usuario_email']??'') ?></div></td><td><span class="badge badge-info" style="font-size:11px"><?= htmlspecialchars($b['categoria']) ?></span></td><td><span class="badge <?= in_array($b['status'],['resolvido','fechado'])?'badge-success':(in_array($b['status'],['em_analise','em_desenvolvimento'])?'badge-warning':'badge-neutral') ?>" style="font-size:11px"><?= htmlspecialchars($b['status']) ?></span></td><td style="font-size:12px;color:#64748b"><?= date('d/m/Y H:i',strtotime($b['created_at'])) ?></td><td><a href="/index.php?action=admin_bug_detail&id=<?= (int)$b['id'] ?>" class="btn btn-ghost btn-xs">Abrir</a></td></tr>
<?php endforeach; ?>
<?php if(empty($bugs)): ?><tr><td colspan="6" class="empty-cell">Nenhum relato</td></tr><?php endif; ?>
</tbody></table></div>
<div class="pagination"><div class="pagination-info"><?= count($bugs) ?> de <?= $total ?> relatos</div></div>
</section>
<?php include __DIR__.'/../partials/layout_end.php'; ?>
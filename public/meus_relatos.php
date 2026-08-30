<?php $activeMenu='meus_relatos'; $showPeriodPicker=false; ?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Meus relatos</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/partials/layout_start.php'; ?>
<?php if(isset($_GET['success'])): ?><div class="alert alert-success">Relato enviado com sucesso</div><?php endif; ?>
<section class="panel"><header class="panel-header"><div><div class="panel-title">Meus relatos</div><div class="panel-subtitle">Acompanhe o status e respostas do suporte</div></div><a href="/index.php?action=reportar" class="btn btn-primary btn-sm" style="background:#059669">Reportar problema</a></header>
<?php if(empty($bugs)): ?><div class="panel-body"><div class="empty-cell">Você ainda não enviou relatos. Clique em Reportar problema para começar.</div></div>
<?php else: ?>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Título</th><th>Categoria</th><th>Status</th><th>Data</th><th>Atualização</th></tr></thead><tbody>
<?php foreach($bugs as $b): ?>
<tr>
<td style="max-width:300px"><div style="font-weight:600"><?= htmlspecialchars($b['titulo']) ?></div><div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px"><?= htmlspecialchars(mb_substr($b['descricao'],0,80)) ?></div><?php if(!empty($b['resposta_admin'])): ?><div style="margin-top:6px;background:#ecfdf5;padding:8px;border-radius:6px;font-size:12px"><b style="color:#065f46">Resposta:</b> <?= htmlspecialchars($b['resposta_admin']) ?></div><?php endif; ?></td>
<td><span class="badge badge-info" style="font-size:11px"><?= htmlspecialchars($b['categoria']) ?></span></td>
<td><span class="badge <?= $b['status']==='resolvido'||$b['status']==='fechado'?'badge-success':($b['status']==='em_analise'?'badge-warning':'badge-neutral') ?>" style="font-size:11px"><?= htmlspecialchars($b['status']) ?></span></td>
<td style="font-size:12px;color:#64748b"><?= date('d/m/Y H:i',strtotime($b['created_at'])) ?></td>
<td style="font-size:12px;color:#64748b"><?= date('d/m/Y H:i',strtotime($b['updated_at'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>
<?php include __DIR__.'/partials/layout_end.php'; ?>
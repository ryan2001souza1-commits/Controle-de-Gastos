<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Bug #<?= (int)($bug['id']??0) ?></title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/../css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/../partials/admin_layout_start.php'; ?>
<?php if(isset($_GET['success'])): ?><div class="alert alert-success">Atualizado com sucesso</div><?php endif; ?>
<div style="display:grid;grid-template-columns:1.6fr .9fr;gap:16px">
<section class="panel"><header class="panel-header"><div class="panel-title">#<?= (int)$bug['id'] ?> — <?= htmlspecialchars($bug['titulo']) ?></div><span class="badge <?= $bug['status']==='resolvido'?'badge-success':'badge-warning' ?>"><?= htmlspecialchars($bug['status']) ?></span></header>
<div class="panel-body" style="display:flex;flex-direction:column;gap:14px">
    <div><div style="font-size:11px;font-weight:600;color:#64748b;letter-spacing:.06em;text-transform:uppercase">Descrição</div><div style="font-size:13px;color:#0f172a;white-space:pre-wrap;margin-top:4px"><?= htmlspecialchars($bug['descricao']) ?></div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><div style="font-size:11px;color:#64748b">Usuário</div><div style="font-weight:600"><?= htmlspecialchars($bug['usuario_nome']) ?> <span style="font-weight:400;color:#64748b"><?= htmlspecialchars($bug['usuario_email']) ?></span></div></div>
        <div><div style="font-size:11px;color:#64748b">Data</div><div style="font-size:13px"><?= date('d/m/Y H:i',strtotime($bug['created_at'])) ?></div></div>
        <div><div style="font-size:11px;color:#64748b">Página</div><div style="font-size:13px"><?= htmlspecialchars($bug['pagina']??'—') ?></div><div style="font-size:11px;color:#94a3b8;word-break:break-all"><?= htmlspecialchars($bug['url']??'') ?></div></div>
        <div><div style="font-size:11px;color:#64748b">Categoria / Prioridade</div><div><span class="badge badge-info" style="font-size:11px"><?= htmlspecialchars($bug['categoria']) ?></span> <span class="badge badge-neutral" style="font-size:11px"><?= htmlspecialchars($bug['prioridade']) ?></span></div></div>
        <div><div style="font-size:11px;color:#64748b">Navegador</div><div style="font-size:12px;word-break:break-all"><?= htmlspecialchars($bug['navegador']??'—') ?></div></div>
        <div><div style="font-size:11px;color:#64748b">SO</div><div style="font-size:12px"><?= htmlspecialchars($bug['sistema_operacional']??'—') ?></div></div>
    </div>
    <?php if(!empty($bug['screenshot'])): ?><div><div style="font-size:11px;color:#64748b">Screenshot</div><a href="<?= htmlspecialchars($bug['screenshot']) ?>" target="_blank"><img src="<?= htmlspecialchars($bug['screenshot']) ?>" alt="screenshot" style="max-width:100%;border:1px solid #e2e8f0;border-radius:8px;margin-top:6px;max-height:400px"></a></div><?php endif; ?>
    <?php if(!empty($bug['resposta_admin'])): ?><div style="background:#ecfdf5;padding:12px;border-radius:8px"><div style="font-size:11px;font-weight:600;color:#065f46">Resposta ao cliente</div><div style="font-size:13px;color:#0f172a;white-space:pre-wrap;margin-top:4px"><?= htmlspecialchars($bug['resposta_admin']) ?></div></div><?php endif; ?>
</div></section>

<section class="panel"><header class="panel-header"><div class="panel-title">Atualizar status</div></header><div class="panel-body-sm">
    <form method="POST" action="/index.php?action=admin_bug_update">
        <input type="hidden" name="id" value="<?= (int)$bug['id'] ?>">
        <div class="form-stack">
            <div class="form-group"><label>Status</label><div class="select-wrap"><select name="status"><?php foreach(['novo','recebido','em_analise','em_desenvolvimento','resolvido','fechado','nao_reproduzido'] as $s): ?><option value="<?= $s ?>" <?= $bug['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div></div>
            <div class="form-group"><label>Resposta ao cliente (visível em Meus relatos)</label><textarea name="resposta_admin" rows="3" placeholder="Ex: Problema corrigido na versão X"><?= htmlspecialchars($bug['resposta_admin']??'') ?></textarea></div>
            <div class="form-group"><label>Observação interna</label><textarea name="observacao_interna" rows="2" placeholder="Notas internas (não visível ao cliente)"><?= htmlspecialchars($bug['observacao_interna']??'') ?></textarea></div>
            <button class="btn btn-primary" style="background:#059669;width:100%">Salvar atualização</button>
        </div>
    </form>
    <div style="margin-top:12px;font-size:11px;color:#64748b">Histórico: criado em <?= date('d/m/Y H:i',strtotime($bug['created_at'])) ?> · atualizado em <?= date('d/m/Y H:i',strtotime($bug['updated_at'])) ?></div>
</div></section>
</div>
<?php include __DIR__.'/../partials/layout_end.php'; ?>
<?php $activeMenu='reportar'; $showPeriodPicker=false; ?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Reportar problema</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/partials/layout_start.php'; ?>
<?php if(isset($_GET['error'])): ?><div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
<section class="panel" style="max-width:720px"><header class="panel-header"><div><div class="panel-title">Reportar problema</div><div class="panel-subtitle">Descreva o que aconteceu, o que esperava e como reproduzir</div></div></header>
<div class="panel-body-sm">
<form method="POST" action="/index.php?action=reportar_create" enctype="multipart/form-data">
<div class="form-stack">
    <div class="form-group"><label>Título *</label><input type="text" name="titulo" required maxlength="150" placeholder="Ex: Novo lançamento não abre"></div>
    <div class="form-row"><div class="form-group"><label>Categoria *</label><div class="select-wrap"><select name="categoria" required><option value="bug">Bug</option><option value="visual">Erro visual</option><option value="login">Problema de login</option><option value="lancamento">Problema de lançamento</option><option value="orcamento">Problema de orçamento</option><option value="metas">Problema de metas</option><option value="relatorio">Problema de relatório</option><option value="outro">Outro</option></select></div></div><div class="form-group"><label>Prioridade</label><div class="select-wrap"><select name="prioridade"><option value="media" selected>Média</option><option value="baixa">Baixa</option><option value="alta">Alta</option></select></div></div></div>
    <div class="form-group"><label>Página onde aconteceu</label><input type="text" name="pagina" placeholder="Ex: /index.php?action=lancamentos" value="<?= htmlspecialchars($_GET['pagina']??'') ?>"></div>
    <div class="form-group"><label>Descrição *</label><textarea name="descricao" rows="4" required placeholder="O que aconteceu? O que esperava? Como reproduzir?"></textarea></div>
    <input type="hidden" name="url" value="<?= htmlspecialchars($_SERVER['HTTP_REFERER']??'') ?>">
    <div class="form-group"><label>Screenshot (opcional, PNG/JPG/WebP, máx 2MB)</label><input type="file" name="screenshot" accept=".png,.jpg,.jpeg,.webp"></div>
    <div class="form-actions"><button class="btn btn-primary" style="background:#059669">Enviar relato</button><a href="/index.php?action=meus_relatos" class="btn btn-ghost">Meus relatos</a></div>
</div>
</form>
</div></section>
<?php include __DIR__.'/partials/layout_end.php'; ?>
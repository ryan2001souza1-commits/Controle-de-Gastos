<?php
$pageTitle='Metas';$pageSubtitle='Acompanhe seus objetivos e conquiste seus sonhos.';$userName=$userName??($_SESSION['user_name']??'Usuário');$userInitials=strtoupper(substr($userName,0,1));$activeMenu='metas';
$goals=$data['goals']??[];$errors=['invalid_data'=>'Dados inválidos.','invalid_date'=>'Data inválida.','not_found'=>'Meta não encontrada.','duplicate_name'=>'Já existe meta com esse nome.'];$successMsgs=['created'=>'Meta criada!','updated'=>'Meta atualizada!','deleted'=>'Meta excluída!'];
$totalObj=array_sum(array_column($goals,'target'));$totalSaved=array_sum(array_column($goals,'saved'));$completed=count(array_filter($goals,fn($g)=>($g['status']??'')==='completed'));$inProgress=count($goals)-$completed;
$progressPct=$totalObj>0?min(100,round($totalSaved/$totalObj*100)):0;
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Metas - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>
<?php if(isset($_GET['success'])&&isset($successMsgs[$_GET['success']])): ?><div class="alert alert-success" role="status"><?= render_icon('check',13) ?><span><?= htmlspecialchars($successMsgs[$_GET['success']]) ?></span></div><?php endif; ?>
<?php if(isset($_GET['error'])&&isset($errors[$_GET['error']])): ?><div class="alert alert-error" role="alert"><?= render_icon('info',13) ?><span><?= htmlspecialchars($errors[$_GET['error']]) ?></span></div><?php endif; ?>

<section class="metric-strip">
    <article class="metric-card"><div class="metric-card-icon" style="background:#ecfdf5;color:#10b981"><?= render_icon('target',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Total de metas</div><div class="metric-card-value">6</div><div class="text-xs" style="color:#64748b"><?= $completed ?> concluídas</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#eff6ff;color:#3b82f6"><?= render_icon('pie',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Em andamento</div><div class="metric-card-value">4</div><div class="text-xs" style="color:#64748b">67% do total</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#f5f3ff;color:#7c3aed"><?= render_icon('check',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Concluídas</div><div class="metric-card-value">2</div><div class="text-xs" style="color:#64748b">33% do total</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#fffbeb;color:#d97706"><?= render_icon('wallet',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Total poupado</div><div class="metric-card-value" style="color:#0f172a">R$ <?= number_format($totalSaved>0?$totalSaved:12540,2,',','.') ?></div><div class="text-xs" style="color:#64748b">de R$ <?= number_format($totalObj>0?$totalObj:28600,2,',','.') ?></div></div></article>
</section>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">
    <section class="panel">
        <header class="panel-header"><div class="panel-title">Minhas metas</div><a href="#" class="btn btn-ghost btn-xs">Mais recentes ▾</a></header>
        <div style="display:flex;gap:16px;padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:600"><a href="#" style="color:#0f172a;border-bottom:2px solid #10b981;padding-bottom:6px">Todas</a><a href="#" style="color:#64748b">Em andamento</a><a href="#" style="color:#64748b">Concluídas</a></div>
        <div style="display:flex;flex-direction:column">
        <?php if(empty($goals)): ?>
            <div class="empty-cell">Nenhuma meta cadastrada.</div>
        <?php else: foreach(array_slice($goals,0,6) as $g): $pct=(int)($g['percentage']??0); $target=(float)($g['target']??0); $saved=(float)($g['saved']??0); $remain=max(0,$target-$saved); $isDone=$pct>=100; $barColor=$isDone?'#10b981':($pct>=60?'#10b981':($pct>=40?'#f59e0b':'#ef4444')); $iconBg=$isDone?'#ecfdf5':($pct>=60?'#eff6ff':'#fffbeb'); $iconColor=$isDone?'#10b981':($pct>=60?'#3b82f6':'#f59e0b'); ?>
            <div style="display:flex;gap:14px;align-items:center;padding:14px 16px;border-bottom:1px solid #f1f5f9">
                <div style="width:44px;height:44px;border-radius:10px;background:<?= $iconBg ?>;color:<?= $iconColor ?>;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><?= render_icon('target',20) ?></div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;justify-content:space-between;gap:12px"><div><div style="font-weight:600;color:#0f172a;font-size:14px"><?= htmlspecialchars($g['name']) ?></div><div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($g['description']??'Sem descrição') ?></div></div><div style="text-align:right"><div style="font-weight:600;color:#0f172a;font-size:13px">R$ <?= number_format($saved,2,',','.') ?> / R$ <?= number_format($target,2,',','.') ?></div><div style="font-size:11px;color:#64748b"><?= $isDone?'Meta concluída!':'Faltam R$ '.number_format($remain,2,',','.') ?></div></div></div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:8px"><div class="progress-bar" style="flex:1;height:6px"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></div><span style="font-size:11px;font-weight:600;color:#334155"><?= $pct ?>%</span><span style="font-size:11px;color:#64748b"><?= isset($g['deadline'])?date('d/m/Y',strtotime($g['deadline'])):'—' ?></span></div>
                </div>
                <button class="row-action-btn" style="flex-shrink:0">⋮</button>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </section>

    <div style="display:flex;flex-direction:column;gap:16px">
        <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:14px">Progresso geral</div></header><div class="panel-body" style="text-align:center">
            <div style="position:relative;width:140px;height:140px;margin:0 auto"><svg width="140" height="140" viewBox="0 0 120 120" style="transform:rotate(-90deg)"><circle cx="60" cy="60" r="54" fill="none" stroke="#e2e8f0" stroke-width="12"/><circle cx="60" cy="60" r="54" fill="none" stroke="#10b981" stroke-width="12" stroke-linecap="round" stroke-dasharray="<?= $progressPct/100*339 ?> 339"/></svg><div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center"><div style="font-size:24px;font-weight:700;color:#0f172a"><?= $progressPct ?:44 ?>%</div><div style="font-size:11px;color:#64748b">do total</div></div></div>
            <div style="margin-top:12px"><div style="color:#10b981;font-weight:700">R$ <?= number_format($totalSaved>0?$totalSaved:12540,2,',','.') ?></div><div style="font-size:11px;color:#64748b">de R$ <?= number_format($totalObj>0?$totalObj:28600,2,',','.') ?></div></div>
        </div></section>

        <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:14px">Dicas para suas metas</div></header><div class="panel-body" style="display:flex;flex-direction:column;gap:14px">
            <div style="display:flex;gap:10px"><div style="width:32px;height:32px;border-radius:8px;background:#f5f3ff;color:#7c3aed;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><?= render_icon('target',14) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:12px">Defina metas específicas</div><div style="font-size:11px;color:#64748b">Seja claro sobre o que deseja alcançar.</div></div></div>
            <div style="display:flex;gap:10px"><div style="width:32px;height:32px;border-radius:8px;background:#ecfdf5;color:#10b981;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><?= render_icon('calendar',14) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:12px">Estabeleça prazos realistas</div><div style="font-size:11px;color:#64748b">Prazos ajudam a manter o foco.</div></div></div>
        </div></section>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
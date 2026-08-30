<?php
$pageTitle='Orçamentos';$pageSubtitle='Acompanhe seus limites de gastos por categoria e mantenha suas finanças sob controle.';$userName=$userName??($_SESSION['user_name']??'Usuário');$userInitials=strtoupper(substr($userName,0,1));$activeMenu='orcamentos';
$budgets=$budgetData['budgets']??[];$totals=$budgetData['totals']??['limit'=>0,'spent'=>0,'remaining'=>0,'percentage'=>0];$counts=$budgetData['counts']??['over'=>0,'warn'=>0,'ok'=>0];
$errors=['invalid_data'=>'Dados inválidos.','invalid_category'=>'Categoria inválida.','invalid_date'=>'Data inválida.','not_found'=>'Não encontrado.','invalid_id'=>'ID inválido.'];$successMsgs=['saved'=>'Orçamento salvo!','deleted'=>'Orçamento removido!'];
$meses=['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];$month=(int)($month??date('n'));$year=(int)($year??date('Y'));
$palette=['#10b981','#3b82f6','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#ef4444'];
// para donut resumo
$utilPct=$totals['percentage']??0;
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Orçamentos - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>
<?php if(isset($_GET['success'])&&isset($successMsgs[$_GET['success']])): ?><div class="alert alert-success" role="status"><?= render_icon('check',13) ?><span><?= htmlspecialchars($successMsgs[$_GET['success']]) ?></span></div><?php endif; ?>
<?php if(isset($_GET['error'])&&isset($errors[$_GET['error']])): ?><div class="alert alert-error" role="alert"><?= render_icon('info',13) ?><span><?= htmlspecialchars($errors[$_GET['error']]) ?></span></div><?php endif; ?>

<section class="metric-strip">
    <article class="metric-card"><div class="metric-card-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('wallet',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Orçamento total</div><div class="metric-card-value" style="color:#059669">R$ <?= number_format($totals['limit'],2,',','.') ?></div><div class="text-xs" style="color:#64748b">Definido para o período</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#f5f3ff;color:#7c3aed"><?= render_icon('pie',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Utilizado</div><div class="metric-card-value">R$ <?= number_format($totals['spent'],2,',','.') ?></div><div class="text-xs" style="color:#64748b"><?= $totals['percentage'] ?>% do total</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#fffbeb;color:#d97706"><?= render_icon('credit-card',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Disponível</div><div class="metric-card-value" style="color:#059669">R$ <?= number_format($totals['remaining'],2,',','.') ?></div><div class="text-xs" style="color:#64748b">47% do total</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#eff6ff;color:#2563eb"><?= render_icon('chart',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Maior gasto</div><div class="metric-card-value">Moradia</div><div class="text-xs" style="color:#64748b">R$ 1.650,00 (38,9%)</div></div></article>
</section>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">
    <section class="panel">
        <header class="panel-header"><div class="panel-title">Orçamento por categoria</div></header>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Categoria ↕</th><th class="th-numeric">Orçamento</th><th class="th-numeric">Utilizado ↕</th><th>% utilizado ↕</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if(empty($budgets)): ?><tr><td colspan="6" class="empty-cell">Nenhum orçamento.</td></tr>
            <?php else: foreach($budgets as $i=>$b): $pct=(float)($b['percentage']??0); $status=$b['status']??'ok'; $col=$status==='over'?'#ef4444':($status==='warn'?'#f59e0b':'#10b981'); $badge=$status==='over'?'badge-danger':($status==='warn'?'badge-warning':'badge-success'); $label=$status==='over'?'Excedido':($status==='warn'?'Atenção':'Normal'); ?>
                <tr>
                    <td><div style="display:flex;gap:10px;align-items:center"><div class="cat-icon" style="background:<?= $palette[$i%count($palette)] ?>;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:#fff"><?= render_icon('home',14) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:13px"><?= htmlspecialchars($b['category_name']) ?></div><div style="font-size:11px;color:#94a3b8">Aluguel, condomínio...</div></div></div></td>
                    <td class="td-numeric" style="font-weight:600">R$ <?= number_format($b['limit_amount'],2,',','.') ?></td>
                    <td class="td-numeric" style="font-weight:600;color:<?= $pct>=80?'#dc2626':'#059669' ?>">R$ <?= number_format($b['spent_amount'],2,',','.') ?></td>
                    <td><div style="display:flex;align-items:center;gap:8px"><span style="font-size:12px;font-weight:700;min-width:28px"><?= $pct ?>%</span><div class="progress-bar" style="width:90px;height:6px"><div class="progress-fill" style="width:<?= min(100,$pct) ?>%;background:<?= $col ?>"></div></div></div></td>
                    <td><span class="badge <?= $badge ?>" style="font-size:11px"><?= $label ?></span></td>
                    <td><a href="#" style="color:#94a3b8">›</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
        <div style="padding:12px 16px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#64748b"><span>Mostrando 1 a <?= count($budgets) ?> de <?= count($budgets) ?> categorias</span><span><span style="display:inline-block;width:8px;height:8px;background:#10b981;border-radius:50%"></span> Normal (até 70%) <span style="display:inline-block;width:8px;height:8px;background:#f59e0b;border-radius:50%;margin-left:8px"></span> Atenção <span style="display:inline-block;width:8px;height:8px;background:#ef4444;border-radius:50%;margin-left:8px"></span> Excedido</span></div>
    </section>

    <div style="display:flex;flex-direction:column;gap:16px">
        <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:14px">Resumo do período</div></header><div class="panel-body" style="text-align:center">
            <div style="position:relative;width:140px;height:140px;margin:0 auto"><svg width="140" height="140" viewBox="0 0 120 120" style="transform:rotate(-90deg)"><circle cx="60" cy="60" r="54" fill="none" stroke="#e2e8f0" stroke-width="12"/><circle cx="60" cy="60" r="54" fill="none" stroke="#10b981" stroke-width="12" stroke-linecap="round" stroke-dasharray="<?= $utilPct/100*339 ?> 339"/></svg><div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center"><div style="font-size:22px;font-weight:700;color:#0f172a"><?= $utilPct ?>%</div><div style="font-size:11px;color:#64748b">Utilizado</div></div></div>
            <div style="margin-top:12px;display:flex;flex-direction:column;gap:6px;font-size:12px;text-align:left">
                <div style="display:flex;justify-content:space-between"><span><span style="display:inline-block;width:8px;height:8px;background:#10b981;border-radius:50%"></span> Utilizado</span><span style="font-weight:600">R$ <?= number_format($totals['spent'],2,',','.') ?></span></div>
                <div style="display:flex;justify-content:space-between"><span><span style="display:inline-block;width:8px;height:8px;background:#f59e0b;border-radius:50%"></span> Disponível</span><span style="font-weight:600">R$ <?= number_format($totals['remaining'],2,',','.') ?></span></div>
                <div style="display:flex;justify-content:space-between"><span><span style="display:inline-block;width:8px;height:8px;background:#3b82f6;border-radius:50%"></span> Total</span><span style="font-weight:600">R$ <?= number_format($totals['limit'],2,',','.') ?></span></div>
            </div>
        </div></section>

        <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:14px">Alertas</div><a href="#" style="font-size:12px;color:#10b981;font-weight:600">Ver todos</a></header><div class="panel-body" style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;gap:10px;padding:10px;background:#fef2f2;border-radius:8px"><div style="color:#ef4444"><?= render_icon('alert',16) ?></div><div style="font-size:12px"><div style="font-weight:600;color:#0f172a">Você excedeu o orçamento da categoria Outros em R$ 84,10</div><a href="#" style="color:#10b981;font-size:11px">Ver detalhes</a></div></div>
            <div style="display:flex;gap:10px;padding:10px;background:#fffbeb;border-radius:8px"><div style="color:#f59e0b"><?= render_icon('wallet',16) ?></div><div style="font-size:12px"><div style="font-weight:600;color:#0f172a">A categoria Moradia está próxima do limite (82% utilizado)</div><a href="#" style="color:#10b981;font-size:11px">Ver detalhes</a></div></div>
        </div></section>

        <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:14px">Dicas para o período</div></header><div class="panel-body"><div style="background:#ecfdf5;padding:12px;border-radius:8px;font-size:12px;color:#065f46"><div style="display:flex;gap:8px"><span><?= render_icon('info',14) ?></span><span>Você ainda tem R$ <?= number_format($totals['remaining'],2,',','.') ?> disponível para gastar até 31/05/2025.</span></div><a href="#" class="btn btn-ghost btn-xs" style="margin-top:8px">Ver dicas</a></div><a href="#" class="btn btn-primary" style="background:#059669;width:100%;margin-top:12px"><?= render_icon('plus',12) ?> Novo orçamento</a></div></section>
    </div>
</div>

<section class="panel" style="margin-top:16px"><header class="panel-header"><div class="panel-title" style="font-size:14px">Insights do período</div></header><div class="panel-body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
    <div style="background:#ecfdf5;padding:14px;border-radius:10px;display:flex;gap:10px"><div style="color:#10b981"><?= render_icon('trending-up',18) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:13px">Você está indo bem!</div><div style="font-size:11px;color:#475569">5 categorias dentro do orçamento</div></div></div>
    <div style="background:#fffbeb;padding:14px;border-radius:10px;display:flex;gap:10px"><div style="color:#f59e0b"><?= render_icon('wallet',18) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:13px">Fique atento</div><div style="font-size:11px;color:#475569">2 categorias próximas do limite</div></div></div>
    <div style="background:#fef2f2;padding:14px;border-radius:10px;display:flex;gap:10px"><div style="color:#ef4444"><?= render_icon('trending-down',18) ?></div><div><div style="font-weight:600;color:#0f172a;font-size:13px">Excedeu o orçamento</div><div style="font-size:11px;color:#475569">1 categoria ultrapassou o limite</div></div></div>
</div></section>

<?php include __DIR__ . '/partials/layout_end.php'; ?>
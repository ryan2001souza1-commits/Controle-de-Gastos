<?php
$pageTitle='Relatórios';$pageSubtitle='Analise seus dados financeiros e tome decisões melhores.';$userName=$userName??($_SESSION['user_name']??'Usuário');$userInitials=strtoupper(substr($userName,0,1));$activeMenu='relatorios';
$report=$report??[];$startDate=$startDate??date('Y-m-01');$endDate=$endDate??date('Y-m-t');$filterType=$filterType??'';$pagePeriodFrom=date('d/m/Y',strtotime($startDate));$pagePeriodTo=date('d/m/Y',strtotime($endDate));
$totalIncomes=(float)($report['total_incomes']??0);$totalExpenses=(float)($report['total_expenses']??0);$balance=$totalIncomes-$totalExpenses;$txCount=(int)($report['transactions_count']??0);$economyPct=$totalIncomes>0?round(($totalIncomes-$totalExpenses)/$totalIncomes*100,1):0;$transactions=$transactions??[];
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Relatórios - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>
<?php if(($_GET['error'] ?? '') === 'upgrade'): ?><div class="alert alert-error" role="alert"><?= render_icon('info',13) ?><span>Relatórios disponíveis no seu plano.</span></div><?php endif; ?>

<section class="metric-strip">
    <article class="metric-card"><div class="metric-card-icon" style="background:#ecfdf5;color:#10b981"><?= render_icon('arrow-up',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Receitas</div><div class="metric-card-value" style="color:#059669">R$ <?= number_format($totalIncomes,2,',','.') ?></div><div class="text-xs" style="color:#64748b"><?= $txCount ?> lançamentos no período</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#fef2f2;color:#ef4444"><?= render_icon('arrow-down',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Despesas</div><div class="metric-card-value" style="color:#dc2626">R$ <?= number_format($totalExpenses,2,',','.') ?></div><div class="text-xs" style="color:#64748b"><?= (int)($report['expense_count']??0) ?> despesas</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#eff6ff;color:#3b82f6"><?= render_icon('wallet',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Saldo</div><div class="metric-card-value" style="color:<?= $balance>=0?'#059669':'#dc2626' ?>">R$ <?= number_format($balance,2,',','.') ?></div><div class="text-xs" style="color:#64748b"><?= $balance>=0?'Positivo':'Negativo' ?> no período</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#fffbeb;color:#f59e0b"><?= render_icon('percent',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Economia</div><div class="metric-card-value" style="color:#d97706"><?= $economyPct ?>%</div><div class="text-xs" style="color:#64748b">da receita</div></div></article>
</section>

<div style="display:flex;gap:16px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:0;font-size:13px;font-weight:600">
    <a href="#" style="color:#0f172a;border-bottom:2px solid #10b981;padding:10px 4px">Visão geral</a><a href="#" style="color:#64748b;padding:10px 4px">Receitas</a><a href="#" style="color:#64748b;padding:10px 4px">Despesas</a><a href="#" style="color:#64748b;padding:10px 4px">Categorias</a><a href="#" style="color:#64748b;padding:10px 4px">Comparativos</a>
</div>

<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:16px;margin-bottom:16px">
    <section class="chart-card">
        <header class="chart-card-head"><div class="chart-card-title">Evolução financeira</div><div class="select-wrap" style="width:auto"><select><option>Gráfico de linhas</option></select></div></header>
        <div class="chart-legend" style="margin-bottom:12px"><span class="legend-item"><span class="legend-swatch" style="background:#10b981"></span>Receitas</span><span class="legend-item"><span class="legend-swatch" style="background:#ef4444"></span>Despesas</span><span class="legend-item"><span class="legend-swatch" style="background:#3b82f6"></span>Saldo</span></div>
        <div class="chart-wrap" style="min-height:260px"><canvas id="chart-financial-flow"></canvas></div>
        <div class="chart-empty" id="chart-flow-empty">Sem dados.</div>
    </section>
    <section class="chart-card">
        <header class="chart-card-head"><div class="chart-card-title">Despesas por categoria</div><div class="select-wrap" style="width:auto"><select><option>Por valor</option></select></div></header>
        <div class="dash-donut-wrap" style="grid-template-columns:140px 1fr">
            <div class="dash-donut" style="width:140px;height:140px"><canvas id="chart-expenses-by-category"></canvas><div class="dash-donut-center"><div class="dash-donut-center-label">Total</div><div class="dash-donut-center-value" style="font-size:15px">R$ <?= number_format($totalExpenses,2,',','.') ?></div></div></div>
            <ul class="dash-category-list" style="font-size:11px">
                <?php
                $realCats = $report['expenses_by_category_table'] ?? [];
                $cols=['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#94a3b8'];
                if (empty($realCats)): ?><li class="dash-category-item" style="font-size:11px"><span style="color:#94a3b8">Nenhuma despesa no período.</span></li>
                <?php else: foreach(array_slice($realCats,0,6) as $i=>$c): $pct = $totalExpenses>0 ? round(($c['total']/$totalExpenses)*100,1) : 0; ?><li class="dash-category-item" style="font-size:11px"><span class="dash-cat-dot" style="background:<?= $cols[$i % count($cols)] ?>"></span><span class="dash-cat-name"><?= htmlspecialchars($c['name']) ?></span><span class="dash-cat-value">R$ <?= number_format($c['total'],2,',','.') ?></span><span class="dash-cat-pct"><?= $pct ?>%</span></li><?php endforeach; endif; ?>
            </ul>
        </div>
    </section>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 340px;gap:16px;margin-bottom:16px">
    <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:13px">Resumo do período</div></header><div class="panel-body" style="padding:0"><table class="data-table" style="min-width:auto"><thead><tr><th>Descrição</th><th>Valor</th><th>% do total</th></tr></thead><tbody>
        <tr><td style="font-size:12px;font-weight:600">Receitas</td><td style="font-size:12px;font-weight:600;color:#059669">R$ <?= number_format($totalIncomes,2,',','.') ?></td><td style="font-size:11px;color:#059669">100%</td></tr>
        <tr><td style="font-size:12px">Despesas</td><td style="font-size:12px;color:#dc2626">R$ <?= number_format($totalExpenses,2,',','.') ?></td><td style="font-size:11px"><?= $totalIncomes>0 ? round($totalExpenses/$totalIncomes*100,1) : 0 ?>%</td></tr>
        <tr><td style="font-size:12px;font-weight:600">Saldo</td><td style="font-size:12px;color:#2563eb">R$ <?= number_format($balance,2,',','.') ?></td><td style="font-size:11px;color:#2563eb"><?= $totalIncomes>0 ? round(max(0,$balance)/$totalIncomes*100,1) : 0 ?>%</td></tr>
        <?php
            $maiorDesp = null;
            foreach ($transactions as $t) { if (($t['type']??'')==='despesa' && ($maiorDesp===null || (float)($t['amount']??0) > (float)($maiorDesp['amount']??0))) $maiorDesp = $t; }
            $daysInPeriod = max(1, (strtotime($endDate) - strtotime($startDate))/86400 + 1);
            $mediaDiaria = $daysInPeriod>0 ? $totalExpenses/$daysInPeriod : 0;
        ?>
        <tr><td style="font-size:11px;color:#64748b">Maior despesa</td><td colspan="2" style="font-size:11px"><?= $maiorDesp ? htmlspecialchars($maiorDesp['description']??'') . ' <span style="color:#dc2626">R$ '.number_format((float)($maiorDesp['amount']??0),2,',','.') .'</span>' : '—' ?></td></tr>
        <tr><td style="font-size:11px;color:#64748b">Média diária de gastos</td><td colspan="2" style="font-size:11px">R$ <?= number_format($mediaDiaria,2,',','.') ?></td></tr>
    </tbody></table></div></section>

    <section class="chart-card"><header class="chart-card-head"><div class="chart-card-title" style="font-size:13px">Despesas por dia da semana</div><div class="select-wrap" style="width:auto"><select><option>Por valor</option></select></div></header><div class="chart-wrap" style="min-height:180px"><canvas id="chart-weekday"></canvas></div></section>

    <section class="panel"><header class="panel-header"><div class="panel-title" style="font-size:13px">Últimos lançamentos</div><a href="/index.php?action=lancamentos" style="font-size:11px;color:#64748b">Ver todos</a></header><div style="display:flex;flex-direction:column">
        <?php
        $lastReal = array_slice($transactions, 0, 5);
        if (empty($lastReal)): ?><div style="padding:16px;font-size:12px;color:#94a3b8;text-align:center">Nenhum lançamento no período.</div>
        <?php else: foreach($lastReal as $r): $isInc = ($r['type']??'')==='receita'; $catName = $r['category_name'] ?? ($isInc ? 'Receita' : 'Despesa'); ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #f1f5f9"><div style="width:28px;height:28px;border-radius:50%;background:<?= $isInc?'#ecfdf5':'#fef2f2' ?>;color:<?= $isInc?'#10b981':'#ef4444' ?>;display:inline-flex;align-items:center;justify-content:center"><?= render_icon($isInc?'arrow-up':'arrow-down',12) ?></div><div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['description']??'') ?></div><div style="font-size:11px;color:#94a3b8"><?= $isInc?'Receita':'Despesa' ?> • <?= htmlspecialchars($catName ?: 'Sem categoria') ?></div></div><div style="text-align:right;flex-shrink:0"><div style="font-size:11px;color:#64748b"><?= isset($r['date']) ? date('d/m/Y', strtotime($r['date'])) : '—' ?></div><div style="font-size:12px;font-weight:600;color:<?= $isInc?'#059669':'#dc2626' ?>"><?= $isInc ? '' : '- ' ?>R$ <?= number_format((float)($r['amount']??0),2,',','.') ?></div></div></div>
        <?php endforeach; endif; ?>
    </div></section>
</div>

<div style="background:#f0f9ff;border:1px solid #dbeafe;border-radius:10px;padding:12px 16px;display:flex;gap:10px;align-items:center;font-size:11px;color:#1e40af"><span style="background:#dbeafe;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;color:#2563eb">ⓘ</span><span>Relatório gerado em <?= date('d/m/Y H:i') ?> — Período <?= htmlspecialchars($pagePeriodFrom) ?> a <?= htmlspecialchars($pagePeriodTo) ?> · <?= $txCount ?> lançamentos.</span></div>

<?php
$extraScripts='<script src="/assets/chart.min.js"></script>'."\n";
$extraScripts.='<script>window.DASHBOARD_CHART_DATA = '.json_encode($report['chart_data']??[], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE).';</script>'."\n";
$extraScripts.='<script src="/js/charts.js"></script>'."\n";
include __DIR__ . '/partials/layout_end.php'; ?>
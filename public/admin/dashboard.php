<?php
// expects $totalUsers, $activeUsers, $newWeek, $recentUsers, $bugStats, $planos
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin — Dashboard</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/../css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/../partials/admin_layout_start.php'; ?>
<section class="metric-strip">
    <article class="metric-card"><div class="metric-card-icon" style="background:#eff6ff;color:#2563eb"><?= render_icon('users',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Total de usuários</div><div class="metric-card-value"><?= (int)($totalUsers??0) ?></div><div class="text-xs" style="color:#64748b"><?= (int)($newWeek??0) ?> novos (7 dias)</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('target',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Usuários ativos</div><div class="metric-card-value"><?= (int)($activeUsers??0) ?></div><div class="text-xs" style="color:#64748b">Últimos 30 dias</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#fffbeb;color:#d97706"><?= render_icon('alert',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Bugs pendentes</div><div class="metric-card-value" style="color:#d97706"><?= (int)($bugStats['pendentes']??0) ?></div><div class="text-xs" style="color:#64748b">Novos + Recebidos</div></div></article>
    <article class="metric-card"><div class="metric-card-icon" style="background:#f5f3ff;color:#7c3aed"><?= render_icon('check',18) ?></div><div class="metric-card-body"><div class="metric-card-label">Bugs resolvidos</div><div class="metric-card-value" style="color:#059669"><?= (int)($bugStats['resolvidos']??0) ?></div><div class="text-xs" style="color:#64748b">de <?= (int)($bugStats['total']??0) ?> total</div></div></article>
</section>

<div style="display:grid;grid-template-columns:1.2fr .8fr;gap:16px">
    <section class="panel"><header class="panel-header"><div class="panel-title">Usuários recentes</div><a href="/index.php?action=admin_usuarios" class="btn btn-ghost btn-xs">Ver todos</a></header><div class="table-wrap"><table class="data-table"><thead><tr><th>Nome</th><th>E-mail</th><th>Plano</th><th>Data</th></tr></thead><tbody>
    <?php foreach(($recentUsers??[]) as $u): ?>
        <tr><td style="font-weight:600"><?= htmlspecialchars($u['nome']) ?> <?= $u['is_admin']?' <span class="badge badge-warning" style="font-size:10px">Admin</span>':'' ?></td><td style="font-size:12px;color:#475569"><?= htmlspecialchars($u['email']) ?></td><td><span class="badge badge-info" style="font-size:11px"><?= htmlspecialchars($u['plano']??'gratuito') ?></span></td><td style="font-size:12px;color:#64748b"><?= date('d/m/Y',strtotime($u['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if(empty($recentUsers)): ?><tr><td colspan="4" class="empty-cell">Nenhum usuário</td></tr><?php endif; ?>
    </tbody></table></div></section>

    <section class="panel"><header class="panel-header"><div class="panel-title">Planos</div><a href="/index.php?action=admin_planos" class="btn btn-ghost btn-xs">Ver planos</a></header><div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
        <?php foreach(($planos??[]) as $p): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px"><div><div style="font-weight:600"><?= htmlspecialchars($p['nome']) ?></div><div style="font-size:11px;color:#64748b"><?= htmlspecialchars($p['slug']) ?></div></div><div style="font-weight:700;color:#059669">R$ <?= number_format((float)$p['preco'],2,',','.') ?></div></div>
        <?php endforeach; ?>
        <div style="font-size:11px;color:#64748b;background:#f8fafc;padding:8px;border-radius:6px">Estrutura pronta para gateway futuro — sem cobrança ativa.</div>
    </div></section>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
    <section class="panel"><header class="panel-header"><div class="panel-title">Bugs por status</div></header><div class="panel-body" style="display:flex;gap:12px;flex-wrap:wrap">
        <span class="badge badge-warning">Pendentes: <?= (int)($bugStats['pendentes']??0) ?></span>
        <span class="badge badge-info">Em análise: <?= (int)($bugStats['em_analise']??0) ?></span>
        <span class="badge badge-success">Resolvidos: <?= (int)($bugStats['resolvidos']??0) ?></span>
        <span class="badge badge-neutral">Total: <?= (int)($bugStats['total']??0) ?></span>
    </div></section>
    <section class="panel"><div class="panel-body" style="font-size:12px;color:#475569">Dica: bugs são criados pelos clientes em <b>Reportar problema</b> e aparecem em <b>Relatos de bugs</b>. Alteração de status notifica o cliente em <b>Meus relatos</b>.</div></section>
</div>

<?php include __DIR__.'/../partials/layout_end.php'; ?>
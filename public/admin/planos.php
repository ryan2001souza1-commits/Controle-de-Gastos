<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin — Planos</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__.'/../css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__.'/../partials/admin_layout_start.php'; ?>
<section class="panel"><header class="panel-header"><div><div class="panel-title">Planos</div><div class="panel-subtitle">Estrutura preparada para assinaturas futuras — sem gateway conectado</div></div></header>
<div class="panel-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
<?php foreach(($planos??[]) as $p): ?>
    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:18px;background:#fff">
        <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-weight:700"><?= htmlspecialchars($p['nome']) ?></span><span class="badge badge-info"><?= htmlspecialchars($p['slug']) ?></span></div>
        <div style="font-size:22px;font-weight:700;margin:8px 0;color:#059669">R$ <?= number_format((float)$p['preco'],2,',','.') ?> <span style="font-size:12px;color:#64748b;font-weight:400">/mês</span></div>
        <div style="font-size:12px;color:#475569"><?= htmlspecialchars($p['descricao']??'') ?></div>
        <div style="margin-top:12px;display:flex;gap:8px"><span class="badge badge-neutral" style="font-size:11px">Status: ativo</span><span class="badge badge-neutral" style="font-size:11px">Renovação: mensal</span></div>
    </div>
<?php endforeach; ?>
</div>
<div style="margin-top:16px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:12px;font-size:12px;color:#475569">
    <b>Como usar futuramente:</b> coluna <code>usuarios.plano</code> (gratuito/pro/premium), <code>plano_status</code> (ativo/cancelado), <code>plano_inicio/fim</code>. Tabela <code>planos</code> já criada. Para ativar cobrança, conectar Stripe/Mercado Pago e atualizar esses campos via webhook — nenhum código de pagamento foi adicionado agora.
</div>
</section>
<?php include __DIR__.'/../partials/layout_end.php'; ?>
<?php
$planos = $planos ?? [];
$stats = $stats ?? [];
$planResources = [
    'gratuito' => ['Transações ilimitadas', 'Categorias padrão', 'Relatórios básicos', 'Dashboard simples'],
    'pro' => ['Tudo do Gratuito', 'Categorias ilimitadas', 'Metas financeiras', 'Orçamentos por categoria', 'Relatórios avançados', 'Exportação CSV'],
    'premium' => ['Tudo do Pro', 'Suporte prioritário', 'Backup automático', 'APIs para integrações', 'Relatórios ilimitados', 'Múltiplas moedas'],
];
$planLimits = [
    'gratuito' => ['transacoes' => 'Ilimitadas', 'categorias' => 'Padrão', 'metas' => '2', 'orcamentos' => '3'],
    'pro' => ['transacoes' => 'Ilimitadas', 'categorias' => 'Ilimitadas', 'metas' => '10', 'orcamentos' => '12'],
    'premium' => ['transacoes' => 'Ilimitadas', 'categorias' => 'Ilimitadas', 'metas' => 'Ilimitadas', 'orcamentos' => 'Ilimitados'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Planos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/admin-system.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/admin-system.css') ?: time() ?>">
</head>
<body>
<div class="admin-app-wrapper">
<?php
$pageTitle = 'Planos e assinaturas';
$pageSubtitle = 'Estrutura preparada para gateway de pagamento futuro';
$activeMenu = 'admin_planos';
include __DIR__ . '/../partials/admin_layout_start.php';
?>

<div class="admin-alert admin-alert-info" style="margin-bottom:4px">
    Nenhum gateway de pagamento está conectado. Para ativar cobranças, configure Stripe, Mercado Pago ou similar e conecte via webhook. As colunas <code>usuarios.plano</code>, <code>plano_status</code>, <code>plano_inicio</code> e <code>plano_fim</code> estão preparadas para receber atualizações automáticas via webhook de pagamento.
</div>

<section class="admin-card">
    <div class="admin-card-header">
        <div class="admin-card-title">Planos da plataforma</div>
    </div>
    <div class="admin-card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px">
        <?php foreach ($planos as $p):
            $slug = $p['slug'] ?? '';
            $count = (int)($stats[$slug] ?? 0);
            $resources = $planResources[$slug] ?? [];
            $limits = $planLimits[$slug] ?? [];
            $planColor = ['gratuito' => 'green', 'pro' => 'amber', 'premium' => 'purple'][$slug] ?? 'neutral';
            $planStatus = $p['status'] ?? 'ativo';
            $statusColor = $planStatus === 'ativo' ? 'green' : 'neutral';
            $preco = $p['preco'] ?? null;
        ?>
            <div class="admin-plan-card" style="border-top:3px solid var(--admin-<?= $slug === 'gratuito' ? 'primary' : ($slug === 'pro' ? 'accent' : 'info') ?>)">
                <div class="admin-plan-header">
                    <span class="admin-plan-name"><?= htmlspecialchars($p['nome']) ?></span>
                    <span class="admin-badge admin-badge-<?= $planColor ?>"><?= htmlspecialchars($slug) ?></span>
                    <span class="admin-badge admin-badge-<?= $statusColor ?>" style="margin-left:4px"><?= htmlspecialchars($planStatus) ?></span>
                </div>
                <div class="admin-plan-price">
                    <?php if ($preco === null): ?>
                        A definir
                    <?php elseif ((float)$preco > 0): ?>
                        R$ <?= number_format((float)$preco, 2, ',', '.') ?>
                        <span class="admin-plan-period">/mês</span>
                    <?php else: ?>
                        Grátis
                    <?php endif; ?>
                </div>
                <div class="admin-plan-desc"><?= htmlspecialchars($p['descricao'] ?? '') ?></div>

                <?php if (!empty($resources)): ?>
                    <div style="border-top:1px solid var(--admin-border);padding-top:12px;margin-top:4px">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:8px">Recursos</div>
                        <div style="display:flex;flex-direction:column;gap:6px">
                            <?php foreach ($resources as $r): ?>
                                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--admin-text)">
                                    <svg width="14" height="14" fill="none" stroke="#10b981" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    <?= htmlspecialchars($r) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="border-top:1px solid var(--admin-border);padding-top:12px;margin-top:4px">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--admin-text-soft);margin-bottom:8px">Limites</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px">
                        <div style="color:var(--admin-text-soft)">Transações:</div><div style="font-weight:600;color:var(--admin-text)"><?= htmlspecialchars($limits['transacoes'] ?? '—') ?></div>
                        <div style="color:var(--admin-text-soft)">Categorias:</div><div style="font-weight:600;color:var(--admin-text)"><?= htmlspecialchars($limits['categorias'] ?? '—') ?></div>
                        <div style="color:var(--admin-text-soft)">Metas:</div><div style="font-weight:600;color:var(--admin-text)"><?= htmlspecialchars($limits['metas'] ?? '—') ?></div>
                        <div style="color:var(--admin-text-soft)">Orçamentos:</div><div style="font-weight:600;color:var(--admin-text)"><?= htmlspecialchars($limits['orcamentos'] ?? '—') ?></div>
                    </div>
                </div>

                <div style="border-top:1px solid var(--admin-border);padding-top:12px;margin-top:4px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:13px;color:var(--admin-text-soft)">usuários neste plano:</span>
                        <span style="font-size:15px;font-weight:800;color:var(--admin-text)"><?= $count ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="admin-card">
    <div class="admin-card-header">
        <div class="admin-card-title">Preparação para cobrança</div>
    </div>
    <div class="admin-card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
            <div style="display:flex;gap:12px;align-items:flex-start">
                <div style="width:36px;height:36px;border-radius:8px;background:var(--admin-info-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--admin-info)"><?= render_icon('credit-card', 16) ?></div>
                <div><div style="font-weight:700;font-size:13.5px;margin-bottom:3px">Webhook de pagamento</div><div style="font-size:12px;color:var(--admin-text-soft)">Configure endpoint para receber notificações de pagamento do gateway.</div></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start">
                <div style="width:36px;height:36px;border-radius:8px;background:var(--admin-primary-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--admin-primary)"><?= render_icon('activity', 16) ?></div>
                <div><div style="font-weight:700;font-size:13.5px;margin-bottom:3px">Upgrade/Downgrade</div><div style="font-size:12px;color:var(--admin-text-soft)">Atualize plano_inicio/fim e plano_status conforme assinatura.</div></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start">
                <div style="width:36px;height:36px;border-radius:8px;background:var(--admin-accent-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--admin-accent)"><?= render_icon('x', 16) ?></div>
                <div><div style="font-weight:700;font-size:13.5px;margin-bottom:3px">Cancelamento</div><div style="font-size:12px;color:var(--admin-text-soft)">Ao cancelar, mude plano para 'gratuito' e plano_status para 'cancelado'.</div></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start">
                <div style="width:36px;height:36px;border-radius:8px;background:var(--admin-purple-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--admin-purple)"><?= render_icon('book', 16) ?></div>
                <div><div style="font-weight:700;font-size:13.5px;margin-bottom:3px">Histórico de pagamentos</div><div style="font-size:12px;color:var(--admin-text-soft)">Crie tabela payments futuramente para registrar transações de cobrança.</div></div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/admin_layout_end.php'; ?>

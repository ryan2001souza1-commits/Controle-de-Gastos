<?php
$totalUsers = (int)($totalUsers ?? 0);
$activeUsers = (int)($activeUsers ?? 0);
$newWeek = (int)($newWeek ?? 0);
$adminCount = (int)($adminCount ?? 0);
$freeUsers = (int)($freeUsers ?? 0);
$paidUsers = (int)($paidUsers ?? 0);
$activeSubscriptions = (int)($activeSubscriptions ?? 0);
$bugStats = $bugStats ?? [];
$feedbackStats = $feedbackStats ?? [];
$planos = $planos ?? [];
$recentUsers = $recentUsers ?? [];
$userGrowth = $userGrowth ?? [];
$planDistribution = $planDistribution ?? [];

$maxGrowth = 0;
foreach ($userGrowth as $g) $maxGrowth = max($maxGrowth, (int)$g['total']);
if ($maxGrowth === 0) $maxGrowth = 1;

$growthLabels = [];
$growthData = [];
foreach ($userGrowth as $g) {
    $ts = strtotime($g['week']);
    $growthLabels[] = date('d/m', $ts);
    $growthData[] = (int)$g['total'];
}

$planLabels = [];
$planData = [];
$planColorMap = [
    'gratuito' => '#10b981',
    'pro' => '#f59e0b',
    'premium' => '#3b82f6',
];
foreach ($planDistribution as $p) {
    $planLabels[] = ucfirst($p['plano']);
    $planData[] = (int)$p['total'];
}

$bugChartLabels = ['Novo', 'Recebido', 'Em análise', 'Em dev', 'Resolvido', 'Fechado'];
$bugChartData = [
    (int)($bugStats['novos'] ?? 0),
    (int)($bugStats['recebidos'] ?? 0),
    (int)($bugStats['em_analise'] ?? 0),
    (int)($bugStats['em_desenvolvimento'] ?? 0),
    (int)($bugStats['resolvidos'] ?? 0),
    (int)($bugStats['fechados'] ?? 0),
];
$bugChartColors = ['#3b82f6', '#06b6d4', '#f59e0b', '#8b5cf6', '#10b981', '#94a3b8'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/admin-system.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/admin-system.css') ?: time() ?>">
</head>
<body>
<div class="admin-app-wrapper">
<?php include __DIR__ . '/../partials/admin_layout_start.php'; ?>

<?php if (!empty($_GET['success'])): ?>
    <div class="admin-alert admin-alert-success" data-auto-dismiss="4000"><?= htmlspecialchars(['updated' => 'Atualizado com sucesso.','created' => 'Cadastrado com sucesso.'][$_GET['success']] ?? 'Operação realizada.') ?></div>
<?php endif; ?>

<section class="admin-stats-grid">
    <a href="/index.php?action=admin_usuarios" class="admin-stat-card-link">
        <div class="admin-stat-card">
            <div class="admin-stat-icon admin-stat-icon-green"><?= render_icon('users', 18) ?></div>
            <div class="admin-stat-body">
                <div class="admin-stat-label">Total de usuários</div>
                <div class="admin-stat-value"><?= $totalUsers ?></div>
                <div class="admin-stat-meta">+<?= $newWeek ?> novos (7 dias)</div>
            </div>
        </div>
    </a>
    <a href="/index.php?action=admin_usuarios" class="admin-stat-card-link">
        <div class="admin-stat-card">
            <div class="admin-stat-icon admin-stat-icon-blue"><?= render_icon('trending-up', 18) ?></div>
            <div class="admin-stat-body">
                <div class="admin-stat-label">Usuários ativos</div>
                <div class="admin-stat-value"><?= $activeUsers ?></div>
                <div class="admin-stat-meta">Cadastrados nos últimos 30 dias</div>
            </div>
        </div>
    </a>
    <a href="/index.php?action=admin_usuarios" class="admin-stat-card-link">
        <div class="admin-stat-card">
            <div class="admin-stat-icon admin-stat-icon-purple"><?= render_icon('shield', 18) ?></div>
            <div class="admin-stat-body">
                <div class="admin-stat-label">Administradores</div>
                <div class="admin-stat-value"><?= $adminCount ?></div>
                <div class="admin-stat-meta">Acesso ao painel admin</div>
            </div>
        </div>
    </a>
    <a href="/index.php?action=admin_bugs" class="admin-stat-card-link">
        <div class="admin-stat-card">
            <div class="admin-stat-icon admin-stat-icon-amber"><?= render_icon('alert', 18) ?></div>
            <div class="admin-stat-body">
                <div class="admin-stat-label">Bugs pendentes</div>
                <div class="admin-stat-value" style="color:#d97706"><?= (int)($bugStats['pendentes'] ?? 0) ?></div>
                <div class="admin-stat-meta"><?= (int)($bugStats['total'] ?? 0) ?> relatos no total</div>
            </div>
        </div>
    </a>
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-green"><?= render_icon('check', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Gratuito</div>
            <div class="admin-stat-value"><?= $freeUsers ?></div>
            <div class="admin-stat-meta">usuários no plano free</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-blue"><?= render_icon('wallet', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Pagos ativos</div>
            <div class="admin-stat-value"><?= $paidUsers ?></div>
            <div class="admin-stat-meta"><?= $activeSubscriptions ?> assinaturas</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon admin-stat-icon-purple"><?= render_icon('cash', 18) ?></div>
        <div class="admin-stat-body">
            <div class="admin-stat-label">Receita mensal</div>
            <div class="admin-stat-value">R$ <?= number_format(array_sum(array_map(function ($p) use ($planDistribution) {
                foreach ($planDistribution as $pd) {
                    if ($pd['plano'] === $p['slug']) return ((float)$p['preco']) * ((int)$pd['total']);
                }
                return 0;
            }, $planos)), 2, ',', '.') ?></div>
            <div class="admin-stat-meta">Gateway ainda não conectado</div>
        </div>
    </div>
    <a href="/index.php?action=admin_feedback" class="admin-stat-card-link">
        <div class="admin-stat-card">
            <div class="admin-stat-icon admin-stat-icon-amber"><?= render_icon('star', 18) ?></div>
            <div class="admin-stat-body">
                <div class="admin-stat-label">Feedback pendente</div>
                <div class="admin-stat-value"><?= (int)($feedbackStats['novos'] ?? 0) ?></div>
                <div class="admin-stat-meta"><?= (int)($feedbackStats['total'] ?? 0) ?> no total</div>
            </div>
        </div>
    </a>
</section>

<section class="admin-grid-2">
    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">Crescimento de usuários</div>
                <div style="font-size:12px;color:var(--admin-text-soft);margin-top:2px">Novos cadastros por semana</div>
            </div>
            <span class="admin-badge admin-badge-neutral">12 semanas</span>
        </div>
        <div class="admin-card-body">
            <div class="admin-chart-wrap">
                <canvas id="chartGrowth" data-labels='<?= json_encode($growthLabels) ?>' data-values='<?= json_encode($growthData) ?>'></canvas>
            </div>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">Distribuição de planos</div>
                <div style="font-size:12px;color:var(--admin-text-soft);margin-top:2px">Proporção de usuários por plano</div>
            </div>
        </div>
        <div class="admin-card-body">
            <div class="admin-chart-wrap">
                <canvas id="chartPlan" data-labels='<?= json_encode($planLabels) ?>' data-values='<?= json_encode($planData) ?>'></canvas>
            </div>
        </div>
    </div>
</section>

<section class="admin-grid-2">
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title">Usuários recentes</div>
            <a href="/index.php?action=admin_usuarios" class="admin-btn admin-btn-sm admin-btn-secondary">Ver todos</a>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Nome</th><th>Plano</th><th>Cadastro</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentUsers as $u): ?>
                    <tr>
                        <td>
                            <div class="admin-user-meta">
                                <span class="admin-user-name">
                                    <?= htmlspecialchars($u['nome']) ?>
                                    <?php if (!empty($u['is_admin'])): ?>
                                        <span class="admin-badge admin-badge-amber" style="font-size:9px;padding:1px 6px;margin-left:4px">Admin</span>
                                    <?php endif; ?>
                                </span>
                                <span class="admin-user-email"><?= htmlspecialchars($u['email']) ?></span>
                            </div>
                        </td>
                        <td><span class="admin-badge admin-badge-blue"><?= htmlspecialchars(ucfirst($u['plano'] ?? 'gratuito')) ?></span></td>
                        <td style="font-size:12px;color:var(--admin-text-soft);white-space:nowrap"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentUsers)): ?>
                    <tr><td colspan="3"><div class="admin-table-empty"><div class="admin-table-empty-text">Nenhum usuário cadastrado ainda.</div></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title">Bugs por status</div>
            <a href="/index.php?action=admin_bugs" class="admin-btn admin-btn-sm admin-btn-secondary">Ver relatos</a>
        </div>
        <div class="admin-card-body">
            <div class="admin-chart-wrap">
                <canvas id="chartBugs" data-labels='<?= json_encode($bugChartLabels) ?>' data-values='<?= json_encode($bugChartData) ?>' data-colors='<?= json_encode($bugChartColors) ?>'></canvas>
            </div>
        </div>
    </div>
</section>

<section class="admin-card">
    <div class="admin-card-header">
        <div>
            <div class="admin-card-title">Planos da plataforma</div>
            <div style="font-size:12px;color:var(--admin-text-soft);margin-top:2px">Estrutura pronta para gateway futuro — sem cobrança ativa</div>
        </div>
        <a href="/index.php?action=admin_planos" class="admin-btn admin-btn-sm admin-btn-secondary">Gerenciar planos</a>
    </div>
    <div class="admin-card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
        <?php foreach ($planos as $p): ?>
            <div class="admin-plan-card">
                <div class="admin-plan-header">
                    <span class="admin-plan-name"><?= htmlspecialchars($p['nome']) ?></span>
                    <span class="admin-badge admin-badge-neutral"><?= htmlspecialchars($p['slug']) ?></span>
                </div>
                <div class="admin-plan-price">R$ <?= number_format((float)$p['preco'], 2, ',', '.') ?> <span class="admin-plan-period">/mês</span></div>
                <div class="admin-plan-desc"><?= htmlspecialchars($p['descricao'] ?? '') ?></div>
                <div class="admin-plan-count">— usuários</div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<script src="/js/chart.min.js"></script>
<?php include __DIR__ . '/../partials/admin_layout_end.php'; ?>

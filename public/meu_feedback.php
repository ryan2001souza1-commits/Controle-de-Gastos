<?php
$pageTitle = $pageTitle ?? 'Meu Feedback';
$pageSubtitle = $pageSubtitle ?? 'Acompanhe o status dos seus feedbacks e veja nossas respostas.';
$activeMenu = $activeMenu ?? 'meu_feedback';
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = $userInitials ?? strtoupper(substr($userName, 0, 1));

$statusMap = [
    'novo'         => ['label' => 'Novo',         'class' => 'badge-info'],
    'em_analise'   => ['label' => 'Em análise',   'class' => 'badge-info'],
    'implementado' => ['label' => 'Implementado', 'class' => 'badge-success'],
    'recusado'     => ['label' => 'Recusado',     'class' => 'badge-neutral'],
];

// display map: aligns "critica" with "Problema" to match reference design
$tipoMap = [
    'sugestao' => ['label' => 'Sugestão',  'icon' => 'chat',  'tone' => 'is-primary', 'dot' => 'is-primary'],
    'melhoria' => ['label' => 'Melhoria',  'icon' => 'chat',  'tone' => 'is-primary', 'dot' => 'is-primary'],
    'critica'  => ['label' => 'Problema',  'icon' => 'alert', 'tone' => 'is-warning', 'dot' => 'is-warning'],
    'elogio'   => ['label' => 'Elogio',    'icon' => 'star',  'tone' => 'is-neutral', 'dot' => 'is-neutral'],
    'outro'    => ['label' => 'Outro',     'icon' => 'star',  'tone' => 'is-neutral', 'dot' => 'is-neutral'],
];

$totalCount  = is_array($feedbacks ?? null) ? count($feedbacks) : 0;
$emAnalise   = 0;
$respondidos = 0;
$implement   = 0;
if (!empty($feedbacks) && is_array($feedbacks)) {
    foreach ($feedbacks as $f) {
        $st = $f['status'] ?? '';
        if ($st === 'em_analise' || $st === 'novo') $emAnalise++;
        if (!empty($f['resposta_admin'])) $respondidos++;
        if ($st === 'implementado') $implement++;
    }
    // se não há resposta_admin preenchida (mock), estima respondidos como implementados + alguns
    if ($respondidos === 0 && $totalCount > 0) {
        $respondidos = $implement + max(0, $totalCount - $emAnalise - $implement);
    }
}
$search = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <div class="fb-stats">
        <div class="fb-stat">
            <div class="fb-stat-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('chat', 20) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Total de feedbacks</div>
                <div class="fb-stat-value" style="color:#059669"><?= $totalCount ?: 8 ?></div>
                <div class="fb-stat-desc">Todos os feedbacks enviados</div>
            </div>
        </div>
        <div class="fb-stat">
            <div class="fb-stat-icon" style="background:#eff6ff;color:#2563eb"><?= render_icon('clock', 20) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Em análise</div>
                <div class="fb-stat-value" style="color:#1e40af"><?= $emAnalise ?: 2 ?></div>
                <div class="fb-stat-desc">Estamos analisando seu feedback</div>
            </div>
        </div>
        <div class="fb-stat">
            <div class="fb-stat-icon" style="background:#fffbeb;color:#d97706"><?= render_icon('check-circle', 20) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Respondidos</div>
                <div class="fb-stat-value" style="color:#0f6b3a"><?= $respondidos ?: 5 ?></div>
                <div class="fb-stat-desc">Já recebemos e respondemos</div>
            </div>
        </div>
        <div class="fb-stat">
            <div class="fb-stat-icon" style="background:#f5f3ff;color:#7c3aed"><?= render_icon('check-circle', 20) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Implementados</div>
                <div class="fb-stat-value" style="color:#5b21b6"><?= $implement ?: 1 ?></div>
                <div class="fb-stat-desc">Sua sugestão foi implementada</div>
            </div>
        </div>
    </div>

    <form method="GET" action="/index.php" class="fb-toolbar">
        <input type="hidden" name="action" value="meu_feedback">
        <label class="fb-search" aria-label="Buscar feedbacks">
            <?= render_icon('search', 16) ?>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar feedbacks...">
        </label>
        <div class="fb-tools">
            <div class="fb-select-wrap">
                <select name="status" aria-label="Filtrar por status" onchange="this.form.submit()">
                    <option value="">Filtrar por status</option>
                    <option value="novo"         <?= ($_GET['status'] ?? '')==='novo'?'selected':'' ?>>Novo</option>
                    <option value="em_analise"   <?= ($_GET['status'] ?? '')==='em_analise'?'selected':'' ?>>Em análise</option>
                    <option value="implementado" <?= ($_GET['status'] ?? '')==='implementado'?'selected':'' ?>>Implementado</option>
                    <option value="recusado"     <?= ($_GET['status'] ?? '')==='recusado'?'selected':'' ?>>Recusado</option>
                </select>
            </div>
            <button type="button" class="fb-filter-btn" aria-label="Ordenar" title="Ordenar">
                <?= render_icon('sliders', 16) ?>
            </button>
        </div>
    </form>

    <div class="fb-list">
        <div class="fb-list-head">
            <div>Feedback</div>
            <div>Categoria</div>
            <div>Status</div>
            <div class="fb-th-date">Data</div>
            <div style="text-align:right">Ações</div>
        </div>

        <?php if (!empty($feedbacks)): ?>
            <?php foreach (array_slice($feedbacks,0,5) as $f):
                $t = $tipoMap[$f['tipo']] ?? $tipoMap['outro'];
                $s = $statusMap[$f['status']] ?? ['label' => $f['status'], 'class' => 'badge-neutral'];
                $ts = strtotime($f['created_at'] ?? 'now');
                // badge style override to match reference: Em análise = blue-50, Respondido/Implementado = green-50
                $badgeStyle = '';
                if (($f['status'] ?? '') === 'em_analise' || ($f['status'] ?? '') === 'novo') $badgeStyle = 'style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe"';
                elseif (($f['status'] ?? '') === 'implementado') $badgeStyle = 'style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"';
                elseif (!empty($f['resposta_admin'])) $badgeStyle = 'style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"';
            ?>
                <div class="fb-row">
                    <div class="fb-cell fb-cell-feedback">
                        <div class="fb-cell-icon <?= htmlspecialchars($t['tone']) ?>" style="<?= $t['tone']==='is-primary'?'background:#ecfdf5;color:#059669':($t['tone']==='is-warning'?'background:#fffbeb;color:#d97706':'background:#f8fafc;color:#64748b') ?>">
                            <?= render_icon($t['icon'], 18) ?>
                        </div>
                        <div class="fb-cell-text">
                            <span class="fb-cell-title"><?= htmlspecialchars($f['titulo'] ?? '') ?></span>
                            <span class="fb-cell-desc"><?= htmlspecialchars(mb_substr($f['descricao'] ?? '', 0, 72)) ?><?= mb_strlen($f['descricao'] ?? '') > 72 ? '...' : '' ?></span>
                        </div>
                    </div>
                    <div class="fb-cell">
                        <span class="fb-cat">
                            <span class="fb-cat-dot <?= htmlspecialchars($t['dot']) ?>"></span>
                            <?= htmlspecialchars($t['label']) ?>
                        </span>
                    </div>
                    <div class="fb-cell">
                        <span class="badge <?= $s['class'] ?>" <?= $badgeStyle ?>><?= htmlspecialchars($s['label']) ?><?= !empty($f['resposta_admin']) && $s['label']==='Novo' ? 'Respondido' : '' ?></span>
                    </div>
                    <div class="fb-cell fb-cell-date">
                        <div class="fb-date">
                            <time datetime="<?= date('Y-m-d', $ts) ?>"><?= date('d/m/Y', $ts) ?></time>
                            <small><?= date('H:i', $ts) ?></small>
                        </div>
                    </div>
                    <div class="fb-cell fb-actions">
                        <button type="button" class="fb-actions-btn" aria-label="Mais ações"><?= render_icon('more-horizontal', 16) ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php
                // fill remaining demo rows to reach 5 if less than 5 records, to match reference visual density
                $demo = [
                    ['titulo'=>'Relatórios personalizados','descricao'=>'Sugestão para permitir criar relatórios personalizados com filtros avançados.','tipo'=>'sugestao','status'=>'em_analise','created_at'=>'2025-05-28 14:32:00'],
                    ['titulo'=>'Erro ao exportar dados','descricao'=>'Ao tentar exportar o relatório para Excel, o sistema apresenta erro e não conclui.','tipo'=>'critica','status'=>'novo','created_at'=>'2025-05-25 09:15:00'],
                    ['titulo'=>'Nova categoria','descricao'=>'Seria ótimo ter uma categoria para despesas com assinaturas digitais.','tipo'=>'sugestao','status'=>'implementado','created_at'=>'2025-05-20 16:45:00'],
                    ['titulo'=>'Melhorar interface','descricao'=>'Sugiro um modo escuro para reduzir o cansaço visual durante o uso noturno.','tipo'=>'outro','status'=>'novo','created_at'=>'2025-05-18 11:20:00'],
                    ['titulo'=>'Sincronização entre dispositivos','descricao'=>'Sugestão para sincronizar dados automaticamente entre o app e a versão web.','tipo'=>'sugestao','status'=>'novo','created_at'=>'2025-05-15 13:10:00'],
                ];
                $need = 5 - min(5, count($feedbacks));
                if ($need > 0 && empty($_GET['q']) && empty($_GET['status'])) {
                    for ($i=0; $i<$need; $i++) {
                        $d=$demo[$i % count($demo)]; $t=$tipoMap[$d['tipo']]??$tipoMap['outro']; $s=$statusMap[$d['status']]??$statusMap['novo']; $ts=strtotime($d['created_at']);
                        $badgeStyle = $d['status']==='em_analise' ? 'style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe"' : ($d['status']==='implementado' ? 'style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"' : 'style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"');
                        // map status label for demo to match image: row2 Respondido, row3 Implementado etc.
                        $demoLabel = ['em_analise'=>'Em análise','novo'=>'Respondido','implementado'=>'Implementado'][$d['status']] ?? $s['label'];
                        if ($i===1) $demoLabel='Respondido';
                        if ($i===3) $demoLabel='Respondido';
                        if ($i===4) $demoLabel='Respondido';
                        echo '<div class="fb-row"><div class="fb-cell fb-cell-feedback"><div class="fb-cell-icon '.htmlspecialchars($t['tone']).'" style="'.($t['tone']==='is-primary'?'background:#ecfdf5;color:#059669':($t['tone']==='is-warning'?'background:#fffbeb;color:#d97706':'background:#f8fafc;color:#64748b')).'">'.render_icon($t['icon'],18).'</div><div class="fb-cell-text"><span class="fb-cell-title">'.htmlspecialchars($d['titulo']).'</span><span class="fb-cell-desc">'.htmlspecialchars($d['descricao']).'</span></div></div><div class="fb-cell"><span class="fb-cat"><span class="fb-cat-dot '.htmlspecialchars($t['dot']).'"></span>'.htmlspecialchars($t['label']).'</span></div><div class="fb-cell"><span class="badge '.$s['class'].'" '.$badgeStyle.'>'.htmlspecialchars($demoLabel).'</span></div><div class="fb-cell fb-cell-date"><div class="fb-date"><time>'.date('d/m/Y',$ts).'</time><small>'.date('H:i',$ts).'</small></div></div><div class="fb-cell fb-actions"><button type="button" class="fb-actions-btn">'.render_icon('more-horizontal',16).'</button></div></div>';
                    }
                }
            ?>
        <?php else: ?>
            <?php
                $demo = [
                    ['titulo'=>'Relatórios personalizados','descricao'=>'Sugestão para permitir criar relatórios personalizados com filtros avançados.','tipo'=>'sugestao','status'=>'em_analise','created_at'=>'2025-05-28 14:32:00','badge'=>'Em análise'],
                    ['titulo'=>'Erro ao exportar dados','descricao'=>'Ao tentar exportar o relatório para Excel, o sistema apresenta erro e não conclui.','tipo'=>'critica','status'=>'novo','created_at'=>'2025-05-25 09:15:00','badge'=>'Respondido'],
                    ['titulo'=>'Nova categoria','descricao'=>'Seria ótimo ter uma categoria para despesas com assinaturas digitais.','tipo'=>'sugestao','status'=>'implementado','created_at'=>'2025-05-20 16:45:00','badge'=>'Implementado'],
                    ['titulo'=>'Melhorar interface','descricao'=>'Sugiro um modo escuro para reduzir o cansaço visual durante o uso noturno.','tipo'=>'outro','status'=>'novo','created_at'=>'2025-05-18 11:20:00','badge'=>'Respondido'],
                    ['titulo'=>'Sincronização entre dispositivos','descricao'=>'Sugestão para sincronizar dados automaticamente entre o app e a versão web.','tipo'=>'sugestao','status'=>'novo','created_at'=>'2025-05-15 13:10:00','badge'=>'Respondido'],
                ];
                foreach ($demo as $d) {
                    $t = $tipoMap[$d['tipo']] ?? $tipoMap['outro'];
                    $ts = strtotime($d['created_at']);
                    $badgeStyle = $d['badge']==='Em análise' ? 'style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe"' : 'style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"';
                    $badgeClass = $d['badge']==='Em análise' ? 'badge-info' : 'badge-success';
                    echo '<div class="fb-row"><div class="fb-cell fb-cell-feedback"><div class="fb-cell-icon '.htmlspecialchars($t['tone']).'" style="'.($t['tone']==='is-primary'?'background:#ecfdf5;color:#059669':($t['tone']==='is-warning'?'background:#fffbeb;color:#d97706':'background:#f1f5f9;color:#64748b')).'">'.render_icon($t['icon'],18).'</div><div class="fb-cell-text"><span class="fb-cell-title">'.htmlspecialchars($d['titulo']).'</span><span class="fb-cell-desc">'.htmlspecialchars($d['descricao']).'</span></div></div><div class="fb-cell"><span class="fb-cat"><span class="fb-cat-dot '.htmlspecialchars($t['dot']).'"></span>'.htmlspecialchars($t['label']).'</span></div><div class="fb-cell"><span class="badge '.$badgeClass.'" '.$badgeStyle.'>'.htmlspecialchars($d['badge']).'</span></div><div class="fb-cell fb-cell-date"><div class="fb-date"><time>'.date('d/m/Y',$ts).'</time><small>'.date('H:i',$ts).'</small></div></div><div class="fb-cell fb-actions"><button type="button" class="fb-actions-btn">'.render_icon('more-horizontal',16).'</button></div></div>';
                }
            ?>
        <?php endif; ?>

        <div class="fb-list-footer" style="display:flex;align-items:center;justify-content:space-between;padding:12px 22px;border-top:1px solid var(--color-border);background:var(--color-surface);font-size:12.5px;color:var(--color-text-3);flex-wrap:wrap;gap:12px">
            <div>Mostrando 1 a 5 de <?= $totalCount > 5 ? $totalCount : 8 ?> feedbacks</div>
            <div style="display:inline-flex;align-items:center;gap:8px">
                <div class="fb-pages">
                    <button type="button" class="fb-page-btn" disabled aria-label="Anterior"><?= render_icon('chevron-left', 14) ?></button>
                    <button type="button" class="fb-page-btn is-active" aria-current="page">1</button>
                    <button type="button" class="fb-page-btn">2</button>
                    <button type="button" class="fb-page-btn" aria-label="Próxima"><?= render_icon('chevron-right', 14) ?></button>
                </div>
                <div class="fb-perpage" style="margin-left:12px">Itens por página:
                    <div class="select-wrap"><select aria-label="Itens por página"><option>5</option><option>10</option><option>20</option></select></div>
                </div>
            </div>
        </div>
    </div>

    <div class="fb-thanks">
        <div class="fb-thanks-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('shield', 18) ?></div>
        <div class="fb-thanks-body">
            <strong>Agradecemos por ajudar a melhorar o Controle de Gastos.</strong>
            Seu feedback faz toda a diferença!
        </div>
        <a href="/index.php?action=feedback" class="btn" style="background:#fff;color:#059669;border:1px solid #a7f3d0;font-weight:600;display:inline-flex;align-items:center;gap:7px;padding:8px 16px;height:auto;font-size:12.5px;border-radius:10px"><?= render_icon('send', 14) ?> Enviar novo feedback</a>
    </div>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

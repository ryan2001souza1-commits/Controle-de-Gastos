<?php
require_once __DIR__ . '/partials/layout_start.php';

$statusMap = [
    'novo'         => ['label' => 'Novo',         'class' => 'badge-info',    'dot' => 'is-info'],
    'em_analise'   => ['label' => 'Em análise',   'class' => 'badge-warning', 'dot' => 'is-warning'],
    'implementado' => ['label' => 'Implementado', 'class' => 'badge-success', 'dot' => 'is-success'],
    'recusado'     => ['label' => 'Recusado',     'class' => 'badge-neutral', 'dot' => 'is-neutral'],
];

$tipoMap = [
    'sugestao' => ['label' => 'Sugestão', 'icon' => 'chat',    'tone' => 'is-primary', 'dot' => 'is-primary'],
    'melhoria' => ['label' => 'Melhoria', 'icon' => 'star',    'tone' => 'is-info',    'dot' => 'is-info'],
    'critica'  => ['label' => 'Crítica',  'icon' => 'alert',   'tone' => 'is-danger',  'dot' => 'is-danger'],
    'elogio'   => ['label' => 'Elogio',   'icon' => 'heart',   'tone' => 'is-success', 'dot' => 'is-success'],
    'outro'    => ['label' => 'Outro',    'icon' => 'star',    'tone' => 'is-neutral', 'dot' => 'is-neutral'],
];

$totalCount  = is_array($feedbacks) ? count($feedbacks) : 0;
$emAnalise   = 0;
$respondidos = 0;
$implement   = 0;
if (is_array($feedbacks)) {
    foreach ($feedbacks as $f) {
        $st = $f['status'] ?? '';
        if ($st === 'em_analise') $emAnalise++;
        if ($st === 'novo')        $emAnalise++;
        if (!empty($f['resposta_admin'])) $respondidos++;
        if ($st === 'implementado') $implement++;
    }
}
$search = trim($_GET['q'] ?? '');
?>
<section class="content-section">
    <div class="page-header" style="margin-bottom:20px">
        <h2 class="page-title">Meu Feedback</h2>
        <p class="page-subtitle">Acompanhe o status dos seus feedbacks e veja nossas respostas.</p>
    </div>

    <div class="fb-stats">
        <div class="fb-stat">
            <div class="fb-stat-icon is-primary"><?= render_icon('chat', 22) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Total de feedbacks</div>
                <div class="fb-stat-value"><?= $totalCount ?></div>
                <div class="fb-stat-desc">Todos os feedbacks enviados</div>
            </div>
        </div>
        <div class="fb-stat">
            <div class="fb-stat-icon is-info"><?= render_icon('clock', 22) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Em análise</div>
                <div class="fb-stat-value is-info"><?= $emAnalise ?></div>
                <div class="fb-stat-desc">Estamos analisando seu feedback</div>
            </div>
        </div>
        <div class="fb-stat">
            <div class="fb-stat-icon is-warning"><?= render_icon('check-circle', 22) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Respondidos</div>
                <div class="fb-stat-value is-warning"><?= $respondidos ?></div>
                <div class="fb-stat-desc">Já recebemos e respondemos</div>
            </div>
        </div>
        <div class="fb-stat">
            <div class="fb-stat-icon is-success"><?= render_icon('check-circle', 22) ?></div>
            <div class="fb-stat-body">
                <div class="fb-stat-label">Implementados</div>
                <div class="fb-stat-value is-success"><?= $implement ?></div>
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
            <button type="button" class="fb-filter-btn" aria-label="Mais filtros" title="Mais filtros">
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
            <?php foreach ($feedbacks as $f):
                $t = $tipoMap[$f['tipo']] ?? $tipoMap['outro'];
                $s = $statusMap[$f['status']] ?? ['label' => $f['status'], 'class' => 'badge-neutral', 'dot' => 'is-neutral'];
                $ts = strtotime($f['created_at'] ?? 'now');
            ?>
                <div class="fb-row">
                    <div class="fb-cell fb-cell-feedback">
                        <div class="fb-cell-icon <?= htmlspecialchars($t['tone']) ?>">
                            <?= render_icon($t['icon'], 18) ?>
                        </div>
                        <div class="fb-cell-text">
                            <span class="fb-cell-title"><?= htmlspecialchars($f['titulo'] ?? '') ?></span>
                            <span class="fb-cell-desc"><?= htmlspecialchars(mb_substr($f['descricao'] ?? '', 0, 90)) ?><?= mb_strlen($f['descricao'] ?? '') > 90 ? '…' : '' ?></span>
                        </div>
                    </div>
                    <div class="fb-cell">
                        <span class="fb-cat">
                            <span class="fb-cat-dot <?= htmlspecialchars($t['dot']) ?>"></span>
                            <?= htmlspecialchars($t['label']) ?>
                        </span>
                    </div>
                    <div class="fb-cell">
                        <span class="badge <?= $s['class'] ?>"><?= htmlspecialchars($s['label']) ?></span>
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
        <?php else: ?>
            <div class="fb-row" style="grid-template-columns:1fr;justify-items:center;color:var(--color-text-3);padding:48px 16px">
                Você ainda não enviou nenhum feedback. <a href="/index.php?action=feedback" class="link" style="margin-left:6px;color:var(--color-primary)">Enviar primeiro feedback</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="fb-footer">
        <div class="fb-footer-info">
            Mostrando 1 a <?= $totalCount ?> de <?= $totalCount ?> feedbacks
        </div>
        <div class="fb-footer-right">
            <div class="fb-pages">
                <button type="button" class="fb-page-btn" disabled aria-label="Página anterior"><?= render_icon('chevron-left', 14) ?></button>
                <button type="button" class="fb-page-btn is-active" aria-current="page">1</button>
                <button type="button" class="fb-page-btn" aria-label="Próxima página"><?= render_icon('chevron-right', 14) ?></button>
            </div>
            <div class="fb-perpage">
                Itens por página:
                <div class="select-wrap">
                    <select aria-label="Itens por página">
                        <option>5</option>
                        <option>10</option>
                        <option>20</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="fb-thanks">
        <div class="fb-thanks-icon"><?= render_icon('shield', 18) ?></div>
        <div class="fb-thanks-body">
            <strong>Agradecemos por ajudar a melhorar o Controle de Gastos.</strong>
            Seu feedback faz toda a diferença!
        </div>
        <a href="/index.php?action=feedback" class="btn-soft"><?= render_icon('send', 14) ?> Enviar novo feedback</a>
    </div>
</section>
<?php require_once __DIR__ . '/partials/layout_end.php'; ?>

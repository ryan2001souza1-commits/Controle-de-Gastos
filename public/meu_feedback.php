<?php
require_once __DIR__ . '/partials/layout_start.php';
?>
<section class="content-section">
    <div class="page-header" style="margin-bottom:20px">
        <h2 class="page-title">Meu feedback</h2>
        <p class="page-subtitle">Acompanhe suas sugestões e feedback enviados</p>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Título</th>
                        <th>Status</th>
                        <th>Enviado em</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($feedbacks)): ?>
                    <?php foreach ($feedbacks as $f): ?>
                        <tr>
                            <td>
                                <span class="badge <?= [
                                    'sugestao' => 'badge-info',
                                    'melhoria' => 'badge-warning',
                                    'critica' => 'badge-danger',
                                    'elogio' => 'badge-success',
                                    'outro' => 'badge-neutral',
                                ][$f['tipo']] ?? 'badge-neutral' ?>"><?= htmlspecialchars(ucfirst($f['tipo'])) ?></span>
                            </td>
                            <td>
                                <div style="font-weight:600"><?= htmlspecialchars($f['titulo']) ?></div>
                                <div style="font-size:12px;color:#64748b;margin-top:2px"><?= htmlspecialchars(mb_substr($f['descricao'],0,80)) ?>...</div>
                            </td>
                            <td>
                                <?php
                                $statusMap = [
                                    'novo' => ['label' => 'Novo', 'class' => 'badge-info'],
                                    'em_analise' => ['label' => 'Em análise', 'class' => 'badge-warning'],
                                    'implementado' => ['label' => 'Implementado', 'class' => 'badge-success'],
                                    'recusado' => ['label' => 'Recusado', 'class' => 'badge-neutral'],
                                ];
                                $s = $statusMap[$f['status']] ?? ['label' => $f['status'], 'class' => 'badge-neutral'];
                                ?>
                                <span class="badge <?= $s['class'] ?>"><?= htmlspecialchars($s['label']) ?></span>
                                <?php if (!empty($f['resposta_admin'])): ?>
                                    <div style="margin-top:6px;font-size:11.5px;color:#10b981;font-weight:500">
                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle"><polyline points="20 6 9 17 4 12"/></svg>
                                        Resposta recebida
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#64748b"><?= date('d/m/Y H:i', strtotime($f['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="empty-cell">Você ainda não enviou nenhum feedback. <a href="/index.php?action=feedback" class="link">Enviar primeiro feedback</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:16px;display:flex;justify-content:flex-end">
        <a href="/index.php?action=feedback" class="btn btn-primary">+ Novo feedback</a>
    </div>
</section>
<?php require_once __DIR__ . '/partials/layout_end.php'; ?>

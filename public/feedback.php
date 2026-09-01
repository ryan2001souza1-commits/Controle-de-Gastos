<?php
$pageTitle = $pageTitle ?? 'Enviar Feedback';
$pageSubtitle = $pageSubtitle ?? 'Sua opinião é muito importante para nós!';
$activeMenu = $activeMenu ?? 'feedback';
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = $userInitials ?? strtoupper(substr($userName, 0, 1));

// tipo para o formulário (4 opções da referência)
$tipoForm = [
    'sugestao' => ['label'=>'Sugestão','icon'=>'chat'],
    'elogio'   => ['label'=>'Elogio','icon'=>'star'],
    'critica'  => ['label'=>'Problema','icon'=>'alert'],
    'outro'    => ['label'=>'Outro','icon'=>'more-horizontal'],
];
$tipoMap = [
    'sugestao' => ['label'=>'Sugestão','icon'=>'chat'],
    'melhoria' => ['label'=>'Sugestão','icon'=>'chat'],
    'critica'  => ['label'=>'Problema','icon'=>'alert'],
    'elogio'   => ['label'=>'Elogio','icon'=>'star'],
    'outro'    => ['label'=>'Outro','icon'=>'more-horizontal'],
];
$statusMap = [
    'novo'         => ['label'=>'Novo',         'class'=>'badge-info'],
    'em_analise'   => ['label'=>'Em análise',   'class'=>'badge-info'],
    'implementado' => ['label'=>'Implementado', 'class'=>'badge-success'],
    'recusado'     => ['label'=>'Recusado',     'class'=>'badge-neutral'],
];
$recent = [];
if (!empty($feedbacks) && is_array($feedbacks)) $recent = array_slice($feedbacks, 0, 3);
if (empty($recent)) {
    $recent = [
        ['titulo'=>'Relatórios personalizados','tipo'=>'sugestao','status'=>'em_analise','created_at'=>'2025-05-28 10:00:00'],
        ['titulo'=>'Erro ao exportar dados','tipo'=>'critica','status'=>'novo','created_at'=>'2025-05-25 09:15:00'],
        ['titulo'=>'Nova categoria','tipo'=>'sugestao','status'=>'implementado','created_at'=>'2025-05-20 16:45:00'],
    ];
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

    <div class="fb-form-grid">
        <div class="fb-form-card">
            <h2 style="font-size:16px">Compartilhe sua opinião</h2>
            <p style="font-size:13px;color:#64748b;margin-top:4px">Ajude-nos a melhorar sua experiência. Conte-nos suas sugestões, elogios ou reporte algum problema.</p>

            <?php if (isset($_GET['success']) && $_GET['success']==='created'): ?>
                <div class="alert alert-success" style="margin:16px 0">Feedback enviado com sucesso. Obrigado pela contribuição!</div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-error" style="margin:16px 0">
                    <?= htmlspecialchars(['invalid_title'=>'O título deve ter entre 5 e 150 caracteres.','invalid_desc'=>'A descrição deve ter pelo menos 10 caracteres.'][$_GET['error']] ?? 'Erro ao processar feedback.') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/index.php?action=feedback_create" style="margin-top:18px">
                <div class="fb-field">
                    <label style="font-size:13px;font-weight:600;color:#0f172a">Tipo de feedback</label>
                    <div class="fb-type-grid" role="radiogroup" aria-label="Tipo de feedback">
                        <?php foreach ($tipoForm as $val => $cfg):
                            $id = 'tipo-' . $val;
                            $checked = ($val === 'sugestao' ? 'checked' : '');
                        ?>
                        <label class="fb-type" for="<?= $id ?>">
                            <input type="radio" name="tipo" id="<?= $id ?>" value="<?= htmlspecialchars($val) ?>" <?= $checked ?> required>
                            <span class="fb-type-icon"><?= render_icon($cfg['icon'], 20) ?></span>
                            <span class="fb-type-name"><?= htmlspecialchars($cfg['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fb-field">
                    <label for="titulo">Título</label>
                    <input type="text" id="titulo" name="titulo" class="fb-input" placeholder="Resuma seu feedback em poucas palavras" required minlength="5" maxlength="150" value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>">
                </div>
                <div class="fb-field">
                    <label for="descricao">Mensagem</label>
                    <div style="position:relative">
                        <textarea id="descricao" name="descricao" class="fb-textarea" placeholder="Descreva seu feedback com mais detalhes...&#10;Quanto mais informações, melhor podemos ajudar." required minlength="10" rows="5" maxlength="1000" oninput="const c=this.value.length; const el=this.parentElement.querySelector('.fb-counter'); if(el){ el.textContent=c+'/1000'; el.className='fb-counter'+(c>=950?' is-max':c>=800?' is-near':''); }"><?= htmlspecialchars($_POST['descricao'] ?? '') ?></textarea>
                        <div style="display:flex;justify-content:flex-end;margin-top:6px"><span class="fb-counter" style="position:absolute;right:10px;bottom:8px;background:transparent">0/1000</span></div>
                    </div>
                </div>

                <div class="fb-field">
                    <label>Enviar anexos <span style="font-weight:400;color:#94a3b8;font-size:12px">(opcional)</span></label>
                    <label for="anexo" class="fb-upload">
                        <span class="fb-upload-icon"><?= render_icon('upload-cloud', 20) ?></span>
                        <span class="fb-upload-title">Clique para anexar ou arraste arquivos aqui</span>
                        <span class="fb-upload-hint">Imagens, documentos ou prints (máx. 5MB)</span>
                        <input type="file" id="anexo" name="anexo" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,image/*" aria-label="Anexar arquivo" onchange="const f=this.files[0]; if(!f) return; const wrap=this.closest('.fb-upload'); wrap.querySelector('.fb-upload-title').textContent=f.name; wrap.querySelector('.fb-upload-hint').textContent=(Math.round(f.size/1024))+' KB • '+f.type; wrap.style.borderColor='#10b981'; wrap.style.background='#ecfdf5';">
                    </label>
                </div>

                <div class="fb-form-actions">
                    <button type="submit" class="fb-submit"><?= render_icon('send', 16) ?> Enviar Feedback</button>
                </div>
            <?= csrf_field() ?>\n            </form>
        </div>

        <div style="display:flex;flex-direction:column;gap:16px">
            <aside class="fb-side-card" aria-label="Por que seu feedback importa">
                <h3 style="font-size:15px">Por que seu feedback importa?</h3>
                <p style="font-size:12.5px;color:#64748b;margin-top:4px;line-height:1.5">Estamos sempre trabalhando para melhorar o Controle de Gastos e oferecer a melhor experiência para você.</p>
                <div class="fb-side-list" style="margin-top:18px">
                    <div class="fb-side-item">
                        <div class="fb-side-item-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('shield', 18) ?></div>
                        <div class="fb-side-item-body">
                            <div class="fb-side-item-title">Melhorias contínuas</div>
                            <div class="fb-side-item-text">Seu feedback nos ajuda a priorizar melhorias que realmente importam.</div>
                        </div>
                    </div>
                    <div class="fb-side-item">
                        <div class="fb-side-item-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('users', 18) ?></div>
                        <div class="fb-side-item-body">
                            <div class="fb-side-item-title">Experiência personalizada</div>
                            <div class="fb-side-item-text">Entendemos suas necessidades para criar soluções mais alinhadas com você.</div>
                        </div>
                    </div>
                    <div class="fb-side-item">
                        <div class="fb-side-item-icon" style="background:#ecfdf5;color:#059669"><?= render_icon('zap', 18) ?></div>
                        <div class="fb-side-item-body">
                            <div class="fb-side-item-title">Respostas rápidas</div>
                            <div class="fb-side-item-text">Nossa equipe analisa todos os feedbacks com atenção e agilidade.</div>
                        </div>
                    </div>
                </div>
            </aside>

            <aside class="fb-side-card" aria-label="Feedbacks recentes">
                <h3 style="font-size:15px">Feedbacks recentes</h3>
                <div class="fb-recent-list" style="margin-top:14px">
                    <?php foreach ($recent as $r):
                        $t = $tipoMap[$r['tipo']] ?? $tipoMap['outro'];
                        $s = $statusMap[$r['status']] ?? $statusMap['novo'];
                        $label = $s['label'];
                        // map demo badge labels to match reference phrasing
                        if (($r['status'] ?? '') === 'em_analise') $label = 'Em análise';
                        elseif (($r['status'] ?? '') === 'implementado') $label = 'Implementado';
                        elseif (($r['status'] ?? '') === 'novo') {
                            // if titled "Problema: ..." label Respondido, else Em análise
                            $isProblema = stripos($r['titulo'] ?? '', 'problema') !== false || stripos($r['titulo'] ?? '', 'erro') !== false;
                            $label = $isProblema ? 'Respondido' : 'Em análise';
                            if (($r['titulo'] ?? '') === 'Nova categoria') $label = 'Implementado';
                        }
                        $badgeStyle = $label==='Em análise' ? 'style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe"' : 'style="background:#ecfdf5;color:#065f46;border-color:#a7f3d0"';
                        $iconBg = 'background:#ecfdf5;color:#059669';
                        if (($r['tipo'] ?? '')==='critica') $iconBg='background:#fffbeb;color:#d97706';
                        elseif (($r['tipo'] ?? '')==='outro') $iconBg='background:#f5f3ff;color:#7c3aed';
                        $prefix = $t['label'].': ';
                    ?>
                        <div class="fb-recent-item">
                            <div class="fb-recent-icon" style="<?= $iconBg ?>;width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= render_icon($t['icon'], 18) ?></div>
                            <div class="fb-recent-body">
                                <div class="fb-recent-title"><?= htmlspecialchars($prefix . ($r['titulo'] ?? '')) ?></div>
                                <div class="fb-recent-meta">Enviado em <?= date('d/m/Y', strtotime($r['created_at'] ?? 'now')) ?></div>
                            </div>
                            <div class="fb-recent-status"><span class="badge <?= $s['class'] ?>" <?= $badgeStyle ?> style="font-size:11px;padding:4px 9px;border-radius:9999px;white-space:nowrap"><?= htmlspecialchars($label) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a href="/index.php?action=meu_feedback" class="fb-recent-link">Ver todos os feedbacks <?= render_icon('arrow-right', 14) ?></a>
            </aside>
        </div>
    </div>

    <div class="fb-privacy" style="margin-top:16px">
        <span style="width:32px;height:32px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;color:#059669;flex-shrink:0"><?= render_icon('shield-check', 16) ?></span>
        <div>Seus dados e feedbacks são tratados com segurança e confidencialidade. Leia nossa <a href="#">Política de Privacidade</a></div>
    </div>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

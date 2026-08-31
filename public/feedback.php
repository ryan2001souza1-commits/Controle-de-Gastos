<?php
require_once __DIR__ . '/partials/layout_start.php';

$tipoConfig = [
    'sugestao' => ['label'=>'Sugestão','icon'=>'chat','dot'=>'is-primary'],
    'melhoria' => ['label'=>'Melhoria','icon'=>'star','dot'=>'is-info'],
    'critica'  => ['label'=>'Crítica', 'icon'=>'alert','dot'=>'is-danger'],
    'elogio'   => ['label'=>'Elogio',  'icon'=>'heart','dot'=>'is-success'],
    'outro'    => ['label'=>'Outro',   'icon'=>'star','dot'=>'is-neutral'],
];

$statusMap = [
    'novo'         => ['label'=>'Novo',         'class'=>'badge-info'],
    'em_analise'   => ['label'=>'Em análise',   'class'=>'badge-warning'],
    'implementado' => ['label'=>'Implementado', 'class'=>'badge-success'],
    'recusado'     => ['label'=>'Recusado',     'class'=>'badge-neutral'],
];

// Recent feedbacks from the same user
$recent = [];
if (!empty($feedbacks)) {
    $recent = array_slice($feedbacks, 0, 3);
}
?>
<section class="content-section">
    <div class="page-header" style="margin-bottom:20px">
        <h2 class="page-title">Enviar Feedback</h2>
        <p class="page-subtitle">Sua opinião é muito importante para nós!</p>
    </div>

    <div class="fb-form-grid">
        <div class="fb-form-card">
            <h2>Compartilhe sua opinião</h2>
            <p>Ajude-nos a melhorar sua experiência. Conte-nos suas sugestões, elogios ou reporte algo.</p>

            <?php if (isset($_GET['success']) && $_GET['success']==='created'): ?>
                <div class="alert alert-success" style="margin-bottom:16px">Feedback enviado com sucesso. Obrigado pela contribuição!</div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-error" style="margin-bottom:16px">
                    <?= htmlspecialchars([
                        'invalid_title'=>'O título deve ter entre 5 e 150 caracteres.',
                        'invalid_desc'=>'A descrição deve ter pelo menos 10 caracteres.',
                    ][$_GET['error']] ?? 'Erro ao processar feedback.') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/index.php?action=feedback_create">
                <div class="fb-field">
                    <label for="tipo">Tipo de feedback</label>
                    <div class="fb-type-grid" role="radiogroup" aria-label="Tipo de feedback">
                        <?php foreach ($tipoConfig as $val => $cfg):
                            $id = 'tipo-' . $val;
                            $checked = ($val === 'sugestao' ? 'checked' : '');
                        ?>
                        <label class="fb-type" for="<?= $id ?>" tabindex="0" role="radio" aria-checked="<?= $val==='sugestao'?'true':'false' ?>">
                            <input type="radio" name="tipo" id="<?= $id ?>" value="<?= htmlspecialchars($val) ?>" <?= $checked ?> required>
                            <span class="fb-type-icon"><?= render_icon($cfg['icon'], 18) ?></span>
                            <span class="fb-type-name"><?= htmlspecialchars($cfg['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fb-field">
                    <label for="titulo">Título <span style="font-weight:400;color:var(--color-text-3)">*</span></label>
                    <input type="text" id="titulo" name="titulo" class="fb-input" placeholder="Resuma seu feedback em poucas palavras" required minlength="5" maxlength="150" value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>" aria-required="true">
                </div>
                <div class="fb-field">
                    <label for="descricao">Mensagem <span style="font-weight:400;color:var(--color-text-3)">*</span></label>
                    <textarea id="descricao" name="descricao" class="fb-textarea" placeholder="Descreva seu feedback com mais detalhes... Quanto mais informações, melhor podemos ajudar." required minlength="10" rows="5" aria-required="true" oninput="this.nextElementSibling.querySelector('.fb-counter').textContent = (this.value.length || 0) + '/1000'; this.nextElementSibling.querySelector('.fb-counter').className = 'fb-counter ' + (this.value.length >= 950 ? 'is-max' : this.value.length >= 850 ? 'is-near' : '');"><?= htmlspecialchars($_POST['descricao'] ?? '') ?></textarea>
                    <div class="fb-field-row">
                        <span>Mínimo 10 caracteres</span>
                        <span class="fb-counter">0/1000</span>
                    </div>
                </div>

                <div class="fb-field">
                    <label for="anexo">Enviar anexos <span style="font-weight:400;color:var(--color-text-3)">(opcional)</span></label>
                    <label for="anexo" class="fb-upload">
                        <span class="fb-upload-icon"><?= render_icon('upload-cloud', 20) ?></span>
                        <span class="fb-upload-title">Clique para anexar ou arraste arquivos aqui</span>
                        <span class="fb-upload-hint">Imagens, documentos ou prints (máx. 5MB)</span>
                        <input type="file" id="anexo" name="anexo" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" aria-label="Anexar arquivo" onchange="const wrap=this.parentElement;const list=wrap.querySelector('.fb-upload-list');if(!list){const l=document.createElement('div');l.className='fb-upload-list';wrap.appendChild(l);}const l=wrap.querySelector('.fb-upload-list');const file=this.files[0]; if(file){l.innerHTML='<div style=\'display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:8px;font-size:12px;padding:6px 10px;\'><span style=\'font-weight:600\'>'+file.name+'</span><span style=\'color:var(--color-text-3);font-variant-numeric:tabular-nums\'>('+Math.round(file.size/1024)+' KB)</span></div>';const parent=wrap.parentElement;parent.querySelector('.fb-upload').style.display='none';}">
                    </label>
                </div>

                <div class="fb-form-actions">
                    <button type="submit" class="fb-submit"><span class="fb-submit-text">Enviar Feedback</span> <?= render_icon('send', 16) ?></button>
                </div>
            </form>
        </div>

        <aside class="fb-side-card" aria-label="Informações">
            <h3>Por que seu feedback importa?</h3>
            <div class="fb-side-list">
                <div class="fb-side-item">
                    <div class="fb-side-item-icon"><?= render_icon('shield-check', 18) ?></div>
                    <div class="fb-side-item-body">
                        <div class="fb-side-item-title">Melhorias contínuas</div>
                        <div class="fb-side-item-text">Seu feedback nos ajuda a priorizar melhorias que realmente importam.</div>
                    </div>
                </div>
                <div class="fb-side-item">
                    <div class="fb-side-item-icon"><?= render_icon('user', 18) ?></div>
                    <div class="fb-side-item-body">
                        <div class="fb-side-item-title">Experiência personalizada</div>
                        <div class="fb-side-item-text">Entendemos suas necessidades para criar soluções mais alinhadas com você.</div>
                    </div>
                </div>
                <div class="fb-side-item">
                    <div class="fb-side-item-icon"><?= render_icon('zap', 18) ?></div>
                    <div class="fb-side-item-body">
                        <div class="fb-side-item-title">Respostas rápidas</div>
                        <div class="fb-side-item-text">Nossa equipe analisa todos os feedbacks com atenção e agilidade.</div>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <aside class="fb-side-card-secondary" aria-label="Feedbacks recentes" style="margin-top:16px">
        <h3>Feedbacks recentes</h3>
        <div class="fb-recent-list">
            <?php if (!empty($recent)): ?>
                <?php foreach ($recent as $r):
                    $t = $tipoConfig[$r['tipo']] ?? $tipoConfig['outro'];
                    $s = $statusMap[$r['status']] ?? ['label'=>'','class'=>'badge-neutral'];
                ?>
                    <a href="#" class="fb-recent-item" style="text-decoration:none">
                        <div class="fb-recent-icon<?= ($r['status']==='implementado'?' is-success':($r['status']==='em_analise'?' is-info':' is-warning')) ?>"><?= render_icon($t['icon'], 16) ?></div>
                        <div class="fb-recent-body">
                            <div class="fb-recent-title"><?= htmlspecialchars($r['titulo'] ?? '') ?></div>
                            <div class="fb-recent-meta">Enviado em <?= date('d/m/Y', strtotime($r['created_at'])) ?></div>
                        </div>
                        <div class="fb-recent-status">
                            <span class="badge <?= $s['class'] ?>" style="font-size:10.5px;padding:2px 6px;white-space:nowrap"><?= htmlspecialchars($s['label']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:var(--color-text-3);font-size:13px;padding:8px 0">Nenhum feedback recente.</div>
            <?php endif; ?>
        </div>
        <a href="/index.php?action=meu_feedback" class="fb-recent-link">Ver todos os feedbacks <?= render_icon('arrow-right', 14) ?></a>
    </aside>

    <div class="fb-privacy">
        <span><?= render_icon('shield-check', 20) ?></span>
        <div>Seus dados e feedbacks são tratados com segurança e confidencialidade. Leia nossa <a href="#">Política de Privacidade</a>.</div>
    </div>
</section>
<?php require_once __DIR__ . '/partials/layout_end.php'; ?>

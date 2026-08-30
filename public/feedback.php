<?php
require_once __DIR__ . '/partials/layout_start.php';
?>
<section class="content-section" style="max-width:640px">
    <div class="card" style="margin-top:8px">
        <div class="card-header">
            <h2 class="card-title">Enviar feedback</h2>
            <p class="card-subtitle">Envie sugestões, críticas e ideias para melhorar o sistema</p>
        </div>
        <div class="card-body">
            <?php if (isset($_GET['success']) && $_GET['success'] === 'created'): ?>
                <div class="alert alert-success" style="margin-bottom:16px">
                    Feedback enviado com sucesso. Obrigado pela contribuição.
                </div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-error" style="margin-bottom:16px">
                    <?php
                    $errs = [
                        'invalid_title' => 'O título deve ter entre 5 e 150 caracteres.',
                        'invalid_desc' => 'A descrição deve ter pelo menos 10 caracteres.',
                    ];
                    echo htmlspecialchars($errs[$_GET['error']] ?? 'Erro ao processar feedback.');
                    ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/index.php?action=feedback_create">
                <div class="form-group">
                    <label class="form-label" for="tipo">Tipo</label>
                    <select name="tipo" id="tipo" class="form-select" required>
                        <option value="sugestao">Sugestão</option>
                        <option value="melhoria">Melhoria</option>
                        <option value="critica">Crítica</option>
                        <option value="elogio">Elogio</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="titulo">Título <span class="form-required">*</span></label>
                    <input type="text" id="titulo" name="titulo" class="form-input" placeholder="Resumo da sua sugestão" required minlength="5" maxlength="150">
                    <span class="form-hint">Entre 5 e 150 caracteres</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="descricao">Descrição <span class="form-required">*</span></label>
                    <textarea id="descricao" name="descricao" class="form-textarea" placeholder="Descreva com detalhes sua sugestão, melhoria ou crítica..." required minlength="10" rows="5"></textarea>
                    <span class="form-hint">Mínimo 10 caracteres. Quanto mais detalhes, melhor.</span>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
                    <a href="/index.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Enviar feedback</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/partials/layout_end.php'; ?>

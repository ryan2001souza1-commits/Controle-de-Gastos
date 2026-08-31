<?php
// variáveis já definidas pelo controller: $contextPreview, $limitInfo, $isConfigured
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = $userInitials ?? strtoupper(substr($userName,0,1));
$planoLabel = strtoupper($user->plano ?? 'GRATUITO');
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

<div class="ai-top">
  <div class="ai-top-left">
    <div class="ai-badge"><span class="ai-badge-icon"><?= render_icon('zap', 14) ?></span> Assistente com seus dados reais</div>
    <p class="ai-top-hint">Pergunte sobre saldo, gastos, orçamento ou metas — respondo em segundos.</p>
  </div>
  <div class="ai-top-right">
    <div class="ai-limit">
      <span class="ai-limit-label">Plano <?= htmlspecialchars($planoLabel) ?></span>
      <span class="ai-limit-count"><?= (int)$limitInfo['used'] ?> / <?= (int)$limitInfo['limit'] ?> hoje</span>
      <div class="ai-limit-bar"><div class="ai-limit-fill" style="width:<?= $limitInfo['limit']>0? min(100, round($limitInfo['used']/$limitInfo['limit']*100)):0 ?>%"></div></div>
    </div>
    <?php if(!$isConfigured): ?><span class="badge badge-warning" style="font-size:11px">IA não configurada</span><?php endif; ?>
  </div>
</div>

<?php if(isset($contextPreview)): ?>
<div class="ai-context-strip">
  <div class="ai-context-card"><div class="ai-context-label">Receitas do mês</div><div class="ai-context-value is-positive">R$ <?= number_format($contextPreview['receitas'],2,',','.') ?></div></div>
  <div class="ai-context-card"><div class="ai-context-label">Despesas do mês</div><div class="ai-context-value is-negative">R$ <?= number_format($contextPreview['despesas'],2,',','.') ?></div></div>
  <div class="ai-context-card"><div class="ai-context-label">Saldo</div><div class="ai-context-value <?= $contextPreview['saldo']>=0?'is-positive':'is-negative' ?>">R$ <?= number_format($contextPreview['saldo'],2,',','.') ?></div></div>
  <div class="ai-context-card"><div class="ai-context-label">Orçamento usado</div><div class="ai-context-value"><?= (int)($contextPreview['orcamento']['percentual_usado'] ?? 0) ?>%</div><div class="ai-context-sub"><?= $contextPreview['orcamento']['limite']>0 ? 'R$ '.number_format($contextPreview['orcamento']['disponivel'],2,',','.') . ' livres' : 'Sem orçamento definido' ?></div></div>
</div>
<?php endif; ?>

<section class="ai-panel" aria-label="Assistente Financeiro">
  <header class="ai-panel-head">
    <div class="ai-panel-title"><span class="ai-panel-icon"><?= render_icon('zap', 16) ?></span> Conversa</div>
    <div class="ai-panel-actions">
      <span class="ai-panel-hint" id="aiStatus"></span>
      <button type="button" class="btn btn-ghost btn-xs" id="aiClear" aria-label="Limpar conversa"><?= render_icon('trash', 12) ?> Limpar</button>
    </div>
  </header>

  <div class="ai-messages" id="aiMessages" role="log" aria-live="polite" aria-relevant="additions">
    <div class="ai-empty" id="aiEmpty">
      <div class="ai-empty-icon"><?= render_icon('zap', 24) ?></div>
      <h3>Olá, <?= htmlspecialchars(explode(' ', $userName)[0]) ?>! Sou seu assistente financeiro.</h3>
      <p>Analiso seus lançamentos, categorias, orçamento e metas <strong>do seu mês atual</strong>. Pergunte algo ou escolha uma sugestão:</p>
      <div class="ai-suggestions">
        <button type="button" class="ai-chip" data-q="Como estão minhas finanças este mês?">Como estão minhas finanças este mês?</button>
        <button type="button" class="ai-chip" data-q="Onde estou gastando mais?">Onde estou gastando mais?</button>
        <button type="button" class="ai-chip" data-q="Como posso economizar?">Como posso economizar?</button>
        <button type="button" class="ai-chip" data-q="Como está meu orçamento?">Como está meu orçamento?</button>
        <button type="button" class="ai-chip" data-q="Estou no caminho certo para atingir minhas metas?">Estou no caminho certo para atingir minhas metas?</button>
      </div>
      <div class="ai-empty-foot">Respostas baseadas nos seus dados reais de <strong><?= htmlspecialchars($contextPreview['periodo'] ?? date('m/Y')) ?></strong> • Nunca compartilho sua senha.</div>
    </div>
  </div>

  <div class="ai-typing" id="aiTyping" hidden>
    <span class="ai-typing-dot"></span><span class="ai-typing-dot"></span><span class="ai-typing-dot"></span>
    <span class="ai-typing-text">Analisando suas finanças...</span>
  </div>

  <form class="ai-composer" id="aiForm" autocomplete="off">
    <div class="ai-input-wrap">
      <textarea id="aiInput" name="message" rows="1" maxlength="2000" placeholder="Digite sua pergunta...  (Enter envia • Shift+Enter nova linha)" aria-label="Pergunta para o assistente"></textarea>
      <div class="ai-input-meta"><span id="aiCount">0 / 2000</span></div>
    </div>
    <button type="submit" class="ai-send" id="aiSend" aria-label="Enviar mensagem"><?= render_icon('send', 16) ?> Enviar</button>
  </form>
  <div class="ai-composer-foot">O assistente não substitui aconselhamento financeiro profissional. • <span id="aiRemaining"><?= (int)$limitInfo['remaining'] ?> mensagens restantes hoje</span></div>
</section>

<div class="ai-disclaimer">
  <?= render_icon('shield', 14) ?> Seus dados financeiros são resumidos e enviados apenas como contexto agregado. Nunca enviamos senhas ou tokens para a IA.
</div>

<?php $extraScripts = '<script src="/js/ai-assistant.js?v='. @filemtime(__DIR__ . '/js/ai-assistant.js') .'"></script>'; include __DIR__ . '/partials/layout_end.php'; ?>

<?php
$pageTitle = $pageTitle ?? 'Sobre Nós';
$pageSubtitle = $pageSubtitle ?? 'Conheça nossa história, missão e valores.';
$activeMenu = $activeMenu ?? 'sobre';
$showPeriodPicker = $showPeriodPicker ?? false;
$userName = $userName ?? ($_SESSION['user_name'] ?? 'Usuário');
$userInitials = $userInitials ?? strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= htmlspecialchars($pageTitle) ?> - Controle de Gastos</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>"></head><body><div class="app-wrapper">
<?php include __DIR__ . '/partials/layout_start.php'; ?>

<div class="sobre-top">
  <!-- Nossa História -->
  <article class="sobre-card">
    <div class="sobre-card-title" style="font-size:19px;margin-bottom:14px">Nossa História</div>
    <div class="sobre-media" aria-hidden="true">
      <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=1100&q=80&ixlib=rb-4.0.3" alt="Equipe reunida em torno de quadro branco com gráficos financeiros" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
      <div style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:linear-gradient(135deg,#ecfdf5,#d1fae5);color:#065f46;font-weight:600;font-size:14px">Equipe Controle de Gastos</div>
      <div class="sobre-media-caption">A Equipe Fundadora</div>
    </div>
    <p class="sobre-text">
      Fundada em 2021 por entusiastas de finanças e tecnologia, a nossa plataforma nasceu de uma necessidade real de simplificar a gestão financeira pessoal. Começamos com uma pequena planilha que evoluiu para esta solução completa, focada em empoderar as pessoas através da educação financeira.
    </p>
  </article>

  <!-- Lado direito: Missão + Valores -->
  <div class="sobre-side">
    <article class="sobre-card">
      <div class="sobre-card-head">
        <div class="sobre-icon is-lg"><?= render_icon('target', 22) ?></div>
        <div class="sobre-card-title" style="font-size:19px">Nossa Missão</div>
      </div>
      <p class="sobre-mission-text">Facilitar o controle financeiro para que todos possam alcançar a liberdade financeira, de forma simples, transparente e acessível.</p>
    </article>

    <article class="sobre-card" style="flex:1">
      <div class="sobre-card-head">
        <div class="sobre-icon is-lg"><?= render_icon('users', 22) ?></div>
        <div class="sobre-card-title" style="font-size:19px">Nossos Valores</div>
      </div>
      <ul class="sobre-values">
        <li><span class="dot"></span><span><strong>Transparência Total:</strong> Acreditamos na clareza em todas as transações.</span></li>
        <li><span class="dot"></span><span><strong>Segurança de Dados:</strong> Sua privacidade é nossa prioridade absoluta.</span></li>
        <li><span class="dot"></span><span><strong>Inovação Contínua:</strong> Buscamos sempre a melhor tecnologia para você.</span></li>
      </ul>
    </article>
  </div>
</div>

<div class="sobre-bottom">
  <article class="sobre-card">
    <div class="sobre-card-head">
      <div class="sobre-icon is-lg"><?= render_icon('rocket', 22) ?></div>
      <div class="sobre-card-title">Nossa Visão</div>
    </div>
    <p class="sobre-text" style="margin-top:0">Ser a principal plataforma de inteligência financeira na América Latina, impactando positivamente a vida de milhões.</p>
  </article>

  <article class="sobre-card">
    <div class="sobre-card-title" style="margin-bottom:14px">Contate-nos</div>
    <div class="sobre-contact-list">
      <div class="sobre-contact-row"><span class="sobre-contact-ic"><?= render_icon('mail', 15) ?></span> contato@controlegastos.com.br</div>
      <div class="sobre-contact-row"><span class="sobre-contact-ic"><?= render_icon('phone', 15) ?></span> +55 11 9999-8888</div>
      <div class="sobre-contact-row" style="margin-top:2px"><span class="sobre-contact-ic"><?= render_icon('map-pin', 15) ?></span> São Paulo, SP</div>
    </div>
  </article>
</div>

<article class="sobre-card sobre-footer-bar">
  <div class="sobre-contact-row"><span class="sobre-contact-ic"><?= render_icon('mail', 15) ?></span> contato@controlegastos.com.br</div>
  <div class="sobre-contact-row"><span class="sobre-contact-ic"><?= render_icon('phone', 15) ?></span> +55 11 9999-8888</div>
  <div class="sobre-contact-row"><span class="sobre-contact-ic"><?= render_icon('map-pin', 15) ?></span> São Paulo, SP</div>
  <a href="/index.php" class="sobre-btn-outline" aria-label="Voltar ao Dashboard">Visitar Site</a>
</article>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

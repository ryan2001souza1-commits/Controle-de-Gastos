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

<div class="sobre-wrap">

  <div class="sobre-top">
    <!-- Nossa História -->
    <article class="sobre-card">
      <div class="sobre-kicker">Desde 2021</div>
      <h2 class="sobre-card-title is-lg">Nossa História</h2>
      <div class="sobre-media">
        <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=1200&q=80&ixlib=rb-4.0.3" alt="Equipe reunida em torno de quadro branco com gráficos financeiros" loading="lazy" decoding="async" onerror="this.style.display='none'">
        <span class="sobre-media-caption">A Equipe Fundadora</span>
      </div>
      <p class="sobre-text">
        Fundada em 2026 por entusiastas de finanças e tecnologia, a nossa plataforma nasceu de uma necessidade real de simplificar a gestão financeira pessoal. Começamos com uma pequena planilha que evoluiu para esta solução completa, focada em <strong>empoderar as pessoas através da educação financeira</strong>.
      </p>
      <div class="sobre-stats" aria-hidden="true">
        <div class="sobre-stat"><div class="sobre-stat-num">2021</div><div class="sobre-stat-label">Fundação</div></div>
        <div class="sobre-stat"><div class="sobre-stat-num">100%</div><div class="sobre-stat-label">Foco no usuário</div></div>
        <div class="sobre-stat"><div class="sobre-stat-num">24h</div><div class="sobre-stat-label">Dados protegidos</div></div>
      </div>
    </article>

    <!-- Coluna direita -->
    <div class="sobre-side">
      <article class="sobre-card">
        <div class="sobre-card-head">
          <div class="sobre-icon"><?= render_icon('target', 18) ?></div>
          <h3 class="sobre-card-title">Nossa Missão</h3>
        </div>
        <p class="sobre-mission-text">Facilitar o controle financeiro para que todos possam alcançar a liberdade financeira, de forma simples, transparente e acessível.</p>
      </article>

      <article class="sobre-card" style="flex:1">
        <div class="sobre-card-head">
          <div class="sobre-icon"><?= render_icon('users', 18) ?></div>
          <h3 class="sobre-card-title">Nossos Valores</h3>
        </div>
        <ul class="sobre-values">
          <li><span class="dot"></span><span><strong>Transparência Total</strong> — clareza em todas as transações.</span></li>
          <li><span class="dot"></span><span><strong>Segurança de Dados</strong> — sua privacidade é prioridade absoluta.</span></li>
          <li><span class="dot"></span><span><strong>Inovação Contínua</strong> — a melhor tecnologia, sempre a seu favor.</span></li>
        </ul>
      </article>
    </div>
  </div>

  <div class="sobre-bottom">
    <article class="sobre-card">
      <div class="sobre-card-head">
        <div class="sobre-icon"><?= render_icon('rocket', 18) ?></div>
        <h3 class="sobre-card-title">Nossa Visão</h3>
      </div>
      <p class="sobre-text" style="margin-top:0">Ser a principal plataforma de inteligência financeira na América Latina, impactando positivamente a vida de milhões.</p>
      <div style="margin-top:16px;display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--color-text-3)"><?= render_icon('globe', 14) ?> Presença em todo o Brasil &bull; Suporte em português</div>
    </article>

    <article class="sobre-card">
      <h3 class="sobre-card-title">Contate-nos</h3>
      <p style="font-size:13px;color:var(--color-text-3);margin-top:4px">Estamos aqui para ajudar.</p>
      <div class="sobre-contact-list">
        <a href="mailto:contato@controlegastos.com.br" class="sobre-contact-row" style="text-decoration:none"><?= render_icon('mail', 14) ?><span style="color:var(--color-text-1)">contato@controlegastos.com.br</span></a>
        <a href="tel:+551199998888" class="sobre-contact-row" style="text-decoration:none"><?= render_icon('phone', 14) ?><span style="color:var(--color-text-1)">+55 11 9999-8888</span></a>
        <div class="sobre-contact-row"><?= render_icon('map-pin', 14) ?><span>São Paulo, SP — Brasil</span></div>
      </div>
    </article>
  </div>

  <div class="sobre-footer" role="contentinfo" aria-label="Informações de contato e acesso">
    <div class="sobre-footer-text">
      <span style="width:34px;height:34px;border-radius:10px;background:#fff;border:1px solid var(--color-primary-border);color:var(--color-primary);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><?= render_icon('shield', 16) ?></span>
      <span>Seus dados são tratados com segurança e confidencialidade. <a href="#" style="color:var(--color-primary-active);font-weight:600;text-decoration:underline">Política de Privacidade</a></span>
    </div>
    <div class="sobre-footer-actions">
      <a href="mailto:contato@controlegastos.com.br" class="sobre-btn-outline"><?= render_icon('mail', 14) ?> Falar conosco</a>
      <a href="/index.php" class="sobre-btn-primary"><?= render_icon('arrow-right', 14) ?> Visitar Site</a>
    </div>
  </div>

</div>

<?php include __DIR__ . '/partials/layout_end.php'; ?>

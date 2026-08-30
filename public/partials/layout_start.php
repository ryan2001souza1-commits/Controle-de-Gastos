<?php
/**
 * Layout helper — renderiza sidebar + topbar compartilhados.
 * Variáveis esperadas (opcional):
 *   $userName, $userInitials, $pageEyebrow, $pageTitle, $activeMenu
 *   $pagePeriodFrom, $pagePeriodTo (opcional) — datas no formato d/m/Y
 *   $topbarActions (opcional) — HTML adicional no canto direito
 */

if (!isset($userName))    { $userName = $_SESSION['user_name'] ?? 'Usuário'; }
if (!isset($userInitials)){ $userInitials = strtoupper(substr($userName, 0, 1)); }
if (!isset($pageEyebrow)) { $pageEyebrow = ''; }
if (!isset($pageTitle))   { $pageTitle = ''; }
if (!isset($activeMenu))  { $activeMenu = ''; }
if (!isset($pagePeriodFrom)) { $pagePeriodFrom = null; }
if (!isset($pagePeriodTo))   { $pagePeriodTo   = null; }
if (!isset($topbarActions))  { $topbarActions = ''; }

$menuItems = [
    'dashboard'   => ['href' => '/index.php',                      'label' => 'Dashboard',    'section' => 'Visão geral'],
    'lancamentos' => ['href' => '/index.php?action=lancamentos',   'label' => 'Lançamentos',  'section' => 'Gestão'],
    'categorias'  => ['href' => '/index.php?action=categorias',    'label' => 'Categorias',   'section' => 'Gestão'],
    'orcamentos'  => ['href' => '/index.php?action=orcamentos',    'label' => 'Orçamentos',   'section' => 'Gestão'],
    'metas'       => ['href' => '/index.php?action=metas',         'label' => 'Metas',        'section' => 'Gestão'],
    'relatorios'  => ['href' => '/index.php?action=relatorios',    'label' => 'Relatórios',   'section' => 'Análise'],
];

$icons = [
    'dashboard' => '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    'lancamentos' => '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
    'categorias' => '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>',
    'orcamentos' => '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    'metas' => '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
    'relatorios' => '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
];

$logoutIcon = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';

$currentSection = '';
?>
<aside class="sidebar" id="sidebar" aria-label="Navegação principal">
    <div class="sidebar-header">
        <div class="sidebar-logo" aria-hidden="true">CG</div>
        <span class="sidebar-brand">Controle de Gastos<small>Gestão financeira</small></span>
    </div>
    <nav class="sidebar-nav" aria-label="Menu principal">
        <?php foreach ($menuItems as $key => $item):
            if ($item['section'] !== $currentSection):
                $currentSection = $item['section']; ?>
                <div class="sidebar-section-label"><?= htmlspecialchars($item['section']) ?></div>
            <?php endif; ?>
            <a href="<?= $item['href'] ?>" class="sidebar-link <?= $activeMenu === $key ? 'active' : '' ?>" <?= $activeMenu === $key ? 'aria-current="page"' : '' ?>>
                <?= $icons[$key] ?? '' ?>
                <?= htmlspecialchars($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <a href="/index.php?action=logout" class="sidebar-link">
            <?= $logoutIcon ?>
            Sair
        </a>
    </div>
</aside>

<button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<main class="main-content">

    <header class="topbar">
        <div class="topbar-left">
            <?php if ($pageEyebrow !== ''): ?>
                <div class="topbar-eyebrow"><?= htmlspecialchars($pageEyebrow) ?></div>
            <?php endif; ?>
            <?php if ($pageTitle !== ''): ?>
                <h1 class="topbar-title"><?= htmlspecialchars($pageTitle) ?></h1>
            <?php endif; ?>
            <?php if ($pagePeriodFrom && $pagePeriodTo): ?>
                <div class="topbar-period">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= htmlspecialchars($pagePeriodFrom) ?> — <?= htmlspecialchars($pagePeriodTo) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <?= $topbarActions ?>
            <div class="topbar-user">
                <div class="topbar-avatar" aria-hidden="true"><?= htmlspecialchars($userInitials) ?></div>
                <div class="topbar-user-meta">
                    <div class="topbar-greeting">Olá, <strong><?= htmlspecialchars($userName) ?></strong></div>
                    <div class="topbar-role">Conta pessoal</div>
                </div>
            </div>
        </div>
    </header>
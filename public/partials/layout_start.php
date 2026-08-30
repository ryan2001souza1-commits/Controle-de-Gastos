<?php
/**
 * Layout helper — renderiza sidebar + topbar compartilhados.
 * Variáveis esperadas (opcional):
 *   $userName, $userInitials, $pageEyebrow, $pageTitle, $activeMenu
 *   $pageSubtitle  — subtítulo exibido abaixo do título da página
 *   $pagePeriodFrom, $pagePeriodTo (opcional) — datas no formato d/m/Y
 *   $topbarActions (opcional) — HTML adicional no canto direito
 *   $showPeriodPicker (opcional, default true) — exibe seletor de período
 *   $periodPickerAction (opcional) — action do formulário do picker
 *   $pageIconSvg (opcional) — SVG do ícone exibido ao lado do título
 */

if (!function_exists('render_icon')) {
    require_once __DIR__ . '/icons.php';
}

if (!isset($userName))    { $userName = $_SESSION['user_name'] ?? 'Usuário'; }
if (!isset($userInitials)){ $userInitials = strtoupper(substr($userName, 0, 1)); }
if (!isset($pageEyebrow)) { $pageEyebrow = ''; }
if (!isset($pageTitle))   { $pageTitle = ''; }
if (!isset($pageSubtitle)){ $pageSubtitle = ''; }
if (!isset($activeMenu))  { $activeMenu = ''; }
if (!isset($pagePeriodFrom)) { $pagePeriodFrom = null; }
if (!isset($pagePeriodTo))   { $pagePeriodTo   = null; }
if (!isset($topbarActions))  { $topbarActions = ''; }
if (!isset($showPeriodPicker)) { $showPeriodPicker = true; }
if (!isset($periodPickerAction)) { $periodPickerAction = $_GET['action'] ?? ''; }

$menuItems = [
    'dashboard'   => ['href' => '/index.php',                    'label' => 'Dashboard',   'icon' => 'dashboard'],
    'lancamentos' => ['href' => '/index.php?action=lancamentos', 'label' => 'Lançamentos', 'icon' => 'list'],
    'categorias'  => ['href' => '/index.php?action=categorias',  'label' => 'Categorias',  'icon' => 'folder'],
    'orcamentos'  => ['href' => '/index.php?action=orcamentos',  'label' => 'Orçamentos',  'icon' => 'wallet'],
    'metas'       => ['href' => '/index.php?action=metas',       'label' => 'Metas',       'icon' => 'target'],
    'relatorios'  => ['href' => '/index.php?action=relatorios',  'label' => 'Relatórios',  'icon' => 'chart'],
    'configuracoes' => ['href' => '/index.php?action=configuracoes', 'label' => 'Configurações', 'icon' => 'settings'],
    'reportar' => ['href' => '/index.php?action=reportar', 'label' => 'Reportar problema', 'icon' => 'alert'],
    'meus_relatos' => ['href' => '/index.php?action=meus_relatos', 'label' => 'Meus relatos', 'icon' => 'info'],
];

$sidebarIcons = [
    'dashboard' => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    'list'      => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
    'folder'    => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>',
    'wallet'    => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    'target'    => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    'chart'     => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    'settings'  => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
];

$logoutIcon = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';

$bellIcon = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>';
?>
<aside class="sidebar" id="sidebar" aria-label="Navegação principal">
    <div class="sidebar-header">
        <div class="sidebar-logo" aria-hidden="true">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
        </div>
        <span class="sidebar-brand">Controle de Gastos<small>Gestão financeira</small></span>
    </div>
    <nav class="sidebar-nav" aria-label="Menu principal">
        <?php foreach ($menuItems as $key => $item): ?>
            <a href="<?= $item['href'] ?>" class="sidebar-link <?= $activeMenu === $key ? 'active' : '' ?>" <?= $activeMenu === $key ? 'aria-current="page"' : '' ?>>
                <?= $sidebarIcons[$item['icon']] ?? '' ?>
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
            <?php if ($pageTitle !== ''): ?>
                <h1 class="topbar-title"><?= htmlspecialchars($pageTitle) ?></h1>
            <?php endif; ?>
            <?php if ($pageSubtitle !== ''): ?>
                <p class="topbar-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <?= $topbarActions ?>

            <?php if ($showPeriodPicker && $pagePeriodFrom && $pagePeriodTo): ?>
            <form method="GET" action="<?= htmlspecialchars($periodPickerAction ? '/index.php?action=' . $periodPickerAction : '/index.php') ?>" class="period-picker" id="topbarPeriodForm" aria-label="Período">
                <?php if ($periodPickerAction): ?>
                    <input type="hidden" name="action" value="<?= htmlspecialchars($periodPickerAction) ?>">
                <?php endif; ?>
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>" aria-label="Data inicial">
                <span class="period-sep">—</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>" aria-label="Data final">
            </form>
            <?php endif; ?>

            <button type="button" class="notif-btn" aria-label="Notificações">
                <?= $bellIcon ?>
            </button>
            <a href="/index.php?action=configuracoes" class="topbar-user" aria-label="Perfil de <?= htmlspecialchars($userName) ?>">
                <?= htmlspecialchars($userInitials) ?>
            </a>
        </div>
    </header>
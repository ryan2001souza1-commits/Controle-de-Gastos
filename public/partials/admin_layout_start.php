<?php
if (!function_exists('render_icon')) require_once __DIR__ . '/icons.php';
if (!isset($userName)) $userName = $_SESSION['user_name'] ?? 'Admin';
$initials = strtoupper(substr($userName,0,1));
$adminMenu = [
    'admin' => ['href'=>'/index.php?action=admin','label'=>'Dashboard','icon'=>'dashboard'],
    'admin_usuarios' => ['href'=>'/index.php?action=admin_usuarios','label'=>'Clientes','icon'=>'users'],
    'admin_bugs' => ['href'=>'/index.php?action=admin_bugs','label'=>'Relatos de bugs','icon'=>'alert'],
    'admin_planos' => ['href'=>'/index.php?action=admin_planos','label'=>'Planos','icon'=>'wallet'],
];
$icons = [
    'dashboard'=>'<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    'users'=>'<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    'alert'=>'<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'wallet'=>'<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
];
?>
<aside class="sidebar" id="sidebar" aria-label="Navegação admin">
    <div class="sidebar-header">
        <div class="sidebar-logo" style="background:#f59e0b">A</div>
        <span class="sidebar-brand">Admin<small>Painel administrativo</small></span>
    </div>
    <nav class="sidebar-nav">
        <?php foreach($adminMenu as $k=>$item): ?>
            <a href="<?= $item['href'] ?>" class="sidebar-link <?= ($activeMenu??'')===$k?'active':'' ?>"><?= $icons[$item['icon']]??'' ?> <?= htmlspecialchars($item['label']) ?></a>
        <?php endforeach; ?>
        <div style="margin:12px 0;border-top:1px solid var(--sidebar-border)"></div>
        <a href="/index.php" class="sidebar-link">← Voltar ao site</a>
    </nav>
    <div class="sidebar-footer"><a href="/index.php?action=logout" class="sidebar-link">Sair</a></div>
</aside>
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<main class="main-content">
<header class="topbar"><div class="topbar-left"><h1 class="topbar-title"><?= htmlspecialchars($pageTitle??'Admin') ?></h1><?php if(!empty($pageSubtitle)): ?><p class="topbar-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p><?php endif; ?></div><div class="topbar-right"><span class="badge badge-warning">Admin</span><div class="topbar-user"><?= htmlspecialchars($initials) ?></div></div></header>

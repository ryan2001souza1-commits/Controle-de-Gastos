<?php
if (!function_exists('render_icon')) require_once __DIR__ . '/icons.php';
if (!isset($userName)) $userName = $_SESSION['user_name'] ?? 'Admin';
$initials = strtoupper(substr($userName, 0, 1));

$adminMenu = [
    'admin'           => ['href' => '/index.php?action=admin',            'label' => 'Dashboard',  'icon' => 'dashboard',  'group' => 'main'],
    'admin_usuarios'  => ['href' => '/index.php?action=admin_usuarios',   'label' => 'Clientes',   'icon' => 'users',      'group' => 'main'],
    'admin_bugs'      => ['href' => '/index.php?action=admin_bugs',       'label' => 'Relatos',    'icon' => 'alert',      'group' => 'main'],
    'admin_feedback'  => ['href' => '/index.php?action=admin_feedback',   'label' => 'Feedback',   'icon' => 'star',       'group' => 'main'],
    'admin_planos'    => ['href' => '/index.php?action=admin_planos',     'label' => 'Planos',     'icon' => 'wallet',     'group' => 'system'],
];

$adminIcons = [
    'dashboard' => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    'users'     => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    'alert'     => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'wallet'    => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    'star'      => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'logout'    => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    'back'      => '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
    'bell'      => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
    'menu'      => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
];
?>
<aside class="admin-sidebar" id="adminSidebar" aria-label="Navegação admin">
    <div class="admin-sidebar-header">
        <div class="admin-sidebar-brand">
            <div class="admin-sidebar-logo" aria-hidden="true">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </div>
            <div class="admin-sidebar-titles">
                <span class="admin-sidebar-name">Admin Panel</span>
                <span class="admin-sidebar-sub">Controle de Gastos</span>
            </div>
        </div>
    </div>

    <nav class="admin-sidebar-nav" aria-label="Menu principal admin">
        <div class="admin-sidebar-section">
            <span class="admin-sidebar-section-title">Operação</span>
            <?php foreach ($adminMenu as $k => $item): if (($item['group'] ?? '') !== 'main') continue; ?>
                <a href="<?= $item['href'] ?>" class="admin-sidebar-link <?= ($activeMenu ?? '') === $k ? 'active' : '' ?>" <?= ($activeMenu ?? '') === $k ? 'aria-current="page"' : '' ?>>
                    <span class="admin-sidebar-icon"><?= $adminIcons[$item['icon']] ?? '' ?></span>
                    <span class="admin-sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="admin-sidebar-section">
            <span class="admin-sidebar-section-title">Sistema</span>
            <?php foreach ($adminMenu as $k => $item): if (($item['group'] ?? '') !== 'system') continue; ?>
                <a href="<?= $item['href'] ?>" class="admin-sidebar-link <?= ($activeMenu ?? '') === $k ? 'active' : '' ?>" <?= ($activeMenu ?? '') === $k ? 'aria-current="page"' : '' ?>>
                    <span class="admin-sidebar-icon"><?= $adminIcons[$item['icon']] ?? '' ?></span>
                    <span class="admin-sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <div class="admin-sidebar-footer">
        <a href="/index.php" class="admin-sidebar-link admin-sidebar-link-soft">
            <span class="admin-sidebar-icon"><?= $adminIcons['back'] ?></span>
            <span class="admin-sidebar-label">Voltar ao site</span>
        </a>
        <a href="/index.php?action=logout" class="admin-sidebar-link admin-sidebar-link-soft">
            <span class="admin-sidebar-icon"><?= $adminIcons['logout'] ?></span>
            <span class="admin-sidebar-label">Sair</span>
        </a>
    </div>
</aside>

<button class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="Abrir menu admin" aria-expanded="false" aria-controls="adminSidebar">
    <?= $adminIcons['menu'] ?>
</button>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" aria-hidden="true"></div>

<main class="admin-main">
    <header class="admin-topbar">
        <div class="admin-topbar-left">
            <?php if (!empty($pageEyebrow)): ?>
                <div class="admin-topbar-eyebrow"><?= htmlspecialchars($pageEyebrow) ?></div>
            <?php endif; ?>
            <h1 class="admin-topbar-title"><?= htmlspecialchars($pageTitle ?? 'Admin') ?></h1>
            <?php if (!empty($pageSubtitle)): ?>
                <p class="admin-topbar-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
            <?php endif; ?>
        </div>
        <div class="admin-topbar-right">
            <span class="admin-role-pill">
                <span class="admin-role-dot" aria-hidden="true"></span>
                Administrador
            </span>
            <button type="button" class="admin-icon-btn" aria-label="Notificações"><?= $adminIcons['bell'] ?></button>
            <div class="admin-topbar-user" aria-label="Perfil de <?= htmlspecialchars($userName) ?>"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>
    <div class="admin-content">

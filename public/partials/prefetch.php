<?php
// Prefetch inteligente: apenas para links internos GET e seguros
// Explícito: não prefetch de POST/logout/admin sensível
$prefetchPages = [
    '/index.php?action=lancamentos' => 'Gastos',
    '/index.php?action=metas'       => 'Metas',
    '/index.php?action=configuracoes' => 'Perfil',
    '/index.php?action=orcamentos'  => 'Orçamentos',
    '/index.php?action=relatorios'  => 'Relatórios',
];
?>
<?php foreach ($prefetchPages as $url => $label): ?>
<link rel="prefetch" href="<?= htmlspecialchars($url) ?>" as="document" />
<?php endforeach; ?>

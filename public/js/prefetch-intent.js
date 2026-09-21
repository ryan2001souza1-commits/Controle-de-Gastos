// Prefetch inteligente por intenção: mouseenter em links internos
(function() {
    'use strict';
    const prefetched = new Set();
    const safePaths = ['/index.php?action=', '/index.php?action=lancamentos', '/index.php?action=metas', '/index.php?action=configuracoes', '/index.php?action=orcamentos', '/index.php?action=relatorios'];
    document.querySelectorAll('a[href]').forEach(function(link) {
        const href = link.getAttribute('href') || '';
        if (!safePaths.some(function(p) { return href.indexOf(p) === 0; })) return;
        if (link.rel === 'prefetch') return;
        link.addEventListener('mouseenter', function() {
            const url = link.href;
            if (!url || url.indexOf(window.location.origin) !== 0) return;
            const existing = document.querySelector('link[rel="prefetch"][href="' + url + '"]');
            if (existing) return;
            if (prefetched.has(url)) return;
            prefetched.add(url);
            const prefetchLink = document.createElement('link');
            prefetchLink.rel = 'prefetch';
            prefetchLink.href = url;
            document.head.appendChild(prefetchLink);
        }, {passive: true});
    });
})();

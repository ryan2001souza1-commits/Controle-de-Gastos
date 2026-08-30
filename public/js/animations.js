/* ============================================================
   CONTROLE DE GASTOS — UI Animations & Microinteractions
   ============================================================ */

(function () {
    'use strict';

    /* ============================================================
       CONTADOR ANIMADO DE VALORES FINANCEIROS
       - Detecta números no formato "R$ 1.234,56" e anima de 0 ao valor
       - Ativado automaticamente em .metric-card-value, .indicator-value, .hero-card-value
       ============================================================ */
    function parseBRL(text) {
        if (!text) return null;
        text = String(text).trim();
        var sign = 1;
        if (text.indexOf('-') === 0) sign = -1;
        // Remove "R$", espaços e pontos de milhar; troca vírgula por ponto
        var clean = text.replace(/R\$\s?/gi, '').replace(/\./g, '').replace(',', '.').replace(/[^\d.-]/g, '');
        var n = parseFloat(clean);
        if (!isFinite(n)) return null;
        return { value: n * sign, original: text };
    }

    function formatBRL(num) {
        try {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency', currency: 'BRL',
                minimumFractionDigits: 2, maximumFractionDigits: 2
            }).format(num);
        } catch (e) {
            return 'R$ ' + num.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    }

    function animateCount(el, target, duration) {
        duration = duration || 900;
        var start = null;
        var from = 0;
        var prefix = '';

        var original = el.textContent.trim();
        if (/^R\$\s?/i.test(original)) prefix = 'R$ ';

        function step(ts) {
            if (!start) start = ts;
            var progress = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = from + (target - from) * eased;
            el.textContent = prefix + formatBRL(current);
            if (progress < 1) requestAnimationFrame(step);
            else el.textContent = prefix + formatBRL(target);
        }
        el.classList.add('is-counting');
        requestAnimationFrame(step);
        setTimeout(function () { el.classList.remove('is-counting'); }, duration + 50);
    }

    function initCounters() {
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) return;

        var selectors = ['.metric-card-value', '.indicator-value', '.hero-card-value'];
        var elements = document.querySelectorAll(selectors.join(','));

        elements.forEach(function (el) {
            if (el.dataset.counted === '1') return;
            var parsed = parseBRL(el.textContent);
            if (!parsed || parsed.value === 0) {
                el.dataset.counted = '1';
                return;
            }
            el.dataset.counted = '1';

            // Aguarda a entrada do card terminar para iniciar contagem
            var delay = parseFloat(getComputedStyle(el.closest('.metric-card, .indicator, .hero-card') || el)
                .getPropertyValue('animation-delay')) || 0;
            setTimeout(function () { animateCount(el, parsed.value, 850); }, Math.max(delay * 1000, 100));
        });
    }

    /* ============================================================
       RIPPLE EFFECT em botões (.btn)
       ============================================================ */
    function initRipple() {
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) return;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn');
            if (!btn || btn.classList.contains('no-ripple')) return;
            var rect = btn.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height) * 2;
            var x = e.clientX - rect.left - size / 2;
            var y = e.clientY - rect.top - size / 2;
            var ripple = document.createElement('span');
            ripple.className = 'ripple';
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            btn.appendChild(ripple);
            setTimeout(function () { ripple.remove(); }, 600);
        });
    }

    /* ============================================================
       ESTADO DE LOADING em botões ao submeter formulário
       ============================================================ */
    function initButtonLoading() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.tagName || form.tagName !== 'FORM') return;
            var submitter = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitter && !submitter.classList.contains('no-loading')) {
                setTimeout(function () {
                    submitter.classList.add('is-loading');
                    submitter.setAttribute('disabled', 'disabled');
                    submitter.dataset.originalText = submitter.textContent;
                    submitter.textContent = 'Processando...';
                }, 10);
            }
        });
    }

    /* ============================================================
       DROPDOWN ANIMADO (.select-wrap nativo já é animado via CSS)
       Apenas adiciona feedback ao focar.
       ============================================================ */
    function initSelectAnim() {
        document.addEventListener('focusin', function (e) {
            if (e.target.matches('select, input')) {
                e.target.style.transition = 'border-color var(--duration-fast) ease, box-shadow var(--duration-fast) ease';
            }
        });
    }

    /* ============================================================
       PROGRESS BARS — shimmer on scroll/visible
       ============================================================ */
    function initProgressAnimation() {
        if (!('IntersectionObserver' in window)) return;

        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var fill = entry.target.querySelector('.progress-fill');
                    if (fill) {
                        var w = fill.style.width;
                        fill.style.width = '0%';
                        requestAnimationFrame(function () {
                            fill.style.width = w;
                        });
                    }
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.4 });

        document.querySelectorAll('.progress-bar').forEach(function (bar) {
            observer.observe(bar);
        });
    }

    /* ============================================================
       SMOOTH REVEAL para seções conforme entram na viewport
       ============================================================ */
    function initReveal() {
        if (!('IntersectionObserver' in window)) return;
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.05, rootMargin: '0px 0px -50px 0px' });

        document.querySelectorAll('.section, .charts-grid, .panel, .chart-card').forEach(function (el) {
            el.classList.add('reveal-on-scroll');
            observer.observe(el);
        });
    }

    /* ============================================================
       REFRESH GRÁFICOS ao redimensionar (com debounce)
       ============================================================ */
    function initChartResize() {
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (typeof Chart === 'undefined') return;
                Object.values(Chart.instances || {}).forEach(function (c) {
                    if (c && c.resize) c.resize();
                });
            }, 200);
        });
    }

    /* ============================================================
       INIT
       ============================================================ */
    function init() {
        initCounters();
        initRipple();
        initButtonLoading();
        initSelectAnim();
        initProgressAnimation();
        initReveal();
        initChartResize();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
(function () {
    'use strict';

    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';
    var COUNTER_DURATION = 1100;
    var PROGRESS_DURATION = 950;
    var PROGRESS_DELAY_STEP = 90;

    function parseBRL(str) {
        if (typeof str !== 'string') return 0;
        var s = str.replace(/[^\d,-]/g, '').replace(/\./g, '').replace(',', '.');
        var n = parseFloat(s);
        return isFinite(n) ? n : 0;
    }

    function formatBRL(n) {
        var neg = n < 0;
        n = Math.abs(n);
        var fixed = n.toFixed(2);
        var intPart = fixed.split('.')[0];
        var decPart = fixed.split('.')[1];
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (neg ? '-R$ ' : 'R$ ') + intPart + ',' + decPart;
    }

    function easeOutQuart(t) {
        return 1 - Math.pow(1 - t, 4);
    }

    function animateCounter(el) {
        var target = parseBRL(el.getAttribute('data-counter') || el.textContent);
        if (prefersReducedMotion || isNaN(target)) {
            el.textContent = formatBRL(target);
            return;
        }
        var start = null;
        function step(ts) {
            if (start === null) start = ts;
            var elapsed = ts - start;
            var progress = Math.min(1, elapsed / COUNTER_DURATION);
            var value = target * easeOutQuart(progress);
            el.textContent = formatBRL(value);
            if (progress < 1) requestAnimationFrame(step);
            else el.textContent = formatBRL(target);
        }
        requestAnimationFrame(step);
    }

    function animateProgress(bar, delay) {
        var target = parseFloat(bar.getAttribute('data-width') || '0');
        if (prefersReducedMotion) {
            bar.style.width = target + '%';
            return;
        }
        bar.style.width = '0%';
        setTimeout(function () {
            bar.style.transition = 'width ' + PROGRESS_DURATION + 'ms ' + EASE;
            bar.style.width = target + '%';
        }, delay || 0);
    }

    function init() {
        var counters = document.querySelectorAll('.dash-metrics [data-counter], .dash-budget-value[data-counter]');
        if (counters.length) {
            if (prefersReducedMotion || !('IntersectionObserver' in window)) {
                counters.forEach(animateCounter);
            } else {
                var seen = new WeakSet();
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting && !seen.has(entry.target)) {
                            seen.add(entry.target);
                            animateCounter(entry.target);
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.2 });
                counters.forEach(function (c) { io.observe(c); });
            }
        }

        var progressEls = document.querySelectorAll('.dash-metrics .progress-fill[data-width], .dash-budget-grid ~ .progress-with-label .progress-fill[data-width], .dash-goal .progress-fill[data-width]');
        progressEls.forEach(function (bar, i) {
            animateProgress(bar, 120 + i * PROGRESS_DELAY_STEP);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

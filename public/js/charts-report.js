document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = '"Inter", "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.font.size = 11.5;
    Chart.defaults.color = '#64748b';

    var brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function fmt(v) { var n = Number(v); return brl.format(isFinite(n) ? n : 0); }

    function emptyState(canvas, msgEl) {
        if (canvas) canvas.style.display = 'none';
        if (msgEl) { msgEl.style.display = 'flex'; }
    }
    function activeState(canvas, msgEl) {
        if (canvas) canvas.style.display = 'block';
        if (msgEl) msgEl.style.display = 'none';
    }
    function destroy(id) { var c = Chart.getChart(id); if (c) c.destroy(); }

    var data = (typeof window.REPORT_CHART_DATA !== 'undefined' && window.REPORT_CHART_DATA) ? window.REPORT_CHART_DATA : null;
    var catColors = ['#10b981','#3b82f6','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#ef4444','#22c55e','#0ea5e9','#a855f7','#f97316','#14b8a6'];
    var gridColor = 'rgba(15, 23, 42, 0.05)';

    var tooltipBase = {
        backgroundColor: 'rgba(15, 23, 42, 0.96)',
        titleColor: '#f8fafc',
        bodyColor: '#cbd5e1',
        borderColor: 'rgba(255,255,255,0.06)',
        borderWidth: 1,
        padding: 12,
        cornerRadius: 8,
        displayColors: true,
        usePointStyle: true,
        titleFont: { weight: '600', size: 12 },
        bodyFont: { size: 12 },
        boxPadding: 4,
        animation: { duration: 180, easing: 'easeOutQuart' }
    };

    /* Receitas x Despesas */
    var ie = document.getElementById('chart-income-expense');
    var ieEmpty = document.getElementById('chart-income-expense-empty');
    if (ie) {
        var d = (data && data.financial_flow) || { labels: [], incomes: [], expenses: [] };
        if (!d.labels || d.labels.length === 0) {
            emptyState(ie, ieEmpty);
        } else {
            activeState(ie, ieEmpty);
            destroy('chart-income-expense');
            new Chart(ie.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: d.labels,
                    datasets: [
                        { label: 'Receitas', data: d.incomes, backgroundColor: '#10b981', borderRadius: 6, borderSkipped: false, barPercentage: 0.7, categoryPercentage: 0.7 },
                        { label: 'Despesas', data: d.expenses, backgroundColor: '#ef4444', borderRadius: 6, borderSkipped: false, barPercentage: 0.7, categoryPercentage: 0.7 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 900, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipBase, { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } } })
                    },
                    scales: {
                        x: { ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { display: false }, border: { display: false } },
                        y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); }, padding: 8 }, grid: { color: gridColor, drawBorder: false }, border: { display: false } }
                    }
                }
            });
        }
    }

    /* Despesas por categoria (Doughnut) */
    var cr = document.getElementById('chart-category-report');
    var crEmpty = document.getElementById('chart-category-report-empty');
    if (cr) {
        var cd = (data && data.expenses_by_category) || { labels: [], values: [] };
        if (!cd.labels || cd.labels.length === 0) {
            emptyState(cr, crEmpty);
        } else {
            activeState(cr, crEmpty);
            destroy('chart-category-report');
            new Chart(cr.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: cd.labels,
                    datasets: [{
                        data: cd.values,
                        backgroundColor: cd.labels.map(function (_, i) { return catColors[i % catColors.length]; }),
                        borderColor: '#fff', borderWidth: 2.5, hoverOffset: 10, spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    animation: { animateRotate: true, animateScale: false, duration: 900, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipBase, { callbacks: { label: function (ctx) { var t = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0); var p = ((ctx.parsed / t) * 100).toFixed(1); return ' ' + ctx.label + ': ' + fmt(ctx.parsed) + ' (' + p + '%)'; } } })
                    }
                }
            });
        }
    }

    /* Evolução do saldo */
    var be = document.getElementById('chart-balance-evolution');
    var beEmpty = document.getElementById('chart-balance-empty');
    if (be) {
        var bd = (data && data.balance_evolution) || { labels: [], values: [] };
        if (!bd.values && bd.balance) bd.values = bd.balance;
        if (!bd.labels || bd.labels.length === 0) {
            emptyState(be, beEmpty);
        } else {
            activeState(be, beEmpty);
            destroy('chart-balance-evolution');
            new Chart(be.getContext('2d'), {
                type: 'line',
                data: {
                    labels: bd.labels,
                    datasets: [{
                        label: 'Saldo',
                        data: bd.values,
                        borderColor: '#10b981',
                        backgroundColor: function (ctx) {
                            var c = ctx.chart.ctx;
                            var g = c.createLinearGradient(0, 0, 0, 220);
                            g.addColorStop(0, 'rgba(16, 185, 129, 0.18)');
                            g.addColorStop(1, 'rgba(16, 185, 129, 0.00)');
                            return g;
                        },
                        tension: 0.38, fill: true, borderWidth: 2.4,
                        pointRadius: 3, pointHoverRadius: 7,
                        pointBackgroundColor: '#10b981', pointBorderColor: '#fff', pointBorderWidth: 1.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 900, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipBase, { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } } })
                    },
                    scales: {
                        x: { ticks: { autoSkip: true, maxRotation: 0, color: '#94a3b8' }, grid: { display: false }, border: { display: false } },
                        y: { ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); }, padding: 8 }, grid: { color: gridColor, drawBorder: false }, border: { display: false } }
                    }
                }
            });
        }
    }
});

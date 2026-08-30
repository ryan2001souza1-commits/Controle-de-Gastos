document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = '"Inter", "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif';
    Chart.defaults.font.size = 11.5;
    Chart.defaults.color = '#64748b';

    var brl = new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency: 'BRL',
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    function fmt(v) {
        var n = Number(v);
        return brl.format(isFinite(n) ? n : 0);
    }

    function getData() {
        if (typeof window.DASHBOARD_CHART_DATA === 'undefined' || !window.DASHBOARD_CHART_DATA) {
            return null;
        }
        return window.DASHBOARD_CHART_DATA;
    }

    function emptyState(canvas, msgEl) {
        if (canvas) canvas.style.display = 'none';
        if (msgEl) {
            msgEl.style.display = 'flex';
            msgEl.style.opacity = '0';
            msgEl.style.transform = 'translateY(4px)';
            requestAnimationFrame(function () {
                msgEl.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
                msgEl.style.opacity = '1';
                msgEl.style.transform = 'translateY(0)';
            });
        }
    }

    function activeState(canvas, msgEl) {
        if (canvas) {
            canvas.style.opacity = '0';
            canvas.style.transform = 'scale(0.99)';
            canvas.style.display = 'block';
            requestAnimationFrame(function () {
                canvas.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                canvas.style.opacity = '1';
                canvas.style.transform = 'scale(1)';
            });
        }
        if (msgEl) msgEl.style.display = 'none';
    }

    function destroy(canvasId) {
        var c = Chart.getChart(canvasId);
        if (c) c.destroy();
    }

    var data = getData();
    if (!data) return;

    function safe(obj, path, fallback) {
        try {
            var cur = obj;
            var parts = path.split('.');
            for (var i = 0; i < parts.length; i++) {
                if (cur == null) return fallback;
                cur = cur[parts[i]];
            }
            return (cur == null) ? fallback : cur;
        } catch (e) { return fallback; }
    }
    function arr(x) { return Array.isArray(x) ? x : []; }
    function num(v) { var n = Number(v); return isFinite(n) ? n : 0; }

    var catColors = [
        '#4f46e5', '#059669', '#dc2626', '#d97706', '#2563eb',
        '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#64748b',
        '#0891b2', '#65a30d', '#ea580c', '#7c3aed', '#be185d'
    ];

    var gridColor = 'rgba(15, 23, 42, 0.05)';

    /* ============== Tooltip padrão melhorado ============== */
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

    /* ============== Chart 0: Fluxo Financeiro ============== */
    var flowCanvas = document.getElementById('chart-financial-flow');
    var flowEmpty  = document.getElementById('chart-flow-empty');
    if (flowCanvas) {
        var ff = (data && data.financial_flow) ? data.financial_flow : { labels: [], incomes: [], expenses: [], balance: [] };
        if (!ff.labels || ff.labels.length === 0) {
            emptyState(flowCanvas, flowEmpty);
        } else {
            activeState(flowCanvas, flowEmpty);
            destroy('chart-financial-flow');
            new Chart(flowCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: ff.labels,
                    datasets: [
                        {
                            label: 'Receitas',
                            data: ff.incomes,
                            borderColor: '#059669',
                            backgroundColor: function(ctx) {
                                var c = ctx.chart.ctx;
                                var g = c.createLinearGradient(0, 0, 0, 280);
                                g.addColorStop(0, 'rgba(5, 150, 105, 0.18)');
                                g.addColorStop(1, 'rgba(5, 150, 105, 0.00)');
                                return g;
                            },
                            tension: 0.38, fill: true, borderWidth: 2.4,
                            pointRadius: 3, pointHoverRadius: 7,
                            pointBackgroundColor: '#059669',
                            pointBorderColor: '#fff', pointBorderWidth: 1.5,
                            pointHoverBorderWidth: 2
                        },
                        {
                            label: 'Despesas',
                            data: ff.expenses,
                            borderColor: '#dc2626',
                            backgroundColor: function(ctx) {
                                var c = ctx.chart.ctx;
                                var g = c.createLinearGradient(0, 0, 0, 280);
                                g.addColorStop(0, 'rgba(220, 38, 38, 0.16)');
                                g.addColorStop(1, 'rgba(220, 38, 38, 0.00)');
                                return g;
                            },
                            tension: 0.38, fill: true, borderWidth: 2.4,
                            pointRadius: 3, pointHoverRadius: 7,
                            pointBackgroundColor: '#dc2626',
                            pointBorderColor: '#fff', pointBorderWidth: 1.5,
                            pointHoverBorderWidth: 2
                        },
                        {
                            label: 'Saldo',
                            data: ff.balance,
                            borderColor: '#4338ca',
                            backgroundColor: 'rgba(67, 56, 202, 0.05)',
                            tension: 0.38, fill: false, borderWidth: 2.5, borderDash: [6, 4],
                            pointRadius: 3, pointHoverRadius: 7,
                            pointBackgroundColor: '#4338ca',
                            pointBorderColor: '#fff', pointBorderWidth: 1.5,
                            pointHoverBorderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 900,
                        easing: 'easeOutQuart',
                        delay: function(ctx) { return ctx.type === 'data' ? ctx.dataIndex * 30 : 0; }
                    },
                    animations: {
                        y: { duration: 600, easing: 'easeOutQuart' },
                        tension: { duration: 800, easing: 'easeOutQuart', from: 0.8, to: 0.38 }
                    },
                    interaction: { mode: 'index', intersect: false },
                    hover: { mode: 'index', intersect: false, animationDuration: 200 },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipBase, {
                            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                        })
                    },
                    scales: {
                        x: {
                            ticks: { autoSkip: true, maxRotation: 0, color: '#94a3b8', font: { size: 11 } },
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: false,
                            ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); }, padding: 8 },
                            grid: { color: gridColor, drawBorder: false },
                            border: { display: false }
                        }
                    }
                }
            });
        }
    }

    /* ============== Chart 1: Despesas por Categoria (Doughnut) ============== */
    var catCanvas = document.getElementById('chart-expenses-by-category');
    var catEmpty  = document.getElementById('chart-category-empty');
    if (catCanvas) {
        var chartCats = (data && Array.isArray(data.categories_chart) && data.categories_chart.length)
            ? data.categories_chart
            : (data && data.expenses_by_category && Array.isArray(data.expenses_by_category.labels) && data.expenses_by_category.labels.length
                ? data.expenses_by_category.labels.map(function (l, i) {
                    var v = (data.expenses_by_category.values || [])[i] || 0;
                    return { label: l, value: v, color: null };
                })
                : []);
        var cLabels = chartCats.map(function (c) { return c.label; });
        var cValues = chartCats.map(function (c) { return c.value; });
        var cColors = chartCats.map(function (c, i) {
            return c.color || catColors[i % catColors.length];
        });
        if (cLabels.length === 0) {
            emptyState(catCanvas, catEmpty);
        } else {
            activeState(catCanvas, catEmpty);
            destroy('chart-expenses-by-category');
            var total = cValues.reduce(function (a, b) { return a + b; }, 0);
            new Chart(catCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: cLabels,
                    datasets: [{
                        data: cValues,
                        backgroundColor: cColors,
                        borderColor: '#fff',
                        borderWidth: 2.5,
                        hoverOffset: 10,
                        hoverBorderWidth: 3,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    animation: {
                        animateRotate: true,
                        animateScale: false,
                        duration: 900,
                        easing: 'easeOutQuart'
                    },
                    animations: {
                        colors: { duration: 250 },
                        numbers: { duration: 600, easing: 'easeOutQuart' }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipBase, {
                            callbacks: {
                                label: function (ctx) {
                                    var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                    return ' ' + ctx.label + ': ' + fmt(ctx.parsed) + ' (' + pct + '%)';
                                }
                            }
                        })
                    }
                }
            });
        }
    }

    /* ============== Chart 2: Receitas x Despesas (Bar) ============== */
    var periodCanvas = document.getElementById('chart-income-vs-expense');
    var periodEmpty  = document.getElementById('chart-period-empty');
    if (periodCanvas) {
        var iRows = data.income_by_period || [];
        var eRows = data.expense_by_period || [];
        if (iRows.length === 0 && eRows.length === 0) {
            emptyState(periodCanvas, periodEmpty);
        } else {
            activeState(periodCanvas, periodEmpty);
            destroy('chart-income-vs-expense');
            var pMap = {};
            iRows.forEach(function (r) { pMap[r.period] = { label: r.label, income: r.total, expense: 0 }; });
            eRows.forEach(function (r) {
                if (!pMap[r.period]) { pMap[r.period] = { label: r.label, income: 0, expense: r.total }; }
                else { pMap[r.period].expense = r.total; }
            });
            var sorted = Object.keys(pMap).sort();
            var pLabels = sorted.map(function (k) { return pMap[k].label; });
            var iData  = sorted.map(function (k) { return pMap[k].income; });
            var eData  = sorted.map(function (k) { return pMap[k].expense; });

            new Chart(periodCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: pLabels,
                    datasets: [
                        {
                            label: 'Receitas', data: iData,
                            backgroundColor: function(ctx) {
                                var c = ctx.chart.ctx;
                                var g = c.createLinearGradient(0, 0, 0, 240);
                                g.addColorStop(0, 'rgba(5, 150, 105, 0.95)');
                                g.addColorStop(1, 'rgba(5, 150, 105, 0.55)');
                                return g;
                            },
                            hoverBackgroundColor: '#047857',
                            borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                            borderSkipped: false,
                            maxBarThickness: 32,
                            borderWidth: 0
                        },
                        {
                            label: 'Despesas', data: eData,
                            backgroundColor: function(ctx) {
                                var c = ctx.chart.ctx;
                                var g = c.createLinearGradient(0, 0, 0, 240);
                                g.addColorStop(0, 'rgba(220, 38, 38, 0.95)');
                                g.addColorStop(1, 'rgba(220, 38, 38, 0.55)');
                                return g;
                            },
                            hoverBackgroundColor: '#b91c1c',
                            borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                            borderSkipped: false,
                            maxBarThickness: 32,
                            borderWidth: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 800,
                        easing: 'easeOutQuart',
                        delay: function(ctx) { return ctx.type === 'data' ? ctx.dataIndex * 60 : 0; }
                    },
                    animations: {
                        y: { duration: 700, easing: 'easeOutQuart' }
                    },
                    interaction: { mode: 'index', intersect: false },
                    hover: { mode: 'index', intersect: false, animationDuration: 200 },
                    plugins: {
                        legend: {
                            position: 'top', align: 'end',
                            labels: { padding: 14, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 12, font: { size: 12, weight: '500' } }
                        },
                        tooltip: Object.assign({}, tooltipBase, {
                            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                        })
                    },
                    scales: {
                        x: {
                            ticks: { color: '#94a3b8', font: { size: 11 } },
                            grid: { display: false },
                            border: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); }, padding: 8 },
                            grid: { color: gridColor, drawBorder: false },
                            border: { display: false }
                        }
                    }
                }
            });
        }
    }

    /* ============== Chart 3: Evolução do Saldo (Line) ============== */
    var balCanvas = document.getElementById('chart-balance-evolution');
    var balEmpty  = document.getElementById('chart-balance-empty');
    if (balCanvas) {
        var bal = data.balance_evolution || { labels: [], balance: [] };
        if (!bal.labels || bal.labels.length === 0) {
            emptyState(balCanvas, balEmpty);
        } else {
            activeState(balCanvas, balEmpty);
            destroy('chart-balance-evolution');

            new Chart(balCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: bal.labels,
                    datasets: [{
                        label: 'Saldo acumulado',
                        data: bal.balance,
                        borderColor: '#4338ca',
                        backgroundColor: function(ctx) {
                            var c = ctx.chart.ctx;
                            var g = c.createLinearGradient(0, 0, 0, 280);
                            g.addColorStop(0, 'rgba(67, 56, 202, 0.22)');
                            g.addColorStop(1, 'rgba(67, 56, 202, 0.00)');
                            return g;
                        },
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2.6,
                        pointRadius: 3,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#4338ca',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 1.5,
                        pointHoverBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart',
                        delay: function(ctx) { return ctx.type === 'data' ? ctx.dataIndex * 35 : 0; }
                    },
                    animations: {
                        y: { duration: 700, easing: 'easeOutQuart' },
                        tension: { duration: 900, easing: 'easeOutQuart', from: 0.85, to: 0.4 }
                    },
                    interaction: { mode: 'index', intersect: false },
                    hover: { mode: 'index', intersect: false, animationDuration: 200 },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipBase, {
                            displayColors: false,
                            callbacks: {
                                title: function (ctx) { return ctx[0].label; },
                                label: function (ctx) { return 'Saldo: ' + fmt(ctx.parsed.y); }
                            }
                        })
                    },
                    scales: {
                        x: {
                            ticks: { color: '#94a3b8', font: { size: 11 } },
                            grid: { display: false },
                            border: { display: false }
                        },
                        y: {
                            ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); }, padding: 8 },
                            grid: { color: gridColor, drawBorder: false },
                            border: { display: false }
                        }
                    }
                }
            });
        }
    }

    /* ============== Chart 4: Comparativo Mensal (Bar + Line) ============== */
    var monCanvas = document.getElementById('chart-monthly-comparison');
    var monEmpty  = document.getElementById('chart-monthly-empty');
    if (monCanvas) {
        var monthly = (typeof window.DASHBOARD_MONTHLY_COMPARISON !== 'undefined' && window.DASHBOARD_MONTHLY_COMPARISON) || [];
        if (monthly.length === 0) {
            emptyState(monCanvas, monEmpty);
        } else {
            activeState(monCanvas, monEmpty);
            destroy('chart-monthly-comparison');

            var mLabels  = monthly.map(function (m) { return m.label; });
            var mIncomes = monthly.map(function (m) { return Number(m.income)  || 0; });
            var mExpense = monthly.map(function (m) { return Number(m.expense) || 0; });
            var mBalance = monthly.map(function (m) { return Number(m.balance) || 0; });

            new Chart(monCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: mLabels,
                    datasets: [
                        {
                            label: 'Receitas', data: mIncomes,
                            backgroundColor: function(ctx) {
                                var c = ctx.chart.ctx;
                                var g = c.createLinearGradient(0, 0, 0, 240);
                                g.addColorStop(0, 'rgba(5, 150, 105, 0.95)');
                                g.addColorStop(1, 'rgba(5, 150, 105, 0.55)');
                                return g;
                            },
                            hoverBackgroundColor: '#047857',
                            borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                            borderSkipped: false,
                            maxBarThickness: 30,
                            borderWidth: 0
                        },
                        {
                            label: 'Despesas', data: mExpense,
                            backgroundColor: function(ctx) {
                                var c = ctx.chart.ctx;
                                var g = c.createLinearGradient(0, 0, 0, 240);
                                g.addColorStop(0, 'rgba(220, 38, 38, 0.95)');
                                g.addColorStop(1, 'rgba(220, 38, 38, 0.55)');
                                return g;
                            },
                            hoverBackgroundColor: '#b91c1c',
                            borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                            borderSkipped: false,
                            maxBarThickness: 30,
                            borderWidth: 0
                        },
                        {
                            type: 'line', label: 'Saldo',
                            data: mBalance,
                            borderColor: '#4338ca',
                            backgroundColor: 'rgba(67, 56, 202, 0.05)',
                            tension: 0.38,
                            borderWidth: 2.6,
                            borderDash: [6, 4],
                            pointRadius: 4,
                            pointHoverRadius: 7,
                            pointBackgroundColor: '#4338ca',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 1.5,
                            pointHoverBorderWidth: 2,
                            order: -1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 900,
                        easing: 'easeOutQuart',
                        delay: function(ctx) { return ctx.type === 'data' ? ctx.dataIndex * 50 : 0; }
                    },
                    animations: {
                        y: { duration: 700, easing: 'easeOutQuart' }
                    },
                    interaction: { mode: 'index', intersect: false },
                    hover: { mode: 'index', intersect: false, animationDuration: 200 },
                    plugins: {
                        legend: {
                            position: 'top', align: 'end',
                            labels: { padding: 14, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 12, font: { size: 12, weight: '500' } }
                        },
                        tooltip: Object.assign({}, tooltipBase, {
                            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                        })
                    },
                    scales: {
                        x: {
                            ticks: { color: '#94a3b8', font: { size: 11 } },
                            grid: { display: false },
                            border: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); }, padding: 8 },
                            grid: { color: gridColor, drawBorder: false },
                            border: { display: false }
                        }
                    }
                }
            });
        }
    }

    /* ============== Charts dashboard: ativar pulse em datasets ao hover ============== */
    document.querySelectorAll('.chart-card').forEach(function (card) {
        card.addEventListener('mouseenter', function () {
            var c = card.querySelector('canvas');
            if (c && Chart.getChart(c.id)) {
                Chart.getChart(c.id).update('none');
            }
        });
    });
});
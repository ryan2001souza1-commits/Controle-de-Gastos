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
        }
    }

    function activeState(canvas, msgEl) {
        if (canvas) canvas.style.display = 'block';
        if (msgEl) msgEl.style.display = 'none';
    }

    function destroy(canvasId) {
        var c = Chart.getChart(canvasId);
        if (c) c.destroy();
    }

    var data = getData();
    if (!data) return;

    var catColors = [
        '#4f46e5', '#059669', '#dc2626', '#d97706', '#2563eb',
        '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#64748b',
        '#0891b2', '#65a30d', '#ea580c', '#7c3aed', '#be185d'
    ];

    var gridColor = 'rgba(15, 23, 42, 0.05)';

    /* ============== Chart 0: Fluxo Financeiro ============== */
    var flowCanvas = document.getElementById('chart-financial-flow');
    var flowEmpty  = document.getElementById('chart-flow-empty');
    if (flowCanvas) {
        var ff = data.financial_flow || { labels: [], incomes: [], expenses: [], balance: [] };
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
                            backgroundColor: 'rgba(5, 150, 105, 0.10)',
                            tension: 0.35, fill: true, borderWidth: 2.2,
                            pointRadius: 3, pointHoverRadius: 6,
                            pointBackgroundColor: '#059669', pointBorderColor: '#fff', pointBorderWidth: 1.5
                        },
                        {
                            label: 'Despesas',
                            data: ff.expenses,
                            borderColor: '#dc2626',
                            backgroundColor: 'rgba(220, 38, 38, 0.10)',
                            tension: 0.35, fill: true, borderWidth: 2.2,
                            pointRadius: 3, pointHoverRadius: 6,
                            pointBackgroundColor: '#dc2626', pointBorderColor: '#fff', pointBorderWidth: 1.5
                        },
                        {
                            label: 'Saldo',
                            data: ff.balance,
                            borderColor: '#4338ca',
                            backgroundColor: 'rgba(67, 56, 202, 0.05)',
                            tension: 0.35, fill: false, borderWidth: 2.5, borderDash: [6, 4],
                            pointRadius: 3, pointHoverRadius: 6,
                            pointBackgroundColor: '#4338ca', pointBorderColor: '#fff', pointBorderWidth: 1.5
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255,255,255,0.06)', borderWidth: 1,
                            padding: 12, cornerRadius: 8, displayColors: true, usePointStyle: true,
                            titleFont: { weight: '600' },
                            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                        }
                    },
                    scales: {
                        x: { ticks: { autoSkip: true, maxRotation: 0, color: '#94a3b8', font: { size: 11 } }, grid: { display: false } },
                        y: { beginAtZero: false, ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); } }, grid: { color: gridColor, drawBorder: false } }
                    }
                }
            });
        }
    }

    /* ============== Chart 1: Despesas por Categoria (Doughnut) ============== */
    var catCanvas = document.getElementById('chart-expenses-by-category');
    var catEmpty  = document.getElementById('chart-category-empty');
    if (catCanvas) {
        var cLabels = data.expenses_by_category.labels || [];
        var cValues = data.expenses_by_category.values || [];
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
                        backgroundColor: cLabels.map(function (_, i) { return catColors[i % catColors.length]; }),
                        borderColor: '#fff',
                        borderWidth: 2, hoverOffset: 8, spacing: 2
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    animation: { duration: 600, animateRotate: true, animateScale: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 12, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, font: { size: 11.5 } }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255,255,255,0.06)', borderWidth: 1,
                            padding: 12, cornerRadius: 8, displayColors: true,
                            callbacks: {
                                label: function (ctx) {
                                    var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                    return ' ' + ctx.label + ': ' + fmt(ctx.parsed) + ' (' + pct + '%)';
                                }
                            }
                        }
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
                        { label: 'Receitas', data: iData, backgroundColor: 'rgba(5, 150, 105, 0.85)', borderColor: '#059669', borderWidth: 0, borderRadius: 6, borderSkipped: false, maxBarThickness: 28 },
                        { label: 'Despesas', data: eData, backgroundColor: 'rgba(220, 38, 38, 0.85)', borderColor: '#dc2626', borderWidth: 0, borderRadius: 6, borderSkipped: false, maxBarThickness: 28 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 500, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top', align: 'end',
                            labels: { padding: 14, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 12, font: { size: 12, weight: '500' } }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255,255,255,0.05)', borderWidth: 1,
                            padding: 12, cornerRadius: 8, displayColors: true, usePointStyle: true,
                            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); } }, grid: { color: gridColor, drawBorder: false } }
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

            var gradient = balCanvas.getContext('2d').createLinearGradient(0, 0, 0, 280);
            gradient.addColorStop(0, 'rgba(67, 56, 202, 0.18)');
            gradient.addColorStop(1, 'rgba(67, 56, 202, 0.00)');

            new Chart(balCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: bal.labels,
                    datasets: [{
                        label: 'Saldo acumulado',
                        data: bal.balance,
                        borderColor: '#4338ca',
                        backgroundColor: gradient,
                        tension: 0.4, fill: true, borderWidth: 2.5,
                        pointRadius: 3, pointHoverRadius: 6,
                        pointBackgroundColor: '#4338ca', pointBorderColor: '#fff', pointBorderWidth: 1.5
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255,255,255,0.05)', borderWidth: 1,
                            padding: 12, cornerRadius: 8, displayColors: false,
                            titleFont: { weight: '600' },
                            callbacks: {
                                title: function (ctx) { return ctx[0].label; },
                                label: function (ctx) { return 'Saldo: ' + fmt(ctx.parsed.y); }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { display: false } },
                        y: { ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); } }, grid: { color: gridColor, drawBorder: false } }
                    }
                }
            });
        }
    }

    /* ============== Chart 4: Comparativo Mensal (Bar) ============== */
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
                        { label: 'Receitas', data: mIncomes, backgroundColor: 'rgba(5, 150, 105, 0.85)', borderColor: '#059669', borderWidth: 0, borderRadius: 6, borderSkipped: false, maxBarThickness: 28 },
                        { label: 'Despesas', data: mExpense, backgroundColor: 'rgba(220, 38, 38, 0.85)', borderColor: '#dc2626', borderWidth: 0, borderRadius: 6, borderSkipped: false, maxBarThickness: 28 },
                        {
                            type: 'line', label: 'Saldo',
                            data: mBalance, borderColor: '#4338ca',
                            backgroundColor: 'rgba(67, 56, 202, 0.05)',
                            tension: 0.35, borderWidth: 2.5, borderDash: [6, 4],
                            pointRadius: 4, pointHoverRadius: 6,
                            pointBackgroundColor: '#4338ca', pointBorderColor: '#fff', pointBorderWidth: 1.5
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top', align: 'end',
                            labels: { padding: 14, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 12, font: { size: 12, weight: '500' } }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255,255,255,0.05)', borderWidth: 1,
                            padding: 12, cornerRadius: 8, displayColors: true, usePointStyle: true,
                            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: function (v) { return fmt(v); } }, grid: { color: gridColor, drawBorder: false } }
                    }
                }
            });
        }
    }
});

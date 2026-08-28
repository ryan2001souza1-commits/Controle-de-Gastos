document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

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
            msgEl.style.justifyContent = 'center';
            msgEl.style.alignItems = 'center';
            msgEl.style.height = '200px';
            msgEl.style.color = '#9ca3af';
            msgEl.style.fontSize = '0.875rem';
            msgEl.style.fontStyle = 'italic';
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
        '#4f46e5', '#16a34a', '#dc2626', '#f59e0b', '#0ea5e9',
        '#a855f7', '#ec4899', '#14b8a6', '#eab308', '#64748b',
        '#06b6d4', '#84cc16', '#f97316', '#8b5cf6', '#db2777'
    ];

    // ============== Chart 0: Fluxo Financeiro (Linha com 3 séries) ==============
    var flowCanvas = document.getElementById('chart-financial-flow');
    var flowEmpty  = document.getElementById('chart-flow-empty');
    if (flowCanvas) {
        var ff = data.financial_flow || { labels: [], incomes: [], expenses: [], balance: [] };
        if (ff.labels.length === 0) {
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
                            borderColor: '#16a34a',
                            backgroundColor: '#16a34a22',
                            tension: 0.3, fill: true, borderWidth: 2,
                            pointRadius: 3, pointBackgroundColor: '#16a34a'
                        },
                        {
                            label: 'Despesas',
                            data: ff.expenses,
                            borderColor: '#dc2626',
                            backgroundColor: '#dc262622',
                            tension: 0.3, fill: true, borderWidth: 2,
                            pointRadius: 3, pointBackgroundColor: '#dc2626'
                        },
                        {
                            label: 'Saldo',
                            data: ff.balance,
                            borderColor: '#4f46e5',
                            backgroundColor: '#4f46e522',
                            tension: 0.3, fill: false, borderWidth: 2, borderDash: [5, 5],
                            pointRadius: 3, pointBackgroundColor: '#4f46e5'
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 400 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { padding: 16, usePointStyle: true, font: { size: 12 } } },
                        tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } } }
                    },
                    scales: {
                        x: { ticks: { autoSkip: false, maxRotation: 45 }, grid: { display: false } },
                        y: { beginAtZero: false, ticks: { callback: function (v) { return fmt(v); } } }
                    }
                }
            });
        }
    }

    // ============== Chart 1: Despesas por Categoria (Doughnut) ==============
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
                        backgroundColor: cLabels.map(function (_, i) { return catColors[i % catColors.length] + 'cc'; }),
                        borderColor: cLabels.map(function (_, i) { return catColors[i % catColors.length]; }),
                        borderWidth: 1.5, hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '55%',
                    animation: { duration: 300 },
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, font: { size: 11 } } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                    return ctx.label + ': ' + fmt(ctx.parsed) + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // ============== Chart 2: Receitas x Despesas (Barras Agrupadas) ==============
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
                        { label: 'Receitas', data: iData, backgroundColor: '#16a34a99', borderColor: '#16a34a', borderWidth: 1.5, borderRadius: 4, borderSkipped: false },
                        { label: 'Despesas', data: eData, backgroundColor: '#dc262699', borderColor: '#dc2626', borderWidth: 1.5, borderRadius: 4, borderSkipped: false }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 300 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { padding: 14, usePointStyle: true, font: { size: 12 } } },
                        tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } } }
                    },
                    scales: {
                        x: { ticks: { autoSkip: false, maxRotation: 45 }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } }
                    }
                }
            });
        }
    }

    // ============== Chart 3: Evolução do Saldo (Linha) ==============
    var balCanvas = document.getElementById('chart-balance-evolution');
    var balEmpty  = document.getElementById('chart-balance-empty');
    if (balCanvas) {
        var bal = data.balance_evolution || { labels: [], balance: [] };
        if (bal.labels.length === 0) {
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
                        borderColor: '#4f46e5',
                        backgroundColor: '#4f46e522',
                        tension: 0.3, fill: true, borderWidth: 2.5,
                        pointRadius: 4, pointBackgroundColor: '#4f46e5'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 300 },
                    plugins: {
                        legend: { position: 'top', labels: { padding: 14, usePointStyle: true, font: { size: 12 } } },
                        tooltip: { callbacks: { label: function (ctx) { return 'Saldo: ' + fmt(ctx.parsed.y); } } }
                    },
                    scales: {
                        x: { ticks: { autoSkip: false, maxRotation: 45 }, grid: { display: false } },
                        y: { ticks: { callback: function (v) { return fmt(v); } } }
                    }
                }
            });
        }
    }
});

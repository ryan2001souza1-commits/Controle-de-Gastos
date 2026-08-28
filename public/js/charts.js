document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    var brl = function (v) {
        return 'R$ ' + Number(v).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    var chartData = (typeof window.DASHBOARD_CHART_DATA !== 'undefined')
        ? window.DASHBOARD_CHART_DATA
        : null;

    if (!chartData) return;

    // ============== Helpers ==============
    function showEmpty(canvas, msgEl) {
        if (canvas) canvas.style.display = 'none';
        if (msgEl) { msgEl.style.display = 'block'; msgEl.textContent = msgEl.dataset.msg || 'Nenhum dado encontrado para o período selecionado.'; }
    }

    function showCanvas(canvas, msgEl) {
        if (canvas) canvas.style.display = 'block';
        if (msgEl) msgEl.style.display = 'none';
    }

    // ============== Chart 1: Despesas por Categoria (Doughnut) ==============
    var catCanvas = document.getElementById('chart-expenses-by-category');
    var catEmpty  = document.getElementById('chart-category-empty');

    if (catCanvas) {
        var catLabels = chartData.expenses_by_category.labels || [];
        var catValues = chartData.expenses_by_category.values || [];

        if (catLabels.length === 0) {
            showEmpty(catCanvas, catEmpty);
        } else {
            showCanvas(catCanvas, catEmpty);

            var catColors = [
                '#4f46e5', '#16a34a', '#dc2626', '#f59e0b', '#0ea5e9',
                '#a855f7', '#ec4899', '#14b8a6', '#eab308', '#64748b',
                '#06b6d4', '#84cc16', '#f97316', '#8b5cf6', '#db2777'
            ];

            new Chart(catCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{
                        data: catValues,
                        backgroundColor: catLabels.map(function (_, i) {
                            return catColors[i % catColors.length] + 'dd';
                        }),
                        borderColor: catLabels.map(function (_, i) {
                            return catColors[i % catColors.length];
                        }),
                        borderWidth: 1.5,
                        hoverOffset: 8,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 16,
                                usePointStyle: true,
                                pointStyleWidth: 10,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                    var pct = ((ctx.parsed / total) * 100).toFixed(1);
                                    return ctx.label + ': ' + brl(ctx.parsed) + ' (' + pct + '%)';
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
        var incomeRows  = chartData.income_by_period  || [];
        var expenseRows = chartData.expense_by_period || [];

        if (incomeRows.length === 0 && expenseRows.length === 0) {
            showEmpty(periodCanvas, periodEmpty);
        } else {
            showCanvas(periodCanvas, periodEmpty);

            // Build a unified map keyed by period_date (sortable string)
            var periodMap = {};

            incomeRows.forEach(function (r) {
                periodMap[r.period] = {
                    label: r.label,
                    income: r.total,
                    expense: 0
                };
            });

            expenseRows.forEach(function (r) {
                if (!periodMap[r.period]) {
                    periodMap[r.period] = {
                        label: r.label,
                        income: 0,
                        expense: r.total
                    };
                } else {
                    periodMap[r.period].expense = r.total;
                }
            });

            var sortedPeriods = Object.keys(periodMap).sort();
            var labels = sortedPeriods.map(function (p) { return periodMap[p].label; });
            var incomeData  = sortedPeriods.map(function (p) { return periodMap[p].income; });
            var expenseData = sortedPeriods.map(function (p) { return periodMap[p].expense; });

            new Chart(periodCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Receitas',
                            data: incomeData,
                            backgroundColor: '#16a34a99',
                            borderColor: '#16a34a',
                            borderWidth: 1.5,
                            borderRadius: 4,
                            borderSkipped: false,
                        },
                        {
                            label: 'Despesas',
                            data: expenseData,
                            backgroundColor: '#dc262699',
                            borderColor: '#dc2626',
                            borderWidth: 1.5,
                            borderRadius: 4,
                            borderSkipped: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 300 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                padding: 16,
                                usePointStyle: true,
                                pointStyleWidth: 10,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ctx.dataset.label + ': ' + brl(ctx.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                autoSkip: false,
                                maxRotation: 45,
                                minRotation: 0
                            },
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (v) { return brl(v); }
                            }
                        }
                    }
                }
            });
        }
    }
});

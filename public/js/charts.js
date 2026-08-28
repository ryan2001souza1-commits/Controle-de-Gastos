document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    var brl = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function fmt(value) {
        var n = Number(value);
        if (!isFinite(n)) n = 0;
        return brl.format(n);
    }

    function getData() {
        if (typeof window.DASHBOARD_CHART_DATA === 'undefined' || !window.DASHBOARD_CHART_DATA) {
            return null;
        }
        return window.DASHBOARD_CHART_DATA;
    }

    function showEmpty(canvas, msgEl) {
        if (canvas) canvas.style.display = 'none';
        if (msgEl) {
            msgEl.style.display = 'block';
            msgEl.textContent = msgEl.dataset.msg || 'Nenhum dado encontrado para o período selecionado.';
        }
    }

    function showCanvas(canvas, msgEl) {
        if (canvas) canvas.style.display = 'block';
        if (msgEl) msgEl.style.display = 'none';
    }

    function destroyExisting(canvasId) {
        var existing = Chart.getChart(canvasId);
        if (existing) existing.destroy();
    }

    var data = getData();
    if (!data) return;

    var categoryColors = [
        '#4f46e5', '#16a34a', '#dc2626', '#f59e0b', '#0ea5e9',
        '#a855f7', '#ec4899', '#14b8a6', '#eab308', '#64748b',
        '#06b6d4', '#84cc16', '#f97316', '#8b5cf6', '#db2777'
    ];

    // ============== Chart 1: Despesas por Categoria (Doughnut) ==============
    var catCanvas = document.getElementById('chart-expenses-by-category');
    var catEmpty  = document.getElementById('chart-category-empty');

    if (catCanvas) {
        var catLabels = data.expenses_by_category.labels || [];
        var catValues = data.expenses_by_category.values || [];

        if (catLabels.length === 0) {
            showEmpty(catCanvas, catEmpty);
        } else {
            showCanvas(catCanvas, catEmpty);
            destroyExisting('chart-expenses-by-category');

            var total = catValues.reduce(function (a, b) { return a + b; }, 0);

            new Chart(catCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{
                        data: catValues,
                        backgroundColor: catLabels.map(function (_, i) {
                            return categoryColors[i % categoryColors.length] + 'dd';
                        }),
                        borderColor: catLabels.map(function (_, i) {
                            return categoryColors[i % categoryColors.length];
                        }),
                        borderWidth: 1.5,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    animation: { duration: 300 },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 14,
                                usePointStyle: true,
                                pointStyleWidth: 10,
                                font: { size: 12 }
                            }
                        },
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
        var incomeRows  = data.income_by_period  || [];
        var expenseRows = data.expense_by_period || [];

        if (incomeRows.length === 0 && expenseRows.length === 0) {
            showEmpty(periodCanvas, periodEmpty);
        } else {
            showCanvas(periodCanvas, periodEmpty);
            destroyExisting('chart-income-vs-expense');

            var periodMap = {};
            incomeRows.forEach(function (r) {
                periodMap[r.period] = { label: r.label, income: r.total, expense: 0 };
            });
            expenseRows.forEach(function (r) {
                if (!periodMap[r.period]) {
                    periodMap[r.period] = { label: r.label, income: 0, expense: r.total };
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
                            borderSkipped: false
                        },
                        {
                            label: 'Despesas',
                            data: expenseData,
                            backgroundColor: '#dc262699',
                            borderColor: '#dc2626',
                            borderWidth: 1.5,
                            borderRadius: 4,
                            borderSkipped: false
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
                                padding: 14,
                                usePointStyle: true,
                                pointStyleWidth: 10,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ctx.dataset.label + ': ' + fmt(ctx.parsed.y);
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
                                callback: function (v) { return fmt(v); }
                            }
                        }
                    }
                }
            });
        }
    }

    // ============== Chart 3: Evolução do Saldo (Linha) ==============
    var balanceCanvas = document.getElementById('chart-balance-evolution');
    var balanceEmpty  = document.getElementById('chart-balance-empty');

    if (balanceCanvas) {
        var balance = data.balance_evolution || { labels: [], balance: [] };

        if (!balance.labels || balance.labels.length === 0) {
            showEmpty(balanceCanvas, balanceEmpty);
        } else {
            showCanvas(balanceCanvas, balanceEmpty);
            destroyExisting('chart-balance-evolution');

            new Chart(balanceCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: balance.labels,
                    datasets: [{
                        label: 'Saldo acumulado',
                        data: balance.balance,
                        borderColor: '#4f46e5',
                        backgroundColor: '#4f46e522',
                        tension: 0.3,
                        fill: true,
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointBackgroundColor: '#4f46e5',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 1.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 300 },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                padding: 14,
                                usePointStyle: true,
                                pointStyleWidth: 10,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return 'Saldo: ' + fmt(ctx.parsed.y);
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
                            ticks: {
                                callback: function (v) { return fmt(v); }
                            }
                        }
                    }
                }
            });
        }
    }
});

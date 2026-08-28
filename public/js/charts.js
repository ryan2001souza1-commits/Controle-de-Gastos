document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;

    const categoryCanvas = document.getElementById('chart-expenses-by-category');
    const periodCanvas   = document.getElementById('chart-income-vs-expense');
    const categoryEmpty  = document.getElementById('chart-category-empty');
    const periodEmpty    = document.getElementById('chart-period-empty');

    const brl = (value) => 'R$ ' + Number(value).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    const categoryColors = [
        '#4f46e5', '#16a34a', '#dc2626', '#f59e0b', '#0ea5e9',
        '#a855f7', '#ec4899', '#14b8a6', '#eab308', '#64748b'
    ];

    const chartData = (typeof window.DASHBOARD_CHART_DATA !== 'undefined')
        ? window.DASHBOARD_CHART_DATA
        : null;

    if (!chartData) return;

    // ============== Chart 1: Despesas por Categoria ==============
    if (categoryCanvas) {
        const labels = chartData.expenses_by_category.labels || [];
        const values = chartData.expenses_by_category.values || [];

        if (labels.length === 0) {
            categoryCanvas.style.display = 'none';
            if (categoryEmpty) categoryEmpty.style.display = 'block';
        } else {
            if (categoryEmpty) categoryEmpty.style.display = 'none';
            categoryCanvas.style.display = 'block';

            new Chart(categoryCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Despesas',
                        data: values,
                        backgroundColor: labels.map((_, i) => categoryColors[i % categoryColors.length] + 'cc'),
                        borderColor:     labels.map((_, i) => categoryColors[i % categoryColors.length]),
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => 'Valor: ' + brl(ctx.parsed.y)
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (v) => brl(v)
                            }
                        },
                        x: {
                            ticks: {
                                autoSkip: false,
                                maxRotation: 45,
                                minRotation: 0
                            }
                        }
                    }
                }
            });
        }
    }

    // ============== Chart 2: Receitas x Despesas ==============
    if (periodCanvas) {
        const incomeData  = chartData.income_by_period  || [];
        const expenseData = chartData.expense_by_period || [];

        if (incomeData.length === 0 && expenseData.length === 0) {
            periodCanvas.style.display = 'none';
            if (periodEmpty) periodEmpty.style.display = 'block';
        } else {
            if (periodEmpty) periodEmpty.style.display = 'none';
            periodCanvas.style.display = 'block';

            const allLabels = new Set();
            incomeData.forEach(r  => allLabels.add(r.label));
            expenseData.forEach(r => allLabels.add(r.label));

            const labelOrder = [];
            const byLabel = {};
            incomeData.forEach(r => {
                byLabel[r.label] = byLabel[r.label] || { income: 0, expense: 0 };
                byLabel[r.label].income = r.total;
            });
            expenseData.forEach(r => {
                byLabel[r.label] = byLabel[r.label] || { income: 0, expense: 0 };
                byLabel[r.label].expense = r.total;
            });

            const sortedIncome  = [...incomeData].sort((a, b) => a.period.localeCompare(b.period));
            const sortedExpense = [...expenseData].sort((a, b) => a.period.localeCompare(b.period));

            const periodMap = new Map();
            sortedIncome.forEach(r  => periodMap.set(r.period, { label: r.label, income: r.total, expense: 0 }));
            sortedExpense.forEach(r => {
                if (periodMap.has(r.period)) {
                    periodMap.get(r.period).expense = r.total;
                } else {
                    periodMap.set(r.period, { label: r.label, income: 0, expense: r.total });
                }
            });

            const sortedKeys = Array.from(periodMap.keys()).sort();
            const labels   = sortedKeys.map(k => periodMap.get(k).label);
            const incomeVals  = sortedKeys.map(k => periodMap.get(k).income);
            const expenseVals = sortedKeys.map(k => periodMap.get(k).expense);

            new Chart(periodCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Receitas',
                            data: incomeVals,
                            borderColor: '#16a34a',
                            backgroundColor: '#16a34a22',
                            tension: 0.3,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: '#16a34a',
                        },
                        {
                            label: 'Despesas',
                            data: expenseVals,
                            borderColor: '#dc2626',
                            backgroundColor: '#dc262622',
                            tension: 0.3,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: '#dc2626',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.dataset.label + ': ' + brl(ctx.parsed.y)
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (v) => brl(v)
                            }
                        }
                    }
                }
            });
        }
    }
});

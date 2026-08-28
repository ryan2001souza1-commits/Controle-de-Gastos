document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;

    const categoryCanvas = document.getElementById('chart-expenses-by-category');
    const periodCanvas   = document.getElementById('chart-income-vs-expense');

    const categoryEmpty = document.getElementById('chart-category-empty');
    const periodEmpty   = document.getElementById('chart-period-empty');

    const brl = (value) => 'R$ ' + Number(value).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    const colors = [
        '#4f46e5', '#16a34a', '#dc2626', '#f59e0b', '#0ea5e9',
        '#a855f7', '#ec4899', '#14b8a6', '#eab308', '#64748b'
    ];

    const chartDataRaw = (typeof window.DASHBOARD_CHART_DATA !== 'undefined')
        ? window.DASHBOARD_CHART_DATA
        : null;

    if (!chartDataRaw) return;

    // ============== Chart 1: Despesas por Categoria ==============
    if (categoryCanvas) {
        const labels = chartDataRaw.expenses_by_category.labels || [];
        const values = chartDataRaw.expenses_by_category.values || [];

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
                        backgroundColor: labels.map((_, i) => colors[i % colors.length] + 'cc'),
                        borderColor:     labels.map((_, i) => colors[i % colors.length]),
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
        const income  = chartDataRaw.income_by_period  || [];
        const expense = chartDataRaw.expense_by_period || [];

        if (income.length === 0 && expense.length === 0) {
            periodCanvas.style.display = 'none';
            if (periodEmpty) periodEmpty.style.display = 'block';
        } else {
            if (periodEmpty) periodEmpty.style.display = 'none';
            periodCanvas.style.display = 'block';

            const periodMap = new Map();
            income.forEach(r => periodMap.set(r.period, {
                label: r.label,
                income: parseFloat(r.total) || 0,
                expense: 0
            }));
            expense.forEach(r => {
                if (!periodMap.has(r.period)) {
                    periodMap.set(r.period, { label: r.label, income: 0, expense: 0 });
                }
                periodMap.get(r.period).expense = parseFloat(r.total) || 0;
            });

            const sorted = Array.from(periodMap.entries())
                .sort((a, b) => a[0].localeCompare(b[0]));

            const labels   = sorted.map(([_, v]) => v.label);
            const incomeData  = sorted.map(([_, v]) => v.income);
            const expenseData = sorted.map(([_, v]) => v.expense);

            new Chart(periodCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Receitas',
                            data: incomeData,
                            borderColor: '#16a34a',
                            backgroundColor: '#16a34a33',
                            tension: 0.3,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 4,
                        },
                        {
                            label: 'Despesas',
                            data: expenseData,
                            borderColor: '#dc2626',
                            backgroundColor: '#dc262633',
                            tension: 0.3,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 4,
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

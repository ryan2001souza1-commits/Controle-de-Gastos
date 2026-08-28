<?php

class ExpenseService
{
    private Expense $expenseModel;
    private Income $incomeModel;

    public function __construct(Expense $expenseModel, Income $incomeModel)
    {
        $this->expenseModel = $expenseModel;
        $this->incomeModel = $incomeModel;
    }

    public function getDashboardData(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $totalExpenses = $this->expenseModel->getTotalByUser($userId, $startDate, $endDate);
        $totalIncomes  = $this->incomeModel->getTotalByUser($userId, $startDate, $endDate);
        $expensesByCategory = $this->expenseModel->getTotalByCategory($userId, $startDate, $endDate);

        $recentExpenses = $this->expenseModel->findByUser($userId, $startDate, $endDate);
        $recentIncomes  = $this->incomeModel->findByUser($userId, $startDate, $endDate);

        $normalizedIncomes = array_map(function ($row) {
            return [
                'id'            => $row['id']            ?? null,
                'description'   => $row['description']   ?? '',
                'amount'        => $row['amount']        ?? 0,
                'date'          => $row['date']          ?? null,
                'type'          => $row['type']          ?? 'receita',
                'category_name' => null,
            ];
        }, $recentIncomes);

        $recentTransactions = array_merge($recentExpenses, $normalizedIncomes);

        usort($recentTransactions, function ($a, $b) {
            $da = isset($a['date']) ? strtotime($a['date']) : 0;
            $db = isset($b['date']) ? strtotime($b['date']) : 0;
            return $db <=> $da;
        });

        $recentTransactions = array_slice($recentTransactions, 0, 10);

        $groupBy = $this->resolveGroupBy($startDate, $endDate);
        $incomeByPeriod  = $this->incomeModel->getTotalsByPeriod($userId, $startDate, $endDate, $groupBy);
        $expenseByPeriod = $this->expenseModel->getTotalsByPeriod($userId, $startDate, $endDate, $groupBy);

        $expensesByCategoryForChart = $this->normalizeExpensesByCategoryForChart($expensesByCategory);

        return [
            'total_expenses'       => $totalExpenses,
            'total_incomes'        => $totalIncomes,
            'balance'              => $totalIncomes - $totalExpenses,
            'expenses_by_category' => $expensesByCategory,
            'recent_transactions'  => $recentTransactions,
            'chart_data'           => [
                'expenses_by_category' => $expensesByCategoryForChart,
                'income_by_period'     => $incomeByPeriod,
                'expense_by_period'    => $expenseByPeriod,
                'group_by'             => $groupBy,
            ],
        ];
    }

    private function resolveGroupBy(?string $startDate, ?string $endDate): string
    {
        if (!$startDate || !$endDate) {
            return 'day';
        }
        $start = new DateTime($startDate);
        $end   = new DateTime($endDate);
        $diff  = $start->diff($end)->days;
        return $diff > 60 ? 'month' : 'day';
    }

    private function normalizeExpensesByCategoryForChart(array $rows): array
    {
        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $total = (float)($r['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }
            $labels[] = $r['name'] ?? 'Sem categoria';
            $values[] = $total;
        }
        return ['labels' => $labels, 'values' => $values];
    }
}

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

        return [
            'total_expenses'       => $totalExpenses,
            'total_incomes'        => $totalIncomes,
            'balance'              => $totalIncomes - $totalExpenses,
            'expenses_by_category' => $expensesByCategory,
            'recent_transactions'  => $recentTransactions,
        ];
    }
}

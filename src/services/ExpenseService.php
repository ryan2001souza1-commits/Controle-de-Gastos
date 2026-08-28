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

    public function getDashboardData(int $userId, ?string $startDate = null, ?string $endDate = null): array
    {
        $totalExpenses = $this->expenseModel->getTotalByUser($userId, $startDate, $endDate);
        $totalIncomes = $this->incomeModel->getTotalByUser($userId, $startDate, $endDate);
        $expensesByCategory = $this->expenseModel->getTotalByCategory($userId, $startDate, $endDate);
        $recentExpenses = array_slice($this->expenseModel->findByUser($userId, $startDate, $endDate), 0, 10);
        $recentIncomes = array_slice($this->incomeModel->findByUser($userId, $startDate, $endDate), 0, 10);

        $recentTransactions = array_merge($recentExpenses, $recentIncomes);
        usort($recentTransactions, function ($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });
        $recentTransactions = array_slice($recentTransactions, 0, 10);

        return [
            'total_expenses' => $totalExpenses,
            'total_incomes' => $totalIncomes,
            'balance' => $totalIncomes - $totalExpenses,
            'expenses_by_category' => $expensesByCategory,
            'recent_expenses' => $recentExpenses,
            'recent_incomes' => $recentIncomes,
            'recent_transactions' => $recentTransactions,
        ];
    }
}

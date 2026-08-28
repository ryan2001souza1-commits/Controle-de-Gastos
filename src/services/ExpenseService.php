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
        $expenseCount = $this->expenseModel->countByUser($userId, $startDate, $endDate);
        $incomeCount  = $this->incomeModel->countByUser($userId, $startDate, $endDate);

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

        $monthlyData = $this->getTotalsByPeriod($userId, $startDate, $endDate, 'month');
        $dailyData   = $this->getTotalsByPeriod($userId, $startDate, $endDate, 'day');

        $balanceEvolution = $this->buildBalanceEvolution($dailyData['income'], $dailyData['expense']);

        $expensesByCategoryForChart = $this->normalizeExpensesByCategoryForChart($expensesByCategory);
        $expensesByCategoryForTable = $this->normalizeExpensesByCategoryForTable($expensesByCategory, $totalExpenses);
        $financialFlow = $this->buildFinancialFlow($dailyData['income'], $dailyData['expense']);

        return [
            'total_expenses'       => $totalExpenses,
            'total_incomes'        => $totalIncomes,
            'balance'              => $totalIncomes - $totalExpenses,
            'transactions_count'   => $expenseCount + $incomeCount,
            'expense_count'        => $expenseCount,
            'income_count'         => $incomeCount,
            'expenses_by_category' => $expensesByCategory,
            'expenses_by_category_table' => $expensesByCategoryForTable,
            'recent_transactions'  => $recentTransactions,
            'chart_data'           => [
                'expenses_by_category' => $expensesByCategoryForChart,
                'income_by_period'     => $monthlyData['income'],
                'expense_by_period'    => $monthlyData['expense'],
                'balance_evolution'    => $balanceEvolution,
                'financial_flow'       => $financialFlow,
                'group_by'             => 'month',
            ],
        ];
    }

    private function getTotalsByPeriod(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        string $groupBy = 'month'
    ): array {
        $db = getDBConnection();

        if ($groupBy === 'day') {
            $periodExpr = "DATE_TRUNC('day', data)::date";
            $labelExpr  = "TO_CHAR(DATE_TRUNC('day', data), 'DD/MM/YYYY')";
        } else {
            $periodExpr = "DATE_TRUNC('month', data)";
            $labelExpr  = "TO_CHAR(DATE_TRUNC('month', data), 'MM/YYYY')";
        }

        $sql = "
            SELECT
                {$periodExpr} AS period_date,
                {$labelExpr}  AS label,
                tipo,
                COALESCE(SUM(valor), 0) AS total
            FROM transacoes
            WHERE usuario_id = :uid
              AND tipo IN ('receita', 'despesa')
        ";

        $params = [':uid' => $userId];

        if ($startDate) {
            $sql .= ' AND data >= :start_date';
            $params[':start_date'] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND data <= :end_date';
            $params[':end_date'] = $endDate;
        }

        $sql .= "
            GROUP BY {$periodExpr}, tipo
            ORDER BY {$periodExpr}
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $income  = [];
        $expense = [];

        foreach ($rows as $row) {
            $item = [
                'period' => $row['period_date'],
                'label'  => $row['label'],
                'total'  => (float) $row['total'],
            ];
            if ($row['tipo'] === 'receita') {
                $income[] = $item;
            } else {
                $expense[] = $item;
            }
        }

        return ['income' => $income, 'expense' => $expense];
    }

    private function buildFinancialFlow(array $income, array $expense): array
    {
        $map = [];
        foreach ($income as $r) {
            $map[$r['period']] = ['label' => $r['label'], 'income' => (float) $r['total'], 'expense' => 0.0];
        }
        foreach ($expense as $r) {
            if (!isset($map[$r['period']])) {
                $map[$r['period']] = ['label' => $r['label'], 'income' => 0.0, 'expense' => (float) $r['total']];
            } else {
                $map[$r['period']]['expense'] = (float) $r['total'];
            }
        }
        ksort($map);

        $labels = [];
        $incomes = [];
        $expenses = [];
        $balance = [];
        $cumulative = 0.0;

        foreach ($map as $period => $row) {
            $cumulative += ($row['income'] - $row['expense']);
            $labels[]   = $row['label'];
            $incomes[]  = round($row['income'], 2);
            $expenses[] = round($row['expense'], 2);
            $balance[]  = round($cumulative, 2);
        }

        return [
            'labels'   => $labels,
            'incomes'  => $incomes,
            'expenses' => $expenses,
            'balance'  => $balance,
        ];
    }

    private function buildBalanceEvolution(array $income, array $expense): array
    {
        $flow = $this->buildFinancialFlow($income, $expense);
        return ['labels' => $flow['labels'], 'balance' => $flow['balance']];
    }

    private function normalizeExpensesByCategoryForChart(array $rows): array
    {
        $entries = [];
        foreach ($rows as $r) {
            $total = (float)($r['total'] ?? 0);
            if ($total <= 0) continue;
            $entries[] = ['name' => $r['name'] ?? 'Sem categoria', 'total' => $total];
        }
        usort($entries, function ($a, $b) { return $b['total'] <=> $a['total']; });
        return [
            'labels' => array_map(fn($e) => $e['name'], $entries),
            'values' => array_map(fn($e) => $e['total'], $entries),
        ];
    }

    private function normalizeExpensesByCategoryForTable(array $rows, float $totalExpenses): array
    {
        $entries = [];
        foreach ($rows as $r) {
            $total = (float)($r['total'] ?? 0);
            if ($total <= 0) continue;
            $entries[] = [
                'name'       => $r['name'] ?? 'Sem categoria',
                'count'      => (int)($r['count'] ?? 0),
                'total'      => $total,
                'percentage' => $totalExpenses > 0 ? round(($total / $totalExpenses) * 100, 1) : 0.0,
            ];
        }
        usort($entries, function ($a, $b) { return $b['total'] <=> $a['total']; });
        return $entries;
    }
}

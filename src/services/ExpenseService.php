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
        $periodData = $this->getTotalsByPeriod($userId, $startDate, $endDate, $groupBy);

        $expensesByCategoryForChart = $this->normalizeExpensesByCategoryForChart($expensesByCategory);

        return [
            'total_expenses'       => $totalExpenses,
            'total_incomes'        => $totalIncomes,
            'balance'              => $totalIncomes - $totalExpenses,
            'expenses_by_category' => $expensesByCategory,
            'recent_transactions'  => $recentTransactions,
            'chart_data'           => [
                'expenses_by_category' => $expensesByCategoryForChart,
                'income_by_period'     => $periodData['income'],
                'expense_by_period'    => $periodData['expense'],
                'group_by'             => $groupBy,
            ],
        ];
    }

    public function getTotalsByPeriod(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        string $groupBy = 'day'
    ): array {
        $db = getDBConnection();

        $periodExpr = $groupBy === 'month'
            ? "DATE_TRUNC('month', data)"
            : "DATE_TRUNC('day', data)::date";

        $labelExpr = $groupBy === 'month'
            ? "TO_CHAR(DATE_TRUNC('month', data), 'MM/YYYY')"
            : "TO_CHAR(DATE_TRUNC('day', data), 'DD/MM/YYYY')";

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

        $income = [];
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

        return [
            'income'  => $income,
            'expense' => $expense,
        ];
    }

    private function resolveGroupBy(?string $startDate, ?string $endDate): string
    {
        if (!$startDate || !$endDate) {
            return 'month';
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

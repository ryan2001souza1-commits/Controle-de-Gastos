<?php

class ReportService
{
    private Expense $expenseModel;
    private Income $incomeModel;

    public function __construct(Expense $expenseModel, Income $incomeModel)
    {
        $this->expenseModel = $expenseModel;
        $this->incomeModel  = $incomeModel;
    }

    public function getReportData(
        int $userId,
        ?string $startDate,
        ?string $endDate,
        ?int $categoryId = null
    ): array {
        $expenseRows = $this->expenseModel->findAllByUser(
            $userId,
            $startDate,
            $endDate,
            $categoryId,
            null
        );
        $incomeRows = $this->incomeModel->findAllByUser(
            $userId,
            $startDate,
            $endDate,
            null
        );

        $totalExpenses = 0.0;
        foreach ($expenseRows as $r) {
            $totalExpenses += (float)($r['amount'] ?? 0);
        }

        $totalIncomes = 0.0;
        foreach ($incomeRows as $r) {
            $totalIncomes += (float)($r['amount'] ?? 0);
        }

        $all = array_merge($expenseRows, $incomeRows);
        usort($all, function ($a, $b) {
            $da = isset($a['date']) ? strtotime($a['date']) : 0;
            $db = isset($b['date']) ? strtotime($b['date']) : 0;
            if ($da === $db) {
                return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
            }
            return $db <=> $da;
        });

        $expensesByCategory = $this->expenseModel->getTotalByCategory(
            $userId,
            $startDate,
            $endDate
        );

        $chartCategories = $this->normalizeCategoriesForChart($expensesByCategory);

        $chartData = $this->buildChartData(
            $userId,
            $startDate,
            $endDate,
            $totalIncomes,
            $totalExpenses
        );

        return [
            'transactions'        => $all,
            'expenses'            => $expenseRows,
            'incomes'             => $incomeRows,
            'total_incomes'       => $totalIncomes,
            'total_expenses'      => $totalExpenses,
            'balance'             => $totalIncomes - $totalExpenses,
            'expense_count'       => count($expenseRows),
            'income_count'        => count($incomeRows),
            'transactions_count'  => count($all),
            'expenses_by_category' => $expensesByCategory,
            'chart_data'          => $chartData,
        ];
    }

    private function buildChartData(
        int $userId,
        ?string $startDate,
        ?string $endDate,
        float $totalIncomes,
        float $totalExpenses
    ): array {
        $incomeByPeriod  = $this->groupByMonth($userId, 'receita', $startDate, $endDate);
        $expenseByPeriod = $this->groupByMonth($userId, 'despesa', $startDate, $endDate);

        $merged = $this->mergePeriods($incomeByPeriod, $expenseByPeriod);

        $labels = [];
        $incomes = [];
        $expenses = [];
        $balance = [];
        $cum = 0.0;
        foreach ($merged as $row) {
            $labels[]   = $row['label'];
            $incomes[]  = round($row['income'], 2);
            $expenses[] = round($row['expense'], 2);
            $cum += ($row['income'] - $row['expense']);
            $balance[]  = round($cum, 2);
        }

        return [
            'income_by_period'  => $incomeByPeriod,
            'expense_by_period' => $expenseByPeriod,
            'balance_evolution' => ['labels' => $labels, 'balance' => $balance],
            'financial_flow'    => [
                'labels'   => $labels,
                'incomes'  => $incomes,
                'expenses' => $expenses,
                'balance'  => $balance,
            ],
            'group_by' => 'month',
        ];
    }

    private function groupByMonth(
        int $userId,
        string $type,
        ?string $startDate,
        ?string $endDate
    ): array {
        $sql = "
            SELECT
                DATE_TRUNC('month', t.data) AS periodo,
                TO_CHAR(DATE_TRUNC('month', t.data), 'MM/YYYY') AS label,
                COALESCE(SUM(t.valor), 0) AS total
            FROM transacoes t
            WHERE t.usuario_id = :uid
              AND t.tipo = :tipo
        ";
        $params = [':uid' => $userId, ':tipo' => $type];

        if ($startDate) {
            $sql .= ' AND t.data >= :start_date';
            $params[':start_date'] = $startDate;
        }
        if ($endDate) {
            $sql .= ' AND t.data <= :end_date';
            $params[':end_date'] = $endDate;
        }

        $sql .= '
            GROUP BY DATE_TRUNC(\'month\', t.data)
            ORDER BY DATE_TRUNC(\'month\', t.data)
        ';

        $db = getDBConnection();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'period' => $r['periodo'],
                'label'  => $r['label'],
                'total'  => (float)$r['total'],
            ];
        }
        return $out;
    }

    private function mergePeriods(array $income, array $expense): array
    {
        $map = [];
        foreach ($income as $r) {
            $map[$r['period']] = ['label' => $r['label'], 'income' => (float)$r['total'], 'expense' => 0.0];
        }
        foreach ($expense as $r) {
            if (!isset($map[$r['period']])) {
                $map[$r['period']] = ['label' => $r['label'], 'income' => 0.0, 'expense' => (float)$r['total']];
            } else {
                $map[$r['period']]['expense'] = (float)$r['total'];
            }
        }
        ksort($map);
        return array_values($map);
    }

    private function normalizeCategoriesForChart(array $rows): array
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
}

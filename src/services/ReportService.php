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
        ?int $categoryId = null,
        ?string $filterType = null
    ): array {
        $expenseRows = $this->expenseModel->findAllByUser(
            $userId, $startDate, $endDate, $categoryId, null
        );
        $incomeRows = $this->incomeModel->findAllByUser(
            $userId, $startDate, $endDate, null
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

        $chartData = $this->buildChartData(
            $userId, $startDate, $endDate, $filterType, $categoryId
        );

        $chartCategories = $this->normalizeCategoriesForChart(
            $this->expenseModel->getTotalByCategory($userId, $startDate, $endDate)
        );

        return [
            'transactions'        => $all,
            'expenses'           => $expenseRows,
            'incomes'            => $incomeRows,
            'total_incomes'      => $totalIncomes,
            'total_expenses'     => $totalExpenses,
            'balance'            => $totalIncomes - $totalExpenses,
            'expense_count'      => count($expenseRows),
            'income_count'       => count($incomeRows),
            'transactions_count'  => count($all),
            'expenses_by_category_table' => $this->normalizeForTable(
                $this->expenseModel->getTotalByCategory($userId, $startDate, $endDate),
                $totalExpenses
            ),
            'chart_data'         => $chartData,
            'chart_categories'   => $chartCategories,
        ];
    }

    private function buildChartData(
        int     $userId,
        ?string $startDate,
        ?string $endDate,
        ?string $filterType   = null,
        ?int    $categoryId   = null
    ): array {
        $showIncomes  = $filterType !== 'despesa';
        $showExpenses = $filterType !== 'receita';

        $incomeByPeriod   = $showIncomes
            ? $this->groupByPeriod($userId, 'receita', 'month', $startDate, $endDate)
            : [];
        $expenseByPeriod  = $showExpenses
            ? $this->groupByPeriod($userId, 'despesa', 'month', $startDate, $endDate, $categoryId)
            : [];

        $merged = $this->mergePeriods($incomeByPeriod, $expenseByPeriod);

        $labels   = [];
        $incomes  = [];
        $expenses = [];
        $balance  = [];
        $cum = 0.0;
        foreach ($merged as $row) {
            $labels[]   = $row['label'];
            $incomes[]  = round($row['income'], 2);
            $expenses[] = round($row['expense'], 2);
            $cum += ($row['income'] - $row['expense']);
            $balance[]  = round($cum, 2);
        }

        $expenseDaily  = $showExpenses
            ? $this->groupByPeriod($userId, 'despesa', 'day', $startDate, $endDate, $categoryId)
            : [];
        $incomeDaily   = $showIncomes
            ? $this->groupByPeriod($userId, 'receita', 'day', $startDate, $endDate)
            : [];
        $dailyMerged    = $this->mergePeriods($incomeDaily, $expenseDaily);
        $flowLabels     = [];
        $flowIncomes   = [];
        $flowExpenses  = [];
        $flowBalance   = [];
        $cumFlow = 0.0;
        foreach ($dailyMerged as $row) {
            $flowLabels[]   = $row['label'];
            $flowIncomes[]  = round($row['income'], 2);
            $flowExpenses[] = round($row['expense'], 2);
            $cumFlow += ($row['income'] - $row['expense']);
            $flowBalance[]  = round($cumFlow, 2);
        }

        $catRaw = $this->expenseModel->getTotalByCategory($userId, $startDate, $endDate);
        if ($categoryId) {
            $catRaw = array_values(array_filter($catRaw, fn($r) => (int)($r['id'] ?? 0) === (int)$categoryId));
        }
        $chartCats = $this->normalizeCategoriesForChart($catRaw);

        return [
            'income_by_period'      => $incomeByPeriod,
            'expense_by_period'    => $expenseByPeriod,
            'balance_evolution'      => ['labels' => $flowLabels, 'balance' => $flowBalance],
            'financial_flow'       => [
                'labels'   => $flowLabels,
                'incomes'  => $flowIncomes,
                'expenses' => $flowExpenses,
                'balance'  => $flowBalance,
            ],
            'expenses_by_category' => $chartCats,
            'group_by'             => 'month',
        ];
    }

    private function groupByPeriod(
        int     $userId,
        string  $type,
        string  $granularity,
        ?string $startDate,
        ?string $endDate,
        ?int    $categoryId = null
    ): array {
        if ($granularity === 'day') {
            $truncExpr = "DATE_TRUNC('day', t.data)";
            $labelFmt  = "'DD/MM/YYYY'";
        } else {
            $truncExpr = "DATE_TRUNC('month', t.data)";
            $labelFmt  = "'MM/YYYY'";
        }

        $sql = "
            SELECT
                agrupado.periodo  AS period_date,
                agrupado.label    AS label,
                agrupado.total    AS total
            FROM (
                SELECT
                    {$truncExpr}    AS periodo,
                    TO_CHAR({$truncExpr}, {$labelFmt}) AS label,
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
        if ($categoryId) {
            $sql .= ' AND t.categoria_id = :cat_id';
            $params[':cat_id'] = $categoryId;
        }

        $sql .= "
                GROUP BY {$truncExpr}
            ) AS agrupado
            ORDER BY agrupado.periodo
        ";

        $db = getDBConnection();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'period' => $r['period_date'],
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
        usort($entries, fn($a, $b) => $b['total'] <=> $a['total']);
        return [
            'labels' => array_map(fn($e) => $e['name'], $entries),
            'values' => array_map(fn($e) => $e['total'], $entries),
        ];
    }

    private function normalizeForTable(array $rows, float $totalExpenses): array
    {
        $entries = [];
        foreach ($rows as $r) {
            $total = (float)($r['total'] ?? 0);
            if ($total <= 0) continue;
            $entries[] = [
                'name'       => $r['name'] ?? 'Sem categoria',
                'count'      => (int)($r['count'] ?? 0),
                'total'      => $total,
                'percentage'  => $totalExpenses > 0 ? round(($total / $totalExpenses) * 100, 1) : 0.0,
            ];
        }
        usort($entries, fn($a, $b) => $b['total'] <=> $a['total']);
        return $entries;
    }
}

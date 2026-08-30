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

        $largestExpense = $this->findLargestExpense($recentExpenses);

        $monthlyData = $this->getTotalsByPeriod($userId, $startDate, $endDate, 'month');
        $dailyData   = $this->getTotalsByPeriod($userId, $startDate, $endDate, 'day');

        $balanceEvolution = $this->buildBalanceEvolution($dailyData['income'], $dailyData['expense']);

        $expensesByCategoryForChart = $this->normalizeExpensesByCategoryForChart($expensesByCategory);
        $expensesByCategoryForTable = $this->normalizeExpensesByCategoryForTable($expensesByCategory, $totalExpenses);
        $financialFlow = $this->buildFinancialFlow($dailyData['income'], $dailyData['expense']);
        $indicators   = $this->buildIndicators($userId, $startDate, $endDate, $totalExpenses, $totalIncomes, $expensesByCategory);
        $monthlyComparison = $this->getMonthlyComparison($userId);

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
            'largest_expense'      => $largestExpense,
            'indicators'          => $indicators,
            'monthly_comparison'  => $monthlyComparison,
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

    private function findLargestExpense(array $expenses): ?array
    {
        if (empty($expenses)) {
            return null;
        }
        $largest = $expenses[0];
        foreach ($expenses as $row) {
            if (($row['amount'] ?? 0) > ($largest['amount'] ?? 0)) {
                $largest = $row;
            }
        }
        return $largest;
    }

    private function getTotalsByPeriod(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        string $groupBy = 'month'
    ): array {
        $db = getDBConnection();

        if ($groupBy === 'day') {
            $truncExpr   = "DATE_TRUNC('day', t.data)";
            $labelFormat = 'DD/MM/YYYY';
        } else {
            $truncExpr   = "DATE_TRUNC('month', t.data)";
            $labelFormat = 'MM/YYYY';
        }

        $where  = 'WHERE t.usuario_id = :uid AND t.tipo IN (:tipo_r, :tipo_d)';
        $params = [
            ':uid'    => $userId,
            ':tipo_r' => 'receita',
            ':tipo_d' => 'despesa',
        ];

        if ($startDate) {
            $where .= ' AND t.data >= :start_date';
            $params[':start_date'] = $startDate;
        }

        if ($endDate) {
            $where .= ' AND t.data <= :end_date';
            $params[':end_date'] = $endDate;
        }

        $sql = "
            SELECT
                agrupado.periodo      AS period_date,
                TO_CHAR(agrupado.periodo, '{$labelFormat}') AS label,
                agrupado.tipo          AS tipo,
                agrupado.total         AS total
            FROM (
                SELECT
                    {$truncExpr}              AS periodo,
                    t.tipo                    AS tipo,
                    SUM(t.valor)              AS total
                FROM transacoes t
                {$where}
                GROUP BY {$truncExpr}, t.tipo
            ) AS agrupado
            ORDER BY agrupado.periodo, agrupado.tipo
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
            'data'   => array_map(fn($e) => $e['total'], $entries),
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

    private function buildIndicators(
        int $userId,
        ?string $startDate,
        ?string $endDate,
        float $totalExpenses,
        float $totalIncomes,
        array $expensesByCategory
    ): array {
        $db = getDBConnection();

        $avgExpense = 0.0;
        $avgIncome = 0.0;

        $monthsStmt = $db->prepare("
            SELECT
                EXTRACT(YEAR FROM t.data) AS yr,
                EXTRACT(MONTH FROM t.data) AS mo,
                t.tipo,
                SUM(t.valor) AS total
            FROM transacoes t
            WHERE t.usuario_id = :uid
            GROUP BY yr, mo, t.tipo
            ORDER BY yr DESC, mo DESC
            LIMIT 12
        ");
        $monthsStmt->execute([':uid' => $userId]);
        $monthTotals = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);

        $incomeByMonth = [];
        $expenseByMonth = [];
        foreach ($monthTotals as $r) {
            $key = (int)$r['yr'] . '-' . str_pad((int)$r['mo'], 2, '0', STR_PAD_LEFT);
            if ($r['tipo'] === 'receita') {
                $incomeByMonth[] = (float)$r['total'];
            } else {
                $expenseByMonth[] = (float)$r['total'];
            }
        }

        $avgExpense = count($expenseByMonth) > 0
            ? round(array_sum($expenseByMonth) / count($expenseByMonth), 2)
            : 0.0;
        $avgIncome = count($incomeByMonth) > 0
            ? round(array_sum($incomeByMonth) / count($incomeByMonth), 2)
            : 0.0;

        $topCategory = null;
        if (!empty($expensesByCategory)) {
            usort($expensesByCategory, fn($a, $b) => ((float)($b['total'] ?? 0)) <=> ((float)($a['total'] ?? 0)));
            $top = $expensesByCategory[0];
            if (($top['total'] ?? 0) > 0) {
                $topCategory = [
                    'name' => $top['name'] ?? 'Sem categoria',
                    'total' => (float)($top['total'] ?? 0),
                ];
            }
        }

        $committedPct = $totalIncomes > 0
            ? round(($totalExpenses / $totalIncomes) * 100, 1)
            : 0.0;

        $economy = $totalIncomes - $totalExpenses;
        $economyPct = $totalIncomes > 0
            ? round(($economy / $totalIncomes) * 100, 1)
            : 0.0;

        return [
            'avg_expense'      => $avgExpense,
            'avg_income'      => $avgIncome,
            'top_category'    => $topCategory,
            'committed_pct'   => $committedPct,
            'economy'         => $economy,
            'economy_pct'    => $economyPct,
        ];
    }

    public function getMonthlyComparison(int $userId, int $months = 6): array
    {
        $db = getDBConnection();

        $stmt = $db->prepare("
            SELECT
                EXTRACT(YEAR FROM t.data) AS yr,
                EXTRACT(MONTH FROM t.data) AS mo,
                t.tipo,
                SUM(t.valor) AS total
            FROM transacoes t
            WHERE t.usuario_id = :uid
            GROUP BY yr, mo, t.tipo
            ORDER BY yr DESC, mo DESC
            LIMIT :limit_rows
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', $months * 2, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $r) {
            $key = (int)$r['yr'] . '-' . str_pad((int)$r['mo'], 2, '0', STR_PAD_LEFT);
            if (!isset($map[$key])) {
                $dt = DateTime::createFromFormat('Y-m', $key);
                $map[$key] = [
                    'year'      => (int)$r['yr'],
                    'month'     => (int)$r['mo'],
                    'label'     => $dt ? ucfirst($dt->format('M/Y')) : $key,
                    'income'    => 0.0,
                    'expense'   => 0.0,
                    'balance'   => 0.0,
                ];
            }
            $val = (float)$r['total'];
            if ($r['tipo'] === 'receita') {
                $map[$key]['income'] = $val;
            } else {
                $map[$key]['expense'] = $val;
            }
        }

        ksort($map);
        $result = array_values($map);

        foreach ($result as &$item) {
            $item['balance'] = round($item['income'] - $item['expense'], 2);
            $item['income']  = round($item['income'], 2);
            $item['expense'] = round($item['expense'], 2);
        }
        unset($item);

        return array_slice($result, -$months);
    }
}

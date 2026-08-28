<?php

class BudgetService
{
    private Budget $budgetModel;
    private Category $categoryModel;

    public function __construct(Budget $budgetModel, Category $categoryModel)
    {
        $this->budgetModel = $budgetModel;
        $this->categoryModel = $categoryModel;
    }

    public function getBudgetsForPeriod(int $userId, int $year, int $month): array
    {
        $rows = $this->budgetModel->findByUserPeriod($userId, $year, $month);

        $totalLimit = 0.0;
        $totalSpent = 0.0;
        $overCount = 0;
        $warnCount = 0;
        $okCount = 0;

        foreach ($rows as &$r) {
            $limit = (float)($r['limit_amount'] ?? 0);
            $spent = (float)($r['spent_amount'] ?? 0);
            $r['limit_amount'] = $limit;
            $r['spent_amount'] = $spent;
            $r['remaining']    = $limit - $spent;
            $r['percentage']   = $limit > 0 ? round(($spent / $limit) * 100, 1) : 0.0;
            $r['status']       = $this->statusFor($r['percentage']);

            $totalLimit += $limit;
            $totalSpent += $spent;

            if ($r['status'] === 'over')   $overCount++;
            if ($r['status'] === 'warn')   $warnCount++;
            if ($r['status'] === 'ok')     $okCount++;
        }
        unset($r);

        return [
            'budgets'    => $rows,
            'totals'     => [
                'limit'      => $totalLimit,
                'spent'      => $totalSpent,
                'remaining'  => $totalLimit - $totalSpent,
                'percentage' => $totalLimit > 0 ? round(($totalSpent / $totalLimit) * 100, 1) : 0.0,
            ],
            'counts'     => [
                'over'       => $overCount,
                'warn'       => $warnCount,
                'ok'         => $okCount,
            ],
        ];
    }

    private function statusFor(float $pct): string
    {
        if ($pct >= 100) return 'over';
        if ($pct >= 80)  return 'warn';
        return 'ok';
    }
}

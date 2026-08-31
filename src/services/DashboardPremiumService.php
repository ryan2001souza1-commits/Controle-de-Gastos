<?php
/**
 * DashboardPremiumService — camada avancada do Dashboard para PRO e PREMIUM.
 *
 * Reutiliza servicos existentes (ExpenseService, BudgetService, GoalService)
 * para evitar consultas duplicadas. Nao cria novas queries que ja existem.
 *
 * Permissao: toda chamada publica e no-op se o usuario nao tiver a feature
 * correspondente. O controller deve chamar isDashboardAdvanced()/isAiInsights()
 * para decidir se chama este servico.
 */
class DashboardPremiumService
{
    private PDO $db;
    private PlanService $planService;
    private ExpenseService $expenseService;
    private BudgetService $budgetService;
    private GoalService $goalService;

    public function __construct(
        PDO $db,
        PlanService $planService,
        ExpenseService $expenseService,
        BudgetService $budgetService,
        GoalService $goalService
    ) {
        $this->db = $db;
        $this->planService = $planService;
        $this->expenseService = $expenseService;
        $this->budgetService = $budgetService;
        $this->goalService = $goalService;
    }

    public function isDashboardAdvanced(int $userId): bool
    {
        return $this->planService->userHasFeature($userId, 'dashboard_advanced');
    }

    public function isAiInsights(int $userId): bool
    {
        return $this->planService->userHasFeature($userId, 'ai_insights');
    }

    public function getSummary(int $userId, ?string $startDate, ?string $endDate): array
    {
        $data = $this->expenseService->getDashboardData($userId, $startDate, $endDate);
        $indicators = $data['indicators'] ?? [];

        $evolution = $this->getFinancialEvolution($userId);
        $currentMonth = end($evolution) ?: ['income' => 0, 'expense' => 0, 'balance' => 0];
        $prevMonth = prev($evolution);
        if ($prevMonth === false) $prevMonth = ['income' => 0, 'expense' => 0, 'balance' => 0];

        $incomeDelta  = $prevMonth['income']  > 0 ? round((($currentMonth['income']  - $prevMonth['income'])  / $prevMonth['income'])  * 100, 1) : 0;
        $expenseDelta = $prevMonth['expense'] > 0 ? round((($currentMonth['expense'] - $prevMonth['expense']) / $prevMonth['expense']) * 100, 1) : 0;
        $balanceDelta = $prevMonth['balance'] != 0 ? round((($currentMonth['balance'] - $prevMonth['balance']) / max(1, abs((float)$prevMonth['balance']))) * 100, 1) : 0;

        $economyPct = $indicators['economy_pct'] ?? 0;
        $economy = $indicators['economy'] ?? ($currentMonth['income'] - $currentMonth['expense']);

        return [
            'income'             => (float)$currentMonth['income'],
            'expense'            => (float)$currentMonth['expense'],
            'balance'            => (float)$currentMonth['balance'],
            'income_delta'       => $incomeDelta,
            'expense_delta'      => $expenseDelta,
            'balance_delta'      => $balanceDelta,
            'avg_expense'        => (float)($indicators['avg_expense'] ?? 0),
            'avg_income'         => (float)($indicators['avg_income'] ?? 0),
            'economy'            => (float)$economy,
            'economy_pct'        => (float)$economyPct,
            'top_category'       => $indicators['top_category'] ?? null,
            'committed_pct'      => (float)($indicators['committed_pct'] ?? 0),
        ];
    }

    public function getFinancialEvolution(int $userId, int $months = 6): array
    {
        return $this->expenseService->getMonthlyComparison($userId, $months);
    }

    public function getCategoryDistribution(int $userId, ?string $startDate, ?string $endDate): array
    {
        $data = $this->expenseService->getDashboardData($userId, $startDate, $endDate);
        return $data['expenses_by_category_table'] ?? [];
    }

    public function getGoalsSummary(int $userId): array
    {
        $data = $this->goalService->getGoalsData($userId);
        $goals = $data['goals'] ?? [];
        $total = count($goals);
        $completed = 0;
        $inProgress = 0;
        $progressSum = 0;
        foreach ($goals as $g) {
            $pct = (int)($g['percentage'] ?? 0);
            $progressSum += $pct;
            if (($g['status'] ?? '') === 'completed') $completed++;
            else $inProgress++;
        }
        return [
            'total'       => $total,
            'completed'   => $completed,
            'in_progress' => $inProgress,
            'avg_progress' => $total > 0 ? round($progressSum / $total, 1) : 0,
        ];
    }

    public function getBudgetsSummary(int $userId, ?int $year = null, ?int $month = null): array
    {
        if ($year === null)  $year  = (int)date('Y');
        if ($month === null) $month = (int)date('n');
        $info = $this->budgetService->getBudgetsForPeriod($userId, $year, $month);
        $budgets = $info['budgets'] ?? [];
        $total = count($budgets);
        $over = 0; $warn = 0; $ok = 0;
        foreach ($budgets as $b) {
            $status = $b['status'] ?? 'ok';
            if ($status === 'over') $over++;
            elseif ($status === 'warn') $warn++;
            else $ok++;
        }
        return [
            'total'    => $total,
            'over'     => $over,
            'warn'     => $warn,
            'ok'       => $ok,
            'budgets'  => $budgets,
        ];
    }

    public function getInsights(int $userId, ?string $startDate, ?string $endDate): array
    {
        $summary = $this->getSummary($userId, $startDate, $endDate);
        $goals   = $this->getGoalsSummary($userId);
        $budgets = $this->getBudgetsSummary($userId);
        $insights = [];

        if ($summary['expense_delta'] > 10) {
            $insights[] = [
                'tone' => 'warning',
                'icon' => 'trending-up',
                'text' => sprintf('Seus gastos aumentaram %.1f%% em relacao ao mes anterior.', $summary['expense_delta']),
            ];
        } elseif ($summary['expense_delta'] < -10) {
            $insights[] = [
                'tone' => 'success',
                'icon' => 'trending-down',
                'text' => sprintf('Otimo! Seus gastos caíram %.1f%% em relacao ao mes anterior.', abs($summary['expense_delta'])),
            ];
        }

        if (!empty($summary['top_category']['name']) && $summary['top_category']['total'] > 0 && $summary['expense'] > 0) {
            $pct = round(($summary['top_category']['total'] / $summary['expense']) * 100, 1);
            if ($pct >= 30) {
                $insights[] = [
                    'tone' => 'info',
                    'icon' => 'pie',
                    'text' => sprintf('A categoria %s representa %.1f%% das suas despesas.', $summary['top_category']['name'], $pct),
                ];
            }
        }

        if ($summary['economy_pct'] > 0) {
            $insights[] = [
                'tone' => 'success',
                'icon' => 'wallet',
                'text' => sprintf('Voce esta economizando %.1f%% da sua receita.', $summary['economy_pct']),
            ];
        } elseif ($summary['economy_pct'] < 0) {
            $insights[] = [
                'tone' => 'danger',
                'icon' => 'alert',
                'text' => sprintf('Atencao: suas despesas estao %.1f%% acima da receita.', abs($summary['economy_pct'])),
            ];
        }

        foreach ($budgets['budgets'] ?? [] as $b) {
            $pct = (float)($b['percentage'] ?? 0);
            $name = $b['category_name'] ?? '';
            if ($pct >= 90 && $name) {
                $insights[] = [
                    'tone' => 'warning',
                    'icon' => 'wallet',
                    'text' => sprintf('Seu orcamento de %s esta proximo do limite (%.0f%%).', $name, $pct),
                ];
            }
        }

        foreach ($goals as $g) {
            $pct = (int)($g['percentage'] ?? 0);
            $name = $g['name'] ?? '';
            if ($pct >= 50 && $pct < 100 && $name) {
                $insights[] = [
                    'tone' => 'info',
                    'icon' => 'target',
                    'text' => sprintf('Voce esta evoluindo em direcao a meta %s (%d%%).', $name, $pct),
                ];
            }
        }

        return $insights;
    }

    public function getAlerts(int $userId, ?string $startDate, ?string $endDate): array
    {
        $alerts = [];
        $summary = $this->getSummary($userId, $startDate, $endDate);
        $budgets = $this->getBudgetsSummary($userId);

        if ($summary['expense'] > $summary['avg_expense'] && $summary['avg_expense'] > 0) {
            $alerts[] = [
                'tone' => 'warning',
                'text' => 'Seus gastos deste periodo estao acima da sua media historica.',
            ];
        }

        if ($summary['expense_delta'] > 25) {
            $alerts[] = [
                'tone' => 'danger',
                'text' => 'Aumento significativo de despesas em relacao ao mes anterior.',
            ];
        }

        foreach ($budgets['budgets'] ?? [] as $b) {
            $pct = (float)($b['percentage'] ?? 0);
            $name = $b['category_name'] ?? '';
            $status = $b['status'] ?? 'ok';
            if ($status === 'over' && $name) {
                $alerts[] = [
                    'tone' => 'danger',
                    'text' => sprintf('Orcamento de %s foi excedido.', $name),
                ];
            } elseif ($pct >= 80 && $name) {
                $alerts[] = [
                    'tone' => 'warning',
                    'text' => sprintf('Orcamento de %s proximo do limite.', $name),
                ];
            }
        }

        return $alerts;
    }
}

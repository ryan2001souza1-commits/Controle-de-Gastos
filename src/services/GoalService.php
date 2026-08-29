<?php

class GoalService
{
    private Goal $goalModel;

    public function __construct(Goal $goalModel)
    {
        $this->goalModel = $goalModel;
    }

    public function getGoalsData(int $userId): array
    {
        $goals = $this->goalModel->findAllByUser($userId);

        return [
            'goals' => $this->normalizeGoals($goals),
        ];
    }

    private function normalizeGoals(array $goals): array
    {
        $result = [];
        $now = new DateTime();

        foreach ($goals as $g) {
            $target = (float)($g['target_amount'] ?? 0);
            $saved  = (float)($g['saved_amount']  ?? 0);
            $deadline = $g['deadline'] ?? null;
            $percentage = $target > 0 ? round(($saved / $target) * 100, 1) : 0.0;
            $remaining = max(0, $target - $saved);

            $daysLeft = null;
            $status = 'pending';
            if ($deadline) {
                $dl = new DateTime($deadline);
                $diff = $now->diff($dl);
                $daysLeft = $diff->invert === 0 ? $diff->days : -$diff->days;
                if ($percentage >= 100) {
                    $status = 'completed';
                } elseif ($daysLeft < 0) {
                    $status = 'overdue';
                } elseif ($daysLeft <= 30) {
                    $status = 'near';
                } else {
                    $status = 'active';
                }
            } else {
                if ($percentage >= 100) {
                    $status = 'completed';
                } elseif ($saved > 0) {
                    $status = 'active';
                }
            }

            $result[] = [
                'id'           => (int)$g['id'],
                'name'         => $g['name'] ?? '',
                'target'       => $target,
                'saved'        => $saved,
                'remaining'    => $remaining,
                'percentage'   => min(100, $percentage),
                'deadline'     => $deadline,
                'days_left'    => $daysLeft,
                'description'  => $g['description'] ?? '',
                'status'       => $status,
            ];
        }

        usort($result, function ($a, $b) {
            if ($a['status'] === 'completed' && $b['status'] !== 'completed') return 1;
            if ($b['status'] === 'completed' && $a['status'] !== 'completed') return -1;
            if ($a['status'] === 'overdue' && $b['status'] !== 'overdue') return -1;
            if ($b['status'] === 'overdue' && $a['status'] !== 'overdue') return 1;
            if ($a['days_left'] !== null && $b['days_left'] !== null) {
                return $a['days_left'] <=> $b['days_left'];
            }
            return 0;
        });

        return $result;
    }
}

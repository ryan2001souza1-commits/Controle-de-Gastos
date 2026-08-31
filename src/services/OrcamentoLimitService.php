<?php
/**
 * OrcamentoLimitService — controle de limite de orcamentos por plano.
 *
 * Unica fonte de verdade para o limite de orcamentos.
 * Utiliza PlanService para obter o limite do plano do usuario.
 *
 * Seguranca:
 * - Contagem via COUNT(*) no banco (sem loop PHP)
 * - Plano determinado exclusivamente pelo servidor
 * - Nao aceita nenhum parametro do frontend para limite
 */
class OrcamentoLimitService
{
    private Budget $budgetModel;
    private PlanService $planService;

    public function __construct(Budget $budgetModel, PlanService $planService)
    {
        $this->budgetModel = $budgetModel;
        $this->planService = $planService;
    }

    public function countByUser(int $userId): int
    {
        return $this->budgetModel->countByUser($userId);
    }

    public function getLimit(int $userId): int|null
    {
        return $this->planService->getUserLimit($userId, 'orcamentos');
    }

    public function getRemaining(int $userId): int|null
    {
        $limite = $this->getLimit($userId);
        if ($limite === null) return null;
        return max(0, $limite - $this->countByUser($userId));
    }

    public function check(int $userId): array
    {
        $limite = $this->getLimit($userId);
        if ($limite === null) {
            return ['allowed' => true, 'message' => ''];
        }
        if ($limite <= 0) {
            return [
                'allowed' => false,
                'message' => 'Limite de orcamentos do plano atingido. Nenhum orcamento permitido.',
            ];
        }

        $usados = $this->countByUser($userId);
        if ($usados >= $limite) {
            return [
                'allowed' => false,
                'message' => 'Voce atingiu o limite de orcamentos do seu plano. Faca upgrade para criar mais orcamentos.',
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }
}


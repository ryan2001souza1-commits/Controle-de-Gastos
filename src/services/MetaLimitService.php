<?php
/**
 * MetaLimitService — controle de limite de metas por plano.
 *
 * Unica fonte de verdade para o limite de metas.
 * Utiliza PlanService para obter o limite do plano do usuario.
 *
 * Seguranca:
 * - Contagem via COUNT(*) no banco (sem loop PHP)
 * - Plano determinado exclusivamente pelo servidor
 * - Nao aceita nenhum parametro do frontend para limite
 */
class MetaLimitService
{
    private Goal $goalModel;
    private PlanService $planService;

    public function __construct(Goal $goalModel, PlanService $planService)
    {
        $this->goalModel = $goalModel;
        $this->planService = $planService;
    }

    public function countByUser(int $userId): int
    {
        return $this->goalModel->countByUser($userId);
    }

    public function getLimit(int $userId): int|null
    {
        return $this->planService->getUserLimit($userId, 'metas');
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
                'message' => 'Limite de metas do plano atingido. Nenhuma meta permitida.',
            ];
        }

        $usados = $this->countByUser($userId);
        if ($usados >= $limite) {
            return [
                'allowed' => false,
                'message' => 'Voce atingiu o limite de metas do seu plano. Faca upgrade para criar mais metas.',
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }
}

<?php
/**
 * CategoriaLimitService — controle de limite de categorias por plano.
 *
 * Unica fonte de verdade para o limite de categorias personalizadas.
 * Todas as categorias pertencem ao usuario (usuario_id IS NOT NULL).
 * Nao ha categorias de sistema nesta arquitetura.
 *
 * Seguranca:
 * - Contagem via COUNT(*) no banco (sem loop PHP)
 * - Plano determinado exclusivamente pelo servidor
 * - Nao aceita nenhum parametro do frontend para limite
 */
class CategoriaLimitService
{
    private Category $categoryModel;
    private PlanService $planService;

    public function __construct(Category $categoryModel, PlanService $planService)
    {
        $this->categoryModel = $categoryModel;
        $this->planService = $planService;
    }

    /**
     * Conta as categorias personalizadas do usuario.
     * Todas as categorias tem usuario_id (IS NOT NULL).
     */
    public function countUserCategories(int $userId): int
    {
        return $this->categoryModel->countUserCategories($userId);
    }

    /**
     * Retorna o limite de categorias para o plano do usuario.
     */
    public function getCategoryLimit(int $userId): int|null
    {
        return $this->planService->getUserLimit($userId, 'categorias');
    }

    /**
     * Retorna quantas categorias ainda podem ser criadas.
     */
    public function getRemaining(int $userId): int|null
    {
        $limite = $this->getCategoryLimit($userId);
        if ($limite === null) return null;
        return max(0, $limite - $this->countUserCategories($userId));
    }

    /**
     * Verifica se o usuario pode criar uma nova categoria.
     * Retorna array com 'allowed' (bool) e 'message' (string).
     */
    public function check(int $userId): array
    {
        $limite = $this->getCategoryLimit($userId);
        if ($limite === null) {
            return ['allowed' => true, 'message' => ''];
        }
        if ($limite <= 0) {
            return [
                'allowed' => false,
                'message' => "Limite de categorias do plano atingido. Nenhuma categoria permitida.",
            ];
        }

        $usadas = $this->countUserCategories($userId);
        if ($usadas >= $limite) {
            return [
                'allowed' => false,
                'message' => "Você atingiu o limite de categorias do seu plano. Faça upgrade para criar mais categorias.",
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }
}

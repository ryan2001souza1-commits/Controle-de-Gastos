<?php
/**
 * LancamentoLimitService — controle de limite de lancamentos por plano.
 *
 * Unica fonte de verdade para o limite de lancamentos por mes.
 * Utiliza PlanService para obter o limite do plano do usuario.
 *
 * Seguranca:
 * - Contagem via COUNT(*) no banco (sem loop PHP)
 * - Plano determinado exclusivamente pelo servidor
 * - Dados do mes atual baseados no timezone do servidor
 * - Nao aceita nenhum parametro do frontend para calculo de limite
 */
class LancamentoLimitService
{
    private Expense $expenseModel;
    private Income $incomeModel;
    private PlanService $planService;

    public function __construct(
        Expense $expenseModel,
        Income $incomeModel,
        PlanService $planService
    ) {
        $this->expenseModel = $expenseModel;
        $this->incomeModel = $incomeModel;
        $this->planService = $planService;
    }

    /**
     * Conta todos os lancamentos (despesa + receita) do usuario no mes/ano informado.
     * Metodo interno para testes e auditoria.
     */
    public function countMonthTransactions(int $userId, int $year, int $month): int
    {
        $despesas = $this->expenseModel->countByUserMonth($userId, $year, $month);
        $receitas = $this->incomeModel->countByUserMonth($userId, $year, $month);
        return $despesas + $receitas;
    }

    /**
     * Retorna o limite de lancamentos por mes para o plano do usuario.
     */
    public function getMonthlyLimit(int $userId): int|null
    {
        return $this->planService->getUserLimit($userId, 'lancamentos');
    }

    /**
     * Retorna quantos lancamentos ainda podem ser criados neste mes.
     * Retorna null se o plano nao tem limite (ilimitado).
     */
    public function getRemaining(int $userId, int $year, int $month): int|null
    {
        $limite = $this->getMonthlyLimit($userId);
        if ($limite === null) return null;
        $usados = $this->countMonthTransactions($userId, $year, $month);
        return max(0, $limite - $usados);
    }

    /**
     * Verifica se o usuario pode criar um novo lancamento neste mes.
     * Retorna um array com 'allowed' (bool) e 'message' (string).
     *
     * Se allowed=false, message contem a mensagem amigavel de erro.
     */
    public function check(int $userId, string $date): array
    {
        $limite = $this->getMonthlyLimit($userId);
        if ($limite === null) {
            return ['allowed' => true, 'message' => ''];
        }
        if ($limite <= 0) {
            return [
                'allowed' => false,
                'message' => "Limite de lancamentos atingido. Nenhum lancamento permitido neste plano.",
            ];
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d) {
            $d = new DateTime();
        }
        $year = (int)$d->format('Y');
        $month = (int)$d->format('n');
        $usados = $this->countMonthTransactions($userId, $year, $month);

        if ($usados >= $limite) {
            return [
                'allowed' => false,
                'message' => "Você atingiu o limite do seu plano ({$limite} lançamentos neste mês). Faça upgrade para continuar adicionando lançamentos.",
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }
}

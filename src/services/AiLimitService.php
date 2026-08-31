<?php
/**
 * AiLimitService — controle de limite de perguntas/dia da IA por plano.
 *
 * Unica fonte de verdade para o limite de perguntas/dia da IA.
 * Utiliza PlanService para obter o limite do plano do usuario.
 * Utiliza a tabela ai_usage para contagem (ja existente no banco).
 *
 * Seguranca:
 * - Contagem via tabela ai_usage no banco
 * - Plano determinado exclusivamente pelo servidor
 * - Nao aceita nenhum parametro do frontend para limite
 */
class AiLimitService
{
    private PDO $db;
    private PlanService $planService;
    private AiService $aiService;

    public function __construct(PDO $db, PlanService $planService, AiService $aiService)
    {
        $this->db = $db;
        $this->planService = $planService;
        $this->aiService = $aiService;
    }

    public function countToday(int $userId): int
    {
        $today = appToday();
        $stmt = $this->db->prepare('SELECT requests FROM ai_usage WHERE user_id=? AND date=?');
        $stmt->execute([$userId, $today]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function getLimit(int $userId): int|null
    {
        return $this->planService->getUserLimit($userId, 'ia_perguntas_dia');
    }

    public function getRemaining(int $userId): int|null
    {
        $limite = $this->getLimit($userId);
        if ($limite === null) return null;
        return max(0, $limite - $this->countToday($userId));
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
                'message' => 'Limite de mensagens diarios do plano atingido. Nenhuma mensagem permitida.',
            ];
        }

        $usados = $this->countToday($userId);
        if ($usados >= $limite) {
            return [
                'allowed' => false,
                'message' => 'Voce atingiu o limite diario do seu plano. Tente novamente amanha.',
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }

    public function incrementUsage(int $userId, int $tokensApprox = 0): void
    {
        $this->aiService->incrementUsage($userId, $tokensApprox);
    }
}

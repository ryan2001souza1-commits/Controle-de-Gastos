<?php
/**
 * PlanService — camada centralizada para gestão de planos de assinatura.
 *
 * Serve como fonte única de verdade para tudo relacionado a planos:
 * - identificacao do plano atual do usuario
 * - listagem de planos disponiveis
 * - limites por plano (estrutura para futuras implementacoes)
 * - verificacao de features
 *
 * O plano do usuario é determinado EXCLUSIVAMENTE pelo servidor (banco de dados).
 * Nunca aceite plano do frontend (GET, POST, cookies, JS, etc.).
 *
 * Como adicionar um plano pago:
 * 1. Adicionar entrada na tabela `planos` (via seed/admin)
 * 2. Adicionar slug em SLUGS
 * 3. Adicionar limites/features em LIMITES se aplicavel
 * 4. A verificacao centralizada em hasFeature() automaticamente considera o novo plano
 */
class PlanService
{
    private PDO $db;

    public const SLUG_FREE     = 'gratuito';
    public const SLUG_PRO      = 'pro';
    public const SLUG_PREMIUM  = 'premium';

    public const STATUS_ATIVO     = 'ativo';
    public const STATUS_PENDENTE  = 'pendente';
    public const STATUS_CANCELADO = 'cancelado';

    private const TODOS_SLUGS = [
        self::SLUG_FREE,
        self::SLUG_PRO,
        self::SLUG_PREMIUM,
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retorna o slug do plano de um usuario.
     * Seguranca: lido diretamente do banco, nunca do frontend.
     */
    public function getUserPlanSlug(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT plano FROM usuarios WHERE id = ?');
        $stmt->execute([$userId]);
        $slug = $stmt->fetchColumn();
        return in_array($slug, self::TODOS_SLUGS, true) ? $slug : self::SLUG_FREE;
    }

    /**
     * Retorna o objeto User com o plano hidratado (ja existe no User model).
     * Alias seguro para uso em controllers.
     */
    public function getUserPlanData(User $user): array
    {
        return [
            'slug'         => $this->normalizeSlug($user->plano),
            'status'       => $this->normalizeStatus($user->plano_status),
            'inicio'        => $user->plano_inicio,
            'fim'           => $user->plano_fim,
            'is_ativo'      => $this->isPlanoAtivo($user),
            'is_free'      => $this->isFree($user),
            'nome'          => $this->getPlanDisplayName($this->normalizeSlug($user->plano)),
        ];
    }

    /**
     * Retorna true se o plano do usuario e FREE.
     */
    public function isFree(User $user): bool
    {
        return $this->normalizeSlug($user->plano) === self::SLUG_FREE;
    }

    /**
     * Retorna true se o plano do usuario esta ativo
     * (status = ativo E data_fim >= agora, ou sem data_fim).
     */
    public function isPlanoAtivo(User $user): bool
    {
        if ($this->normalizeStatus($user->plano_status) !== self::STATUS_ATIVO) {
            return false;
        }
        if ($user->plano_fim === null) {
            return true;
        }
        try {
            $fim = new DateTimeImmutable($user->plano_fim);
            return $fim >= new DateTimeImmutable('now');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Retorna todos os planos ativos disponiveis na tabela planos.
     * (Para uso em qualquer exibicao publica de catalogo.)
     */
    public function getAvailablePlanos(): array
    {
        $stmt = $this->db->query(
            "SELECT id, nome, slug, preco, descricao, status, created_at
             FROM planos
             WHERE status = 'ativo'
             ORDER BY COALESCE(preco, 0) ASC, nome ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna todos os planos (ativos e inativos).
     * Apenas para uso administrativo.
     */
    public function getAllPlanosAdmin(): array
    {
        $stmt = $this->db->query(
            "SELECT id, nome, slug, preco, descricao, status, created_at, updated_at
             FROM planos
             ORDER BY COALESCE(preco, 0) ASC, nome ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna um plano pelo slug. Null se nao encontrado.
     */
    public function getPlanoBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, slug, preco, descricao, status, created_at, updated_at
             FROM planos WHERE slug = ?'
        );
        $stmt->execute([$this->normalizeSlug($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retorna true se o plano existe no catalogo e esta ativo.
     * (Para qualquer logica que dependa de o plano estar "disponivel".)
     */
    public function isPlanoDisponivel(string $slug): bool
    {
        $plano = $this->getPlanoBySlug($slug);
        return $plano !== null && ($plano['status'] ?? '') === self::STATUS_ATIVO;
    }

    /**
     * Retorna o nome de exibicao de um plano pelo slug.
     * Fallback para slugs desconhecidos (ex: plano removido do DB).
     */
    public function getPlanDisplayName(string $slug): string
    {
        $nomes = [
            self::SLUG_FREE    => 'Gratuito',
            self::SLUG_PRO     => 'Pro',
            self::SLUG_PREMIUM => 'Premium',
        ];
        $normalized = $this->normalizeSlug($slug);
        return $nomes[$normalized] ?? ucfirst($normalized);
    }

    /**
     * Retorna o preco formatado de um plano.
     * Null = "A definir" (plano existente mas preco ainda nao configurado).
     * Zero = "Gratuito".
     */
    public function getPlanPrice(string $slug): string
    {
        $plano = $this->getPlanoBySlug($slug);
        if ($plano === null) return 'A definir';
        $preco = $plano['preco'] ?? null;
        if ($preco === null) return 'A definir';
        $preco = (float)$preco;
        if ($preco <= 0) return 'Gratuito';
        return 'R$ ' . number_format($preco, 2, ',', '.') . '/mês';
    }

    /**
     * Verifica se o plano do usuario tem uma determinada feature.
     * Esta estrutura permite adicionar features sem alterar a logica em todo o codigo.
     *
     * Features disponiveis (ESTA ETAPA — apenas FREE):
     *   - 'ia_assistant'   — assistente financeiro IA
     *   - 'ia_rate_limit'  — qual limite de mensagens IA (retorna int)
     *   - 'categorias_ilimitadas' — sem limite de categorias
     *   - 'metas_ilimitadas' — sem limite de metas
     *
     * Futuras (exemplo de como sera implementado):
     *   return match($slug) {
     *       self::SLUG_PREMIUM => true,
     *       self::SLUG_PRO => in_array($feature, ['ia_assistant','export_pdf'], true),
     *       default => false,
     *   };
     */
    public function hasFeature(string $planSlug, string $feature): bool
    {
        $slug = $this->normalizeSlug($planSlug);
        // ESTA ETAPA: FREE suporta apenas ia_assistant
        // Pro e Premium serao implementados nas proximas etapas
        return match ($feature) {
            'ia_assistant' => true, // todo plano tem IA
            'ia_rate_limit' => true, // todo plano tem rate limit (AiService define por plano)
            'categorias_ilimitadas' => $slug !== self::SLUG_FREE,
            'metas_ilimitadas' => $slug !== self::SLUG_FREE,
            'export_pdf' => $slug === self::SLUG_PREMIUM,
            'relatorios_avancados' => $slug === self::SLUG_PREMIUM,
            default => false,
        };
    }

    /**
     * Retorna o limite de uma funcionalidade para o plano informado.
     * Retorna null se o plano nao possui o recurso.
     *
     * Limites por plano (ESTA ETAPA — apenas para IA):
     *   'ai_assistant'  — int: maximo de mensagens por dia
     */
    public function getLimit(string $planSlug, string $limitType): int
    {
        $slug = $this->normalizeSlug($planSlug);
        $limites = [
            'ai_assistant' => [
                self::SLUG_FREE    => 5,
                self::SLUG_PRO     => 20,
                self::SLUG_PREMIUM => 50,
            ],
        ];
        if (!isset($limites[$limitType])) return 0;
        if (!isset($limites[$limitType][$slug])) return 0;
        return $limites[$limitType][$slug];
    }

    /**
     * Retorna todos os slugs de plano validos.
     */
    public static function getValidSlugs(): array
    {
        return self::TODOS_SLUGS;
    }

    /**
     * Normaliza um slug de plano para minuscula e sem espacos.
     * Seguranca: impede slugs invalidos de绕过 validacao.
     */
    public static function normalizeSlug(?string $slug): string
    {
        $s = strtolower(trim((string)($slug ?? '')));
        return in_array($s, self::TODOS_SLUGS, true) ? $s : self::SLUG_FREE;
    }

    /**
     * Normaliza um status de plano para minuscula.
     */
    public static function normalizeStatus(?string $status): string
    {
        return strtolower(trim((string)($status ?? self::STATUS_ATIVO)));
    }
}

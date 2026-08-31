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

    /**
     * Representa "sem limite" (ex: historico ilimitado do PREMIUM).
     * Use null para checagem: $valor === self::LIMIT_UNLIMITED.
     */
    public const LIMIT_UNLIMITED = null;

    private const TODOS_SLUGS = [
        self::SLUG_FREE,
        self::SLUG_PRO,
        self::SLUG_PREMIUM,
    ];

    /**
     * Tabela central de LIMITES por plano.
     * Unica fonte de verdade — NUNCA replicar em controllers.
     *
     * Chave: tipo de limite. Valor: array [slug => int|null].
     * null = sem limite (ilimitado).
     *
     * Tipos disponiveis (chaves publicas para uso em chamadas):
     *   - lancamentos      : int  - transacoes por mes
     *   - categorias       : int  - categorias personalizadas
     *   - orcamentos       : int  - orcamentos ativos
     *   - metas            : int  - metas ativas
     *   - historico_meses  : int|null - meses de historico disponivel (null=ilimitado)
     *   - ia_perguntas_dia : int  - perguntas ao assistente IA por dia
     *   - ia_insights_dia  : int  - insights automaticos da IA por dia
     */
    private const LIMITES = [
        'lancamentos' => [
            self::SLUG_FREE    => 100,
            self::SLUG_PRO     => 500,
            self::SLUG_PREMIUM => 2000,
        ],
        'categorias' => [
            self::SLUG_FREE    => 10,
            self::SLUG_PRO     => 50,
            self::SLUG_PREMIUM => 200,
        ],
        'orcamentos' => [
            self::SLUG_FREE    => 5,
            self::SLUG_PRO     => 20,
            self::SLUG_PREMIUM => self::LIMIT_UNLIMITED, // ilimitado
        ],
        'metas' => [
            self::SLUG_FREE    => 3,
            self::SLUG_PRO     => 15,
            self::SLUG_PREMIUM => 50,
        ],
        'historico_meses' => [
            self::SLUG_FREE    => 3,
            self::SLUG_PRO     => 24,
            self::SLUG_PREMIUM => self::LIMIT_UNLIMITED, // ilimitado
        ],
        'ia_perguntas_dia' => [
            self::SLUG_FREE    => 10,
            self::SLUG_PRO     => 40,
            self::SLUG_PREMIUM => 100,
        ],
        'ia_insights_dia' => [
            self::SLUG_FREE    => 0,
            self::SLUG_PRO     => 5,
            self::SLUG_PREMIUM => 20,
        ],
    ];

    /**
     * Tabela central de RECURSOS (features) por plano.
     * Cada feature e um bool por slug.
     *
     * Features disponiveis (chaves publicas):
     *   - exportar_csv       : exportar relatorios em CSV
     *   - exportar_pdf       : exportar relatorios em PDF
     *   - comparacao_meses   : comparacao entre meses
     *   - filtros_avancados  : filtros avancados em relatorios
     *   - ia_analise_metas   : analise de metas pela IA
     *   - ia_assistant       : acesso ao assistente IA (todo plano)
     *   - categorias_ilimitadas : sem limite de categorias (compat)
     *   - metas_ilimitadas   : sem limite de metas (compat)
     */
    private const FEATURES = [
        'exportar_csv' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => true,
            self::SLUG_PREMIUM => true,
        ],
        'exportar_pdf' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => true,
            self::SLUG_PREMIUM => true,
        ],
        'comparacao_meses' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => true,
            self::SLUG_PREMIUM => true,
        ],
        'filtros_avancados' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => true,
            self::SLUG_PREMIUM => true,
        ],
        'ia_analise_metas' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => true,
            self::SLUG_PREMIUM => true,
        ],
        'ia_assistant' => [
            self::SLUG_FREE    => true,
            self::SLUG_PRO     => true,
            self::SLUG_PREMIUM => true,
        ],
        'categorias_ilimitadas' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => false,
            self::SLUG_PREMIUM => true,
        ],
        'metas_ilimitadas' => [
            self::SLUG_FREE    => false,
            self::SLUG_PRO     => false,
            self::SLUG_PREMIUM => true,
        ],
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
     * Verifica se o plano tem uma feature.
     *
     * Features disponiveis:
     *   - exportar_csv, exportar_pdf, comparacao_meses,
     *     filtros_avancados, ia_analise_metas, ia_assistant,
     *     categorias_ilimitadas, metas_ilimitadas
     */
    public function hasFeature(string $planSlug, string $feature): bool
    {
        $slug = $this->normalizeSlug($planSlug);
        if (!isset(self::FEATURES[$feature])) {
            return false;
        }
        return self::FEATURES[$feature][$slug] ?? false;
    }

    /**
     * Retorna o limite de uma funcionalidade para o plano informado.
     *
     * Tipos disponiveis:
     *   - lancamentos, categorias, orcamentos, metas,
     *     historico_meses, ia_perguntas_dia, ia_insights_dia
     *
     * Retorna int|null:
     *   - int: limite numerico (inclui 0 para "nenhum")
     *   - null: sem limite (ilimitado — use LIMIT_UNLIMITED para checar)
     *
     * Exemplo:
     *   $limite = $planSvc->getLimit($slug, 'historico_meses');
     *   if ($limite === PlanService::LIMIT_UNLIMITED) { ... }
     */
    public function getLimit(string $planSlug, string $limitType): int|null
    {
        $slug = $this->normalizeSlug($planSlug);
        if (!isset(self::LIMITES[$limitType])) {
            return 0;
        }
        return self::LIMITES[$limitType][$slug] ?? 0;
    }

    /**
     * Retorna o limite de uma funcionalidade para o usuario informado.
     * Alias de getLimit que obtem o plano do usuario automaticamente.
     *
     * Exemplo:
     *   $planSvc->getUserLimit($userId, 'lancamentos')
     */
    public function getUserLimit(int $userId, string $limitType): int|null
    {
        $slug = $this->getUserPlanSlug($userId);
        return $this->getLimit($slug, $limitType);
    }

    /**
     * Verifica se o plano do usuario tem uma feature.
     * Alias de hasFeature que obtem o plano automaticamente.
     */
    public function userHasFeature(int $userId, string $feature): bool
    {
        $slug = $this->getUserPlanSlug($userId);
        return $this->hasFeature($slug, $feature);
    }

    /**
     * Retorna todos os limites do plano como array associativo.
     * Util para pasar contexto para views ou para auditoria.
     */
    public function getAllLimits(string $planSlug): array
    {
        $slug = $this->normalizeSlug($planSlug);
        $result = [];
        foreach (self::LIMITES as $type => $values) {
            $result[$type] = $values[$slug] ?? 0;
        }
        return $result;
    }

    /**
     * Retorna todas as features do plano como array associativo.
     */
    public function getAllFeatures(string $planSlug): array
    {
        $slug = $this->normalizeSlug($planSlug);
        $result = [];
        foreach (self::FEATURES as $feature => $values) {
            $result[$feature] = $values[$slug] ?? false;
        }
        return $result;
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

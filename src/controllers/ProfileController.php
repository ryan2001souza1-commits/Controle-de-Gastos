<?php
require_once __DIR__ . '/../services/CpfValidator.php';

class ProfileController
{
    private User $userModel;
    private PDO $db;

    public function __construct(User $userModel, PDO $db)
    {
        $this->userModel = $userModel;
        $this->db = $db;
    }

    public function index(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }
        $pageTitle = 'Configurações';
        $pageSubtitle = 'Gerencie seus dados e preferências';
        $activeMenu = 'configuracoes';
        $showPeriodPicker = false;
        $userName = $_SESSION['user_name'] ?? $user->name;
        $userInitials = strtoupper(substr($userName, 0, 1));
        $error = $_GET['error'] ?? null;
        $success = $_GET['success'] ?? null;
        require basePath('configuracoes.php');
    }

    public function updateProfile(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?action=configuracoes'); exit; }
        $userId = $_SESSION['user_id'];
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }

        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $cpf_raw = trim($_POST['cpf'] ?? '');
        $data_nascimento = trim($_POST['data_nascimento'] ?? '');
        $renda = trim($_POST['renda_mensal'] ?? '');
        $dia = trim($_POST['dia_recebimento'] ?? '');
        $objetivo = trim($_POST['objetivo'] ?? '');
        $moeda = trim($_POST['moeda'] ?? 'BRL');
        $notificacoes = isset($_POST['notificacoes']) ? 1 : 0;

        if ($nome === '' || $email === '') {
            header('Location: /index.php?action=configuracoes&error=invalid_data'); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: /index.php?action=configuracoes&error=invalid_email'); exit;
        }
        if ($this->userModel->isEmailTaken($email, $userId)) {
            header('Location: /index.php?action=configuracoes&error=email_taken'); exit;
        }
        if ($telefone !== '' && !preg_match('/^[0-9 \(\)\-\+]{8,20}$/', $telefone)) {
            header('Location: /index.php?action=configuracoes&error=invalid_phone'); exit;
        }
        $cpfNorm = null;
        if ($cpf_raw !== '') {
            $digits = CpfValidator::digits($cpf_raw);
            if ($digits === null || !CpfValidator::isValid($digits)) {
                header('Location: /index.php?action=configuracoes&error=invalid_cpf'); exit;
            }
            $cpfNorm = $digits;
        }
        $dataNascNorm = null;
        if ($data_nascimento !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $data_nascimento);
            if (!$d || $d->format('Y-m-d') !== $data_nascimento) {
                header('Location: /index.php?action=configuracoes&error=invalid_date'); exit;
            }
            if ($d > new DateTime()) {
                header('Location: /index.php?action=configuracoes&error=invalid_date'); exit;
            }
            $dataNascNorm = $data_nascimento;
        }
        $rendaNorm = null;
        if ($renda !== '') {
            $rendaNorm = (float)str_replace(',', '.', $renda);
            if (!is_numeric($rendaNorm) || $rendaNorm < 0 || $rendaNorm > 9999999) {
                header('Location: /index.php?action=configuracoes&error=invalid_income'); exit;
            }
        }
        $diaNorm = null;
        if ($dia !== '') {
            $diaNorm = (int)$dia;
            if ($diaNorm < 1 || $diaNorm > 31) {
                header('Location: /index.php?action=configuracoes&error=invalid_payday'); exit;
            }
        }
        if (!in_array($moeda, ['BRL','USD','EUR'], true)) $moeda = 'BRL';
        if (!in_array($objetivo, ['', 'economizar','organizar','investir','quitar_dividas'], true)) $objetivo = $objetivo ?: null;

        $fields = [
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone !== '' ? $telefone : null,
            'cpf' => $cpfNorm,
            'data_nascimento' => $dataNascNorm,
            'renda_mensal' => $rendaNorm,
            'dia_recebimento' => $diaNorm,
            'objetivo' => $objetivo ?: null,
            'moeda' => $moeda,
            'notificacoes' => $notificacoes,
        ];
        $this->userModel->updateProfile($userId, $fields);
        $_SESSION['user_name'] = $nome;
        $_SESSION['user_email'] = $email;
        header('Location: /index.php?action=configuracoes&success=updated'); exit;
    }

    public function meuPlano(): void
    {
        requireLogin();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }

        $planSvc = new PlanService($this->db);
        $currentPlanSlug = $planSvc->getUserPlanSlug($userId);
        $planData = $planSvc->getUserPlanData($user);
        $currentFeatures = $planSvc->getAllFeatures($currentPlanSlug);
        $currentLimits = $planSvc->getAllLimits($currentPlanSlug);
        $allPlanos = $planSvc->getAvailablePlanos();

        $planMeta = $planSvc->getPlanoBySlug($currentPlanSlug);
        $currentPrice = $planSvc->getPlanPrice($currentPlanSlug);

        $upgrades = [];
        $upgradeSlugs = array_filter(
            PlanService::getValidSlugs(),
            fn(string $s) => !in_array($s, [$currentPlanSlug], true)
                && in_array($s, SubscriptionService::ALLOWED_PLANS, true)
        );
        foreach ($upgradeSlugs as $slug) {
            $upgrades[$slug] = [
                'slug'    => $slug,
                'nome'    => $planSvc->getPlanDisplayName($slug),
                'preco'   => $planSvc->getPlanPrice($slug),
                'numeric_price' => $planSvc->getPlanNumericPrice($slug),
                'features' => $planSvc->getAllFeatures($slug),
                'limits'   => $planSvc->getAllLimits($slug),
            ];
        }

        $pageTitle = 'Meu Plano';
        $pageSubtitle = 'Gerencie seu plano e veja os recursos disponíveis para você.';
        $activeMenu = 'meu_plano';
        $showPeriodPicker = false;
        $userName = $_SESSION['user_name'] ?? $user->name;
        $userEmail = $user->email;

        // Labels da view (única definição — nunca no frontend).
        $limitLabels = [
            'lancamentos'     => ['label' => 'Lançamentos/mês', 'icon' => 'wallet'],
            'categorias'      => ['label' => 'Categorias', 'icon' => 'tag'],
            'orcamentos'      => ['label' => 'Orçamentos', 'icon' => 'chart'],
            'metas'           => ['label' => 'Metas', 'icon' => 'target'],
            'historico_meses' => ['label' => 'Histórico (meses)', 'icon' => 'history'],
            'ia_perguntas_dia'=> ['label' => 'Perguntas IA/dia', 'icon' => 'sparkles'],
            'ia_insights_dia' => ['label' => 'Insights IA/dia', 'icon' => 'zap'],
        ];
        $featureLabels = [
            'relatorios'        => ['label' => 'Relatórios', 'desc' => 'Acesso à tela de relatórios.'],
            'historico'         => ['label' => 'Histórico completo', 'desc' => 'Consulta estendida do histórico.'],
            'exportar_csv'      => ['label' => 'Exportar CSV', 'desc' => 'Exporte relatórios em CSV/Excel.'],
            'exportar_pdf'      => ['label' => 'Exportar PDF', 'desc' => 'Exporte relatórios em PDF.'],
            'comparacao_meses'  => ['label' => 'Comparação de meses', 'desc' => 'Compare meses lado a lado.'],
            'filtros_avancados' => ['label' => 'Filtros avançados', 'desc' => 'Filtros avançados nos relatórios.'],
            'ia_analise_metas'  => ['label' => 'IA analisa metas', 'desc' => 'Análise das metas pela IA.'],
            'ia_assistant'      => ['label' => 'Assistente IA', 'desc' => 'Acesso ao assistente financeiro.'],
            'categorias_ilimitadas' => ['label' => 'Categorias ilimitadas', 'desc' => 'Sem limite de categorias.'],
            'metas_ilimitadas'  => ['label' => 'Metas ilimitadas', 'desc' => 'Sem limite de metas financeiras.'],
            'ia_history'        => ['label' => 'Histórico da IA', 'desc' => 'Salva conversas com a IA.'],
            'ia_upload'         => ['label' => 'Upload para IA', 'desc' => 'Envie arquivos para a IA.'],
            'ia_images'         => ['label' => 'Imagens pela IA', 'desc' => 'Geração de imagens.'],
            'dashboard_advanced'=> ['label' => 'Dashboard avançado', 'desc' => 'Cards avançados no painel.'],
            'ai_insights'       => ['label' => 'Insights inteligentes', 'desc' => 'Alertas e insights automáticos.'],
        ];

        // Cancelamento disponível somente com assinatura ativa local.
        $canCancelSubscription = false;
        try {
            $subSvc = new SubscriptionService($this->db);
            $canCancelSubscription = $subSvc->findActiveForUser($userId) !== null;
        } catch (Throwable $e) {
            $canCancelSubscription = false;
        }

        require basePath('meu_plano.php');
    }

    /**
     * POST subscription_start — cria assinatura no Mercado Pago.
     * Preço NUNCA vem do frontend: o backend resolve plan_slug -> plano.
     * Em sucesso redireciona ao checkout (init_point); nunca ativa plano.
     */
    public function subscriptionStart(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=meu_plano&error=method'); exit;
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }

        $plan = strtolower(trim((string)($_POST['plan'] ?? '')));
        $svc = new SubscriptionService($this->db);
        $res = $svc->start($userId, (string)$user->email, $plan);
        if (!$res['ok']) {
            $map = [
                'invalid_plan' => 'invalid_plan', 'plan_not_found' => 'plan_not_found',
                'already_subscribed' => 'already_subscribed', 'config_error' => 'config_error',
            ];
            $err = $map[$res['error']] ?? 'service_error';
            header('Location: /index.php?action=meu_plano&error=' . $err); exit;
        }
        header('Location: ' . $res['init_point']); exit;
    }

    /** POST subscription_cancel — cancela no MP (quando vinculado) + baixa local. */
    public function subscriptionCancel(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=meu_plano&error=method'); exit;
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $svc = new SubscriptionService($this->db);
        $res = $svc->cancelActive($userId);
        if (!$res['ok']) {
            $err = $res['error'] === 'no_active_subscription' ? 'no_active_subscription' : 'cancel_service_error';
            header('Location: /index.php?action=meu_plano&error=' . $err); exit;
        }
        header('Location: /index.php?action=meu_plano&cancelled=1'); exit;
    }

    /**
     * GET mp_return — retorno neutro do checkout.
     * NUNCA ativa plano: apenas devolve o usuário ao Meu Plano.
     * A confirmação real virá pelo backend (API/webhook — ETAPA 2).
     */
    public function mpReturn(): void
    {
        requireLogin();
        header('Location: /index.php?action=meu_plano'); exit;
    }

    public function updatePassword(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?action=configuracoes'); exit; }
        $userId = (int)$_SESSION['user_id'];
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (strlen($new) < 8) {
            header('Location: /index.php?action=configuracoes&error=weak_password'); exit;
        }
        if (!preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)) {
            header('Location: /index.php?action=configuracoes&error=weak_password'); exit;
        }
        if ($new !== $confirm) {
            header('Location: /index.php?action=configuracoes&error=password_mismatch'); exit;
        }
        if ($user->password_hash && $current === '') {
            header('Location: /index.php?action=configuracoes&error=wrong_password'); exit;
        }
        if ($user->password_hash && !$user->verifyPassword($current)) {
            header('Location: /index.php?action=configuracoes&error=wrong_password'); exit;
        }

        $this->userModel->updatePassword($userId, $new);

        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (Throwable $e) {
            error_log('[ProfileController] session invalidation failed: ' . $e->getMessage());
        }

        header('Location: /index.php?action=configuracoes&success=password_updated'); exit;
    }
}
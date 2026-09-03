<?php

require_once __DIR__ . '/../src/config/config.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_VERCEL_FORWARDED_PROTO'] ?? '') === 'https')
    || (getenv('VERCEL_ENV') !== false);
// 7 dias de sessão — corrige logout após segundos/minutos
$lifetime = 604800;
if (PHP_VERSION_ID >= 70300 && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
// Configura handler em DB (serverless) antes de iniciar
if (session_status() === PHP_SESSION_NONE) {
    // Tenta conexão antecipada apenas para registrar handler; fallback para arquivos se falhar
    try {
        $tmpDb = getDBConnection();
        configureSession($tmpDb);
    } catch (Throwable $e) {
        error_log('[session init] ' . $e->getMessage());
        ini_set('session.gc_maxlifetime', (string)$lifetime);
    }
    session_start();
}

require_once __DIR__ . '/../src/helpers/csrf.php';

require_once __DIR__ . '/../src/models/User.php';
require_once __DIR__ . '/../src/models/Category.php';
require_once __DIR__ . '/../src/models/Expense.php';
require_once __DIR__ . '/../src/models/Income.php';
require_once __DIR__ . '/../src/models/Goal.php';
require_once __DIR__ . '/../src/models/Budget.php';
require_once __DIR__ . '/../src/models/PasswordReset.php';
require_once __DIR__ . '/../src/services/Mailer.php';
require_once __DIR__ . '/../src/services/RateLimiter.php';
require_once __DIR__ . '/../src/services/AuthService.php';
require_once __DIR__ . '/../src/services/GoogleAuthService.php';
require_once __DIR__ . '/../src/services/ExpenseService.php';
require_once __DIR__ . '/../src/services/ReportService.php';
require_once __DIR__ . '/../src/services/GoalService.php';
require_once __DIR__ . '/../src/services/BudgetService.php';
require_once __DIR__ . '/../src/services/PlanService.php';
require_once __DIR__ . '/../src/services/LancamentoLimitService.php';
require_once __DIR__ . '/../src/services/CategoriaLimitService.php';
require_once __DIR__ . '/../src/services/OrcamentoLimitService.php';
require_once __DIR__ . '/../src/services/MetaLimitService.php';
require_once __DIR__ . '/../src/services/DashboardPremiumService.php';
require_once __DIR__ . '/../src/controllers/AuthController.php';
require_once __DIR__ . '/partials/icons.php';
require_once __DIR__ . '/../src/controllers/ExpenseController.php';
require_once __DIR__ . '/../src/controllers/GoalController.php';
require_once __DIR__ . '/../src/controllers/ProfileController.php';
require_once __DIR__ . '/../src/models/BugReport.php';
require_once __DIR__ . '/../src/models/Plan.php';
require_once __DIR__ . '/../src/models/Feedback.php';
require_once __DIR__ . '/../src/controllers/AdminController.php';
require_once __DIR__ . '/../src/controllers/BugReportController.php';
require_once __DIR__ . '/../src/controllers/FeedbackController.php';
require_once __DIR__ . '/../src/services/AiFinanceContext.php';
require_once __DIR__ . '/../src/services/AiService.php';
require_once __DIR__ . '/../src/controllers/AiController.php';
require_once __DIR__ . '/../src/models/Subscription.php';
require_once __DIR__ . '/../src/services/MercadoPagoService.php';
require_once __DIR__ . '/../src/services/WebhookService.php';
require_once __DIR__ . '/../src/controllers/SubscriptionController.php';
require_once __DIR__ . '/../src/services/AsaasService.php';
require_once __DIR__ . '/../src/services/AsaasWebhookService.php';
require_once __DIR__ . '/../src/controllers/AsaasSubscriptionController.php';

$db = getDBConnection();
require_once __DIR__ . '/../src/db_bootstrap.php';
ensureSchemaUpToDate($db);
require_once __DIR__ . '/../src/migrations.php';
runMigrations($db);
$userModel = new User($db);
$categoryModel = new Category($db);
$expenseModel = new Expense($db);
$incomeModel = new Income($db);
$goalModel = new Goal($db);
$budgetModel = new Budget($db);
$resetModel = new PasswordReset($db);
$mailer = new Mailer();
$authService = new AuthService($userModel, $resetModel, $mailer, $db);
$expenseService = new ExpenseService($expenseModel, $incomeModel);
$goalService = new GoalService($goalModel);
$budgetService = new BudgetService($budgetModel, $categoryModel);
$planService = new PlanService($db);
$lancamentoLimitService = new LancamentoLimitService($expenseModel, $incomeModel, $planService);
$categoriaLimitService = new CategoriaLimitService($categoryModel, $planService);
$orcamentoLimitService = new OrcamentoLimitService($budgetModel, $planService);
$metaLimitService = new MetaLimitService($goalModel, $planService);
$dashboardPremiumService = new DashboardPremiumService($db, $planService, $expenseService, $budgetService, $goalService);
$authController = new AuthController($authService, new GoogleAuthService(), $userModel);
$expenseController = new ExpenseController($expenseModel, $incomeModel, $categoryModel, $expenseService, $budgetModel, $budgetService, $lancamentoLimitService, $categoriaLimitService, $orcamentoLimitService, $planService, $dashboardPremiumService);
$goalController = new GoalController($goalModel, $goalService, $metaLimitService);
$profileController = new ProfileController($userModel, $db);
$bugModel = new BugReport($db);
$planModel = new Plan($db);
$feedbackModel = new Feedback($db);
$adminController = new AdminController($userModel, $bugModel, $planModel, $feedbackModel, $db);
$bugReportController = new BugReportController($bugModel, $db);
$feedbackController = new FeedbackController($feedbackModel, $db);
$aiController = new AiController($db);
$subscriptionModel = new Subscription($db);
$mpService = MercadoPagoService::isConfigured()
    ? new MercadoPagoService()
    : null;
$subscriptionController = $mpService !== null
    ? new SubscriptionController($db, $userModel, $planService, $subscriptionModel, $mpService)
    : null;

$asaasService = AsaasService::isConfigured()
    ? new AsaasService()
    : null;
$asaasSubscriptionController = $asaasService !== null
    ? new AsaasSubscriptionController($db, $userModel, $planService, $subscriptionModel, $asaasService)
    : null;

$action = $_GET['action'] ?? null;

// --- Validação CSRF para requisições POST ---
// Lista de ações que exigem validação CSRF (todas que processam dados do formulário)
$csrfProtectedActions = [
    'register', 'login', 'forgot', 'reset', 'store', 'update', 'delete', 'store_category',
    'update_category', 'delete_category', 'store_budget', 'delete_budget',
    'store_goal', 'update_goal', 'delete_goal', 'update_profile',
    'update_password', 'feedback_create', 'reportar', 'reportar_create',
    'admin_bug_update', 'admin_feedback_update', 'ai_chat', 'logout',
    'subscription_create', 'subscription_cancel',
    'asaas_subscription_create', 'asaas_subscription_cancel',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Valida apenas ações que precisam de CSRF
    if (in_array($action, $csrfProtectedActions, true)) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;
        if (empty($csrfToken) || !$csrfService->validateToken($userId, $csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success'=>false, 'error'=>'Sessão expirada. Recarregue a página e tente novamente.']);
            exit;
        }
    }
}
// --- Fim validação CSRF ---

if ($action === 'register') {
    $authController->register();
} elseif ($action === 'login') {
    $authController->login();
} elseif ($action === 'logout') {
    $authController->logout();
} elseif ($action === 'forgot') {
    $authController->forgot();
} elseif ($action === 'reset') {
    $authController->reset();
} elseif ($action === 'site') {
    if (!isLoggedIn()) { header('Location: /index.php?action=login'); exit; }
    $expenseController->dashboard();
} elseif ($action === 'google-login') {
    $authController->googleLogin();
} elseif ($action === 'google-callback') {
    $authController->googleCallback();
} elseif ($action === 'store') {
    $expenseController->store();
} elseif ($action === 'store_category') {
    $expenseController->storeCategory();
} elseif ($action === 'delete') {
    $expenseController->delete();
} elseif ($action === 'edit') {
    $expenseController->edit();
} elseif ($action === 'update') {
    $expenseController->update();
} elseif ($action === 'lancamentos') {
    $expenseController->lancamentos();
} elseif ($action === 'categorias') {
    $expenseController->categorias();
} elseif ($action === 'update_category') {
    $expenseController->updateCategory();
} elseif ($action === 'delete_category') {
    $expenseController->deleteCategory();
} elseif ($action === 'relatorios') {
    $expenseController->relatorios();
} elseif ($action === 'metas') {
    $goalController->index();
} elseif ($action === 'store_goal') {
    $goalController->store();
} elseif ($action === 'update_goal') {
    $goalController->update();
} elseif ($action === 'delete_goal') {
    $goalController->delete();
} elseif ($action === 'orcamentos') {
    $expenseController->orcamentos();
} elseif ($action === 'store_budget') {
    $expenseController->storeBudget();
} elseif ($action === 'delete_budget') {
    $expenseController->deleteBudget();
} elseif ($action === 'assistente_ia') {
    $aiController->page();
} elseif ($action === 'ai_chat') {
    $aiController->chat();
} elseif ($action === 'sobre') {
    requireLogin();
    $pageTitle = 'Sobre Nós';
    $pageSubtitle = 'Conheça nossa história, missão e valores.';
    $activeMenu = 'sobre';
    $showPeriodPicker = false;
    require basePath('sobre.php');
} elseif ($action === 'configuracoes') {
    $profileController->index();
} elseif ($action === 'meu_plano') {
    $profileController->meuPlano();
} elseif ($action === 'mercadopago_webhook') {
    // Webhook publico do Mercado Pago (servidor-servidor).
    // NAO exige login nem CSRF: a autenticacao e feita via X-Signature (HMAC-SHA256)
    // validada pelo WebhookService.
    if ($mpService === null) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'mp_not_configured']);
        exit;
    }

    $rawBody = (string)file_get_contents('php://input');

    $xSignature = isset($_SERVER['HTTP_X_SIGNATURE']) ? (string)$_SERVER['HTTP_X_SIGNATURE'] : null;
    $xRequestId = isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string)$_SERVER['HTTP_X_REQUEST_ID'] : null;

    // O Mercado Pago pode enviar o ID do recurso na query string de varias formas:
    //   ?data.id=123   (formato com ponto na chave — PHP converte para $_GET['data_id'])
    //   ?data_id=123   (formato alternativo)
    //   ?id=123        (alguns tipos de notificacao)
    $resourceId = null;
    if (isset($_GET['data_id']) && is_string($_GET['data_id']) && $_GET['data_id'] !== '') {
        $resourceId = (string)$_GET['data_id'];
    } elseif (isset($_GET['data.id']) && is_string($_GET['data.id']) && $_GET['data.id'] !== '') {
        $resourceId = (string)$_GET['data.id'];
    } elseif (isset($_GET['id']) && is_string($_GET['id']) && $_GET['id'] !== '') {
        $resourceId = (string)$_GET['id'];
    }

    $sourceIp = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;

    $webhookService = new WebhookService($db, $subscriptionModel, $mpService);
    $result = $webhookService->handle($rawBody, $xSignature, $xRequestId, $resourceId, $sourceIp);

    http_response_code((int)($result['status'] ?? 200));
    header('Content-Type: application/json');
    echo json_encode([
        'received'  => true,
        'duplicate' => (bool)($result['duplicate'] ?? false),
        'processed' => (bool)($result['processed'] ?? false),
    ]);
    exit;
} elseif ($action === 'asaas_webhook') {
    // Webhook publico do Asaas (servidor-servidor).
    // NAO exige login nem CSRF: autenticacao via header "asaas-access-token"
    // (hash_equals com ASAAS_WEBHOOK_TOKEN).
    if ($asaasService === null) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'asaas_not_configured']);
        exit;
    }

    $rawBody = (string)file_get_contents('php://input');
    $accessToken = isset($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']) ? (string)$_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] : null;
    $sourceIp = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;

    $webhookSecret = (string)(getenv('ASAAS_WEBHOOK_TOKEN') ?: '');
    if ($webhookSecret === '') {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'webhook_token_not_configured']);
        exit;
    }

    if ($accessToken === null || $accessToken === '' || !hash_equals($webhookSecret, $accessToken)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }

    $webhookService = new AsaasWebhookService($db, $subscriptionModel);
    $result = $webhookService->handle($rawBody, $accessToken, $sourceIp);

    http_response_code((int)($result['status'] ?? 200));
    header('Content-Type: application/json');
    echo json_encode([
        'received'  => true,
        'duplicate' => (bool)($result['duplicate'] ?? false),
        'processed' => (bool)($result['processed'] ?? false),
    ]);
    exit;
} elseif ($action === 'subscription_create') {
    // Mercado Pago (POST com card_token_id). Nunca cai no Asaas.
    if ($subscriptionController !== null) {
        $subscriptionController->create();
    } else {
        header('Location: /?action=meu_plano&error=mp_not_configured'); exit;
    }
} elseif ($action === 'subscription_redirect') {
    if ($subscriptionController !== null) {
        $subscriptionController->redirect();
    } else {
        header('Location: /?action=meu_plano&error=mp_not_configured'); exit;
    }
} elseif ($action === 'subscription_cancel') {
    // Mercado Pago (POST). Nunca cai no Asaas.
    if ($subscriptionController !== null) {
        $subscriptionController->cancel();
    } else {
        header('Location: /?action=meu_plano&error=mp_not_configured'); exit;
    }
} elseif ($action === 'asaas_subscription_create') {
    if ($asaasSubscriptionController !== null) {
        $asaasSubscriptionController->create();
    } else {
        header('Location: /?action=meu_plano&error=asaas_not_configured'); exit;
    }
} elseif ($action === 'asaas_subscription_cancel') {
    if ($asaasSubscriptionController !== null) {
        $asaasSubscriptionController->cancel();
    } else {
        header('Location: /?action=meu_plano&error=asaas_not_configured'); exit;
    }
} elseif ($action === 'update_profile') {
    $profileController->updateProfile();
} elseif ($action === 'update_password') {
    $profileController->updatePassword();
} elseif ($action === 'admin' || $action === 'admin_dashboard') {
    $adminController->dashboard();
} elseif ($action === 'admin_usuarios') {
    $adminController->usuarios();
} elseif ($action === 'admin_bugs') {
    $adminController->bugs();
} elseif ($action === 'admin_bug_detail') {
    $adminController->bugDetail();
} elseif ($action === 'admin_bug_update') {
    $adminController->updateBug();
} elseif ($action === 'admin_planos') {
    $adminController->planos();
} elseif ($action === 'admin_feedback') {
    $adminController->feedback();
} elseif ($action === 'admin_feedback_update') {
    $feedbackController->adminUpdate();
} elseif ($action === 'feedback') {
    $feedbackController->form();
} elseif ($action === 'feedback_create') {
    $feedbackController->create();
} elseif ($action === 'meu_feedback') {
    $feedbackController->myFeedback();
} elseif ($action === 'reportar') {
    $bugReportController->form();
} elseif ($action === 'reportar_create') {
    $bugReportController->create();
} elseif ($action === 'meus_relatos') {
    $bugReportController->myReports();
} elseif ($action === 'setup_first_admin') {
    // Endpoint one-time: cria primeiro admin em produção (só funciona se ainda não existir admin)
    // Protegido por token secreto configurado via SETUP_SECRET no ambiente
    if (getenv('SETUP_SECRET') === false || getenv('SETUP_SECRET') === '') {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Endpoint nao disponivel']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Metodo nao permitido']);
        exit;
    }
    $provided = $_REQUEST['secret'] ?? '';
    if (!is_string($provided) || !hash_equals(getenv('SETUP_SECRET'), $provided)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Token invalido']);
        exit;
    }
    try {
        $hasAdmin = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE is_admin = 1")->fetchColumn();
        if ($hasAdmin > 0) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok'=>false,'error'=>'Admin ja existe']);
            exit;
        }
        $email = trim($_REQUEST['email'] ?? '');
        $pass = $_REQUEST['password'] ?? '';
        $name = trim($_REQUEST['name'] ?? 'Administrador');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Email ou senha invalidos (senha min 8)']);
            exit;
        }
        $existing = $db->prepare("SELECT id FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
        $existing->execute([$email]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        if ($row) {
            $db->prepare("UPDATE usuarios SET senha=?, is_admin=1, nome=COALESCE(NULLIF(?,''), nome), plano='premium', plano_status='ativo', updated_at=NOW() WHERE id=?")->execute([$hash, $name, $row['id']]);
            $id = $row['id'];
        } else {
            $db->prepare("INSERT INTO usuarios (nome,email,senha,is_admin,plano,plano_status) VALUES (?,?,?,1,'premium','ativo')")->execute([$name,$email,$hash]);
            $id = $db->lastInsertId();
        }
        $chk = $db->prepare("SELECT id,email,is_admin FROM usuarios WHERE id=?");
        $chk->execute([$id]);
        $ok = $chk->fetch(PDO::FETCH_ASSOC);
        if ($ok && (int)$ok['is_admin']===1) {
            echo json_encode(['ok'=>true,'id'=>$ok['id'],'email'=>$ok['email'],'is_admin'=>1]);
        } else {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'Falha ao criar admin']);
        }
        exit;
    } catch (Throwable $e) {
        error_log('[setup_first_admin] '.$e->getMessage());
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Erro interno']);
        exit;
    }
} elseif ($action === 'diag') {
    // Endpoint de diagnóstico — SOMENTE development/local.
    // Bloqueia em produção (VERCEL_ENV) a menos que DIAG_SECRET esteja configurado.
    requireLogin();
    if (empty($_SESSION['is_admin'])) { http_response_code(403); exit; }
    $isProduction = getenv('VERCEL_ENV') !== false;
    $diagSecret = getenv('DIAG_SECRET');
    if ($isProduction && (!$diagSecret || $diagSecret === '')) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Endpoint nao disponivel']);
        exit;
    }
    if ($diagSecret && $diagSecret !== '') {
        $provided = $_REQUEST['diag_secret'] ?? '';
        if (!is_string($provided) || !hash_equals($diagSecret, $provided)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error'=>'Acesso negado']);
            exit;
        }
    }
    header('Content-Type: application/json');
    try {
        $email = is_string($_REQUEST['email'] ?? null) ? trim((string)$_REQUEST['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error'=>'E-mail invalido']);
            exit;
        }
        $stmt = $db->prepare("SELECT id, is_admin FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'exists' => (bool)$row,
            'is_admin' => $row ? (int)$row['is_admin'] : null,
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('[diag] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error'=>'Erro interno']);
        exit;
    }
} elseif ($action === 'mp_env_diag') {
    requireLogin();
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Admin required']);
        exit;
    }
    $token = (string)(getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '');
    $pubKey = (string)(getenv('MERCADOPAGO_PUBLIC_KEY') ?: '');
    $mode = strtolower((string)(getenv('MERCADOPAGO_MODE') ?: ''));
    $webhookSecret = (string)(getenv('MERCADOPAGO_WEBHOOK_SECRET') ?: '');
    $planPro = (string)(getenv('MERCADOPAGO_PLAN_ID_PRO') ?: '');
    $planPrem = (string)(getenv('MERCADOPAGO_PLAN_ID_PREMIUM') ?: '');

    $tokenType = 'missing';
    if ($token !== '') {
        $tokenType = str_starts_with($token, 'TEST-') ? 'test' :
            (str_starts_with($token, 'APP_USR-') || str_starts_with($token, 'APP PRD-') ? 'production' : 'unknown');
    }

    $pubKeyType = 'missing';
    if ($pubKey !== '') {
        $pubKeyType = str_starts_with($pubKey, 'TEST-') ? 'test' :
            (str_starts_with($pubKey, 'APP_USR-') || str_starts_with($pubKey, 'APP PRD-') ? 'production' : 'unknown');
    }

    $modeType = 'production';
    if ($mode === 'sandbox') {
        $modeType = 'sandbox';
    } elseif ($mode !== 'production' && $mode !== '') {
        $modeType = 'other';
    }

    $diag = [
        'mode' => $modeType,
        'public_key_type' => $pubKeyType,
        'access_token_type' => $tokenType,
        'plan_pro_configured' => ($planPro !== '' ? 'yes' : 'no'),
        'plan_premium_configured' => ($planPrem !== '' ? 'yes' : 'no'),
        'webhook_secret_configured' => ($webhookSecret !== '' ? 'yes' : 'no'),
    ];

    $checkPlan = function(string $planId) use ($token): array {
        if ($planId === '') {
            return ['configured' => false, 'http' => null, 'api_reachable' => null];
        }
        if ($token === '') {
            return ['configured' => true, 'http' => null, 'api_reachable' => false, 'error' => 'access_token_missing'];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\nUser-Agent: Controle-de-Gastos-diag/1.0\r\n",
                'timeout' => 8,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $url = 'https://api.mercadopago.com/preapproval_plan/' . rawurlencode($planId);
        $raw = @file_get_contents($url, false, $ctx);
        $statusHeader = isset($http_response_header[0]) ? $http_response_header[0] : '';
        preg_match('/\s(\d{3})\s/', $statusHeader, $m);
        $httpCode = isset($m[1]) ? (int)$m[1] : 0;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }
        $out = [
            'configured' => true,
            'http' => $httpCode,
            'api_reachable' => ($httpCode > 0),
        ];
        if ($httpCode >= 200 && $httpCode < 300) {
            $out['plan_id'] = isset($data['id']) ? (string)$data['id'] : null;
            $out['plan_status'] = isset($data['status']) ? (string)$data['status'] : null;
            $out['application_id'] = isset($data['application_id']) ? (string)$data['application_id'] : null;
            $out['collector_id'] = isset($data['collector_id']) ? (string)$data['collector_id'] : null;
            $out['reason'] = isset($data['reason']) ? (string)$data['reason'] : null;
            $ar = isset($data['auto_recurring']) && is_array($data['auto_recurring']) ? $data['auto_recurring'] : [];
            $out['amount'] = isset($ar['transaction_amount']) ? (string)$ar['transaction_amount'] : null;
            $out['currency'] = isset($ar['currency_id']) ? (string)$ar['currency_id'] : null;
            $out['has_sandbox_init_point'] = !empty($data['sandbox_init_point']);
            $out['has_init_point'] = !empty($data['init_point']);
        } else {
            $out['api_code'] = isset($data['code']) ? (string)$data['code'] : null;
            $out['api_message'] = isset($data['message']) ? substr((string)$data['message'], 0, 200) : null;
            if (!empty($data['cause']) && is_array($data['cause'])) {
                $cs = [];
                foreach ($data['cause'] as $c) {
                    if (is_array($c)) {
                        $cs[] = (isset($c['code']) ? (string)$c['code'] : '?')
                            . (isset($c['description']) ? ':' . substr((string)$c['description'], 0, 80) : '');
                    }
                }
                if ($cs !== []) {
                    $out['api_causes'] = $cs;
                }
            }
        }
        return $out;
    };

    $diag['plan_pro'] = $checkPlan($planPro);
    $diag['plan_premium'] = $checkPlan($planPrem);

    header('Content-Type: application/json');
    echo json_encode($diag);
    exit;
} elseif ($action === 'termos') {
    require basePath('termos.php');
} elseif ($action === 'privacy') {
    require basePath('privacidade.php');
} elseif (isLoggedIn()) {
    if (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
        header('Location: /index.php?action=admin');
        exit;
    }
    try {
        if (!empty($_SESSION['user_id']) && (new User(getDBConnection()))->isAdmin((int)$_SESSION['user_id'])) {
            $_SESSION['is_admin'] = 1;
            header('Location: /index.php?action=admin');
            exit;
        }
    } catch (Throwable $e) {}
    $expenseController->dashboard();
} else {
    $authController->login();
}

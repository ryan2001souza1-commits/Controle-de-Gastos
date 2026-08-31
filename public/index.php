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
require_once __DIR__ . '/../src/models/User.php';
require_once __DIR__ . '/../src/models/Category.php';
require_once __DIR__ . '/../src/models/Expense.php';
require_once __DIR__ . '/../src/models/Income.php';
require_once __DIR__ . '/../src/models/Goal.php';
require_once __DIR__ . '/../src/models/Budget.php';
require_once __DIR__ . '/../src/models/PasswordReset.php';
require_once __DIR__ . '/../src/services/Mailer.php';
require_once __DIR__ . '/../src/services/AuthService.php';
require_once __DIR__ . '/../src/services/GoogleAuthService.php';
require_once __DIR__ . '/../src/services/ExpenseService.php';
require_once __DIR__ . '/../src/services/ReportService.php';
require_once __DIR__ . '/../src/services/GoalService.php';
require_once __DIR__ . '/../src/services/BudgetService.php';
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
$authController = new AuthController($authService, new GoogleAuthService(), $userModel);
$expenseController = new ExpenseController($expenseModel, $incomeModel, $categoryModel, $expenseService, $budgetModel, $budgetService);
$goalController = new GoalController($goalModel, $goalService);
$profileController = new ProfileController($userModel, $db);
$bugModel = new BugReport($db);
$planModel = new Plan($db);
$feedbackModel = new Feedback($db);
$adminController = new AdminController($userModel, $bugModel, $planModel, $feedbackModel, $db);
$bugReportController = new BugReportController($bugModel, $db);
$feedbackController = new FeedbackController($feedbackModel, $db);

$action = $_GET['action'] ?? null;

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
} elseif ($action === 'sobre') {
    requireLogin();
    $pageTitle = 'Sobre Nós';
    $pageSubtitle = 'Conheça nossa história, missão e valores.';
    $activeMenu = 'sobre';
    $showPeriodPicker = false;
    require basePath('sobre.php');
} elseif ($action === 'configuracoes') {
    $profileController->index();
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
    // Diagnóstico: verifica conta admin no banco sem expor hash completo
    header('Content-Type: application/json');
    try {
        $email = $_REQUEST['email'] ?? 'admin20264@gmail.com';
        $stmt = $db->prepare("SELECT id, email, is_admin, plano, LEFT(senha,8) as hash_prefix, LENGTH(senha) as hash_len FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['exists'=>false,'email'=>$email,'db_host'=>parse_url(getenv('DATABASE_URL')?:'',PHP_URL_HOST)]);
            exit;
        }
        $verify = null;
        if (!empty($_REQUEST['test_password'])) {
            $full = $db->prepare("SELECT senha FROM usuarios WHERE id=?");
            $full->execute([$row['id']]);
            $verify = password_verify($_REQUEST['test_password'], $full->fetchColumn()) ? 'OK' : 'FAIL';
        }
        echo json_encode([
            'exists'=>true,
            'id'=>$row['id'],
            'is_admin'=>(int)$row['is_admin'],
            'plano'=>$row['plano'],
            'hash_prefix'=>$row['hash_prefix'],
            'hash_len'=>(int)$row['hash_len'],
            'verify_test'=>$verify,
            'db_host'=>parse_url(getenv('DATABASE_URL')?:'',PHP_URL_HOST),
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error'=>$e->getMessage()]);
        exit;
    }
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

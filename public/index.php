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

$db = getDBConnection();
require_once __DIR__ . '/../src/db_bootstrap.php';
ensureSchemaUpToDate($db);
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
} elseif ($action === 'configuracoes') {
    $profileController->index();
} elseif ($action === 'update_profile') {
    $profileController->updateProfile();
} elseif ($action === 'update_password') {
    $profileController->updatePassword();
} elseif (isLoggedIn()) {
    $expenseController->dashboard();
} else {
    $authController->login();
}

<?php

session_start();

require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/models/User.php';
require_once __DIR__ . '/../src/models/Category.php';
require_once __DIR__ . '/../src/models/Expense.php';
require_once __DIR__ . '/../src/models/Income.php';
require_once __DIR__ . '/../src/models/Goal.php';
require_once __DIR__ . '/../src/models/Budget.php';
require_once __DIR__ . '/../src/services/AuthService.php';
require_once __DIR__ . '/../src/services/ExpenseService.php';
require_once __DIR__ . '/../src/services/ReportService.php';
require_once __DIR__ . '/../src/services/GoalService.php';
require_once __DIR__ . '/../src/services/BudgetService.php';
require_once __DIR__ . '/../src/controllers/AuthController.php';
require_once __DIR__ . '/../src/controllers/ExpenseController.php';
require_once __DIR__ . '/../src/controllers/GoalController.php';

$db = getDBConnection();
$userModel = new User($db);
$categoryModel = new Category($db);
$expenseModel = new Expense($db);
$incomeModel = new Income($db);
$goalModel = new Goal($db);
$budgetModel = new Budget($db);
$authService = new AuthService($userModel);
$expenseService = new ExpenseService($expenseModel, $incomeModel);
$goalService = new GoalService($goalModel);
$budgetService = new BudgetService($budgetModel, $categoryModel);
$authController = new AuthController($authService);
$expenseController = new ExpenseController($expenseModel, $incomeModel, $categoryModel, $expenseService, $budgetModel, $budgetService);
$goalController = new GoalController($goalModel, $goalService);

$action = $_GET['action'] ?? null;

if ($action === 'register') {
    $authController->register();
} elseif ($action === 'login') {
    $authController->login();
} elseif ($action === 'logout') {
    $authController->logout();
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
} elseif (isLoggedIn()) {
    $expenseController->dashboard();
} else {
    $authController->login();
}

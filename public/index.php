<?php

session_start();

require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/models/User.php';
require_once __DIR__ . '/../src/models/Category.php';
require_once __DIR__ . '/../src/models/Expense.php';
require_once __DIR__ . '/../src/models/Income.php';
require_once __DIR__ . '/../src/services/AuthService.php';
require_once __DIR__ . '/../src/services/ExpenseService.php';
require_once __DIR__ . '/../src/services/ReportService.php';
require_once __DIR__ . '/../src/controllers/AuthController.php';
require_once __DIR__ . '/../src/controllers/ExpenseController.php';

$db = getDBConnection();
$userModel = new User($db);
$categoryModel = new Category($db);
$expenseModel = new Expense($db);
$incomeModel = new Income($db);
$authService = new AuthService($userModel);
$expenseService = new ExpenseService($expenseModel, $incomeModel);
$authController = new AuthController($authService);
$expenseController = new ExpenseController($expenseModel, $incomeModel, $categoryModel, $expenseService);

$action = $_GET['action'] ?? null;

if ($action === 'register') {
    $authController->register();
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
} elseif (isLoggedIn()) {
    $expenseController->dashboard();
} else {
    $authController->login();
}

<?php

session_start();

require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/models/User.php';
require_once __DIR__ . '/../src/models/Category.php';
require_once __DIR__ . '/../src/models/Expense.php';
require_once __DIR__ . '/../src/models/Income.php';
require_once __DIR__ . '/../src/services/AuthService.php';
require_once __DIR__ . '/../src/services/ExpenseService.php';
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
} elseif ($action === 'delete') {
    $expenseController->delete();
} elseif (isLoggedIn()) {
    $expenseController->dashboard();
} else {
    $authController->login();
}

<?php

class ExpenseController
{
    private Expense $expenseModel;
    private Income $incomeModel;
    private Category $categoryModel;
    private ExpenseService $expenseService;

    public function __construct(
        Expense $expenseModel,
        Income $incomeModel,
        Category $categoryModel,
        ExpenseService $expenseService
    ) {
        $this->expenseModel = $expenseModel;
        $this->incomeModel = $incomeModel;
        $this->categoryModel = $categoryModel;
        $this->expenseService = $expenseService;
    }

    public function dashboard(): void
    {
        requireLogin();

        $userId = $_SESSION['user_id'];
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        $data = $this->expenseService->getDashboardData($userId, $startDate, $endDate);
        $categories = $this->categoryModel->findAll($userId, 'expense');

        require basePath('dashboard.php');
    }

    public function store(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $description = trim($_POST['description'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $date = $_POST['date'] ?? date('Y-m-d');
            $categoryId = !empty($_POST['category_id'])
                ? (int) $_POST['category_id']
                : null;
            $type = $_POST['type'] ?? 'despesa';
            $userId = $_SESSION['user_id'];

            if (empty($description) || $amount <= 0 || empty($date)) {
                header('Location: /dashboard.php?error=invalid_data');
                exit;
            }

            if ($type === 'despesa') {
                $this->expenseModel->create(
                    $description,
                    $amount,
                    $date,
                    $categoryId,
                    $userId
                );
            } elseif ($type === 'receita') {
                $this->incomeModel->create(
                    $description,
                    $amount,
                    $date,
                    $userId
                );
            }

            header('Location: /dashboard.php?success=1');
            exit;
        }
    }

    public function delete(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['type'])) {
            $id = (int)$_POST['id'];
            $type = $_POST['type'];
            $userId = $_SESSION['user_id'];

            if ($type === 'despesa') {
                $this->expenseModel->delete($id, $userId);
            } elseif ($type === 'receita') {
                $this->incomeModel->delete($id, $userId);
            }

            header('Location: /dashboard.php');
            exit;
        }
    }
}

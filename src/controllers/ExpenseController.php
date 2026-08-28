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
        $data = $this->expenseService->getDashboardData(
            $userId,
            $startDate,
            $endDate
        );
        $expenseCategories = $this->categoryModel->findAll(
            $userId,
            'despesa'
        );
        $incomeCategories = $this->categoryModel->findAll(
            $userId,
            'receita'
        );

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
                header('Location: /index.php?error=invalid_data');
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

            header('Location: /index.php?success=1');
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

            header('Location: /index.php');
            exit;
        }
    }

    public function storeCategory(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'despesa';
            $userId = $_SESSION['user_id'];

            if ($name === '' || !in_array($type, ['despesa', 'receita'], true)) {
                header('Location: /index.php?error=invalid_category');
                exit;
            }

            $this->categoryModel->create($name, $type, $userId);

            header('Location: /index.php?success=1');
            exit;
        }
    }

    public function edit(): void
    {
        requireLogin();

        $userId = $_SESSION['user_id'];
        $id = (int)($_GET['id'] ?? 0);
        $type = $_GET['type'] ?? 'despesa';

        if ($id <= 0 || !in_array($type, ['despesa', 'receita'], true)) {
            header('Location: /index.php?error=invalid_id');
            exit;
        }

        if ($type === 'despesa') {
            $transaction = $this->expenseModel->findById($id, $userId);
        } else {
            $transaction = $this->incomeModel->findById($id, $userId);
        }

        if (!$transaction) {
            header('Location: /index.php?error=not_found');
            exit;
        }

        $expenseCategories = $this->categoryModel->findAll($userId, 'despesa');
        $incomeCategories = $this->categoryModel->findAll($userId, 'receita');

        $error = $_GET['error'] ?? null;
        $pageTitle = 'Editar Lançamento - Controle de Gastos';

        require basePath('edit.php');
    }

    public function update(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php');
            exit;
        }

        $userId = $_SESSION['user_id'];
        $id = (int)($_POST['id'] ?? 0);
        $type = $_POST['type'] ?? 'despesa';

        if ($id <= 0 || !in_array($type, ['despesa', 'receita'], true)) {
            header('Location: /index.php?error=invalid_id');
            exit;
        }

        $description = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $date = $_POST['date'] ?? '';
        $categoryId = !empty($_POST['category_id'])
            ? (int) $_POST['category_id']
            : null;

        if ($description === '' || $amount <= 0 || $date === '') {
            header("Location: /index.php?action=edit&id={$id}&type={$type}&error=invalid_data");
            exit;
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            header("Location: /index.php?action=edit&id={$id}&type={$type}&error=invalid_date");
            exit;
        }

        if ($type === 'despesa') {
            if ($categoryId !== null) {
                $cats = $this->categoryModel->findAll($userId, 'despesa');
                $validIds = array_map(fn($c) => (int)$c['id'], $cats);
                if (!in_array($categoryId, $validIds, true)) {
                    $categoryId = null;
                }
            }

            $ok = $this->expenseModel->update($id, $description, $amount, $date, $categoryId, $userId);
        } else {
            $ok = $this->incomeModel->update($id, $description, $amount, $date, $userId);
        }

        if ($ok) {
            header('Location: /index.php?success=updated');
        } else {
            header("Location: /index.php?action=edit&id={$id}&type={$type}&error=update_failed");
        }
        exit;
    }
}

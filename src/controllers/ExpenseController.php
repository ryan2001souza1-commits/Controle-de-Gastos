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
        $this->incomeModel  = $incomeModel;
        $this->categoryModel = $categoryModel;
        $this->expenseService = $expenseService;
    }

    public function dashboard(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];
        $startDate = $_GET['start_date'] ?? null;
        $endDate   = $_GET['end_date']   ?? null;

        if (!$startDate) $startDate = date('Y-m-01');
        if (!$endDate)   $endDate   = date('Y-m-t');

        $data = $this->expenseService->getDashboardData(
            $userId,
            $startDate,
            $endDate
        );
        $expenseCategories = $this->categoryModel->findAll($userId, 'despesa');
        $incomeCategories  = $this->categoryModel->findAll($userId, 'receita');

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

            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                header('Location: /index.php?error=invalid_data');
                exit;
            }

            if ($type === 'despesa') {
                $this->expenseModel->create($description, $amount, $date, $categoryId, $userId);
            } elseif ($type === 'receita') {
                $this->incomeModel->create($description, $amount, $date, $userId);
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

            header('Location: ' . $this->safeReferer());
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

            if ($this->categoryModel->countByName($name, $type, $userId) > 0) {
                header('Location: /index.php?error=duplicate_category');
                exit;
            }

            $this->categoryModel->create($name, $type, $userId);

            $referer = $this->safeReferer();
            header('Location: ' . $referer . (strpos($referer, '?') !== false ? '&' : '?') . 'success=1');
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
        $incomeCategories  = $this->categoryModel->findAll($userId, 'receita');

        $error = $_GET['error'] ?? null;
        $pageTitle = 'Editar Lançamento - Controle de Gastos';
        $userName  = $_SESSION['user_name'] ?? 'Usuário';

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
            $referer = $this->safeReferer();
            header('Location: ' . $referer . (strpos($referer, '?') !== false ? '&' : '?') . 'success=updated');
        } else {
            header("Location: /index.php?action=edit&id={$id}&type={$type}&error=update_failed");
        }
        exit;
    }

    private function safeReferer(): string
    {
        $default = '/index.php';
        $referer = $_SERVER['HTTP_REFERER'] ?? $default;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '' && $referer !== '' && stripos($referer, $host) === false) {
            return $default;
        }
        if (!preg_match('#^/[^/]#', $referer)) {
            return $default;
        }
        return $referer;
    }

    /* ================================================================
       MÓDULO: LANÇAMENTOS
       ================================================================ */
    public function lancamentos(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];

        $startDate  = $_GET['start_date']  ?? date('Y-m-01');
        $endDate    = $_GET['end_date']    ?? date('Y-m-t');
        $filterType = $_GET['type']        ?? '';
        $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== ''
            ? (int)$_GET['category_id']
            : null;
        $search     = $_GET['search']      ?? '';

        if ($filterType && !in_array($filterType, ['despesa', 'receita'], true)) {
            $filterType = '';
        }

        if ($filterType === 'despesa') {
            $rows = $this->expenseModel->findAllByUser(
                $userId, $startDate, $endDate, $categoryId, $search
            );
        } elseif ($filterType === 'receita') {
            $rows = $this->incomeModel->findAllByUser(
                $userId, $startDate, $endDate, $search
            );
        } else {
            $expenses = $this->expenseModel->findAllByUser(
                $userId, $startDate, $endDate, $categoryId, $search
            );
            $incomes = $this->incomeModel->findAllByUser(
                $userId, $startDate, $endDate, $search
            );
            $rows = array_merge($expenses, $incomes);
            usort($rows, function ($a, $b) {
                $da = isset($a['date']) ? strtotime($a['date']) : 0;
                $db = isset($b['date']) ? strtotime($b['date']) : 0;
                if ($da === $db) return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
                return $db <=> $da;
            });
        }

        $totalIncomes  = $this->incomeModel->getTotalByUser($userId, $startDate, $endDate);
        $totalExpenses = $this->expenseModel->getTotalByUser($userId, $startDate, $endDate);
        $balance = $totalIncomes - $totalExpenses;

        $expenseCategories = $this->categoryModel->findAll($userId, 'despesa');
        $incomeCategories  = $this->categoryModel->findAll($userId, 'receita');

        $pageTitle = 'Lançamentos - Controle de Gastos';
        $userName  = $_SESSION['user_name'] ?? 'Usuário';

        require basePath('lancamentos.php');
    }

    /* ================================================================
       MÓDULO: CATEGORIAS
       ================================================================ */
    public function categorias(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];

        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate   = $_GET['end_date']   ?? date('Y-m-t');

        $expenseCats = $this->categoryModel->findAllWithStats(
            $userId, 'despesa', $startDate, $endDate
        );
        $incomeCats = $this->categoryModel->findAllWithStats(
            $userId, 'receita', $startDate, $endDate
        );

        $pageTitle = 'Categorias - Controle de Gastos';
        $userName  = $_SESSION['user_name'] ?? 'Usuário';

        require basePath('categorias.php');
    }

    public function updateCategory(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=categorias');
            exit;
        }

        $userId = $_SESSION['user_id'];
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'despesa';

        if ($id <= 0 || $name === '' || !in_array($type, ['despesa', 'receita'], true)) {
            header('Location: /index.php?action=categorias&error=invalid_category');
            exit;
        }

        $existing = $this->categoryModel->findById($id, $userId);
        if (!$existing) {
            header('Location: /index.php?action=categorias&error=not_found');
            exit;
        }

        if ($this->categoryModel->countByName($name, $type, $userId, $id) > 0) {
            header('Location: /index.php?action=categorias&error=duplicate_category');
            exit;
        }

        $this->categoryModel->update($id, $name, $type, $userId);
        header('Location: /index.php?action=categorias&success=updated');
        exit;
    }

    public function deleteCategory(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=categorias');
            exit;
        }

        $userId = $_SESSION['user_id'];
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: /index.php?action=categorias&error=invalid_category');
            exit;
        }

        $existing = $this->categoryModel->findById($id, $userId);
        if (!$existing) {
            header('Location: /index.php?action=categorias&error=not_found');
            exit;
        }

        $txCount = $this->categoryModel->countTransactions($id, $userId);
        if ($txCount > 0) {
            header('Location: /index.php?action=categorias&error=category_in_use');
            exit;
        }

        $this->categoryModel->delete($id, $userId);
        header('Location: /index.php?action=categorias&success=deleted');
        exit;
    }

    /* ================================================================
       MÓDULO: RELATÓRIOS
       ================================================================ */
    public function relatorios(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];

        $startDate  = $_GET['start_date']  ?? date('Y-m-01');
        $endDate    = $_GET['end_date']    ?? date('Y-m-t');
        $filterType = $_GET['type']        ?? '';
        $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== ''
            ? (int)$_GET['category_id']
            : null;

        if ($filterType && !in_array($filterType, ['despesa', 'receita'], true)) {
            $filterType = '';
        }

        $reportService = new ReportService($this->expenseModel, $this->incomeModel);
        $report = $reportService->getReportData(
            $userId,
            $startDate,
            $endDate,
            $categoryId,
            $filterType ?: null
        );

        if (!empty($report['chart_categories'])) {
            $report['chart_data']['expenses_by_category'] = $report['chart_categories'];
        }

        if ($filterType === 'despesa') {
            $transactions = $report['expenses'];
        } elseif ($filterType === 'receita') {
            $transactions = $report['incomes'];
        } else {
            $transactions = $report['transactions'];
        }

        $expenseCategories = $this->categoryModel->findAll($userId, 'despesa');

        $pageTitle = 'Relatórios - Controle de Gastos';
        $userName  = $_SESSION['user_name'] ?? 'Usuário';

        require basePath('relatorios.php');
    }
}

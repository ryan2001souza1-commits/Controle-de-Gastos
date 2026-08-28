<?php

class GoalController
{
    private Goal $goalModel;
    private GoalService $goalService;

    public function __construct(Goal $goalModel, GoalService $goalService)
    {
        $this->goalModel = $goalModel;
        $this->goalService = $goalService;
    }

    public function index(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];

        $data = $this->goalService->getGoalsData($userId);

        $pageTitle = 'Metas Financeiras - Controle de Gastos';
        $userName  = $_SESSION['user_name'] ?? 'Usuário';
        $error     = $_GET['error']   ?? null;
        $success   = $_GET['success'] ?? null;

        require basePath('metas.php');
    }

    public function store(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=metas');
            exit;
        }

        $userId = $_SESSION['user_id'];
        $name = trim($_POST['name'] ?? '');
        $target = (float)($_POST['target_amount'] ?? 0);
        $saved  = (float)($_POST['saved_amount']  ?? 0);
        $deadline = $_POST['deadline'] ?? '';
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $target <= 0 || $saved < 0) {
            header('Location: /index.php?action=metas&error=invalid_data');
            exit;
        }

        $deadlineNorm = null;
        if ($deadline !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $deadline);
            if (!$d || $d->format('Y-m-d') !== $deadline) {
                header('Location: /index.php?action=metas&error=invalid_date');
                exit;
            }
            $deadlineNorm = $deadline;
        }

        if ($this->goalModel->countByName($name, $userId) > 0) {
            header('Location: /index.php?action=metas&error=duplicate_name');
            exit;
        }

        $this->goalModel->create($name, $target, $saved, $deadlineNorm, $description, $userId);
        header('Location: /index.php?action=metas&success=created');
        exit;
    }

    public function update(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=metas');
            exit;
        }

        $userId = $_SESSION['user_id'];
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $target = (float)($_POST['target_amount'] ?? 0);
        $saved  = (float)($_POST['saved_amount']  ?? 0);
        $deadline = $_POST['deadline'] ?? '';
        $description = trim($_POST['description'] ?? '');

        if ($id <= 0 || $name === '' || $target <= 0 || $saved < 0) {
            header('Location: /index.php?action=metas&error=invalid_data');
            exit;
        }

        $existing = $this->goalModel->findById($id, $userId);
        if (!$existing) {
            header('Location: /index.php?action=metas&error=not_found');
            exit;
        }

        if ($this->goalModel->countByName($name, $userId, $id) > 0) {
            header('Location: /index.php?action=metas&error=duplicate_name');
            exit;
        }

        $deadlineNorm = null;
        if ($deadline !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $deadline);
            if (!$d || $d->format('Y-m-d') !== $deadline) {
                header('Location: /index.php?action=metas&error=invalid_date');
                exit;
            }
            $deadlineNorm = $deadline;
        }

        $this->goalModel->update($id, $name, $target, $saved, $deadlineNorm, $description, $userId);
        header('Location: /index.php?action=metas&success=updated');
        exit;
    }

    public function delete(): void
    {
        requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=metas');
            exit;
        }

        $userId = $_SESSION['user_id'];
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: /index.php?action=metas&error=invalid_id');
            exit;
        }

        if (!$this->goalModel->findById($id, $userId)) {
            header('Location: /index.php?action=metas&error=not_found');
            exit;
        }

        $this->goalModel->delete($id, $userId);
        header('Location: /index.php?action=metas&success=deleted');
        exit;
    }
}

<?php
class FeedbackController
{
    private Feedback $feedbackModel;
    private PDO $db;

    public function __construct(Feedback $feedbackModel, PDO $db)
    {
        $this->feedbackModel = $feedbackModel;
        $this->db = $db;
    }

    private function requireAdmin(): void
    {
        requireLogin();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT is_admin FROM usuarios WHERE id = ?');
        $stmt->execute([$uid]);
        if ((int)($stmt->fetchColumn() ?? 0) !== 1) {
            http_response_code(403);
            header('Location: /index.php?error=unauthorized');
            exit;
        }
    }

    public function form(): void
    {
        requireLogin();
        $pageTitle = 'Enviar feedback';
        $pageSubtitle = 'Sugestões, melhorias e críticas';
        $activeMenu = 'feedback';
        $showPeriodPicker = false;
        $success = ($_GET['success'] ?? '') === 'created';
        require basePath('feedback.php');
    }

    public function create(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=feedback');
            exit;
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $tipo = trim($_POST['tipo'] ?? 'sugestao');
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');

        $allowedTipos = ['sugestao', 'melhoria', 'critica', 'elogio', 'outro'];
        if (!in_array($tipo, $allowedTipos, true)) $tipo = 'sugestao';
        if (mb_strlen($titulo) < 5 || mb_strlen($titulo) > 150) {
            header('Location: /index.php?action=feedback&error=invalid_title');
            exit;
        }
        if (mb_strlen($descricao) < 10) {
            header('Location: /index.php?action=feedback&error=invalid_desc');
            exit;
        }

        $this->feedbackModel->create([
            'usuario_id' => $userId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'descricao' => $descricao,
        ]);
        header('Location: /index.php?action=feedback&success=created');
        exit;
    }

    public function myFeedback(): void
    {
        requireLogin();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $feedbacks = $this->feedbackModel->findByUser($userId, 50);
        $pageTitle = 'Meu feedback';
        $pageSubtitle = 'Acompanhe suas sugestões';
        $activeMenu = 'meu_feedback';
        $showPeriodPicker = false;
        require basePath('meu_feedback.php');
    }

    public function adminList(): void
    {
        $this->requireAdmin();
        $status = $_GET['status'] ?? '';
        $q = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $feedbacks = $this->feedbackModel->findAll($status ?: null, $q ?: null, $perPage, $offset);
        $total = $this->feedbackModel->countAll($status ?: null, $q ?: null);
        $stats = $this->feedbackModel->stats();
        $pageTitle = 'Admin — Feedback';
        $activeMenu = 'admin_feedback';
        $showPeriodPicker = false;
        require basePath('admin/feedback.php');
    }

    public function adminUpdate(): void
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /index.php?action=admin_feedback');
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $resposta = trim($_POST['resposta_admin'] ?? '');
        $allowed = ['novo', 'em_analise', 'implementado', 'recusado'];
        if (!in_array($status, $allowed, true)) {
            header('Location: /index.php?action=admin_feedback&error=invalid_status');
            exit;
        }
        $this->feedbackModel->updateStatus($id, $status, $resposta !== '' ? $resposta : null);
        header('Location: /index.php?action=admin_feedback&success=updated');
        exit;
    }
}

<?php
class AdminController
{
    private User $userModel;
    private BugReport $bugModel;
    private Plan $planModel;
    private Feedback $feedbackModel;
    private PDO $db;

    public function __construct(User $userModel, BugReport $bugModel, Plan $planModel, Feedback $feedbackModel, PDO $db)
    {
        $this->userModel = $userModel;
        $this->bugModel = $bugModel;
        $this->planModel = $planModel;
        $this->feedbackModel = $feedbackModel;
        $this->db = $db;
    }

    private function requireAdmin(): void
    {
        requireLogin();
        $uid = $_SESSION['user_id'] ?? 0;
        // verifica via DB (não confia só na sessão)
        if (!$this->userModel->isAdmin((int)$uid)) {
            http_response_code(403);
            header('Location: /index.php?error=unauthorized');
            exit;
        }
    }

    public function dashboard(): void
    {
        $this->requireAdmin();
        $totalUsers = (int)$this->db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        $recentUsers = $this->db->query("SELECT id,nome,email,created_at,plano,is_admin FROM usuarios ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $activeUsers = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE created_at > NOW() - INTERVAL '30 days'")->fetchColumn();
        $newWeek = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE created_at > NOW() - INTERVAL '7 days'")->fetchColumn();
        $adminCount = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE is_admin = 1")->fetchColumn();
        $freeUsers = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE is_admin = 0 AND plano = 'gratuito'")->fetchColumn();
        $paidUsers = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE is_admin = 0 AND plano_status = 'ativo' AND plano <> 'gratuito'")->fetchColumn();
        $activeSubscriptions = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE plano_status = 'ativo' AND plano <> 'gratuito'")->fetchColumn();
        $bugStats = $this->bugModel->stats();
        $feedbackStats = $this->feedbackModel->stats();
        $planos = $this->planModel->findAll();

        $userGrowth = $this->db->query(
            "SELECT
                DATE_TRUNC('week', created_at) AS week,
                COUNT(*) AS total
             FROM usuarios
             WHERE created_at > NOW() - INTERVAL '12 weeks'
             GROUP BY 1 ORDER BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $planDistribution = $this->db->query(
            "SELECT plano, COUNT(*) AS total FROM usuarios WHERE is_admin = 0 GROUP BY plano"
        )->fetchAll(PDO::FETCH_ASSOC);

        $pageTitle = 'Admin — Dashboard';
        $activeMenu = 'admin';
        $showPeriodPicker = false;
        require basePath('admin/dashboard.php');
    }

    public function usuarios(): void
    {
        $this->requireAdmin();
        $search = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page-1)*$perPage;
        $where = ''; $params = [];
        if ($search !== '') {
            $where = "WHERE nome ILIKE ? OR email ILIKE ?";
            $like = '%'.$search.'%';
            $params = [$like,$like];
        }
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM usuarios $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $sql = "SELECT id,nome,email,created_at,plano,plano_status,is_admin FROM usuarios $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = 'Admin — Clientes';
        $activeMenu = 'admin_usuarios';
        $showPeriodPicker = false;
        require basePath('admin/usuarios.php');
    }

    public function bugs(): void
    {
        $this->requireAdmin();
        $status = $_GET['status'] ?? '';
        $q = trim($_GET['q'] ?? '');
        $page = max(1,(int)($_GET['page']??1));
        $perPage=20; $offset=($page-1)*$perPage;
        $bugs = $this->bugModel->findAll($status ?: null, $q ?: null, $perPage, $offset);
        $total = $this->bugModel->countAll($status ?: null, $q ?: null);
        $pageTitle = 'Admin — Bugs';
        $activeMenu = 'admin_bugs';
        $showPeriodPicker = false;
        require basePath('admin/bugs.php');
    }

    public function bugDetail(): void
    {
        $this->requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        $bug = $this->bugModel->findById($id);
        if (!$bug) { header('Location: /index.php?action=admin_bugs&error=not_found'); exit; }
        $pageTitle = 'Admin — Bug #'.$id;
        $activeMenu = 'admin_bugs';
        $showPeriodPicker = false;
        require basePath('admin/bug_detail.php');
    }

    public function updateBug(): void
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?action=admin_bugs'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $resposta = trim($_POST['resposta_admin'] ?? '');
        $obs = trim($_POST['observacao_interna'] ?? '');
        $bug = $this->bugModel->findById($id);
        if (!$bug) { header('Location: /index.php?action=admin_bugs&error=not_found'); exit; }
        $this->bugModel->updateStatus($id, $status, $resposta !== '' ? $resposta : null, $obs !== '' ? $obs : null);
        header('Location: /index.php?action=admin_bug_detail&id='.$id.'&success=updated'); exit;
    }

    public function planos(): void
    {
        $this->requireAdmin();
        $planos = $this->planModel->findAll();
        $stats = [];
        foreach ($planos as $p) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM usuarios WHERE plano = ?");
            $stmt->execute([$p['slug']]);
            $stats[$p['slug']] = (int)$stmt->fetchColumn();
        }
        $pageTitle = 'Admin — Planos';
        $activeMenu = 'admin_planos';
        $showPeriodPicker = false;
        require basePath('admin/planos.php');
    }

    public function feedback(): void
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
}

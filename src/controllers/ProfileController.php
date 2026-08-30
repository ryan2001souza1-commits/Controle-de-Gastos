<?php
class ProfileController
{
    private User $userModel;
    private PDO $db;

    public function __construct(User $userModel, PDO $db)
    {
        $this->userModel = $userModel;
        $this->db = $db;
    }

    public function index(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }
        $pageTitle = 'Configurações';
        $pageSubtitle = 'Gerencie seus dados e preferências';
        $activeMenu = 'configuracoes';
        $showPeriodPicker = false;
        $userName = $_SESSION['user_name'] ?? $user->name;
        $userInitials = strtoupper(substr($userName, 0, 1));
        $error = $_GET['error'] ?? null;
        $success = $_GET['success'] ?? null;
        require basePath('configuracoes.php');
    }

    public function updateProfile(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?action=configuracoes'); exit; }
        $userId = $_SESSION['user_id'];
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }

        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $data_nascimento = trim($_POST['data_nascimento'] ?? '');
        $renda = trim($_POST['renda_mensal'] ?? '');
        $dia = trim($_POST['dia_recebimento'] ?? '');
        $objetivo = trim($_POST['objetivo'] ?? '');
        $moeda = trim($_POST['moeda'] ?? 'BRL');
        $notificacoes = isset($_POST['notificacoes']) ? 1 : 0;

        if ($nome === '' || $email === '') {
            header('Location: /index.php?action=configuracoes&error=invalid_data'); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: /index.php?action=configuracoes&error=invalid_email'); exit;
        }
        if ($this->userModel->isEmailTaken($email, $userId)) {
            header('Location: /index.php?action=configuracoes&error=email_taken'); exit;
        }
        if ($telefone !== '' && !preg_match('/^[0-9 \(\)\-\+]{8,20}$/', $telefone)) {
            header('Location: /index.php?action=configuracoes&error=invalid_phone'); exit;
        }
        $dataNascNorm = null;
        if ($data_nascimento !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $data_nascimento);
            if (!$d || $d->format('Y-m-d') !== $data_nascimento) {
                header('Location: /index.php?action=configuracoes&error=invalid_date'); exit;
            }
            if ($d > new DateTime()) {
                header('Location: /index.php?action=configuracoes&error=invalid_date'); exit;
            }
            $dataNascNorm = $data_nascimento;
        }
        $rendaNorm = null;
        if ($renda !== '') {
            $rendaNorm = (float)str_replace(',', '.', $renda);
            if (!is_numeric($rendaNorm) || $rendaNorm < 0 || $rendaNorm > 9999999) {
                header('Location: /index.php?action=configuracoes&error=invalid_income'); exit;
            }
        }
        $diaNorm = null;
        if ($dia !== '') {
            $diaNorm = (int)$dia;
            if ($diaNorm < 1 || $diaNorm > 31) {
                header('Location: /index.php?action=configuracoes&error=invalid_payday'); exit;
            }
        }
        if (!in_array($moeda, ['BRL','USD','EUR'], true)) $moeda = 'BRL';
        if (!in_array($objetivo, ['', 'economizar','organizar','investir','quitar_dividas'], true)) $objetivo = $objetivo ?: null;

        $fields = [
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone !== '' ? $telefone : null,
            'data_nascimento' => $dataNascNorm,
            'renda_mensal' => $rendaNorm,
            'dia_recebimento' => $diaNorm,
            'objetivo' => $objetivo ?: null,
            'moeda' => $moeda,
            'notificacoes' => $notificacoes,
        ];
        $this->userModel->updateProfile($userId, $fields);
        $_SESSION['user_name'] = $nome;
        $_SESSION['user_email'] = $email;
        header('Location: /index.php?action=configuracoes&success=updated'); exit;
    }

    public function updatePassword(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?action=configuracoes'); exit; }
        $userId = $_SESSION['user_id'];
        $user = $this->userModel->findById($userId);
        if (!$user) { header('Location: /?action=login'); exit; }
        // OAuth users without password cannot change this way
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($new === '' || strlen($new) < 8) {
            header('Location: /index.php?action=configuracoes&error=weak_password'); exit;
        }
        if ($new !== $confirm) {
            header('Location: /index.php?action=configuracoes&error=password_mismatch'); exit;
        }
        if ($user->password_hash && !$user->verifyPassword($current)) {
            header('Location: /index.php?action=configuracoes&error=wrong_password'); exit;
        }
        // If user has no password (OAuth), allow set without current
        if ($user->password_hash && $current === '') {
            header('Location: /index.php?action=configuracoes&error=wrong_password'); exit;
        }
        $this->userModel->updatePassword($userId, $new);
        header('Location: /index.php?action=configuracoes&success=password_updated'); exit;
    }
}

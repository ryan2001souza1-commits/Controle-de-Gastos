<?php
class BugReportController
{
    private BugReport $bugModel;
    private PDO $db;
    public function __construct(BugReport $bugModel, PDO $db) { $this->bugModel = $bugModel; $this->db = $db; }

    public function form(): void
    {
        requireLogin();
        $pageTitle = 'Reportar problema';
        $pageSubtitle = 'Descreva o problema para ajudarmos';
        $activeMenu = 'reportar';
        $showPeriodPicker = false;
        require basePath('bug_report.php');
    }

    public function create(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?action=reportar'); exit; }
        $userId = $_SESSION['user_id'];
        $titulo = trim($_POST['titulo'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $pagina = trim($_POST['pagina'] ?? '');
        $prioridade = trim($_POST['prioridade'] ?? 'media');
        $url = trim($_POST['url'] ?? $_SERVER['HTTP_REFERER'] ?? '');
        $allowedCats = ['bug','visual','login','lancamento','orcamento','metas','relatorio','outro'];
        $allowedPrio = ['baixa','media','alta'];
        if ($titulo === '' || mb_strlen($titulo) < 5 || $descricao === '' || mb_strlen($descricao) < 10) {
            header('Location: /index.php?action=reportar&error=invalid_data'); exit;
        }
        if (!in_array($categoria, $allowedCats, true)) $categoria = 'outro';
        if (!in_array($prioridade, $allowedPrio, true)) $prioridade = 'media';
        if (mb_strlen($titulo) > 150) $titulo = mb_substr($titulo,0,150);
        // captura automática
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $navegador = mb_substr($ua,0,200);
        $so = $this->detectOS($ua);
        // screenshot
        $screenshotPath = null;
        if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
            $screenshotPath = $this->handleUpload($_FILES['screenshot']);
            if ($screenshotPath === false) {
                header('Location: /index.php?action=reportar&error=invalid_file'); exit;
            }
        }
        $this->bugModel->create([
            'usuario_id' => $userId,
            'titulo' => $titulo,
            'categoria' => $categoria,
            'descricao' => $descricao,
            'pagina' => $pagina ?: null,
            'url' => $url ?: null,
            'prioridade' => $prioridade,
            'status' => 'novo',
            'navegador' => $navegador,
            'sistema_operacional' => $so,
            'screenshot' => $screenshotPath,
        ]);
        header('Location: /index.php?action=meus_relatos&success=created'); exit;
    }

    public function myReports(): void
    {
        requireLogin();
        $userId = $_SESSION['user_id'];
        $bugs = $this->bugModel->findByUser($userId, 50);
        $pageTitle = 'Meus relatos';
        $pageSubtitle = 'Acompanhe seus reports e respostas';
        $activeMenu = 'meus_relatos';
        $showPeriodPicker = false;
        require basePath('meus_relatos.php');
    }

    private function detectOS(string $ua): string
    {
        $ua = strtolower($ua);
        if (str_contains($ua,'windows')) return 'Windows';
        if (str_contains($ua,'mac os')) return 'macOS';
        if (str_contains($ua,'android')) return 'Android';
        if (str_contains($ua,'iphone')||str_contains($ua,'ipad')) return 'iOS';
        if (str_contains($ua,'linux')) return 'Linux';
        return 'Desconhecido';
    }

    private function handleUpload(array $file): string|false|null
    {
        if ($file['size'] > 2*1024*1024) return false;
        $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg','image/webp'=>'webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed[$mime])) return false;
        $ext = $allowed[$mime];
        $dir = dirname(__DIR__,2).'/public/uploads/bugs';
        if (!is_dir($dir)) mkdir($dir,0755,true);
        $name = bin2hex(random_bytes(8)).'.'.$ext;
        $dest = $dir.'/'.$name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) return false;
        return '/uploads/bugs/'.$name;
    }
}

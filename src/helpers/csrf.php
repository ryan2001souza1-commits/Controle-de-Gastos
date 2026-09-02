<?php
/**
 * Helper compartilhado de CSRF.
 *
 * Centraliza:
 *  - carregamento da classe CsrfService
 *  - inicialização do token CSRF em sessão (após session_start)
 *  - função csrf_field() que renderiza o input hidden
 *
 * Pode ser carregado de:
 *  - public/index.php (front controller)
 *  - views standalone de auth (login.php, register.php, forgot.php, reset.php)
 *
 * Assume que session_start() já foi chamado pelo bootstrap (config.php / index.php)
 * ou que o caller garante sessão ativa antes de incluir este helper.
 */

if (!class_exists('CsrfService', false)) {
    require_once __DIR__ . '/../services/CsrfService.php';
}

if (!isset($csrfService) || !($csrfService instanceof CsrfService)) {
    $csrfService = new CsrfService();
}

// Inicialização do token: gera se não existe ou se o usuário mudou (login/logout).
// Persistência forçada: em ambientes serverless (Vercel), a sessão é gravada via
// DbSessionHandler::write() apenas no shutdown. Se o request terminar antes disso
// (timeout, cold start, output flush), o token gerado aqui seria perdido e a próxima
// requisição (POST /index.php?action=login) falharia a validação com "Sessão expirada".
$wasActive = session_status() === PHP_SESSION_ACTIVE;
if ($wasActive) {
    $csrfUserId    = $_SESSION['user_id'] ?? null;
    $storedCsrfUid = $_SESSION['csrf_user_id'] ?? null;
    $tokenExists   = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']);
    $userIdChanged = $csrfUserId !== $storedCsrfUid;
    if (getenv('CSRF_DIAG') === '1') {
        error_log('[CSRF_DIAG-helper] session_active=1'
            . ' csrf_uid=' . (isset($_SESSION['csrf_user_id']) ? (string)$_SESSION['csrf_user_id'] : 'unset')
            . ' csrf_token_present=' . ($tokenExists ? '1' : '0')
            . ' userIdChanged=' . ($userIdChanged ? '1' : '0')
            . ' will_regenerate=' . ((!$tokenExists || $userIdChanged) ? '1' : '0')
        );
    }
    if (!$tokenExists || $userIdChanged) {
        $csrfService->generateToken($csrfUserId);
        session_write_close();
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Renderiza <input type="hidden" name="csrf_token" value="..."> para formulários.
     * Retorna string vazia se a sessão não estiver disponível.
     */
    function csrf_field(): string
    {
        global $csrfService;
        if (!isset($csrfService) || !($csrfService instanceof CsrfService)) {
            $csrfService = new CsrfService();
        }
        $sessActive = session_status() === PHP_SESSION_ACTIVE;
        if (!$sessActive) {
            if (getenv('CSRF_DIAG') === '1') {
                error_log('[CSRF_DIAG-csrf_field] session_not_active - returning empty');
            }
            return '';
        }
        $userId = $_SESSION['user_id'] ?? null;
        $token  = $csrfService->getToken($userId);
        if ($token === null && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
            $token = $_SESSION['csrf_token'];
        }
        if (!$token) {
            if (getenv('CSRF_DIAG') === '1') {
                error_log('[CSRF_DIAG-csrf_field] no_token - returning empty. csrf_token in session: ' . (isset($_SESSION['csrf_token']) ? '1' : '0'));
            }
            return '';
        }
        return "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($token, ENT_QUOTES) . "'>";
    }
}

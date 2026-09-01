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
if (session_status() === PHP_SESSION_ACTIVE) {
    $csrfUserId      = $_SESSION['user_id'] ?? null;
    $storedCsrfUid   = $_SESSION['csrf_user_id'] ?? null;
    $tokenExists     = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']);
    $userIdChanged   = $csrfUserId !== $storedCsrfUid;
    if (!$tokenExists || $userIdChanged) {
        $csrfService->generateToken($csrfUserId);
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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $userId = $_SESSION['user_id'] ?? null;
        $token  = $csrfService->getToken($userId);
        if ($token === null && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
            $token = $_SESSION['csrf_token'];
        }
        if (!$token) {
            return '';
        }
        return "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($token, ENT_QUOTES) . "'>";
    }
}

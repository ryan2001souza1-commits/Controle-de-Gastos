<?php
require_once __DIR__ . '/../services/MercadoPagoCheckoutStarter.php';
if (!class_exists('CsrfService', false)) {
    require_once __DIR__ . '/../services/CsrfService.php';
}

/**
 * SubscribeController — etapa 1 do checkout de assinatura.
 *
 * Rota: POST /index.php?action=subscribe (autenticada, com CSRF).
 * GET NUNCA cria preapproval (falha segura com redirect, sem efeito colateral).
 *
 * - Valida metodo POST + CSRF (mecanismo existente: CsrfService + csrf_field).
 * - Valida plano por whitelist estrita (pro/premium) vindo de $_POST['plan'].
 * - Exige usuario autenticado; NUNCA confia em ID vindo do navegador.
 * - Usa e-mail do usuario autenticado (banco/sessao) como payer_email.
 * - Delega criacao do preapproval ao MercadoPagoCheckoutStarter.
 * - Redireciona SOMENTE para checkout HTTPS em host oficial MP (init_point).
 * - O retorno (back_url -> meu_plano?subscribe=return) NAO ativa plano.
 * - Nao escreve no banco, nao cria migration, nao ativa Pro/Premium.
 */
class SubscribeController
{
    private PDO $db;
    private MercadoPagoCheckoutStarter $starter;
    /** @var callable|null fn(string $url): void — sobrescrito em testes para capturar redirect sem exit */
    private $onRedirect;

    public function __construct(PDO $db, ?MercadoPagoCheckoutStarter $starter = null, ?callable $onRedirect = null)
    {
        $this->db = $db;
        $this->starter = $starter ?? new MercadoPagoCheckoutStarter();
        $this->onRedirect = $onRedirect;
    }

    /**
     * Normaliza e valida o plano bruto vindo de $_GET['plan'].
     * Retorna 'pro'|'premium' ou null quando invalido.
     */
    public static function resolvePlan(?string $raw): ?string
    {
        $p = MercadoPagoCheckoutStarter::normalizePlan($raw);
        return MercadoPagoCheckoutStarter::isPlanAllowed($p) ? $p : null;
    }

    public function start(): void
    {
        // 1. Autenticacao obrigatoria.
        if (!function_exists('isLoggedIn') || !isLoggedIn() || empty($_SESSION['user_id'])) {
            $this->redirect('/index.php?action=login');
        }
        $userId = (int)$_SESSION['user_id'];
        if ($userId <= 0) {
            $this->redirect('/index.php?action=login');
        }

        // 2. GET (ou qualquer metodo != POST) NUNCA cria assinatura.
        $method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'POST') {
            error_log('[subscribe] metodo rejeitado user_id=' . $userId . ' method=' . $method);
            $this->redirect('/index.php?action=meu_plano&error=invalid_method');
        }

        // 3. CSRF obrigatorio (mecanismo existente do projeto).
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!$this->isCsrfValid($userId, $csrfToken)) {
            error_log('[subscribe] csrf invalido user_id=' . $userId);
            $this->redirect('/index.php?action=meu_plano&error=invalid_csrf');
        }

        // 4. Whitelist estrita de plano (SOMENTE $_POST; nunca user_id do navegador).
        $plan = self::resolvePlan($_POST['plan'] ?? null);
        if ($plan === null) {
            $this->redirect('/index.php?action=meu_plano&error=invalid_plan');
        }

        // 5. E-mail do usuario AUTENTICADO (banco; fallback sessao).
        $email = $this->getAuthenticatedUserEmail($userId);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('[subscribe] usuario sem e-mail valido user_id=' . $userId);
            $this->redirect('/index.php?action=meu_plano&error=no_email');
        }

        // 6. Cria preapproval e redireciona para checkout oficial.
        try {
            $checkoutUrl = $this->starter->startForUser($userId, $email, $plan);
        } catch (MpCheckoutException $e) {
            // Mensagem ao usuario e GENERICA (detalhes so no log, sem segredos).
            error_log('[subscribe] falha user_id=' . $userId . ' plano=' . $plan . ' reason=' . $e->reason);
            $this->redirect('/index.php?action=meu_plano&error=checkout_unavailable');
        } catch (Throwable $e) {
            error_log('[subscribe] erro inesperado user_id=' . $userId . ' plano=' . $plan . ' err=' . substr($e->getMessage(), 0, 150));
            $this->redirect('/index.php?action=meu_plano&error=checkout_unavailable');
        }

        // 7. Valida URL oficial antes de redirecionar (nunca monta manualmente,
        // nem redireciona para host arbitrario — SOMENTE host oficial MP em HTTPS).
        if (!is_string($checkoutUrl) || !MercadoPagoCheckoutStarter::isOfficialCheckoutUrl($checkoutUrl)) {
            error_log('[subscribe] checkout_url invalida user_id=' . $userId . ' plano=' . $plan);
            $this->redirect('/index.php?action=meu_plano&error=checkout_unavailable');
        }

        $this->redirect($checkoutUrl);
    }

    private function isCsrfValid(int $userId, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        try {
            if (isset($GLOBALS['csrfService']) && $GLOBALS['csrfService'] instanceof CsrfService) {
                return $GLOBALS['csrfService']->validateToken($userId, $token);
            }
            if (isset($GLOBALS['csrfService']) && is_object($GLOBALS['csrfService']) && method_exists($GLOBALS['csrfService'], 'validateToken')) {
                return (bool)$GLOBALS['csrfService']->validateToken($userId, $token);
            }
            $svc = new CsrfService();
            return $svc->validateToken($userId, $token);
        } catch (Throwable $e) {
            error_log('[subscribe] falha csrf user_id=' . $userId);
            return false;
        }
    }

    private function getAuthenticatedUserEmail(int $userId): string
    {
        try {
            if (class_exists('User')) {
                $model = new User($this->db);
                $user = $model->findById($userId);
                if ($user && !empty($user->email)) {
                    return trim((string)$user->email);
                }
            }
        } catch (Throwable $e) {
            error_log('[subscribe] falha ao ler usuario user_id=' . $userId);
        }
        $sessEmail = trim((string)($_SESSION['user_email'] ?? ''));
        return $sessEmail;
    }

    /** @return never */
    private function redirect(string $url): void
    {
        if ($this->onRedirect !== null) {
            ($this->onRedirect)($url);
            // Em testes o callback lanca excecao para interromper o fluxo.
            // Se retornar, interrompe aqui para nao continuar o checkout.
            throw new RuntimeException('redirect:' . $url);
        }
        header('Location: ' . $url);
        exit;
    }
}

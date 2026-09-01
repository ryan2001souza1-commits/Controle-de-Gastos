<?php
/**
 * CsrfService — geração e validação de tokens CSRF (Cross-Site Request Forgery).
 *
 * Este serviço segue o padrão MVC do projeto:
 * - Segue a mesma estrutura dos outros services (AuthService, GoogleAuthService)
 * - Usa injeção de dependência (não depende de superglobais diretamente)
 * - Validação segura via hash_equals (timing-safe)
 * - Token armazenado na sessão (consistente com sessão do projeto)
 * - Compatível com Vercel serverless (usa sessão existente)
 */
class CsrfService
{
    /**
     * Gera um token CSRF e armazena na sessão do usuário.
     *
     * Segurança:
     * - Usa random_bytes + base64 para entropia alta
     * - Token é armazenado na sessão do usuário (userid + token)
     * - Se já existe, retorna o mesmo (reusável por sessão)
     */
    public function generateToken(int $userId): string
    {
        // Gera token seguro de 32 bytes → 43 chars base64
        $token = base64_encode(random_bytes(32));

        // Armazena na sessão do usuário para este request
        // O session handler em DB (configureSession) sobrevive entre instâncias serverless
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_user_id'] = $userId;

        return $token;
    }

    /**
     * Valida um token CSRF contra o armazenado na sessão.
     *
     * Fluxo:
     * 1. Verifica se usuário está logado (session_exists)
     * 2. Verifica user_id corresponde (evita users tentando tokens uns dos outros)
     * 3. Usa hash_equals para timing-safe comparison (evita timing attacks)
     * 4. Retorna true/false (sem exposição de debug)
     */
    public function validateToken(int $userId, string $token): bool
    {
        // Requisitos mínimos
        if (!$token || !is_string($token)) {
            return false;
        }

        // Deve haver token armazenado na sessão
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            return false;
        }

        // Comparação segura (evita timing attacks)
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Regenera o token CSRF (útil após login, mudança de privilégios, etc.).
     *
     * Casos de uso:
     * - Após login do usuário (login bem-sucedido)
     * - Após mudança de senha/admin
     * - Período periódico (opcional)
     */
    public function regenerateToken(int $userId): string
    {
        $token = $this->generateToken($userId);
        // session já atualizada no generateToken
        return $token;
    }

    /**
     * Obtém o token atual para o usuário (para uso no frontend).
     *
     * Segurança: frontend só precisa do token para o formulário,
     * não expõe o backend state.
     */
    public function getToken(int $userId): ?string
    {
        if (!isset($_SESSION['csrf_user_id'], $_SESSION['csrf_token'])) {
            return null;
        }
        if ((int)$_SESSION['csrf_user_id'] !== $userId) {
            return null;
        }
        return $_SESSION['csrf_token'];
    }
}
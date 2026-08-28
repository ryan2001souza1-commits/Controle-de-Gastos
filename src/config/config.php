<?php

// =========================================================
// Configurações de conexão com o PostgreSQL 18
// Projeto: controle-gastos
// =========================================================

// --- Credenciais do banco (PREENCHA A SENHA) ---
// A senha NÃO foi definida aqui por segurança.
// Defina a variável de ambiente DB_PASS ou altere o valor abaixo.
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'controle_gastos');
define('DB_USER', 'postgres');

// Senha do PostgreSQL:
// 1) Recomendado: defina a variável de ambiente DB_PASS no seu sistema.
// 2) Alternativa: substitua o valor abaixo diretamente (menos seguro).
define('DB_PASS', '123');

// Função utilitária para obter uma conexão PDO com o PostgreSQL.
// Retorna sempre a mesma instância durante a requisição (singleton).
function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[DB] Falha de conexão: ' . $e->getMessage());
            throw new PDOException(
                'Não foi possível conectar ao banco de dados. Tente novamente em instantes.',
                (int) $e->getCode()
            );
        }
    }

    return $pdo;
}

function basePath(string $path = ''): string
{
    return __DIR__ . '/../../public/' . ltrim($path, '/');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

<?php

// =========================================================
// Configurações de conexão com o PostgreSQL
// Projeto: controle-gastos
// =========================================================
// Em deploy (Vercel), defina DATABASE_URL ou DB_HOST/DB_PASS/etc.
// em "Settings → Environment Variables" no painel da Vercel.
// =========================================================

/**
 * Lê DATABASE_URL do ambiente com fallback para variáveis individuais.
 *
 * Exemplos de DATABASE_URL:
 *   postgres://user:pass@host:5432/db
 *   postgres://user:pass@host:5432/db?sslmode=require
 *
 * Formato das variáveis individuais (para dev local):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
function getDbConfig(): array
{
    $url = getenv('DATABASE_URL');

    if ($url !== false && $url !== '') {
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new \RuntimeException('DATABASE_URL inválida');
        }

        $host     = $parsed['host']     ?? '';
        $port     = isset($parsed['port']) ? (int)$parsed['port'] : 5432;
        $user     = $parsed['user']     ?? '';
        $password = $parsed['pass']     ?? '';
        $dbname   = ltrim($parsed['path'] ?? '', '/');

        // Detectar sslmode pela query string
        $sslmode = 'require'; // default seguro para clouds gerenciados
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['sslmode'])) {
                $sslmode = $query['sslmode'];
            }
        }

        return [
            'host'     => $host,
            'port'     => $port,
            'dbname'   => $dbname,
            'user'     => $user,
            'password'  => $password,
            'sslmode'   => $sslmode,
        ];
    }

    // Fallback: variáveis individuais (dev local)
    return [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'port'     => (int)(getenv('DB_PORT') ?: '5432'),
        'dbname'   => getenv('DB_NAME') ?: 'controle_gastos',
        'user'     => getenv('DB_USER') ?: 'postgres',
        'password'  => getenv('DB_PASS') ?: '',
        'sslmode'   => 'disable', // local não precisa de SSL
    ];
}

/**
 * Obtém uma conexão PDO com o PostgreSQL.
 * Em ambiente serverless (Vercel), a conexão é criada a cada cold start
 * e reutilizada durante a mesma requisição via singleton.
 */
function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $cfg = getDbConfig();

        $endpointId = '';
        $isPooler = str_contains($cfg['host'], '-pooler.');
        $isNeon = $isPooler || (str_contains($cfg['host'], '.neon.tech') || str_contains($cfg['host'], '.neon.'));
        if ($isPooler) {
            $endpointId = explode('-pooler.', $cfg['host'], 2)[0];
        } elseif ($isNeon) {
            $endpointId = explode('.', $cfg['host'], 2)[0];
        }

        $dsnExtra = $isNeon ? sprintf('options=endpoint=%s', $endpointId) : '';
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['dbname'],
            $cfg['sslmode'],
            $dsnExtra
        );

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            require_once __DIR__ . '/../migrations.php';
            runMigrations($pdo);
        } catch (\PDOException $e) {
            error_log('[DB] ' . $e->getMessage());
            throw new PDOException(
                'Erro de conexão com o banco. Tente novamente em instantes.',
                (int) $e->getCode()
            );
        }
    }

    return $pdo;
}

/**
 * Retorna o caminho absoluto para a pasta public/ (raiz web).
 * Usado pelas views para require de outros arquivos PHP.
 */
function basePath(string $path = ''): string
{
    return dirname(__DIR__, 2) . '/public/' . ltrim($path, '/');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /?action=login');
        exit;
    }
}

/**
 * Configura sessão para durar 7 dias (604800s) e, em Vercel, persiste no Postgres.
 * Deve ser chamado ANTES de session_start() e antes de qualquer output.
 */
function configureSession(?PDO $db = null): void
{
    // 7 dias — evita logout após poucos minutos, mas mantém expiração de segurança
    $lifetime = 604800;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    // Tenta usar handler em DB quando possível (resolve filesystem efêmero do serverless)
    if ($db !== null && !headers_sent()) {
        try {
            // Garante tabela sessions existe (idempotente)
            $db->exec("CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires_at TIMESTAMP NOT NULL)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)");
            require_once __DIR__ . '/session_handler.php';
            $handler = new DbSessionHandler($db, $lifetime);
            session_set_save_handler($handler, true);
        } catch (Throwable $e) {
            error_log('[session handler] ' . $e->getMessage());
        }
    }
}

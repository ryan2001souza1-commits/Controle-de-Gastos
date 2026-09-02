<?php
// =============================================================================
// api/index.php — Vercel Serverless Function entry point
// =============================================================================
// Reaproveita 100% do roteador existente em public/index.php.
// O runtime vercel-php@0.9.0 chama este arquivo para cada requisição.
//
// Roteamento:
//   /css/style.css           → serve public/css/style.css
//   /js/app.js               → serve public/js/app.js
//   /assets/chart.min.js      → serve public/assets/chart.min.js
//   /index.php?action=login  → login (public/login.php)
//   /index.php?action=metas  → metas (public/metas.php)
//   /                         → dashboard (public/index.php por ação)
// =============================================================================

declare(strict_types=1);

$ROOT = dirname(__DIR__);

function mp_diagnose_env(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $token = (string)(getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '');
    $pubKey = (string)(getenv('MERCADOPAGO_PUBLIC_KEY') ?: '');
    $mode = strtolower((string)(getenv('MERCADOPAGO_MODE') ?: ''));
    $webhookSecret = (string)(getenv('MERCADOPAGO_WEBHOOK_SECRET') ?: '');
    $planPro = (string)(getenv('MERCADOPAGO_PLAN_ID_PRO') ?: '');
    $planPrem = (string)(getenv('MERCADOPAGO_PLAN_ID_PREMIUM') ?: '');

    $tokenType = 'missing';
    if ($token !== '') {
        $tokenType = str_starts_with($token, 'TEST-') ? 'test' : 'production';
    }

    $pubKeyType = 'missing';
    if ($pubKey !== '') {
        $pubKeyType = str_starts_with($pubKey, 'TEST-') ? 'test' : 'production';
    }

    $modeType = 'production';
    if ($mode === 'sandbox') {
        $modeType = 'sandbox';
    } elseif ($mode !== 'production' && $mode !== '') {
        $modeType = 'other';
    }

    $diag = [
        'mode' => $modeType,
        'public_key_type' => $pubKeyType,
        'access_token_type' => $tokenType,
        'plan_pro_configured' => ($planPro !== '' ? 'yes' : 'no'),
        'plan_premium_configured' => ($planPrem !== '' ? 'yes' : 'no'),
        'webhook_secret_configured' => ($webhookSecret !== '' ? 'yes' : 'no'),
    ];

    error_log('[MP Environment Diagnostic]' . http_build_query($diag, '', ' '));
    $cached = $diag;
    return $diag;
}
mp_diagnose_env();

// =============================================================================
// 0. Carregar .env automaticamente em dev local (em produção/Vercel o .env
//    não existe — variáveis vêm de env real configurado no painel)
// =============================================================================
$envFile = $ROOT . '/.env';
if (is_file($envFile) && getenv('VERCEL_ENV') === false) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Não sobrescreve variáveis de ambiente já definidas pelo sistema
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$pathInfo = parse_url($requestUri, PHP_URL_PATH);

// =============================================================================
// 1. Servir arquivos estáticos de public/
// =============================================================================
$staticExtensions = ['.css', '.js', '.woff2', '.woff', '.ttf', '.png', '.jpg',
    '.jpeg', '.gif', '.svg', '.ico', '.webp', '.map', '.txt', '.html'];

$isStatic = false;
foreach ($staticExtensions as $ext) {
    if (str_ends_with($pathInfo, $ext)) {
        $isStatic = true;
        break;
    }
}

if ($isStatic) {
    $filePath = $ROOT . '/public' . $pathInfo;
    if (is_file($filePath) && is_readable($filePath)) {
        $mimeTypes = [
            '.css'  => 'text/css; charset=utf-8',
            '.js'   => 'application/javascript; charset=utf-8',
            '.json' => 'application/json',
            '.png'  => 'image/png',
            '.jpg'  => 'image/jpeg',
            '.jpeg' => 'image/jpeg',
            '.gif'  => 'image/gif',
            '.svg'  => 'image/svg+xml',
            '.ico'  => 'image/x-icon',
            '.webp' => 'image/webp',
            '.woff' => 'font/woff',
            '.woff2'=> 'font/woff2',
            '.ttf'  => 'font/ttf',
            '.map'  => 'application/json',
            '.txt'  => 'text/plain',
            '.html' => 'text/html; charset=utf-8',
        ];
        $ext = strrchr($pathInfo, '.');
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        // Apenas fontes e imagens são imutáveis (hash no nome). CSS/JS devem revalidar
        // para que alterações de design apareçam imediatamente (evita cache de 1 ano).
        $isImmutable = in_array($ext, ['.woff2', '.woff', '.ttf', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp'], true);
        if ($isImmutable) {
            $maxAge = 'public, max-age=31536000, immutable';
        } else {
            // CSS/JS/HTML: revalida sempre, mas permite cache com ETag/Last-Modified
            $maxAge = 'public, max-age=0, must-revalidate';
            // ETag e Last-Modified para validação condicional (304 Not Modified quando não mudou)
            $etag = sprintf('"%s-%s"', dechex(filesize($filePath)), dechex(filemtime($filePath)));
            $lastMod = gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT';
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastMod);
            // Responde 304 se o navegador já tem a versão atual
            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
            if ($ifNoneMatch === $etag || $ifModSince === $lastMod) {
                http_response_code(304);
                return;
            }
        }

        header('Content-Type: ' . $mime);
        header('Cache-Control: ' . $maxAge);
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        return;
    } else {
        http_response_code(404);
        echo '404 Not Found';
        return;
    }
}

// =============================================================================
// 2. Servir views PHP existentes — whitelist restrita e sem expor testes
//    Bloqueia test_db.php e qualquer arquivo fora de /public
// =============================================================================
$blocked = ['/test_db.php', '/test_db.php/', '/.env', '/.env.example', '/diag.php', '/diag.php/'];
if (in_array($pathInfo, $blocked, true)) {
    http_response_code(404);
    echo '404 Not Found';
    return;
}
// Webhook do Mercado Pago e um endpoint publico (servidor-servidor)
// SEM sessao e SEM CSRF. Ele se valida por X-Signature.
$publicWebhookPaths = ['/mercadopago_webhook.php', '/mercadopago_webhook'];
if (in_array($pathInfo, $publicWebhookPaths, true)) {
    require_once $ROOT . '/public/mercadopago_webhook.php';
    return;
}
// Pagina de retorno do Mercado Pago (apenas UX, nao ativa plano)
$publicReturnPaths = ['/mercadopago_return.php', '/mercadopago_return'];
if (in_array($pathInfo, $publicReturnPaths, true)) {
    require_once $ROOT . '/public/mercadopago_return.php';
    return;
}
if ($pathInfo !== '/index.php' && $pathInfo !== '/' && !str_starts_with($pathInfo, '/api/')) {
    $viewFile = $ROOT . '/public' . $pathInfo;
    $real = realpath($viewFile);
    $publicReal = realpath($ROOT . '/public');
    if ($real && $publicReal && str_starts_with($real, $publicReal) && is_file($real) && is_readable($real)) {
        // Apenas views standalone (sem dependência de $data do controller) podem ser servidas diretamente.
        // Views com dados (dashboard, lancamentos etc.) DEVEM passar pelo router public/index.php
        // para que o controller prepare $data, senão renderizam vazias ou quebram.
        $allowed = ['/login.php','/register.php','/forgot.php','/reset.php','/termos.php','/privacidade.php'];
        if (in_array($pathInfo, $allowed, true)) {
            require_once $ROOT . '/public/partials/icons.php';
            if (file_exists($ROOT . '/src/config/config.php') && !function_exists('isLoggedIn')) {
                require_once $ROOT . '/src/config/config.php';
            }
            // Registro do DbSessionHandler ANTES de session_start() — CRÍTICO.
            // Se este bloco usar o handler padrão (arquivo), o token CSRF é escrito no
            // sistema de arquivos. Mas public/index.php registra DbSessionHandler antes de
            // session_start(). O POST do login leria do DB — onde o token não existe.
            // Resultado: "Sessão expirada" após logout → login.
            if (session_status() === PHP_SESSION_NONE) {
                $lifetime = 604800;
                try {
                    $db = getDBConnection();
                    $db->exec("CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires_at TIMESTAMP NOT NULL)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)");
                    require_once $ROOT . '/src/config/session_handler.php';
                    $handler = new DbSessionHandler($db, $lifetime);
                    session_set_save_handler($handler, true);
                } catch (Throwable $e) {
                    error_log('[api/index.php session] ' . $e->getMessage());
                    ini_set('session.gc_maxlifetime', (string)$lifetime);
                }
                @session_start();
            }
            if (!function_exists('csrf_field')) {
                require_once $ROOT . '/src/helpers/csrf.php';
            }
            include $real;
            return;
        }
    }
}

// =============================================================================
// 3. Configurar sessão para ambiente serverless
// =============================================================================
$isHttps = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['HTTP_X_VERCEL_FORWARDED_PROTO']) && $_SERVER['HTTP_X_VERCEL_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['VERCEL_ENV']))
);

$lifetime = 604800;
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
ini_set('session.gc_maxlifetime', (string)$lifetime);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

// =============================================================================
// 4. Carregar entry point — toda a lógica de negócio é preservada intacta
// =============================================================================
require $ROOT . '/public/index.php';

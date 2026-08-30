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
$blocked = ['/test_db.php', '/test_db.php/', '/.env', '/.env.example'];
if (in_array($pathInfo, $blocked, true)) {
    http_response_code(404);
    echo '404 Not Found';
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
        $allowed = ['/login.php','/register.php','/forgot.php','/reset.php'];
        if (in_array($pathInfo, $allowed, true)) {
            // Carrega o ambiente mínimo antes de incluir a view diretamente.
            // Garante que render_icon() e isLoggedIn() existam mesmo quando a view
            // é acessada sem passar pelo router public/index.php.
            require_once $ROOT . '/public/partials/icons.php';
            if (file_exists($ROOT . '/src/config/config.php') && !function_exists('isLoggedIn')) {
                require_once $ROOT . '/src/config/config.php';
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

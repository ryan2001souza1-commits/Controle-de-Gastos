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
        // app.js contém lógica crítica (mostrar senha) — nunca cachear como immutable para garantir correção imediata
        $isImmutable = in_array($ext, ['.css', '.woff2', '.woff', '.ttf', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp'], true)
            || ($ext === '.js' && $pathInfo !== '/js/app.js');
        $maxAge = $isImmutable
            ? 'public, max-age=31536000, immutable'
            : 'no-cache';

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
// 2. Servir views PHP existentes que existem como arquivos reais
//    Exemplos: /login.php → public/login.php
//              /dashboard.php → public/dashboard.php
// =============================================================================
if ($pathInfo !== '/index.php' && $pathInfo !== '/' && !str_starts_with($pathInfo, '/api/')) {
    $viewFile = $ROOT . '/public' . $pathInfo;
    if (is_file($viewFile) && is_readable($viewFile)) {
        // Arquivo PHP real encontrado — incluir diretamente
        // e terminar aqui (não chama o roteador principal)
        include $viewFile;
        return;
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

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// =============================================================================
// 4. Carregar entry point — toda a lógica de negócio é preservada intacta
// =============================================================================
require $ROOT . '/public/index.php';

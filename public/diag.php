<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/config/config.php';

if (getenv('VERCEL_ENV') !== false) {
    http_response_code(404);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$probe = function (string $k): string {
    $v = getenv($k);
    if ($v !== false && $v !== '') return 'OK';
    if (isset($_ENV[$k]) && $_ENV[$k] !== '') return 'OK';
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return 'OK';
    return 'VAZIO';
};

echo json_encode([
    'php_version'    => PHP_VERSION,
    'vercel_env'    => getenv('VERCEL_ENV') ?: null,
    'vercel_url'    => getenv('VERCEL_URL') ?: null,
    'app_env'       => getenv('APP_ENV') ?: null,
    'google_auth'   => $probe('GOOGLE_CLIENT_ID'),
    'database'      => $probe('DATABASE_URL'),
    'mailer'        => $probe('BREVO_API_KEY'),
    'ai'            => $probe('AI_API_KEY'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

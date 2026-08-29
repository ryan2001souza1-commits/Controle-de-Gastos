<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$probe = function (string $k): array {
    $v = getenv($k);
    $r = [
        'name'   => $k,
        'getenv' => ($v === false) ? null : ($v === '' ? '' : 'OK'),
    ];
    $r['$_ENV']    = (isset($_ENV[$k])    && $_ENV[$k]    !== '') ? 'OK' : 'VAZIO';
    $r['$_SERVER'] = (isset($_SERVER[$k]) && $_SERVER[$k] !== '') ? 'OK' : 'VAZIO';
    return $r;
};

echo json_encode([
    'php_version'        => PHP_VERSION,
    'vercel_env'         => getenv('VERCEL_ENV') ?: null,
    'vercel_url'         => getenv('VERCEL_URL') ?: null,
    'app_env'            => getenv('APP_ENV') ?: null,
    'google_client_id'   => $probe('GOOGLE_CLIENT_ID'),
    'google_client_secret' => $probe('GOOGLE_CLIENT_SECRET'),
    'database_url'       => $probe('DATABASE_URL'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
/**
 * mercadopago_return.php — Página de retorno do Mercado Pago.
 *
 * SEGURANCA CRITICA:
 * Esta pagina e apenas informativa (UX). Ela NAO ativa, atualiza ou
 * modifica qualquer plano de usuario.
 *
 * O plano do usuario so pode ser alterado via webhook do Mercado Pago,
 * que valida X-Signature e atualiza o banco de forma idempotente.
 *
 * Esta pagina apenas:
 *   - Mostra mensagem de processamento
 *   - Redireciona para meu_plano apos alguns segundos
 */
declare(strict_types=1);
$ROOT = dirname(__DIR__);

$envFile = $ROOT . '/.env';
if (is_file($envFile) && getenv('VERCEL_ENV') === false) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

require_once $ROOT . '/src/config/config.php';
require_once $ROOT . '/src/helpers/csrf.php';

$ref = $_GET['ref'] ?? '';
$planSlug = '';
if (preg_match('/^user_\d+_([a-z]+)$/', $ref, $m)) {
    $planSlug = $m[1];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processando assinatura — Controle de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 48px 40px;
            max-width: 480px;
            width: 90%;
            text-align: center;
        }
        .icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(124,58,237,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .icon svg { color: #7c3aed; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 12px; color: #f8fafc; }
        p { font-size: 15px; color: #94a3b8; line-height: 1.6; margin-bottom: 8px; }
        .highlight { color: #7c3aed; font-weight: 600; }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid #334155;
            border-top-color: #7c3aed;
            border-radius: 50%;
            animation: spin .8s linear infinite;
            margin: 28px auto 0;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .notice {
            margin-top: 24px;
            padding: 12px 16px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: 8px;
            font-size: 13px;
            color: #f59e0b;
        }
        .redirect-bar {
            margin-top: 20px;
            height: 3px;
            background: #334155;
            border-radius: 2px;
            overflow: hidden;
        }
        .redirect-bar .fill {
            height: 100%;
            background: #7c3aed;
            animation: progress 5s linear forwards;
        }
        @keyframes progress { from { width: 0; } to { width: 100%; } }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
            <line x1="1" y1="10" x2="23" y2="10"></line>
        </svg>
    </div>
    <h1>Assinatura em processamento</h1>
    <p>O Mercado Pago está processando sua assinatura<?php if ($planSlug) echo ' de <span class="highlight">' . htmlspecialchars(ucfirst($planSlug)) . '</span>'; ?>.</p>
    <p>A confirmação pode levar <strong>alguns segundos</strong>. Seu plano será ativado automaticamente após a confirmação do pagamento.</p>
    <div class="spinner"></div>
    <div class="notice">
        Não feche esta página. Você será redirecionado automaticamente.
    </div>
    <div class="redirect-bar"><div class="fill"></div></div>
</div>
<script>
    setTimeout(function() {
        window.location.href = '/index.php?action=meu_plano';
    }, 5000);
</script>
</body>
</html>

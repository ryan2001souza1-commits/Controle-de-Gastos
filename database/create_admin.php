<?php
/**
 * Cria ou promove usuário administrador.
 * Uso: php database/create_admin.php --email=admin@exemplo.com --password='SenhaForte123' [--name="Nome"]
 * Ou via env: ADMIN_EMAIL / ADMIN_PASSWORD
 * Seguro: senha é hasheada com password_hash, nunca salva em texto.
 */
require_once __DIR__ . '/../src/config/config.php';

$email = null; $password = null; $name = 'Administrador';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--email=')) $email = substr($arg, 8);
    if (str_starts_with($arg, '--password=')) $password = substr($arg, 11);
    if (str_starts_with($arg, '--name=')) $name = substr($arg, 7);
}
if (!$email) $email = getenv('ADMIN_EMAIL') ?: null;
if (!$password) $password = getenv('ADMIN_PASSWORD') ?: null;
if (!$email || !$password) {
    echo "Uso: php database/create_admin.php --email=admin@exemplo.com --password='SenhaForte123' [--name=\"Nome\"]\n";
    echo "Ou defina ADMIN_EMAIL e ADMIN_PASSWORD no .env e rode sem args.\n";
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo "E-mail inválido\n"; exit(1); }
if (strlen($password) < 8) { echo "Senha deve ter >=8 caracteres\n"; exit(1); }

try {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT id, is_admin FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($existing) {
        $db->prepare("UPDATE usuarios SET senha = ?, is_admin = 1, nome = COALESCE(NULLIF(?,''), nome), updated_at = NOW() WHERE id = ?")->execute([$hash, $name, $existing['id']]);
        echo "Usuário existente promovido a admin e senha atualizada: $email (id {$existing['id']})\n";
    } else {
        $db->prepare("INSERT INTO usuarios (nome, email, senha, is_admin, plano, plano_status) VALUES (?, ?, ?, 1, 'premium', 'ativo')")->execute([$name, $email, $hash]);
        $id = $db->lastInsertId();
        echo "Administrador criado: $email (id $id)\n";
    }
    echo "Acesse /index.php?action=login com essas credenciais e depois /index.php?action=admin\n";
} catch (Throwable $e) {
    echo "Erro: ".$e->getMessage()."\n";
    exit(1);
}

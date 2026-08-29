<?php
// Bloqueado em produção — apenas desenvolvimento local
if (getenv('VERCEL_ENV') !== false || getenv('APP_ENV') === 'production') {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

require_once __DIR__ . '/../src/config/config.php';

try {
    $pdo = getDBConnection();

    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    $total = $stmt->fetchColumn();

    echo "CONEXÃO OK! Total de usuários: " . $total;
} catch (PDOException $e) {
    echo "<h2>Erro ao conectar ao banco de dados</h2>";
    echo "<pre>";
    echo htmlspecialchars($e->getMessage());
    echo "</pre>";
}
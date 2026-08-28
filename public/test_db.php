<?php

require_once __DIR__ . '/../src/config/config.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query('SELECT COUNT(*) FROM usuarios');
    $total = $stmt->fetchColumn();

    echo "Conexao OK. Total de usuarios: " . $total;
} catch (PDOException $e) {
    echo "Erro ao conectar ao banco de dados.";
}

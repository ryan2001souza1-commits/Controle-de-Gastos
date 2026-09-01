<?php

require 'src/config/config.php';

try {
    $db = getDBConnection();
    echo "CONEXAO COM NEON OK" . PHP_EOL;
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . PHP_EOL;
}
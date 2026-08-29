<?php

require_once __DIR__ . '/../src/config/config.php';

$db = getDBConnection();

$sql = file_get_contents(__DIR__ . '/schema.sql');

$db->exec($sql);

// Migração idempotente: adiciona colunas de reset se não existirem
$columnsToAdd = [
    'reset_token'   => 'ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token VARCHAR(128)',
    'reset_expires' => 'ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_expires TIMESTAMP',
];
foreach ($columnsToAdd as $name => $sql) {
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        // coluna já existe ou ambiente sem permissão — segue
    }
}
$db->exec('CREATE INDEX IF NOT EXISTS idx_usuarios_reset_token ON usuarios(reset_token)');

echo "Schema aplicado com sucesso!\n";

$tables = $db->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public'
    AND table_name IN ('usuarios', 'categorias', 'transacoes', 'metas', 'orcamentos')
    ORDER BY CASE table_name
        WHEN 'usuarios' THEN 1
        WHEN 'categorias' THEN 2
        WHEN 'transacoes' THEN 3
        WHEN 'metas' THEN 4
        WHEN 'orcamentos' THEN 5
    END
");

foreach ($tables as $row) {
    $count = $db->query("SELECT COUNT(*) FROM {$row['table_name']}")->fetchColumn();
    echo "  {$row['table_name']}: $count registros\n";
}

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

// Migração idempotente: cor e ícone nas categorias
$catColumns = [
    'cor'   => 'ALTER TABLE categorias ADD COLUMN IF NOT EXISTS cor VARCHAR(7) DEFAULT \'#10b981\'',
    'icone' => 'ALTER TABLE categorias ADD COLUMN IF NOT EXISTS icone VARCHAR(40) DEFAULT \'tag\'',
    'ativo' => 'ALTER TABLE categorias ADD COLUMN IF NOT EXISTS ativo SMALLINT NOT NULL DEFAULT 1',
];
foreach ($catColumns as $name => $sql) {
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        // coluna já existe ou ambiente sem permissão — segue
    }
}

// Migração idempotente da tabela password_resets
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INTEGER NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) { /* já existe */ }
$db->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_user_id ON password_resets(user_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_token_hash ON password_resets(token_hash)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at ON password_resets(expires_at)');

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

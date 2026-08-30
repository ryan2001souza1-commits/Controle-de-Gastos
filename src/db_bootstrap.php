<?php
/**
 * Bootstrap idempotente do schema.
 *
 * Garante que o banco de producao (Neon/Postgres) tenha as colunas
 * adicionadas em migracoes recentes. Executa apenas uma vez por
 * requisicao (cache em variavel estatica) e na primeira interacao
 * com o banco de dados.
 */

function ensureSchemaUpToDate(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $required = [
            'categorias' => [
                'cor'   => "ALTER TABLE categorias ADD COLUMN cor VARCHAR(7) NOT NULL DEFAULT '#10b981'",
                'icone' => "ALTER TABLE categorias ADD COLUMN icone VARCHAR(40) NOT NULL DEFAULT 'tag'",
                'ativo' => "ALTER TABLE categorias ADD COLUMN ativo SMALLINT NOT NULL DEFAULT 1",
            ],
        ];

        $stmt = $db->prepare("
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $existing = [];
        foreach ($rows as $r) {
            $existing[$r['table_name']][$r['column_name']] = true;
        }

        foreach ($required as $table => $columns) {
            if (!isset($existing[$table])) {
                continue;
            }
            foreach ($columns as $col => $sql) {
                if (!isset($existing[$table][$col])) {
                    $db->exec($sql);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[schema-bootstrap] ' . $e->getMessage());
    }
}

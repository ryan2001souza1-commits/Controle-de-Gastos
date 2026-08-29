<?php

/**
 * Migrações idempotentes executadas no boot do app.
 * Garante que o schema mínimo existe no banco de produção
 * (Vercel serverless não tem como rodar migrate.php manualmente
 * a cada deploy — então a primeira requisição cria o que falta).
 *
 * Seguro: usa CREATE TABLE/INDEX IF NOT EXISTS e ALTER TABLE ADD COLUMN
 * IF NOT EXISTS. Não apaga dados. Pode rodar N vezes.
 */

function runMigrations(PDO $db): void
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    $statements = [
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            senha VARCHAR(255) NOT NULL,
            reset_token VARCHAR(128),
            reset_expires TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS categorias (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            nome VARCHAR(50) NOT NULL,
            tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('receita', 'despesa')),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS transacoes (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            categoria_id INTEGER,
            descricao VARCHAR(255) NOT NULL,
            valor NUMERIC(12, 2) NOT NULL,
            tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('receita', 'despesa')),
            data DATE NOT NULL,
            observacao TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS metas (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            nome VARCHAR(100) NOT NULL,
            valor_objetivo NUMERIC(12, 2) NOT NULL,
            valor_acumulado NUMERIC(12, 2) NOT NULL DEFAULT 0,
            data_limite DATE,
            descricao TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS orcamentos (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            categoria_id INTEGER NOT NULL,
            ano INTEGER NOT NULL,
            mes INTEGER NOT NULL,
            valor_limite NUMERIC(12, 2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT chk_mes CHECK (mes BETWEEN 1 AND 12),
            CONSTRAINT chk_ano CHECK (ano BETWEEN 2000 AND 2100),
            CONSTRAINT uq_orcamento_categoria UNIQUE (usuario_id, categoria_id, ano, mes)
        )",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INTEGER NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE INDEX IF NOT EXISTS idx_password_resets_user_id ON password_resets(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_password_resets_token_hash ON password_resets(token_hash)",
        "CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at ON password_resets(expires_at)",
        "CREATE INDEX IF NOT EXISTS idx_categorias_usuario_id ON categorias(usuario_id)",
        "CREATE INDEX IF NOT EXISTS idx_transacoes_usuario_id ON transacoes(usuario_id)",
        "CREATE INDEX IF NOT EXISTS idx_transacoes_categoria_id ON transacoes(categoria_id)",
        "CREATE INDEX IF NOT EXISTS idx_transacoes_data ON transacoes(data)",
        "CREATE INDEX IF NOT EXISTS idx_transacoes_usuario_data ON transacoes(usuario_id, data)",
        "CREATE INDEX IF NOT EXISTS idx_metas_usuario_id ON metas(usuario_id)",
        "CREATE INDEX IF NOT EXISTS idx_orcamentos_usuario_id ON orcamentos(usuario_id)",
        "CREATE INDEX IF NOT EXISTS idx_orcamentos_usuario_periodo ON orcamentos(usuario_id, ano, mes)",
    ];

    foreach ($statements as $sql) {
        $db->exec($sql);
    }

    $addColumnIfMissing = [
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token VARCHAR(128)",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_expires TIMESTAMP",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS provider VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS provider_sub VARCHAR(255) DEFAULT NULL",
    ];
    foreach ($addColumnIfMissing as $sql) {
        $db->exec($sql);
    }

    try {
        $db->exec("ALTER TABLE usuarios ALTER COLUMN senha DROP NOT NULL");
    } catch (PDOException $e) {
        // pode falhar se já é nullable — ignora
    }

    try {
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_usuarios_provider ON usuarios(provider, provider_sub) WHERE provider IS NOT NULL AND provider_sub IS NOT NULL");
    } catch (PDOException $e) {
        // índice pode existir — ignora
    }

    try {
        $db->exec("ALTER TABLE password_resets
                   ADD CONSTRAINT fk_password_resets_user
                   FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // constraint já existe — ignora
    }

    try {
        $db->exec("ALTER TABLE transacoes
                   ADD CONSTRAINT fk_transacoes_usuario
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    } catch (PDOException $e) { /* já existe */ }

    try {
        $db->exec("ALTER TABLE transacoes
                   ADD CONSTRAINT fk_transacoes_categoria
                   FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL");
    } catch (PDOException $e) { /* já existe */ }

    try {
        $db->exec("ALTER TABLE categorias
                   ADD CONSTRAINT fk_categorias_usuario
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    } catch (PDOException $e) { /* já existe */ }

    try {
        $db->exec("ALTER TABLE metas
                   ADD CONSTRAINT fk_metas_usuario
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    } catch (PDOException $e) { /* já existe */ }

    try {
        $db->exec("ALTER TABLE orcamentos
                   ADD CONSTRAINT fk_orcamentos_usuario
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    } catch (PDOException $e) { /* já existe */ }

    try {
        $db->exec("ALTER TABLE orcamentos
                   ADD CONSTRAINT fk_orcamentos_categoria
                   FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE");
    } catch (PDOException $e) { /* já existe */ }
}

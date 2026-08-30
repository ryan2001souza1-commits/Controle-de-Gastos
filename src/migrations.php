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
        "CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) PRIMARY KEY,
            data TEXT NOT NULL,
            expires_at TIMESTAMP NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)",
        "CREATE TABLE IF NOT EXISTS planos (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            nome VARCHAR(50) NOT NULL,
            slug VARCHAR(30) UNIQUE NOT NULL,
            preco NUMERIC(10,2) NOT NULL DEFAULT 0,
            descricao TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS bug_reports (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            titulo VARCHAR(150) NOT NULL,
            categoria VARCHAR(30) NOT NULL,
            descricao TEXT NOT NULL,
            pagina VARCHAR(100),
            url TEXT,
            prioridade VARCHAR(20) DEFAULT 'media',
            status VARCHAR(20) NOT NULL DEFAULT 'novo',
            navegador VARCHAR(200),
            sistema_operacional VARCHAR(100),
            screenshot VARCHAR(255),
            resposta_admin TEXT,
            observacao_interna TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE INDEX IF NOT EXISTS idx_bug_reports_usuario ON bug_reports(usuario_id)",
        "CREATE INDEX IF NOT EXISTS idx_bug_reports_status ON bug_reports(status)",
        "CREATE INDEX IF NOT EXISTS idx_bug_reports_created ON bug_reports(created_at DESC)",
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
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS telefone VARCHAR(20)",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS data_nascimento DATE",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS renda_mensal NUMERIC(12,2)",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS dia_recebimento SMALLINT",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS objetivo VARCHAR(100)",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS moeda VARCHAR(3) DEFAULT 'BRL'",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS notificacoes SMALLINT DEFAULT 1",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS is_admin SMALLINT NOT NULL DEFAULT 0",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS plano VARCHAR(20) NOT NULL DEFAULT 'gratuito'",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS plano_status VARCHAR(20) NOT NULL DEFAULT 'ativo'",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS plano_inicio TIMESTAMP",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS plano_fim TIMESTAMP",
    ];
    foreach ($addColumnIfMissing as $sql) {
        $db->exec($sql);
    }

    // Seed planos básicos (idempotente)
    try {
        $db->exec("INSERT INTO planos (nome, slug, preco, descricao) VALUES ('Gratuito','gratuito',0,'Plano gratuito com recursos essenciais') ON CONFLICT (slug) DO NOTHING");
        $db->exec("INSERT INTO planos (nome, slug, preco, descricao) VALUES ('Pro','pro',19.90,'Plano Pro com recursos avançados') ON CONFLICT (slug) DO NOTHING");
        $db->exec("INSERT INTO planos (nome, slug, preco, descricao) VALUES ('Premium','premium',39.90,'Plano Premium completo') ON CONFLICT (slug) DO NOTHING");
    } catch (Throwable $e) {}

    // Criação/promotion do admin via variáveis de ambiente (sem credencial no código)
    try {
        $adminEmail = trim((string)(getenv('ADMIN_EMAIL') ?: ''));
        $adminPass  = (string)(getenv('ADMIN_PASSWORD') ?: '');
        if ($adminEmail !== '' && $adminPass !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) && strlen($adminPass) >= 8) {
            $stmt = $db->prepare("SELECT id, is_admin FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$adminEmail]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                // Promove a admin se ainda não for
                if ((int)($existing['is_admin'] ?? 0) !== 1) {
                    $up = $db->prepare("UPDATE usuarios SET is_admin = 1, updated_at = NOW() WHERE id = ?");
                    $up->execute([$existing['id']]);
                }
            } else {
                // Cria novo usuário admin (hash seguro)
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $ins = $db->prepare("INSERT INTO usuarios (nome, email, senha, is_admin, plano, plano_status) VALUES (?, ?, ?, 1, 'premium', 'ativo')");
                $adminName = trim((string)(getenv('ADMIN_NAME') ?: 'Administrador'));
                if ($adminName === '') $adminName = 'Administrador';
                $ins->execute([$adminName, $adminEmail, $hash]);
            }
        }
    } catch (Throwable $e) {
        error_log('[admin seed] ' . $e->getMessage());
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

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
            preco NUMERIC(10,2) DEFAULT 0,
            descricao TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'ativo'
                CHECK (status IN ('ativo','inativo')),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        //============================================================
        // SUBSCRIPTIONS
        //============================================================
        // Estados internos (CHECK):
        //   pending   - assinatura criada, aguardando ativacao
        //   active    - ativa e em cobranca
        //   paused    - pausada
        //   cancelled - cancelada
        //   expired   - periodo pago terminou
        //   rejected  - recusada
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            plan_id INTEGER NOT NULL REFERENCES planos(id) ON DELETE RESTRICT,
            plan_slug VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending','active','paused','cancelled','expired','rejected')),
            start_date TIMESTAMP,
            next_billing_date TIMESTAMP,
            paused_at TIMESTAMP,
            cancelled_at TIMESTAMP,
            expired_at TIMESTAMP,
            grace_period_end TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

        "CREATE TABLE IF NOT EXISTS feedback (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            tipo VARCHAR(20) NOT NULL DEFAULT 'sugestao' CHECK (tipo IN ('sugestao','melhoria','critica','elogio','outro')),
            titulo VARCHAR(150) NOT NULL,
            descricao TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'novo' CHECK (status IN ('novo','em_analise','implementado','recusado')),
            resposta_admin TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS ai_usage (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            date DATE NOT NULL DEFAULT CURRENT_DATE,
            requests INTEGER NOT NULL DEFAULT 0,
            tokens_used INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uq_ai_usage_user_date UNIQUE (user_id, date)
        )",
        "CREATE INDEX IF NOT EXISTS idx_ai_usage_user_date ON ai_usage(user_id, date)",

        "CREATE TABLE IF NOT EXISTS rate_limit_attempts (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            action VARCHAR(30) NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            failed BOOLEAN NOT NULL DEFAULT TRUE,
            blocked_until TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE INDEX IF NOT EXISTS idx_rate_limit_action_id ON rate_limit_attempts(action, identifier, created_at DESC)",

        "CREATE TABLE IF NOT EXISTS user_sessions (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id, expires_at)",

        "ALTER TABLE sessions ADD COLUMN IF NOT EXISTS user_id INTEGER",
        "CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id) WHERE user_id IS NOT NULL",
    ];

    foreach ($statements as $sql) {
        $db->exec($sql);
    }

    $extraIndexes = [
        "CREATE INDEX IF NOT EXISTS idx_feedback_usuario ON feedback(usuario_id)",
        "CREATE INDEX IF NOT EXISTS idx_feedback_status ON feedback(status)",
        "CREATE INDEX IF NOT EXISTS idx_feedback_created ON feedback(created_at DESC)",

        // subscriptions: busca rapida "tem assinatura ativa para este usuario?"
        "CREATE INDEX IF NOT EXISTS idx_subscriptions_user_status
            ON subscriptions(user_id, status)",
        // cron de renovacoes futuras
        "CREATE INDEX IF NOT EXISTS idx_subscriptions_status_renewal
            ON subscriptions(status, next_billing_date) WHERE status = 'active'",
        // Mercado Pago: busca por ID do MP
        "CREATE INDEX IF NOT EXISTS idx_subscriptions_mp_id
            ON subscriptions(mp_preapproval_id)",
        // Mercado Pago: busca por external_reference
        "CREATE INDEX IF NOT EXISTS idx_subscriptions_external_ref
            ON subscriptions(external_reference)",
    ];
    foreach ($extraIndexes as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    $addColumnIfMissing = [
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token VARCHAR(128)",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_expires TIMESTAMP",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS provider VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS provider_sub VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cpf VARCHAR(14) DEFAULT NULL",
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
        // Relacionamento com assinatura ativa
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS active_subscription_id
            INTEGER REFERENCES subscriptions(id) ON DELETE SET NULL",
        // Mercado Pago: correlacionar com a assinatura externa
        "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS mp_preapproval_id VARCHAR(80)",
        // external_reference: rastreio do formato user_{ID}_{plano}
        "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS external_reference VARCHAR(120)",
        // raw_status: status original retornado pelo Mercado Pago (auditoria)
        "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS raw_status VARCHAR(40) DEFAULT NULL",
    ];

    foreach ($addColumnIfMissing as $sql) {
        $db->exec($sql);
    }

    require_once __DIR__ . '/migrations/remove_legacy_payment_gateways.php';
    run_remove_legacy_payment_gateways($db);

    // Seed planos basicos (idempotente).
    // Precos sao a fonte oficial para o backend e o frontend.
    // GRATUITO: 0.00 | PRO: 9.90 | PREMIUM: 19.90
    // Para ajustar valores, atualize aqui e reexecute as migrations.
    try {
        $db->exec("INSERT INTO planos (nome, slug, preco, descricao, status)
            VALUES ('Gratuito','gratuito',0.00,'Plano gratuito com recursos essenciais.','ativo')
            ON CONFLICT (slug) DO NOTHING");
        $db->exec("INSERT INTO planos (nome, slug, preco, descricao, status)
            VALUES ('Pro','pro',9.90,'Plano Pro com recursos avancados.','ativo')
            ON CONFLICT (slug) DO NOTHING");
        $db->exec("INSERT INTO planos (nome, slug, preco, descricao, status)
            VALUES ('Premium','premium',19.90,'Plano Premium completo.','ativo')
            ON CONFLICT (slug) DO NOTHING");
    } catch (Throwable $e) {}

    // Migra colunas caso a tabela planos ja exista com estrutura antiga
    try {
        $db->exec("ALTER TABLE planos ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'ativo'");
    } catch (Throwable $e) {}
    try {
        $db->exec("ALTER TABLE planos ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    } catch (Throwable $e) {}
    try {
        // Torna preco nullable (permite NULL para planos sem preco definido)
        $db->exec("ALTER TABLE planos ALTER COLUMN preco DROP NOT NULL");
    } catch (Throwable $e) {}
    // Garante que preco DEFAULT 0 existe (apos tornar nullable)
    try {
        $db->exec("ALTER TABLE planos ALTER COLUMN preco SET DEFAULT 0");
    } catch (Throwable $e) {}

    // Aplica precos oficiais a PRO e PREMIUM (sempre, para refletir ajustes).
    // Idempotente: a cada deploy sincroniza preco com o valor definido aqui.
    // Se precisar ajustar valores, altere aqui e reexecute as migrations.
    try {
        $db->exec("UPDATE planos SET preco = 9.90, updated_at = NOW()
            WHERE slug = 'pro'");
        $db->exec("UPDATE planos SET preco = 19.90, updated_at = NOW()
            WHERE slug = 'premium'");
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
        $dupStmt = $db->query(
            "SELECT mp_preapproval_id, COUNT(*) as cnt
               FROM subscriptions
              WHERE mp_preapproval_id IS NOT NULL
                AND mp_preapproval_id <> ''
              GROUP BY mp_preapproval_id
             HAVING COUNT(*) > 1"
        );
        $duplicates = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($duplicates) === 0) {
            $db->exec(
                "CREATE UNIQUE INDEX IF NOT EXISTS uq_subscriptions_mp_preapproval_id
                    ON subscriptions(mp_preapproval_id)
                   WHERE mp_preapproval_id IS NOT NULL
                     AND mp_preapproval_id <> ''"
            );
        } else {
            error_log(
                '[migrations] mp_preapproval_id tem duplicatas — UNIQUE index nao criado: '
                . json_encode($duplicates)
            );
        }
    } catch (Throwable $e) {
        error_log('[migrations] falha ao criar uq_subscriptions_mp_preapproval_id: ' . $e->getMessage());
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

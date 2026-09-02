-- ============================================================
-- Controle de Gastos — Schema do Banco de Dados (PostgreSQL)
-- Mantido atualizado: as colunas de plano e admin estao
-- alem das Migracoes (src/migrations.php) que garantem
-- que o banco de producao tenha a estrutura correta.
-- ============================================================

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    -- Autenticacao
    reset_token VARCHAR(128),
    reset_expires TIMESTAMP,
    -- OAuth
    provider VARCHAR(20) DEFAULT NULL,
    provider_sub VARCHAR(255) DEFAULT NULL,
    -- Perfil
    telefone VARCHAR(20),
    data_nascimento DATE,
    renda_mensal NUMERIC(12,2),
    dia_recebimento SMALLINT,
    objetivo VARCHAR(100),
    moeda VARCHAR(3) DEFAULT 'BRL',
    notificacoes SMALLINT DEFAULT 1,
    -- Planos e admin
    is_admin SMALLINT NOT NULL DEFAULT 0,
    plano VARCHAR(20) NOT NULL DEFAULT 'gratuito',
    plano_status VARCHAR(20) NOT NULL DEFAULT 'ativo',
    plano_inicio TIMESTAMP,
    plano_fim TIMESTAMP,
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

--============================================================
-- PLANOS DE ASSINATURA
-- Catalogo de planos disponiveis no sistema.
-- Seed via migrations.php: gratuito, pro, premium
--============================================================
CREATE TABLE IF NOT EXISTS planos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    slug VARCHAR(30) UNIQUE NOT NULL,
    preco NUMERIC(10,2) NOT NULL DEFAULT 0,
    descricao TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

--============================================================
-- SUBSCRIPTIONS (Mercado Pago - preapproval)
--============================================================
-- Estados internos (CHECK):
--   pending   - preapproval criado, aguardando pagamento
--   active    - autorizado e em cobranca recorrente
--   paused    - pausado pelo usuario ou pelo MP
--   cancelled - cancelado (acesso preservado ate grace_period_end)
--   expired   - periodo pago terminou
--   rejected  - pagamento recusado / assinatura rejeitada
-- Mapeamento MP -> interno:
--   pending   <- pending
--   active    <- authorized
--   paused    <- paused
--   cancelled <- cancelled
--   expired   <- expired / finished
--   rejected  <- rejected
CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    plan_slug VARCHAR(20) NOT NULL,
    mp_preapproval_id VARCHAR(60) UNIQUE,
    mp_plan_id VARCHAR(60),
    mp_payer_id VARCHAR(60),
    external_reference VARCHAR(100),
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','active','paused','cancelled','expired','rejected')),
    reason VARCHAR(50),
    raw_status VARCHAR(50),
    start_date TIMESTAMP,
    next_billing_date TIMESTAMP,
    paused_at TIMESTAMP,
    cancelled_at TIMESTAMP,
    expired_at TIMESTAMP,
    grace_period_end TIMESTAMP,
    amount_cents INTEGER,
    currency CHAR(3) DEFAULT 'BRL',
    frequency SMALLINT,
    frequency_type VARCHAR(10) DEFAULT 'months'
        CHECK (frequency_type IS NULL OR frequency_type IN ('days','months','years')),
    last_event_id VARCHAR(100),
    last_event_type VARCHAR(40),
    last_event_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES planos(id) ON DELETE RESTRICT
);

--============================================================
-- PAYMENT_WEBHOOKS (idempotencia e auditoria de webhooks MP)
--============================================================
-- - event_id UNIQUE garante idempotencia
-- - payload JSONB preserva o evento para auditoria
-- - NUNCA armazena access_token nem dados de cartao
CREATE TABLE IF NOT EXISTS payment_webhooks (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id VARCHAR(100) UNIQUE,
    topic VARCHAR(30) NOT NULL
        CHECK (topic IN ('preapproval','subscription','payment','plan','invoice')),
    action VARCHAR(30),
    resource_id VARCHAR(100),
    payload JSONB NOT NULL,
    signature_header VARCHAR(500),
    source_ip VARCHAR(45),
    status VARCHAR(20) NOT NULL DEFAULT 'received'
        CHECK (status IN ('received','processing','processed','failed','skipped')),
    error_message TEXT,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    subscription_id INTEGER,
    user_id INTEGER,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- Adicao tardia em usuarios para relacionamento com assinatura ativa
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS active_subscription_id INTEGER
    REFERENCES subscriptions(id) ON DELETE SET NULL;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS mercadopago_payer_id VARCHAR(60);

--============================================================
-- CATEGORIAS
--============================================================
CREATE TABLE IF NOT EXISTS categorias (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    nome VARCHAR(50) NOT NULL,
    tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('receita', 'despesa')),
    cor VARCHAR(7) DEFAULT '#10b981',
    icone VARCHAR(40) DEFAULT 'tag',
    ativo SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

--============================================================
-- TRANSACOES
--============================================================
CREATE TABLE IF NOT EXISTS transacoes (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    categoria_id INTEGER,
    descricao VARCHAR(255) NOT NULL,
    valor NUMERIC(12, 2) NOT NULL,
    tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('receita', 'despesa')),
    data DATE NOT NULL,
    observacao TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
);

--============================================================
-- METAS FINANCEIRAS
--============================================================
CREATE TABLE IF NOT EXISTS metas (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    nome VARCHAR(100) NOT NULL,
    valor_objetivo NUMERIC(12, 2) NOT NULL,
    valor_acumulado NUMERIC(12, 2) NOT NULL DEFAULT 0,
    data_limite DATE,
    descricao TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

--============================================================
-- ORCAMENTOS MENSAIS
--============================================================
CREATE TABLE IF NOT EXISTS orcamentos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    categoria_id INTEGER NOT NULL,
    ano INTEGER NOT NULL,
    mes INTEGER NOT NULL,
    valor_limite NUMERIC(12, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE,
    CONSTRAINT chk_mes CHECK (mes BETWEEN 1 AND 12),
    CONSTRAINT chk_ano CHECK (ano BETWEEN 2000 AND 2100),
    CONSTRAINT uq_orcamento_categoria UNIQUE (usuario_id, categoria_id, ano, mes)
);

--============================================================
-- USAGE DO ASSISTENTE IA
-- Controle de rate limit por usuario/dia
--============================================================
CREATE TABLE IF NOT EXISTS ai_usage (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL,
    date DATE NOT NULL DEFAULT CURRENT_DATE,
    requests INTEGER NOT NULL DEFAULT 0,
    tokens_used INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT uq_ai_usage_user_date UNIQUE (user_id, date)
);

--============================================================
-- FEEDBACK DE USUARIOS
--============================================================
CREATE TABLE IF NOT EXISTS feedback (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'sugestao'
        CHECK (tipo IN ('sugestao','melhoria','critica','elogio','outro')),
    titulo VARCHAR(150) NOT NULL,
    descricao TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'novo'
        CHECK (status IN ('novo','em_analise','implementado','recusado')),
    resposta_admin TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

--============================================================
-- BUG REPORTS
--============================================================
CREATE TABLE IF NOT EXISTS bug_reports (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
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
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

--============================================================
-- RESET DE SENHA
--============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

--============================================================
-- SESSOES (para session handler em DB)
--============================================================
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    data TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL
);

-- ============================================================
-- INDICES
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email);
CREATE INDEX IF NOT EXISTS idx_usuarios_provider ON usuarios(provider, provider_sub) WHERE provider IS NOT NULL AND provider_sub IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_categorias_usuario_id ON categorias(usuario_id);
CREATE INDEX IF NOT EXISTS idx_transacoes_usuario_id ON transacoes(usuario_id);
CREATE INDEX IF NOT EXISTS idx_transacoes_categoria_id ON transacoes(categoria_id);
CREATE INDEX IF NOT EXISTS idx_transacoes_data ON transacoes(data);
CREATE INDEX IF NOT EXISTS idx_transacoes_usuario_data ON transacoes(usuario_id, data);
CREATE INDEX IF NOT EXISTS idx_metas_usuario_id ON metas(usuario_id);
CREATE INDEX IF NOT EXISTS idx_orcamentos_usuario_id ON orcamentos(usuario_id);
CREATE INDEX IF NOT EXISTS idx_orcamentos_usuario_periodo ON orcamentos(usuario_id, ano, mes);
CREATE INDEX IF NOT EXISTS idx_ai_usage_user_date ON ai_usage(user_id, date);
CREATE INDEX IF NOT EXISTS idx_feedback_usuario ON feedback(usuario_id);
CREATE INDEX IF NOT EXISTS idx_feedback_status ON feedback(status);
CREATE INDEX IF NOT EXISTS idx_feedback_created ON feedback(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bug_reports_usuario ON bug_reports(usuario_id);
CREATE INDEX IF NOT EXISTS idx_bug_reports_status ON bug_reports(status);
CREATE INDEX IF NOT EXISTS idx_bug_reports_created ON bug_reports(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_password_resets_user_id ON password_resets(user_id);
CREATE INDEX IF NOT EXISTS idx_password_resets_token_hash ON password_resets(token_hash);
CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at ON password_resets(expires_at);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- subscriptions indexes
CREATE UNIQUE INDEX IF NOT EXISTS idx_subscriptions_mp_preapproval
    ON subscriptions(mp_preapproval_id) WHERE mp_preapproval_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_subscriptions_user_status
    ON subscriptions(user_id, status);
CREATE INDEX IF NOT EXISTS idx_subscriptions_status_renewal
    ON subscriptions(status, next_billing_date) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_subscriptions_ext_ref
    ON subscriptions(external_reference) WHERE external_reference IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_subscriptions_grace
    ON subscriptions(grace_period_end) WHERE grace_period_end IS NOT NULL;

-- payment_webhooks indexes
CREATE INDEX IF NOT EXISTS idx_payment_webhooks_topic_received
    ON payment_webhooks(topic, received_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_webhooks_status
    ON payment_webhooks(status) WHERE status IN ('received','failed');
CREATE INDEX IF NOT EXISTS idx_payment_webhooks_subscription
    ON payment_webhooks(subscription_id) WHERE subscription_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_payment_webhooks_user
    ON payment_webhooks(user_id) WHERE user_id IS NOT NULL;

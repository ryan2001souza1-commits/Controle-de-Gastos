CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categorias (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    nome VARCHAR(50) NOT NULL,
    tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('receita', 'despesa')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

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

CREATE INDEX idx_categorias_usuario_id ON categorias(usuario_id);
CREATE INDEX idx_transacoes_usuario_id ON transacoes(usuario_id);
CREATE INDEX idx_transacoes_categoria_id ON transacoes(categoria_id);
CREATE INDEX idx_transacoes_data ON transacoes(data);
CREATE INDEX idx_transacoes_usuario_data ON transacoes(usuario_id, data);

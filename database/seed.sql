BEGIN;

-- =========================================================
-- SEED: Controle de Gastos
-- Postgres 18
-- =========================================================

-- 1) Usuário de teste
INSERT INTO usuarios (nome, email, senha)
SELECT 'Usuário Teste', 'teste@controlegastos.com', '$2y$12$2POPpvlLqlX3uJML8sneLukeXzRA1Dp0dfJ8hmKG2nlgwii36FPHi'
WHERE NOT EXISTS (
    SELECT 1 FROM usuarios WHERE email = 'teste@controlegastos.com'
);

-- Recupera o id do usuário (já existe ou acabou de ser inserido)
DO $$
DECLARE
    v_user_id INTEGER;
BEGIN
    SELECT id INTO v_user_id
    FROM usuarios
    WHERE email = 'teste@controlegastos.com';

    IF v_user_id IS NULL THEN
        RAISE EXCEPTION 'Usuário de teste não encontrado após inserção.';
    END IF;

    -- 2) Categorias
    -- Insere apenas se ainda não existir para este usuário
    INSERT INTO categorias (usuario_id, nome, tipo)
    SELECT v_user_id, nome, tipo FROM (VALUES
        ('Alimentação', 'despesa'),
        ('Transporte',  'despesa'),
        ('Moradia',     'despesa'),
        ('Lazer',       'despesa'),
        ('Saúde',       'despesa'),
        ('Salário',     'receita'),
        ('Freelance',   'receita'),
        ('Outros',      'receita')
    ) AS cat(nome, tipo)
    WHERE NOT EXISTS (
        SELECT 1 FROM categorias c
        WHERE c.usuario_id = v_user_id
          AND c.nome = cat.nome
          AND c.tipo = cat.tipo
    );

    -- 3) Transações de exemplo
    -- Cada transação é inserida apenas se ainda não existir para este usuário
    -- Usa uma abordagem linha a linha para evitar duplicatas
    INSERT INTO transacoes (usuario_id, categoria_id, descricao, valor, tipo, data, observacao)
    SELECT v_user_id, c.id, t.descricao, t.valor, t.tipo, t.data, t.observacao
    FROM (VALUES
        ('Salário',     'Salário mensal',                5500.00, 'receita', (CURRENT_DATE - INTERVAL '25 days')::date, 'Salário de CLT'),
        ('Freelance',   'Projeto website',               1800.00, 'receita', (CURRENT_DATE - INTERVAL '15 days')::date, 'Cliente A'),
        ('Outros',      'Rendimento de investimentos',    220.50, 'receita', (CURRENT_DATE - INTERVAL '5 days')::date,  'Dividendos'),
        ('Moradia',     'Aluguel',                       1500.00, 'despesa', (CURRENT_DATE - INTERVAL '20 days')::date, 'Apartamento'),
        ('Alimentação', 'Supermercado',                   450.75, 'despesa', (CURRENT_DATE - INTERVAL '18 days')::date, 'Compras da semana'),
        ('Alimentação', 'Restaurante',                     89.90, 'despesa', (CURRENT_DATE - INTERVAL '12 days')::date, 'Almoço com amigos'),
        ('Transporte',  'Combustível',                    250.00, 'despesa', (CURRENT_DATE - INTERVAL '10 days')::date, 'Gasolina'),
        ('Transporte',  'Uber',                             45.50, 'despesa', (CURRENT_DATE - INTERVAL '7 days')::date, NULL),
        ('Lazer',       'Cinema',                           60.00, 'despesa', (CURRENT_DATE - INTERVAL '6 days')::date, 'Ingresso + pipoca'),
        ('Saúde',       'Farmácia',                         95.30, 'despesa', (CURRENT_DATE - INTERVAL '4 days')::date, 'Medicamentos'),
        ('Lazer',       'Streaming',                        39.90, 'despesa', (CURRENT_DATE - INTERVAL '2 days')::date, 'Assinatura mensal'),
        ('Alimentação', 'Padaria',                           28.50, 'despesa', (CURRENT_DATE - INTERVAL '1 day')::date,  'Café da manhã')
    ) AS t(categoria_nome, descricao, valor, tipo, data, observacao)
    JOIN categorias c ON c.usuario_id = v_user_id AND c.nome = t.categoria_nome AND c.tipo = t.tipo
    WHERE NOT EXISTS (
        SELECT 1 FROM transacoes tr
        WHERE tr.usuario_id = v_user_id
          AND tr.categoria_id = c.id
          AND tr.descricao = t.descricao
          AND tr.valor = t.valor
          AND tr.tipo = t.tipo
          AND tr.data = t.data
    );

END $$;

COMMIT;

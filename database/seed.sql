BEGIN;

-- =========================================================
-- SEED: Controle de Gastos
-- Postgres 18
-- Reexecutável: usa ON CONFLICT / NOT EXISTS para evitar duplicações
-- =========================================================

-- 1) Usuário de teste
-- Senha: 123456
-- Hash bcrypt válido gerado para "123456"
INSERT INTO usuarios (nome, email, senha)
VALUES (
    'Usuário Teste',
    'teste@controlegastos.com',
    '$2y$10$wH8QzwH3JqT5yP4mY6lJxeN1uVxLk7cG2dQf5bZ8sR0tV9wX3aB4e'
)
ON CONFLICT (email) DO NOTHING;

-- 2) Categorias
-- Recupera o id do usuário de teste de forma idempotente
DO $$
DECLARE
    v_user_id INTEGER;
BEGIN
    SELECT id INTO v_user_id
    FROM usuarios
    WHERE email = 'teste@controlegastos.com';

    IF v_user_id IS NULL THEN
        RAISE EXCEPTION 'Usuário de teste não encontrado.';
    END IF;

    -- Categorias de DESPESA
    INSERT INTO categorias (usuario_id, nome, tipo) VALUES
        (v_user_id, 'Alimentação', 'despesa'),
        (v_user_id, 'Transporte',  'despesa'),
        (v_user_id, 'Moradia',     'despesa'),
        (v_user_id, 'Lazer',       'despesa'),
        (v_user_id, 'Saúde',       'despesa')
    ON CONFLICT DO NOTHING;

    -- Categorias de RECEITA
    INSERT INTO categorias (usuario_id, nome, tipo) VALUES
        (v_user_id, 'Salário',   'receita'),
        (v_user_id, 'Freelance', 'receita'),
        (v_user_id, 'Outros',    'receita')
    ON CONFLICT DO NOTHING;

    -- 3) Transações de exemplo
    -- Datas recentes (mês atual e anterior relativo à data de execução)
    -- Usa subselects para resolver categoria_id por nome
    INSERT INTO transacoes (usuario_id, categoria_id, descricao, valor, tipo, data, observacao)
    SELECT v_user_id, c.id, t.descricao, t.valor, t.tipo, t.data, t.observacao
    FROM (VALUES
        -- Receitas
        ('Salário',     'Salário mensal',                 5500.00, 'receita', (CURRENT_DATE - INTERVAL '25 days')::date, 'Salário de CLT'),
        ('Freelance',   'Projeto website',                1800.00, 'receita', (CURRENT_DATE - INTERVAL '15 days')::date, 'Cliente A'),
        ('Outros',      'Rendimento de investimentos',     220.50, 'receita', (CURRENT_DATE - INTERVAL '5 days')::date,  'Dividendos'),
        -- Despesas
        ('Moradia',     'Aluguel',                        1500.00, 'despesa', (CURRENT_DATE - INTERVAL '20 days')::date, 'Apartamento'),
        ('Alimentação', 'Supermercado',                    450.75, 'despesa', (CURRENT_DATE - INTERVAL '18 days')::date, 'Compras da semana'),
        ('Alimentação', 'Restaurante',                     89.90,  'despesa', (CURRENT_DATE - INTERVAL '12 days')::date, 'Almoço com amigos'),
        ('Transporte',  'Combustível',                     250.00, 'despesa', (CURRENT_DATE - INTERVAL '10 days')::date, 'Gasolina'),
        ('Transporte',  'Uber',                             45.50, 'despesa', (CURRENT_DATE - INTERVAL '7 days')::date,  NULL),
        ('Lazer',       'Cinema',                           60.00, 'despesa', (CURRENT_DATE - INTERVAL '6 days')::date,  'Ingresso + pipoca'),
        ('Saúde',       'Farmácia',                         95.30, 'despesa', (CURRENT_DATE - INTERVAL '4 days')::date,  'Medicamentos'),
        ('Lazer',       'Streaming',                        39.90, 'despesa', (CURRENT_DATE - INTERVAL '2 days')::date,  'Assinatura mensal'),
        ('Alimentação', 'Padaria',                          28.50, 'despesa', (CURRENT_DATE - INTERVAL '1 day')::date,   'Café da manhã')
    ) AS t(categoria_nome, descricao, valor, tipo, data, observacao)
    JOIN categorias c
      ON c.usuario_id = v_user_id
     AND c.nome = t.categoria_nome
     AND c.tipo = t.tipo
    ON CONFLICT DO NOTHING;
END $$;

COMMIT;

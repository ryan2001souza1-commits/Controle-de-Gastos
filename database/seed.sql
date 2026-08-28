USE controle_gastos;

INSERT INTO users (name, email, password_hash) VALUES
('Admin', 'admin@test.com', '$2y$10$abcdefghijklmnopqrstuv');

INSERT INTO categories (name, type, user_id) VALUES
('Alimentação', 'expense', 1),
('Transporte', 'expense', 1),
('Lazer', 'expense', 1),
('Saúde', 'expense', 1),
('Salário', 'income', 1),
('Freelance', 'income', 1);

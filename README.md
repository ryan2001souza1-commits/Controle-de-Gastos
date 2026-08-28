# Controle de Gastos

Sistema simples de controle de gastos pessoais em PHP + MySQL.

## Requisitos

- PHP 8.0+
- MySQL 5.7+
- Servidor web (Apache/Nginx) ou PHP built-in server

## Instalação

1. Clone o repositório
2. Configure o banco de dados em `src/config/config.php`
3. Execute o schema:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
4. (Opcional) Execute os seeds:
   ```bash
   mysql -u root -p < database/seed.sql
   ```
5. Inicie o servidor:
   ```bash
   php -S localhost:8000 -t public
   ```

## Configuração

Edite `src/config/config.php` com suas credenciais:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'controle_gastos');
define('DB_USER', 'root');
define('DB_PASS', '');
```

## Uso

- `public/index.php` - Entry point com routing
- `public/login.php` - Página de login
- `public/register.php` - Página de cadastro
- `public/dashboard.php` - Painel principal

## Estrutura

```
src/
├── config/      - Configuração e helpers
├── controllers/ - Controladores MVC
├── models/      - Modelos de dados
└── services/    - Lógica de negócio
```

## Funcionalidades

- Cadastro e login de usuários
- Registro de despesas e receitas
- Categorias personalizáveis
- Filtros por período
- Dashboard com totais e gráficos

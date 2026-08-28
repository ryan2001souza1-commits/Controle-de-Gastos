# Deploy — Controle de Gastos

Este projeto está pronto para deploy na **Vercel** via **FrankenPHP** (Caddy + PHP 8.4 em Docker).

## Estrutura criada

| Arquivo | Propósito |
|---|---|
| `Dockerfile` | Imagem baseada em `dunglas/frankenphp:1.0-php8.4-trixie` com `pdo_pgsql` |
| `Caddyfile` | Roteamento: estáticos servidos, PHP via `php_server`, fallback para `index.php?action=...` |
| `vercel.json` | Configura a Vercel para buildar via Docker (`@vercel/docker`) |
| `docker-compose.yml` | Ambiente de dev local: PostgreSQL 18 + FrankenPHP |
| `.env.example` | Template de variáveis de ambiente |
| `.dockerignore` | Exclui `.git`, `.env`, `node_modules`, etc. do contexto Docker |

## Alterações aplicadas em arquivos existentes

| Arquivo | Mudança |
|---|---|
| `src/config/config.php` | `DB_HOST/PORT/NAME/USER/PASS` agora leem de `getenv()` com defaults locais |
| `src/config/config.php` | `requireLogin()` redireciona para `/index.php?action=login` (roteador) em vez de `/login.php` direto |

## Variáveis de ambiente obrigatórias

Configure no painel da Vercel (Settings → Environment Variables) ou em `.env` local:

| Variável | Descrição | Default local |
|---|---|---|
| `DB_HOST` | Hostname do PostgreSQL | `localhost` |
| `DB_PORT` | Porta do PostgreSQL | `5432` |
| `DB_NAME` | Nome do database | `controle_gastos` |
| `DB_USER` | Usuário do banco | `postgres` |
| `DB_PASS` | Senha do banco | `123` |

## Como funciona o roteamento

A aplicação usa `index.php?action=...` como roteador. O Caddyfile traduz qualquer URL para o PHP correto:

- `/login.php` → serve `public/login.php` diretamente
- `/index.php?action=metas` → serve `public/index.php` (router trata `action=metas`)
- `/qualquer-coisa` → fallback para `public/index.php?action=qualquer-coisa`

## Build e teste local

```bash
# Subir tudo (PostgreSQL + app)
docker compose up -d

# Ver logs da aplicação
docker compose logs -f app

# Parar e limpar volumes
docker compose down -v
```

Acesse: **http://localhost:8080**

## Deploy na Vercel

1. Instale a CLI da Vercel e faça login: `vercel login`
2. Em **Settings → Environment Variables**, adicione `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` apontando para um PostgreSQL acessível (Neon, Supabase, Vercel Postgres, etc.)
3. Importe o repositório na Vercel — ela detectará o `vercel.json` e usará o `Dockerfile` automaticamente
4. Após o deploy, o schema do banco precisa ser executado manualmente:

```bash
psql $DATABASE_URL < database/schema.sql
```

## Notas de segurança

- O cookie de sessão já é gerado pelo PHP sem flags `Secure`/`HttpOnly` (padrão). Em produção com HTTPS, recomenda-se ajustar `session_set_cookie_params()` no `index.php`
- Senhas armazenadas com `password_hash(PASSWORD_DEFAULT)` (bcrypt/argon2)
- `PDO::ATTR_EMULATE_PREPARES = false` garante prepared statements reais no PostgreSQL
- Isolamento por `usuario_id` em 100% das queries (verificado na auditoria)

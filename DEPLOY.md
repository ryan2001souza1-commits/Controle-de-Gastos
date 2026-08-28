# Deploy — Vercel (PHP Serverless Functions)

Este projeto está pronto para deploy na Vercel usando o **runtime nativo `vercel-php@0.9.0`** (sem Docker).

## Como funciona

```
┌─────────────────────────────────────────────────────┐
│                   Vercel Edge                       │
│                  url: *.vercel.app                  │
└──────────────────────┬──────────────────────────────┘
                       │ qualquer URL
                       ▼
       ┌──────────────────────────────────┐
       │  vercel.json (catch-all)         │
       │  /css, /js, /assets, /*.php, /*  │
       └──────────┬───────────────────────┘
                  ▼
       ┌──────────────────────────────────┐
       │  api/index.php  (vercel-php)     │
       │  • serve estáticos de public/    │
       │  • serve views PHP reais         │
       │  • inclui public/index.php       │
       └──────────┬───────────────────────┘
                  ▼
       ┌──────────────────────────────────┐
       │  public/index.php                │
       │  roteador ?action=...            │
       │  (controllers, models, services) │
       └──────────────────────────────────┘
```

## Arquivos críticos

| Arquivo | Propósito |
|---|---|
| `api/index.php` | **Único entry point** do Vercel. Serve estáticos e delega para `public/index.php` |
| `public/index.php` | Roteador existente (intocado). Continua funcionando localmente |
| `vercel.json` | Define `runtime: vercel-php@0.9.0` e rotas catch-all |
| `src/config/config.php` | Lê `DATABASE_URL` com fallback para `DB_HOST`/`DB_PASS` |
| `.env.example` | Template de `DATABASE_URL` |

## Variáveis de ambiente (obrigatórias)

Configure em **Settings → Environment Variables** da Vercel:

| Variável | Descrição | Exemplo |
|---|---|---|
| `DATABASE_URL` | Connection string completa | `postgres://user:pass@host:5432/db?sslmode=require` |

Alternativa: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (também suportadas).

**Provedores recomendados** (todos têm plano gratuito):

- **Vercel Postgres** (integrado, ideal para Vercel) — `https://vercel.com/docs/storage/vercel-postgres`
- **Neon** (PostgreSQL serverless) — `https://neon.tech`
- **Supabase** — `https://supabase.com`

## Deploy passo a passo

### 1. Subir para o Git

```bash
git init
git add .
git commit -m "feat: deploy Vercel vercel-php runtime"
git remote add origin https://github.com/SEU_USUARIO/controle-de-gastos.git
git push -u origin main
```

### 2. Importar na Vercel

1. Acesse [vercel.com](https://vercel.com) → **Add New Project**
2. Selecione o repositório
3. A Vercel detecta o `vercel.json` automaticamente
4. Em **Environment Variables**, adicione `DATABASE_URL`
5. Clique **Deploy**

### 3. Aplicar o schema do banco

Após o primeiro deploy, aplique o schema no PostgreSQL remoto:

```bash
# Opção A: usando psql
PGPASSWORD=senha psql -h host -U user -d dbname -f database/schema.sql

# Opção B: usando a connection string
psql "postgres://user:pass@host:5432/dbname?sslmode=require" -f database/schema.sql
```

## Como funciona em ambiente serverless

- **Conexão PDO**: nova a cada cold start (cold = 200-500ms), reutilizada por request (singleton)
- **Sessões PHP**: cookies stateless. Cada requisição traz o PHPSESSID. **NÃO persiste entre cold starts** — login expira quando a função "dorme"
- **Arquivos estáticos**: servidos pelo `api/index.php` lendo de `public/` (com cache `immutable`)
- **POSTs**: funcionam normalmente, body chega em `php://input` e `$_POST`

## Limitações conhecidas

1. **Sessões em serverless stateless** — sem storage compartilhado, cada função tem seu próprio filesystem. Para produção, use JWT ou Postgres para sessions.
2. **Cold starts** — primeira requisição após inatividade demora ~500ms
3. **Timeout 10s** — configurado em `vercel.json`. Aumentar se necessário.

## Testar localmente

```bash
# Subir PostgreSQL local
docker run --name pg-test -e POSTGRES_PASSWORD=123 -e POSTGRES_DB=controle_gastos -p 5432:5432 -d postgres:18-alpine

# Criar .env
cp .env.example .env
# (ajuste DATABASE_URL se necessário)

# Aplicar schema
psql "postgres://postgres:123@localhost:5432/controle_gastos" -f database/schema.sql

# Rodar servidor PHP
php -S localhost:8000 -t public

# Acesse: http://localhost:8000
```

O servidor local usa o **mesmo** `public/index.php` e **mesmo** `config.php`. Tudo continua funcionando.

## Rollback

Se o deploy falhar, na Vercel: **Deployments → 3 pontos → Promote to Production** em uma versão anterior.

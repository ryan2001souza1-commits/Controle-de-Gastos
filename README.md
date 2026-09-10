# Controle de Gastos

Sistema simples de controle de gastos pessoais em PHP + PostgreSQL
(deploy serverless na Vercel).

## Requisitos

- PHP 8.1+
- PostgreSQL 12+ (local via Docker, ou gerenciado: Neon/Supabase/Vercel Postgres)
- Servidor web (Apache/Nginx), PHP built-in server ou Vercel

## Instalação

1. Clone o repositório
2. Configure o banco de dados em `src/config/config.php`
3. Execute o schema:
   ```bash
   psql "$DATABASE_URL" -f database/schema.sql
   ```
4. (Opcional) Execute os seeds:
   ```bash
   psql "$DATABASE_URL" -f database/seed.sql
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

## Pagamentos — gateway removido (reconstrucao futura)

Nenhuma integracao ativa com gateway de pagamento esta implementada. A
integracao anterior foi removida por completo para reconstrucao do zero;
restou apenas infraestrutura generica e inerte:

- `subscriptions.provider` / `provider_plan_id`: identificam o gateway e o
  plano do provedor sem acoplar o schema a um gateway especifico;
- `webhook_events`: ledger com `UNIQUE(provider, provider_event_id)` para
  impedir processamento duplicado de notificações;
- `src/services/BillingSyncService.php`: regra que converte status externo
  em estado interno (`usuarios.plano` / `plano_status` /
  `active_subscription_id`) dentro de transação;
- `src/services/WebhookLedger.php`: reserva idempotente de eventos com
  payload sanitizado (sem segredos).

Regras para a futura integracao (quando for reconstruida do zero):

- API do gateway sera **backend-only, via HTTP/cURL** (sem SDK);
- credenciais ficarao **somente em variaveis de ambiente**, nunca no
  frontend ou no repositorio;
- o **webhook sera a fonte de sincronizacao** (com validacao e consulta a
  API oficial quando necessario);
- o **frontend nunca ativa plano**: somente `active` libera plano pago;
  `pending`/`paused`/`expired` nao alteram nada sozinhos e
  `cancelled`/`rejected` rebaixam para gratuito.

Historico: as etapas `subscribe_start` (checkout) e webhook do gateway
anterior foram removidas por completo (codigo, rotas, SDK no frontend,
variaveis de ambiente e CSP). Colunas historicas inertes
(`mp_preapproval_id`, `external_reference`, `raw_status`, `checkout_url`)
foram preservadas no banco sem efeito funcional.

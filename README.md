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

## Pagamentos — preparação para Mercado Pago (futuro)

A infraestrutura mínima de cobrança já existe, mas **nenhuma integração
ativa com gateway** está implementada:

- `subscriptions.provider` / `provider_plan_id`: identificam o gateway e o
  plano do provedor sem acoplar o schema ao Mercado Pago;
- `webhook_events`: ledger com `UNIQUE(provider, provider_event_id)` para
  impedir processamento duplicado de notificações;
- `src/services/BillingSyncService.php`: regra que converte status externo
  em estado interno (`usuarios.plano` / `plano_status` /
  `active_subscription_id`) dentro de transação;
- `src/services/WebhookLedger.php`: reserva idempotente de eventos com
  payload sanitizado (sem segredos).

Regras da futura integração oficial:

- API Mercado Pago será **backend-only, via HTTP/cURL** (sem SDK);
- credenciais ficarão **somente em variáveis de ambiente**, nunca no
  frontend ou no repositório;
- o **webhook será a fonte de sincronização** (com validação e consulta à
  API oficial quando necessário);
- o **frontend nunca ativa plano**: somente `active` libera plano pago;
  `pending`/`paused`/`expired` não alteram nada sozinhos e
  `cancelled`/`rejected` rebaixam para gratuito.

Etapa `subscribe_start` (implementada, sem webhook ainda):

- `POST /index.php?action=subscribe_start` com `{plan: pro|premium}` + CSRF;
- backend valida sessão, catálogo e ambiente, registra tentativa `pending`
  e chama `POST https://api.mercadopago.com/preapproval` via
  `src/services/MercadoPagoClient.php` (cURL, sem SDK);
- credenciais somente no ambiente: `MERCADOPAGO_ACCESS_TOKEN`,
  `MERCADOPAGO_PLAN_ID_PRO`, `MERCADOPAGO_PLAN_ID_PREMIUM`
  (ver `.env.example`; sem elas, o endpoint retorna `mp_not_configured`);
- resposta `{success, checkout_url}` com a `init_point` oficial; o plano do
  usuário só mudará via webhook futuro (ainda não implementado).

Etapa webhook (implementada, sem credenciais reais):

- `POST /mercadopago_webhook.php`: endpoint público dedicado, sem
  login/sessão/CSRF; a autenticação é via `x-signature` (HMAC-SHA256,
  manifest oficial `id:...;request-id:...;ts:...;`) com segredo lido de
  `MERCADOPAGO_WEBHOOK_SECRET` (somente ambiente; sem ele, 500 controlado);
- ordem rígida: parse → valida assinatura (401 se inválida, sem tocar em
  banco/API) → reserva idempotente em `webhook_events` (`new`/`retry`/
  `duplicate`) → `GET /preapproval/{id}` como única fonte do status →
  vínculo somente via `mp_preapproval_id`/`external_reference` locais →
  sync transacional via `BillingSyncService::syncProviderStatus()`;
- status oficiais mapeados: `authorized→active`, `pending→pending`,
  `paused→paused`, `canceled→cancelled`; somente `active` libera plano pago;
- falha transitória marca `failed` (retry futuro retoma); evento concluído
  responde 2xx sem repetir efeitos; fora de ordem resolve pelo estado atual
  da API.

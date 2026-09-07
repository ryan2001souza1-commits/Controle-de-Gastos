<?php
/**
 * remove_legacy_payment_gateways.php
 *
 * Remove colunas, indices e tabelas legadas dos gateways de pagamento
 * Asaas e Mercado Pago que foram descontinuados.
 *
 * IMPORTANTE: este arquivo APENAS remove colunas/indíces já descontinuados
 * (operações DROP IF EXISTS = no-op onde já aplicado). A integração Mercado
 * Pago foi removida por completo do código; este arquivo NÃO toca em dados
 * de assinaturas existentes e NÃO deve ganhar novas operações destrutivas
 * sem revisão explícita.
 * A coluna subscriptions.mp_preapproval_id é adicionada pela migration
 * principal (src/migrations.php) e NAO deve ser removido por este arquivo:
 * ela é reutilizável pela futura integração oficial (correlação com o
 * preapproval do Mercado Pago) e preserva o histórico de tentativas.
 *
 * Executado automaticamente no boot do app (via runMigrations em migrations.php).
 * Idempotente: IF EXISTS em todos os DROP.
 */

function run_remove_legacy_payment_gateways(PDO $db): void
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    $statements = [
        // Asaas: colunas legadas da tabela subscriptions
        "ALTER TABLE subscriptions DROP COLUMN IF EXISTS mp_plan_id",
        "ALTER TABLE subscriptions DROP COLUMN IF EXISTS mp_payer_id",
        "ALTER TABLE subscriptions DROP COLUMN IF EXISTS asaas_customer_id",
        "ALTER TABLE subscriptions DROP COLUMN IF EXISTS asaas_subscription_id",
        "ALTER TABLE subscriptions DROP COLUMN IF EXISTS provider",
        "ALTER TABLE subscriptions DROP COLUMN IF EXISTS provider_status",

        // Asaas: colunas legadas da tabela usuarios
        "ALTER TABLE usuarios DROP COLUMN IF EXISTS mercadopago_payer_id",
        "ALTER TABLE usuarios DROP COLUMN IF EXISTS asaas_customer_id",

        // Asaas: indices legados
        "DROP INDEX IF EXISTS idx_subscriptions_asaas_subscription",
        "DROP INDEX IF EXISTS idx_usuarios_asaas_customer",
        "DROP INDEX IF EXISTS idx_usuarios_mp_payer",
        "DROP INDEX IF EXISTS idx_subscriptions_provider",

        // Asaas: tabela legada
        "DROP TABLE IF EXISTS payment_webhooks",
    ];

    foreach ($statements as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            error_log('[remove_legacy_payment_gateways] ' . $e->getMessage());
        }
    }
}

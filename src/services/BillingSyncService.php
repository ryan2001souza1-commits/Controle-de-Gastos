<?php
/**
 * BillingSyncService — regras neutras de sincronizacao de cobranca.
 *
 * Prepara a futura integracao oficial (ex: Mercado Pago) SEM implementar
 * nenhuma chamada de API, sem credenciais e sem webhook publico.
 *
 * Responsabilidades:
 *  - normalizar/validar provider e status de assinatura;
 *  - decidir, a partir do status EXTERNO, qual deve ser o estado INTERNO
 *    (usuarios.plano / plano_status / active_subscription_id);
 *  - garantir que o preco sempre venha do catalogo (nunca do frontend);
 *  - vincular operacoes sempre ao usuario da sessao (anti-IDOR);
 *  - aplicar a sincronizacao em transacao (rollback em qualquer falha).
 *
 * Regra central (compativel com o modelo atual):
 *  - active    -> ativa plano pago (somente slug pago valido);
 *  - cancelled / rejected -> rebaixa para gratuito/ativo;
 *  - pending / paused / expired -> NAO altera plano sozinho
 *    (evita ativar sem confirmacao ou rebaixar sem criterio).
 */
final class BillingSyncService
{
    public const PROVIDER_MERCADOPAGO = 'mercadopago';

    private const PROVIDERS = [
        self::PROVIDER_MERCADOPAGO,
    ];

    public const SUBSCRIPTION_PENDING   = 'pending';
    public const SUBSCRIPTION_ACTIVE    = 'active';
    public const SUBSCRIPTION_PAUSED    = 'paused';
    public const SUBSCRIPTION_CANCELLED = 'cancelled';
    public const SUBSCRIPTION_EXPIRED   = 'expired';
    public const SUBSCRIPTION_REJECTED  = 'rejected';

    private const SUBSCRIPTION_STATUSES = [
        self::SUBSCRIPTION_PENDING,
        self::SUBSCRIPTION_ACTIVE,
        self::SUBSCRIPTION_PAUSED,
        self::SUBSCRIPTION_CANCELLED,
        self::SUBSCRIPTION_EXPIRED,
        self::SUBSCRIPTION_REJECTED,
    ];

    /**
     * Normaliza o provedor. Retorna null quando desconhecido — nunca
     * persiste provider arbitrario vindo da rede.
     */
    public static function normalizeProvider(?string $provider): ?string
    {
        $p = strtolower(trim((string)($provider ?? '')));
        return in_array($p, self::PROVIDERS, true) ? $p : null;
    }

    /**
     * Normaliza o status da assinatura para o vocabulario interno.
     * Retorna null quando incompatível com o modelo atual.
     */
    public static function normalizeSubscriptionStatus(?string $status): ?string
    {
        $s = strtolower(trim((string)($status ?? '')));
        return in_array($s, self::SUBSCRIPTION_STATUSES, true) ? $s : null;
    }

    /**
     * Resolve qual atualizacao de plano um status de assinatura autoriza.
     *
     * @return array{change:bool, subscription_status:string, plano?:string,
     *     plano_status?:string, clear_active_subscription?:bool, reason?:string}
     *
     * @throws InvalidArgumentException em status/slug invalidos.
     */
    public static function resolvePlanUpdate(string $subscriptionStatus, string $planSlug): array
    {
        $status = self::normalizeSubscriptionStatus($subscriptionStatus);
        if ($status === null) {
            throw new InvalidArgumentException('Status de assinatura invalido.');
        }

        $slug = strtolower(trim($planSlug));
        if (!in_array($slug, PlanService::getValidSlugs(), true)) {
            throw new InvalidArgumentException('plan_slug invalido.');
        }

        if ($status === self::SUBSCRIPTION_ACTIVE) {
            if ($slug === PlanService::SLUG_FREE) {
                throw new InvalidArgumentException('Assinatura ativa exige plano pago.');
            }
            return [
                'change'                    => true,
                'subscription_status'       => $status,
                'plano'                     => $slug,
                'plano_status'              => PlanService::STATUS_ATIVO,
                'clear_active_subscription' => false,
            ];
        }

        if ($status === self::SUBSCRIPTION_CANCELLED || $status === self::SUBSCRIPTION_REJECTED) {
            // Rebaixamento seguro conforme regra atual do sistema: ao voltar
            // para 'gratuito', plano_status fica 'ativo'.
            return [
                'change'                    => true,
                'subscription_status'       => $status,
                'plano'                     => PlanService::SLUG_FREE,
                'plano_status'              => PlanService::STATUS_ATIVO,
                'clear_active_subscription' => true,
            ];
        }

        return [
            'change'              => false,
            'subscription_status' => $status,
            'reason'              => 'status nao ativa plano pago nem rebaixa automaticamente',
        ];
    }

    /**
     * Garante que a operacao atinja somente o usuario autenticado.
     * Nunca confia em user_id vindo do navegador sem conferir a sessao.
     *
     * @throws InvalidArgumentException quando nao conferem.
     */
    public static function authenticatedUserId(int $sessionUserId, int $targetUserId): int
    {
        if ($sessionUserId <= 0 || $targetUserId <= 0 || $sessionUserId !== $targetUserId) {
            throw new InvalidArgumentException('Usuario da operacao nao confere com a sessao autenticada.');
        }
        return $sessionUserId;
    }

    /**
     * Preco autoritativo: somente o catalogo decide. O frontend nunca
     * envia preco — esta funcao sequer recebe valor do navegador.
     *
     * @throws RuntimeException quando o catalogo nao tem preco definido.
     */
    public static function catalogPriceOrFail(?float $catalogPrice, string $planSlug): float
    {
        if ($catalogPrice === null) {
            throw new RuntimeException("Preco nao definido no catalogo para o plano '{$planSlug}'.");
        }
        return $catalogPrice;
    }

    /**
     * Constroi external_reference deterministica e segura:
     * user_{id}_{slug}_{attempt}, onde attempt e hex de 32 chars.
     *
     * @throws InvalidArgumentException em qualquer componente invalido.
     */
    public static function buildExternalReference(int $userId, string $planSlug, string $attemptToken): string
    {
        $slug = strtolower(trim($planSlug));
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id invalido para external_reference.');
        }
        if (!in_array($slug, PlanService::getValidSlugs(), true)) {
            throw new InvalidArgumentException('plan_slug invalido para external_reference.');
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $attemptToken)) {
            throw new InvalidArgumentException('attempt_token invalido para external_reference.');
        }
        return "user_{$userId}_{$slug}_{$attemptToken}";
    }

    /**
     * Remove segredos de payloads antes de persistir em ledger/logs.
     * Redacao recursiva por nome de chave (token, secret, card, etc.).
     */
    public static function sanitizePayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (is_string($k) && preg_match('/token|secret|passw|passwd|pwd|card|cvv|cvc|access|authorization|api[_-]?key|private/i', $k)) {
                $out[$k] = '[redacted]';
                continue;
            }
            $out[$k] = is_array($v) ? self::sanitizePayload($v) : $v;
        }
        return $out;
    }

    /**
     * Aplica a resolucao em transacao: atualiza usuarios + subscriptions
     * de forma atomica. Qualquer falha faz rollback total.
     *
     * - Resolucao com change=false nao toca no banco.
     * - Ativacao exige subscriptionId e recusa segunda assinatura ativa
     *   incompativel para o mesmo usuario.
     * - A linha de subscription so e atualizada se pertencer ao usuario
     *   (previne associacao cruzada e condicao de corrida simples).
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public static function applyPlanUpdate(PDO $db, int $userId, ?int $subscriptionId, array $resolution): void
    {
        if (($resolution['change'] ?? false) !== true) {
            return;
        }
        $status = (string)($resolution['subscription_status'] ?? '');
        $plan = (string)($resolution['plano'] ?? '');
        $planStatus = (string)($resolution['plano_status'] ?? '');
        if ($userId <= 0 || $status === '' || $plan === '') {
            throw new InvalidArgumentException('Parametros invalidos para sincronizacao de plano.');
        }
        if ($status === self::SUBSCRIPTION_ACTIVE && $subscriptionId === null) {
            throw new InvalidArgumentException('Ativacao exige o id da assinatura.');
        }

        self::transaction($db, function () use ($db, $userId, $subscriptionId, $status, $plan, $planStatus, $resolution) {
            self::coreApply($db, $userId, $subscriptionId, $status, $plan, $planStatus, !empty($resolution['clear_active_subscription']));
        });
    }

    /**
     * Nucleo da aplicacao (SEM gerenciar transacao): checagem de segunda
     * assinatura ativa + UPDATE usuarios + UPDATE subscriptions.
     * Usado por applyPlanUpdate() e por syncProviderStatus().
     */
    private static function coreApply(PDO $db, int $userId, ?int $subscriptionId, string $status, string $plan, string $planStatus, bool $clearActiveSubscription, bool $applyPlan = true): void
    {
        if (!$applyPlan) {
            return;
        }
        if ($status === self::SUBSCRIPTION_ACTIVE) {
            $chk = $db->prepare(
                "SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND id <> ?"
            );
            $chk->execute([$userId, $subscriptionId]);
            if (!empty($chk->fetchAll(PDO::FETCH_ASSOC))) {
                throw new RuntimeException('Ja existe outra assinatura ativa para este usuario.');
            }
        }

        $updUser = $db->prepare(
            "UPDATE usuarios SET plano = ?, plano_status = ?, active_subscription_id = ?, updated_at = NOW() WHERE id = ?"
        );
        $updUser->execute([$plan, $planStatus, $clearActiveSubscription ? null : $subscriptionId, $userId]);
        if ($updUser->rowCount() !== 1) {
            throw new RuntimeException('Usuario nao encontrado para sincronizacao de plano.');
        }

        if ($subscriptionId !== null) {
            $updSub = $db->prepare(
                "UPDATE subscriptions SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?"
            );
            $updSub->execute([$status, $subscriptionId, $userId]);
            if ($updSub->rowCount() !== 1) {
                throw new RuntimeException('Assinatura nao encontrada para este usuario.');
            }
        }
    }

    /**
     * Sincronizacao completa pos-consulta oficial (uso do webhook), em UMA
     * unica transacao (travamento + updates + ledger):
     *  1. trava a subscription (FOR UPDATE) e confirma usuario + provider;
     *  2. grava os identificadores oficiais (mp id, raw_status, checkout);
     *  3. aplica a regra de plano via nucleo compartilhado;
     *  4. marca o evento do ledger como processado.
     *
     * NUNCA abrir transacao antes de chamadas HTTP: este metodo so recebe
     * dados ja confirmados pela API oficial.
     *
     * @param array{id:int, user_id:int} $local Linha local previamente vinculada.
     * @param array{change:bool, subscription_status:string, plano:string, plano_status:string, clear_active_subscription:bool} $resolution Saida de resolvePlanUpdate().
     * @param array{mp_preapproval_id:string, raw_status:string, checkout_url:?string} $providerIds
     * @throws InvalidArgumentException|RuntimeException (rollback automatico)
     */
    public static function syncProviderStatus(PDO $db, array $local, array $resolution, array $providerIds, string $ledgerProvider, string $ledgerEventId): void
    {
        $subscriptionId = (int)($local['id'] ?? 0);
        $userId = (int)($local['user_id'] ?? 0);
        if ($subscriptionId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Subscription local invalida para sincronizacao.');
        }
        if (($resolution['change'] ?? false) !== true) {
            // Sem alteracao de plano (ex: pending/paused): ainda grava os
            // identificadores oficiais e marca o ledger, sem tocar no plano.
            $applyPlan = false;
        } else {
            $applyPlan = true;
        }
        $status = (string)($resolution['subscription_status'] ?? '');
        $plan = (string)($resolution['plano'] ?? '');
        $planStatus = (string)($resolution['plano_status'] ?? '');
        if ($status === '') {
            throw new InvalidArgumentException('Resolucao incompleta para sincronizacao.');
        }
        if ($applyPlan && $plan === '') {
            throw new InvalidArgumentException('Resolucao incompleta para sincronizacao.');
        }

        self::transaction($db, function () use ($db, $subscriptionId, $userId, $status, $plan, $planStatus, $resolution, $providerIds, $ledgerProvider, $ledgerEventId, $applyPlan) {
            $lock = $db->prepare(
                'SELECT id, user_id, plan_slug, provider FROM subscriptions WHERE id = ? FOR UPDATE'
            );
            $lock->execute([$subscriptionId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int)($row['user_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Assinatura nao encontrada para este usuario.');
            }
            if (($row['provider'] ?? '') !== self::PROVIDER_MERCADOPAGO) {
                throw new RuntimeException('Provider da subscription divergente.');
            }

            $updSub = $db->prepare(
                'UPDATE subscriptions SET mp_preapproval_id = ?, raw_status = ?, checkout_url = COALESCE(?, checkout_url), updated_at = NOW() WHERE id = ?'
            );
            $updSub->execute([
                substr((string)($providerIds['mp_preapproval_id'] ?? ''), 0, 80),
                substr((string)($providerIds['raw_status'] ?? ''), 0, 40),
                $providerIds['checkout_url'] ?? null,
                $subscriptionId,
            ]);
            if ($updSub->rowCount() !== 1) {
                throw new RuntimeException('Falha ao gravar identificadores oficiais.');
            }

            self::coreApply($db, $userId, $subscriptionId, $status, $plan, $planStatus, !empty($resolution['clear_active_subscription']), $applyPlan);

            WebhookLedger::markProcessed($db, $ledgerProvider, $ledgerEventId, $subscriptionId);
        });
    }

    /**
     * Executa $work em transacao PDO com rollback garantido em falha.
     */
    private static function transaction(PDO $db, callable $work): mixed
    {
        $db->beginTransaction();
        try {
            $result = $work();
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            try {
                $db->rollBack();
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
    }
}

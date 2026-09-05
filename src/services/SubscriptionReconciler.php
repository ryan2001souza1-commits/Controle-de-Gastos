<?php
/**
 * SubscriptionReconciler — fonte confiavel para reconciliar uma preapproval
 * do Mercado Pago com o estado local do banco.
 *
 * Usado por:
 *  - mercadopago_webhook.php (caminho normal, idempotente)
 *  - mercadopago_return.php (fallback seguro quando o webhook ainda nao chegou)
 *
 * NUNCA ativa um plano baseado apenas em parametros da URL.
 * A reconciliacao so ocorre quando TODAS as condicoes sao verdadeiras:
 *  1. A preapproval existe na API do MP (GET /preapproval/{id})
 *  2. O status do MP e "authorized" (assinatura paga e em cobranca)
 *  3. O preapproval_plan_id corresponde a um dos planos configurados no .env
 *  4. O external_reference da preapproval corresponde ao usuario informado
 *     (parametrizado pelo chamador: webhook usa o external_reference; return
 *     usa o usuario autenticado e valida o external_reference contra ele)
 *
 * Retorna sempre um array com 'ok', 'action', 'http_status' e detalhes.
 */
class SubscriptionReconciler
{
    private $db;
    private MercadoPagoService $mpService;
    private Plan $planModel;
    private Subscription $subscriptionModel;

    public function __construct($db, MercadoPagoService $mpService)
    {
        $this->db = $db;
        $this->mpService = $mpService;
        $this->planModel = new Plan($db);
        $this->subscriptionModel = new Subscription($db);
    }

    /**
     * Reconcilia uma preapproval a partir do MP, validando TODAS as condicoes.
     *
     * @param string $mpPreapprovalId       ID da assinatura no MP (regex validada)
     * @param int|null $expectedUserId      Quando fornecido, o external_reference
     *                                      precisa apontar para este usuario.
     *                                      Quando null, confia no external_reference.
     * @param bool $applyToUser             Quando true e a reconciliacao for OK,
     *                                      aplica o status ao usuario (grant access).
     * @return array{ok:bool, action:string, http_status:int, details?:array}
     */
    public function reconcile(
        string $mpPreapprovalId,
        ?int $expectedUserId = null,
        bool $applyToUser = true
    ): array {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $mpPreapprovalId)) {
            return ['ok' => false, 'action' => 'invalid_id', 'http_status' => 400];
        }

        $result = $this->mpService->getPreapproval($mpPreapprovalId);
        if ($result['ok'] === false) {
            $http = (int)($result['status'] ?? 0);
            if ($http === 404) {
                return ['ok' => false, 'action' => 'not_found', 'http_status' => 404];
            }
            if ($http === 0 || $http >= 500) {
                return ['ok' => false, 'action' => 'transient_error', 'http_status' => 503];
            }
            return ['ok' => false, 'action' => 'mp_error', 'http_status' => 502];
        }

        $data = $result['data'];
        $mpStatus = strtolower(trim((string)($data['status'] ?? '')));
        $mpPlanId = (string)($data['preapproval_plan_id'] ?? '');
        $externalRef = (string)($data['external_reference'] ?? '');
        $nextBillingDate = isset($data['next_payment_date']) ? (string)$data['next_payment_date'] : null;

        if ($mpStatus === '' || $mpPlanId === '' || $externalRef === '') {
            return [
                'ok' => false,
                'action' => 'incomplete_payload',
                'http_status' => 422,
                'details' => ['reason' => 'mp_missing_fields'],
            ];
        }

        if ($mpStatus !== 'authorized') {
            return [
                'ok' => false,
                'action' => 'not_authorized',
                'http_status' => 200,
                'details' => ['mp_status' => $mpStatus],
            ];
        }

        $parsed = MercadoPagoWebhookService::parseExternalReference($externalRef);
        if ($parsed === null) {
            return [
                'ok' => false,
                'action' => 'invalid_external_reference',
                'http_status' => 200,
                'details' => ['external_reference' => $externalRef],
            ];
        }
        [$refUserId, $refPlanSlug] = $parsed;

        if ($expectedUserId !== null && $expectedUserId !== $refUserId) {
            return [
                'ok' => false,
                'action' => 'user_mismatch',
                'http_status' => 200,
                'details' => [
                    'expected_user_id' => $expectedUserId,
                    'external_reference_user_id' => $refUserId,
                ],
            ];
        }

        $planFromMp = MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId($mpPlanId);
        if ($planFromMp === null) {
            return [
                'ok' => false,
                'action' => 'unknown_plan',
                'http_status' => 200,
                'details' => ['preapproval_plan_id' => $mpPlanId],
            ];
        }
        if ($planFromMp !== $refPlanSlug) {
            return [
                'ok' => false,
                'action' => 'plan_mismatch',
                'http_status' => 200,
                'details' => [
                    'plan_from_mp' => $planFromMp,
                    'plan_from_ref' => $refPlanSlug,
                ],
            ];
        }

        $planRow = $this->planModel->findBySlug($planFromMp);
        if ($planRow === null) {
            return ['ok' => false, 'action' => 'plan_not_in_db', 'http_status' => 200];
        }
        $planId = (int)$planRow['id'];

        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $refUserId]);
        if ($stmt->fetchColumn() === false) {
            return ['ok' => false, 'action' => 'user_not_found', 'http_status' => 200];
        }

        $internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatus);
        if ($internalStatus === null) {
            return ['ok' => false, 'action' => 'unmapped_status', 'http_status' => 200];
        }

        $existing = $this->subscriptionModel->findByMpId($mpPreapprovalId);
        $isNew = false;
        if ($existing === null) {
            $existingByRef = $this->subscriptionModel->findActiveOrPendingByUserAndPlan($refUserId, $planFromMp);
            if ($existingByRef !== null) {
                $this->subscriptionModel->updateMpData(
                    (int)$existingByRef['id'],
                    $mpPreapprovalId,
                    $mpStatus,
                    $nextBillingDate
                );
                $subscriptionId = (int)$existingByRef['id'];
            } else {
                $subscriptionId = $this->subscriptionModel->create([
                    'user_id'            => $refUserId,
                    'plan_id'            => $planId,
                    'plan_slug'          => $planFromMp,
                    'mp_preapproval_id'  => $mpPreapprovalId,
                    'status'             => $internalStatus,
                    'raw_status'         => $mpStatus,
                    'start_date'         => null,
                    'next_billing_date'  => $nextBillingDate,
                    'external_reference' => $externalRef,
                ]);
                $isNew = true;
            }
        } else {
            $subscriptionId = (int)$existing['id'];
            $this->subscriptionModel->updateMpData(
                $subscriptionId,
                $mpPreapprovalId,
                $mpStatus,
                $nextBillingDate
            );
        }

        $sub = $this->subscriptionModel->findById($subscriptionId);
        if ($sub === null) {
            return ['ok' => false, 'action' => 'subscription_disappeared', 'http_status' => 500];
        }

        $previousStatus = (string)$sub['status'];
        $this->subscriptionModel->updateStatusById(
            $subscriptionId,
            $internalStatus,
            $mpStatus,
            $nextBillingDate,
            null
        );

        if ($applyToUser && ($previousStatus !== $internalStatus || $isNew)) {
            $fresh = $this->subscriptionModel->findById($subscriptionId);
            if ($fresh !== null) {
                $this->subscriptionModel->applyStatusToUser($fresh);
            }
        }

        return [
            'ok' => true,
            'action' => $isNew ? 'created' : 'updated',
            'http_status' => 200,
            'details' => [
                'subscription_id' => $subscriptionId,
                'user_id'         => $refUserId,
                'plan_slug'       => $planFromMp,
                'status'          => $internalStatus,
            ],
        ];
    }

    /**
     * Reconciliação chamada pelo mercadopago_return.php.
     *
     * Fluxo: o usuario conclui o checkout e volta ao site com preapproval_id na URL.
     * O webhook pode ter chegado antes (e feito nada por falta de external_reference)
     * ou ainda nao ter chegado. Este metodo faz a reconciliacao segura.
     *
     * Seguranca三重:
     *  1. Consultar sempre a API do MP (fonte confiavel)
     *  2. Validar preapproval_plan_id contra .env
     *  3. Encontrar pending local por userId + plan_slug
     *  4. Rejeitar se o pending nao pertence ao usuario autenticado
     *
     * @param string $mpPreapprovalId  ID da preapproval na URL
     * @param int    $userId          ID do usuario autenticado (da sessao)
     * @return array{ok:bool, action:string, http_status:int, details?:array}
     */
    public function reconcileFromReturn(string $mpPreapprovalId, int $userId): array
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $mpPreapprovalId)) {
            return ['ok' => false, 'action' => 'invalid_id', 'http_status' => 400];
        }
        if ($userId <= 0) {
            return ['ok' => false, 'action' => 'invalid_user', 'http_status' => 400];
        }

        $result = $this->mpService->getPreapproval($mpPreapprovalId);
        if ($result['ok'] === false) {
            $http = (int)($result['status'] ?? 0);
            if ($http === 404) {
                return ['ok' => false, 'action' => 'not_found', 'http_status' => 404];
            }
            if ($http === 0 || $http >= 500) {
                return ['ok' => false, 'action' => 'transient_error', 'http_status' => 503];
            }
            return ['ok' => false, 'action' => 'mp_error', 'http_status' => 502];
        }

        $data = $result['data'];
        $mpStatus = strtolower(trim((string)($data['status'] ?? '')));
        $mpPlanId = (string)($data['preapproval_plan_id'] ?? '');
        $nextBillingDate = isset($data['next_payment_date']) ? (string)$data['next_payment_date'] : null;

        if ($mpStatus === '' || $mpPlanId === '') {
            return [
                'ok' => false,
                'action' => 'incomplete_payload',
                'http_status' => 422,
                'details' => ['reason' => 'mp_missing_fields'],
            ];
        }

        $planSlug = MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId($mpPlanId);
        if ($planSlug === null) {
            return [
                'ok' => false,
                'action' => 'unknown_plan',
                'http_status' => 200,
                'details' => ['preapproval_plan_id' => $mpPlanId],
            ];
        }

        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetchColumn() === false) {
            return ['ok' => false, 'action' => 'user_not_found', 'http_status' => 200];
        }

        $internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatus);
        if ($internalStatus === null) {
            return ['ok' => false, 'action' => 'unmapped_status', 'http_status' => 200];
        }

        if ($internalStatus !== 'active') {
            return [
                'ok' => false,
                'action' => 'not_authorized',
                'http_status' => 200,
                'details' => ['mp_status' => $mpStatus],
            ];
        }

        $existingByMpId = $this->subscriptionModel->findByMpId($mpPreapprovalId);
        if ($existingByMpId !== null) {
            $ownerUserId = (int)($existingByMpId['user_id'] ?? 0);
            if ($ownerUserId !== $userId) {
                return [
                    'ok' => false,
                    'action' => 'user_mismatch',
                    'http_status' => 200,
                    'details' => ['expected_user_id' => $userId, 'owner_user_id' => $ownerUserId],
                ];
            }
            $previousStatus = (string)$existingByMpId['status'];
            $this->subscriptionModel->updateMpData(
                (int)$existingByMpId['id'],
                $mpPreapprovalId,
                $mpStatus,
                $nextBillingDate
            );
            $this->subscriptionModel->updateStatusById(
                (int)$existingByMpId['id'],
                $internalStatus,
                $mpStatus,
                $nextBillingDate,
                null
            );
            if ($previousStatus !== $internalStatus) {
                $fresh = $this->subscriptionModel->findById((int)$existingByMpId['id']);
                if ($fresh !== null) {
                    $this->subscriptionModel->applyStatusToUser($fresh);
                }
            }
            return [
                'ok' => true,
                'action' => 'already_linked',
                'http_status' => 200,
                'details' => [
                    'subscription_id' => (int)$existingByMpId['id'],
                    'user_id' => $userId,
                    'plan_slug' => $planSlug,
                    'status' => $internalStatus,
                ],
            ];
        }

        $pending = $this->subscriptionModel->findActiveOrPendingByUserAndPlan($userId, $planSlug);
        if ($pending === null) {
            return [
                'ok' => false,
                'action' => 'no_pending_for_user',
                'http_status' => 200,
                'details' => [
                    'user_id' => $userId,
                    'plan_slug' => $planSlug,
                    'mp_preapproval_id' => $mpPreapprovalId,
                ],
            ];
        }
        $previousStatus = (string)$pending['status'];
        $subscriptionId = (int)$pending['id'];
        $this->subscriptionModel->attachMpPreapprovalId($subscriptionId, $mpPreapprovalId);
        $this->subscriptionModel->updateMpData($subscriptionId, $mpPreapprovalId, $mpStatus, $nextBillingDate);

        $this->subscriptionModel->updateStatusById(
            $subscriptionId,
            $internalStatus,
            $mpStatus,
            $nextBillingDate,
            null
        );

        if ($previousStatus !== $internalStatus) {
            $fresh = $this->subscriptionModel->findById($subscriptionId);
            if ($fresh !== null) {
                $this->subscriptionModel->applyStatusToUser($fresh);
            }
        }

        return [
            'ok' => true,
            'action' => $previousStatus === '' ? 'created' : ($previousStatus !== $internalStatus ? 'activated' : 'updated'),
            'http_status' => 200,
            'details' => [
                'subscription_id' => $subscriptionId,
                'user_id'       => $userId,
                'plan_slug'     => $planSlug,
                'status'        => $internalStatus,
            ],
        ];
    }
}

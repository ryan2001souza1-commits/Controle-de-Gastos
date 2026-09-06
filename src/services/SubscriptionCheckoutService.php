<?php
/**
 * SubscriptionCheckoutService — fluxo tokenizado de assinatura (CardForm + POST).
 *
 * Responsabilidade: transformar (user_id da sessao, attempt_token, card_token_id)
 * em assinatura vinculada, de forma idempotente e com correcao deterministica.
 *
 * Identidade: attempt_token UUID (linha local) -> user_id + plan_slug lidos
 * DA LINHA. external_reference enviado ao MP e o proprio attempt_token; o MP
 * o ecoa no GET/webhook, fechando o ciclo sem heuristicas.
 *
 * Fases instrumentadas (para diagnostico seguro em logs):
 *   validate_input, begin, lock_attempt, reconcile_linked, reconcile_search,
 *   derive_server_data, mp_create, resolve_timeout, guard_mpid, persist_link,
 *   commit, done
 *
 * Em erro inesperado retorna http=500 com 'debug' sanitizado
 * (Subscription::describeDbError) em vez de lancar excecao.
 * Nunca expoe card_token_id, tokens, segredos ou dados do cartao.
 */
class SubscriptionCheckoutService
{
    private $db;
    private MercadoPagoService $mpService;
    private $userModel;
    private Subscription $subscriptionModel;

    public function __construct($db, MercadoPagoService $mpService, $userModel)
    {
        $this->db = $db;
        $this->mpService = $mpService;
        $this->userModel = $userModel;
        $this->subscriptionModel = new Subscription($db);
    }

    /**
     * @return array{http:int, body:array, phase:string, debug?:array}
     */
    public function processTokenPayment(int $userId, string $attemptToken, string $cardTokenId): array
    {
        $phase = 'validate_input';
        try {
            if ($userId <= 0) {
                return $this->out(401, ['ok' => false, 'error' => 'unauthorized'], $phase);
            }
            $attemptToken = strtolower(trim($attemptToken));
            if (!Subscription::isAttemptToken($attemptToken)) {
                return $this->out(400, ['ok' => false, 'error' => 'invalid_attempt'], $phase);
            }
            if ($cardTokenId === '') {
                return $this->out(400, ['ok' => false, 'error' => 'invalid_card_token'], $phase);
            }

            $phase = 'begin';
            $this->db->beginTransaction();

            $phase = 'lock_attempt';
            $attempt = $this->subscriptionModel->findByAttemptTokenForUpdate($attemptToken);
            if ($attempt === null || (int)($attempt['user_id'] ?? 0) !== $userId) {
                $this->db->rollBack();
                return $this->out(404, ['ok' => false, 'error' => 'attempt_not_found'], $phase);
            }
            $attemptId = (int)$attempt['id'];
            $slug = (string)($attempt['plan_slug'] ?? '');
            if (!in_array($slug, ['pro', 'premium'], true)) {
                $this->db->rollBack();
                return $this->out(400, ['ok' => false, 'error' => 'invalid_plan'], $phase);
            }

            $phase = 'reconcile_linked';
            $existingMpId = (string)($attempt['mp_preapproval_id'] ?? '');
            if ($existingMpId !== '') {
                $this->db->rollBack();
                $check = $this->mpService->getPreapproval($existingMpId);
                $mpStatus = 'unknown';
                if ($check['ok'] === true && is_array($check['data'])) {
                    $mpStatus = strtolower((string)($check['data']['status'] ?? 'unknown'));
                }
                return $this->out(200, [
                    'ok' => true,
                    'already' => true,
                    'status' => $mpStatus,
                    'redirect' => '/index.php?action=meu_plano&subscribed=1',
                ], $phase);
            }

            // Reconciliacao antes de POST: se o MP ja possui preapproval com
            // este exact external_reference (ex.: timeout pos-criacao anterior
            // ou webhook ainda nao processado), vincula em vez de duplicar.
            $phase = 'reconcile_search';
            $found = $this->findOwnPreapproval($attempt);
            if ($found === 'conflict') {
                $this->db->rollBack();
                error_log('[subscribe_token] phase=reconcile_search multiplos registros MP para o attempt');
                return $this->out(409, ['ok' => false, 'error' => 'conflict'], $phase);
            }
            if (is_array($found)) {
                $body = $this->linkAttempt($attemptId, $found['mp_id'], $found['mp_status']);
                $body['reconciled'] = true;
                return $this->out(200, $body, $phase);
            }

            $phase = 'derive_server_data';
            $mpPlanId = MercadoPagoService::getPlanIdForSlug($slug);
            $userRow = $this->userModel->findById($userId);
            $email = is_array($userRow)
                ? (string)($userRow['email'] ?? '')
                : (string)($userRow->email ?? '');
            if ($mpPlanId === null || $mpPlanId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->db->rollBack();
                return $this->out(400, ['ok' => false, 'error' => 'invalid_plan'], $phase);
            }
            $backUrl = rtrim((string)(getenv('APP_URL') ?: 'https://controle-de-gastos-one-silk.vercel.app'), '/')
                . '/mercadopago_return.php';

            $phase = 'mp_create';
            $result = $this->mpService->createPreapproval(
                $mpPlanId,
                $email,
                $attemptToken,
                $backUrl,
                $cardTokenId,
                $attemptToken
            );
            if ($result['ok'] === false) {
                // Timeout/rede apos possivel criacao no MP: tenta resolver
                // pelo registro exato antes de desistir (sem novo POST).
                if (($result['error'] ?? '') === 'network_error') {
                    $phase = 'resolve_timeout';
                    $foundAfter = $this->findOwnPreapproval($attempt);
                    if (is_array($foundAfter)) {
                        $body = $this->linkAttempt($attemptId, $foundAfter['mp_id'], $foundAfter['mp_status']);
                        $body['reconciled'] = true;
                        return $this->out(200, $body, $phase);
                    }
                    if ($foundAfter === 'conflict') {
                        $this->db->rollBack();
                        return $this->out(409, ['ok' => false, 'error' => 'conflict'], $phase);
                    }
                }
                $this->db->rollBack();
                $userCode = MercadoPagoService::mapErrorToUserCode($result);
                $httpCode = match ($userCode) {
                    'processing', 'service_error' => 502,
                    'card_declined' => 402,
                    default => 400,
                };
                return $this->out($httpCode, ['ok' => false, 'error' => $userCode], $phase);
            }

            $phase = 'persist_link';
            $mpPreapprovalId = (string)$result['preapproval_id'];
            $body = $this->linkAttempt(
                $attemptId,
                $mpPreapprovalId,
                strtolower(trim((string)($result['mp_status'] ?? 'authorized')))
            );
            return $this->out(200, $body, $phase);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable $t) {
                    // rollback best-effort; o erro original prevalece
                }
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'mp_conflict') {
                error_log('[subscribe_token] phase=' . $phase . ' mp_preapproval_id ja vinculado a outra assinatura');
                return $this->out(409, ['ok' => false, 'error' => 'conflict'], $phase);
            }
            return [
                'http' => 500,
                'body' => ['ok' => false, 'error' => 'internal_error'],
                'phase' => $phase,
                'debug' => Subscription::describeDbError($e),
            ];
        }
    }

    /**
     * Procura no MP preapproval com external_reference EXATO desta tentativa.
     *
     * @return array{mp_id:string,mp_status:string}|'conflict'|null
     *   array  = exatamente um registro compativel (vincular, sem POST);
     *   'conflict' = multiplos registros (fail closed, visao humana);
     *   null   = nenhum (seguir para POST) ou busca falhou (fail-open).
     */
    private function findOwnPreapproval(array $attempt): array|string|null
    {
        $ext = (string)($attempt['attempt_token'] ?? '');
        $planSlug = (string)($attempt['plan_slug'] ?? '');
        try {
            $search = $this->mpService->searchPreapprovalsByExternalReference($ext);
        } catch (Throwable $t) {
            return null;
        }
        if (($search['ok'] ?? false) !== true) {
            return null;
        }
        $matches = $search['matches'] ?? [];
        if (!is_array($matches) || count($matches) === 0) {
            return null;
        }
        if (count($matches) > 1) {
            return 'conflict';
        }
        $m = $matches[0];
        $planFromMp = MercadoPagoWebhookService::resolvePlanSlugFromMpPlanId(
            (string)($m['preapproval_plan_id'] ?? '')
        );
        if ($planFromMp === null || $planFromMp !== $planSlug) {
            return 'conflict';
        }
        return [
            'mp_id' => (string)$m['id'],
            'mp_status' => strtolower(trim((string)($m['status'] ?? ''))),
        ];
    }

    /**
     * Vincula mp_id a tentativa (com guardas), atualiza status, aplica plano,
     * faz commit. Lanca RuntimeException('mp_conflict') em conflito.
     *
     * @return array corpo de resposta de sucesso
     */
    private function linkAttempt(int $attemptId, string $mpPreapprovalId, string $mpStatusRaw): array
    {
        $other = $this->subscriptionModel->findByMpId($mpPreapprovalId);
        if ($other !== null && (int)$other['id'] !== $attemptId) {
            throw new RuntimeException('mp_conflict');
        }
        $this->subscriptionModel->attachMpPreapprovalId($attemptId, $mpPreapprovalId);
        $internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatusRaw);
        if ($internalStatus === null) {
            $internalStatus = Subscription::STATUS_PENDING;
        }
        $this->subscriptionModel->updateStatusById($attemptId, $internalStatus, $mpStatusRaw, null, null);
        if ($internalStatus === Subscription::STATUS_ACTIVE) {
            $fresh = $this->subscriptionModel->findById($attemptId);
            if ($fresh !== null) {
                $this->subscriptionModel->applyStatusToUser($fresh);
            }
        }
        $this->db->commit();
        return [
            'ok' => true,
            'status' => $internalStatus,
            'redirect' => '/index.php?action=meu_plano&subscribed=1',
        ];
    }

    private function out(int $http, array $body, string $phase): array
    {
        return ['http' => $http, 'body' => $body, 'phase' => $phase];
    }
}

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
 * REGRA ESTRUTURAL: NENHUMA chamada HTTP ao Mercado Pago ocorre dentro de
 * transacao SQL aberta. Transacoes sao curtas (re-ler, verificar, anexar,
 * commitar — milissegundos) e nunca esperam rede.
 *
 * Ordem: validate -> lookup (leitura) -> reconcile_search (rede, sem txn) ->
 * derive (leituras) -> mp_create (rede, sem txn) -> txn curta atomica.
 *
 * Fases instrumentadas (para diagnostico seguro em logs):
 *   pre_validation, attempt_lookup, reconcile_linked, reconcile_search,
 *   derive_server_data, mp_create, resolve_timeout, txn_begin, txn_lock,
 *   txn_attach, txn_status, plan_apply, txn_commit, done
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
    private string $currentPhase = 'init';
    /** @var list<string> trilha phase:txstate para diagnostico pos-falha */
    private array $trail = [];

    private function setPhase(string $phase): string
    {
        $this->currentPhase = $phase;
        $tx = 'unknown';
        try {
            $tx = $this->db->inTransaction() ? 'yes' : 'no';
        } catch (Throwable $ignored) {
        }
        $this->trail[] = $phase . ':' . $tx;
        if (count($this->trail) > 40) {
            array_shift($this->trail);
        }
        return $phase;
    }

    /**
     * Garante ausencia de transacao residual antes de trecho critico.
     * Retorna true se precisou reverter (logado pelo chamador).
     */
    private function ensureNoOpenTransaction(string $where): bool
    {
        try {
            if ($this->db->inTransaction()) {
                error_log('[subscribe_token] phase=' . $where . ' invariant_violation unexpected_open_transaction');
                try {
                    $this->db->rollBack();
                } catch (Throwable $ignored) {
                }
                return true;
            }
        } catch (Throwable $ignored) {
        }
        return false;
    }

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
        $this->setPhase('pre_validation');
        try {
            if ($userId <= 0) {
                return $this->out(401, ['ok' => false, 'error' => 'unauthorized'], $this->currentPhase);
            }
            $attemptToken = strtolower(trim($attemptToken));
            if (!Subscription::isAttemptToken($attemptToken)) {
                return $this->out(400, ['ok' => false, 'error' => 'invalid_attempt'], $this->currentPhase);
            }
            if ($cardTokenId === '') {
                return $this->out(400, ['ok' => false, 'error' => 'invalid_card_token'], $this->currentPhase);
            }

            // Guarda de entrada (Fase 6): se a conexao chegou aqui com
            // transacao aberta sem o fluxo ter aberto, algo anterior
            // (boot/middleware) vazou estado — reverte com evidencia.
            $this->ensureNoOpenTransaction('entry');
            // Leitura SEM transacao: nenhuma trava e mantida durante rede.
            $this->setPhase('attempt_lookup');
            $attempt = $this->subscriptionModel->findByAttemptToken($attemptToken);
            if ($attempt === null || (int)($attempt['user_id'] ?? 0) !== $userId) {
                return $this->out(404, ['ok' => false, 'error' => 'attempt_not_found'], $this->currentPhase);
            }
            $attemptId = (int)$attempt['id'];
            $slug = (string)($attempt['plan_slug'] ?? '');
            if (!in_array($slug, ['pro', 'premium'], true)) {
                return $this->out(400, ['ok' => false, 'error' => 'invalid_plan'], $this->currentPhase);
            }

            // Idempotencia: tentativa ja vinculada — reconcilia por leitura,
            // sem transacao e sem novo POST ao MP.
            $this->setPhase('reconcile_linked');
            $existingMpId = (string)($attempt['mp_preapproval_id'] ?? '');
            if ($existingMpId !== '') {
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
                ], $this->currentPhase);
            }

            // Reconciliacao (rede, SEM transacao): se o MP ja possui preapproval
            // com este exact external_reference, vincula em vez de duplicar.
            // Invariante: reconcile_search (rede) NUNCA roda com transacao
            // aberta. Se aberta, registra, reverte e NAO segue silencioso.
            $this->ensureNoOpenTransaction('reconcile_search');
            $this->setPhase('reconcile_search');
            $found = $this->findOwnPreapproval($attempt);
            if ($found === 'conflict') {
                error_log('[subscribe_token] phase=reconcile_search search_inconclusivo_para_attempt');
                return $this->out(409, ['ok' => false, 'error' => 'conflict'], $this->currentPhase);
            }
            if (is_array($found)) {
                $body = $this->linkAttempt($attemptId, $userId, $slug, $attemptToken, $found['mp_id'], $found['mp_status']);
                $body['reconciled'] = true;
                return $this->out(200, $body, 'txn_commit');
            }

            // Tudo derivado do servidor (leituras, sem transacao).
            $this->setPhase('derive_server_data');
            $mpPlanId = MercadoPagoService::getPlanIdForSlug($slug);
            $userRow = $this->userModel->findById($userId);
            $email = is_array($userRow)
                ? (string)($userRow['email'] ?? '')
                : (string)($userRow->email ?? '');
            if ($mpPlanId === null || $mpPlanId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->out(400, ['ok' => false, 'error' => 'invalid_plan'], $this->currentPhase);
            }
            $backUrl = rtrim((string)(getenv('APP_URL') ?: 'https://controle-de-gastos-one-silk.vercel.app'), '/')
                . '/mercadopago_return.php';

            // POST ao MP com idempotency key = attempt (SEM transacao aberta).
            // Retry do mesmo attempt reenvia a MESMA chave: o MP deduplica.
            $this->setPhase('mp_create');
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
                    $this->setPhase('resolve_timeout');
                    $foundAfter = $this->findOwnPreapproval($attempt);
                    if (is_array($foundAfter)) {
                        $body = $this->linkAttempt($attemptId, $userId, $slug, $attemptToken, $foundAfter['mp_id'], $foundAfter['mp_status']);
                        $body['reconciled'] = true;
                        return $this->out(200, $body, 'txn_commit');
                    }
                    if ($foundAfter === 'conflict') {
                        return $this->out(409, ['ok' => false, 'error' => 'conflict'], $this->currentPhase);
                    }
                }
                $userCode = MercadoPagoService::mapErrorToUserCode($result);
                $httpCode = match ($userCode) {
                    'processing', 'service_error' => 502,
                    'card_declined' => 402,
                    default => 400,
                };
                return $this->out($httpCode, ['ok' => false, 'error' => $userCode], $this->currentPhase);
            }

            $this->setPhase('persist_link');
            $mpPreapprovalId = (string)$result['preapproval_id'];
            $body = $this->linkAttempt(
                $attemptId,
                $userId,
                $slug,
                $attemptToken,
                $mpPreapprovalId,
                strtolower(trim((string)($result['mp_status'] ?? 'authorized')))
            );
            return $this->out(200, $body, 'txn_commit');
        } catch (Throwable $e) {
            // Rollback IMEDIATO: nenhuma query pode rodar depois de excecao
            // dentro de transacao PostgreSQL (vira 25P02 em cascata).
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable $t) {
                    // rollback best-effort; o erro original prevalece
                }
            }
            $failedPhase = $this->currentPhase;
            if ($e instanceof RuntimeException && $e->getMessage() === 'mp_conflict') {
                error_log('[subscribe_token] phase=' . $failedPhase . ' mp_preapproval_id ja vinculado a outra assinatura');
                return $this->out(409, ['ok' => false, 'error' => 'conflict'], $failedPhase);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'attempt_changed') {
                return $this->out(404, ['ok' => false, 'error' => 'attempt_not_found'], $failedPhase);
            }
            $debug = Subscription::describeDbError($e);
            $debug['trail'] = implode('>', $this->trail);
            return [
                'http' => 500,
                'body' => ['ok' => false, 'error' => 'internal_error'],
                'phase' => $failedPhase,
                'debug' => $debug,
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
     * Vincula mp_id a tentativa em transacao CURTA e deterministica.
     *
     * NENHUMA rede acontece aqui dentro: re-le a linha com trava, revalida
     * ownership + estado + idempotencia, anexa, atualiza, aplica plano e faz
     * commit em milissegundos. Lanca RuntimeException('mp_conflict') em
     * conflito (fail closed, sem persistir nada).
     *
     * @return array corpo de resposta de sucesso
     */
    private function linkAttempt(
        int $attemptId,
        int $userId,
        string $planSlug,
        string $attemptToken,
        string $mpPreapprovalId,
        string $mpStatusRaw
    ): array {
        // Invariante: entrar aqui SEM transacao aberta. Se houver residual
        // (ex.: boot/migration deixou txn abortada), registra e limpa em vez
        // de contaminar este vinculo — fail-closed com evidencia.
        $this->ensureNoOpenTransaction('txn_preflight');
        $this->setPhase('txn_begin');
        $this->db->beginTransaction();
        try {
            // Roundtrip de verificacao: prova que o begin abriu transacao
            // valida no SERVIDOR (detecta pooler/conexao que mente no begin).
            $this->setPhase('txn_begin_verify');
            $this->db->query('SELECT 1');
            // Tomada atomica PRIMEIRO (ownership+plano+mp-vazio no WHERE):
            // um unico statement decide o vencedor. Nenhum SELECT previo,
            // nenhuma trava mantida, nenhuma janela para 25P02 em cascata.
            $this->setPhase('txn_claim');
            $claimed = $this->subscriptionModel->claimMpPreapprovalId(
                $attemptId,
                $userId,
                $planSlug,
                $mpPreapprovalId
            );
            if ($claimed === null) {
                // Derrota ou linha ja vinculada: UMA releitura decide.
                $this->setPhase('txn_claim_lost');
                $reread = $this->subscriptionModel->findByAttemptToken($attemptToken);
                if ($reread === null || (int)$reread['id'] !== $attemptId || (int)($reread['user_id'] ?? 0) !== $userId) {
                    throw new RuntimeException('attempt_changed');
                }
                if ((string)($reread['plan_slug'] ?? '') !== $planSlug) {
                    throw new RuntimeException('attempt_changed');
                }
                $winnerMpId = (string)($reread['mp_preapproval_id'] ?? '');
                if ($winnerMpId !== '' && $winnerMpId !== $mpPreapprovalId) {
                    throw new RuntimeException('mp_conflict');
                }
                if ($winnerMpId === $mpPreapprovalId && $winnerMpId !== '') {
                    $this->setPhase('txn_commit');
                    $this->db->commit();
                    return [
                        'ok' => true,
                        'already' => true,
                        'status' => (string)($reread['status'] ?? 'pending'),
                        'redirect' => '/index.php?action=meu_plano&subscribed=1',
                    ];
                }
                throw new RuntimeException('claim_failed');
            }
            // Vitoria: mp gravado pelo claim. Guarda cross-account residual
            // (outra linha com mesmo mp id) antes de ativar.
            $this->setPhase('txn_guard_find');
            $other = $this->subscriptionModel->findByMpId($mpPreapprovalId);
            if ($other !== null && (int)$other['id'] !== $attemptId) {
                throw new RuntimeException('mp_conflict');
            }
            $internalStatus = MercadoPagoWebhookService::mapMpStatusToInternal($mpStatusRaw);
            if ($internalStatus === null) {
                $internalStatus = Subscription::STATUS_PENDING;
            }
            $this->setPhase('txn_status');
            $this->subscriptionModel->updateStatusById($attemptId, $internalStatus, $mpStatusRaw, null, null);
            if ($internalStatus === Subscription::STATUS_ACTIVE) {
                $this->setPhase('plan_apply');
                $applied = $this->subscriptionModel->findById($attemptId);
                if ($applied !== null) {
                    $this->subscriptionModel->applyStatusToUser($applied);
                }
            }
            $this->setPhase('txn_commit');
            $this->db->commit();
            return [
                'ok' => true,
                'status' => $internalStatus,
                'redirect' => '/index.php?action=meu_plano&subscribed=1',
            ];
        } catch (Throwable $t) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable $ignored) {
                }
            }
            throw $t;
        }
    }

    private function out(int $http, array $body, string $phase): array
    {
        return ['http' => $http, 'body' => $body, 'phase' => $phase];
    }
}

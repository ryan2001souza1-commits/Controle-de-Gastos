<?php
/**
 * AsaasService — cliente HTTP da API do Asaas (v3).
 *
 * Seguranca:
 *   - Access Token lido APENAS de getenv() (NUNCA do codigo, banco ou sessao)
 *   - Webhook Token (ASAAS_WEBHOOK_TOKEN) lido APENAS de getenv()
 *   - Dados de cartao sao transmitidos ao Asaas via HTTPS e DESCARTADOS
 *     imediatamente apos a chamada. Nunca sao logados, persistidos ou
 *     incluidos em exceptions.
 *   - Logs sanitizados: nunca contem access_token, cartao, CVV, CPF completo,
 *     payload bruto, ASAAS_WEBHOOK_TOKEN, Authorization header.
 *
 * Documentacao: https://docs.asaas.com
 */
class AsaasService
{
    private const API_BASE_SANDBOX    = 'https://api-sandbox.asaas.com/v3';
    private const API_BASE_PRODUCTION  = 'https://api.asaas.com/v3';
    private const TIMEOUT = 60;

    private string $accessToken;
    private string $mode;
    private string $baseUrl;

    public function __construct()
    {
        $token = (string)(getenv('ASAAS_API_KEY') ?: '');
        if ($token === '') {
            throw new RuntimeException('ASAAS_API_KEY nao configurado');
        }
        $this->accessToken = $token;
        $this->mode = strtolower((string)(getenv('ASAAS_ENV') ?: 'sandbox'));
        $this->baseUrl = ($this->mode === 'production')
            ? self::API_BASE_PRODUCTION
            : self::API_BASE_SANDBOX;
    }

    public static function isConfigured(): bool
    {
        $t = getenv('ASAAS_API_KEY');
        return is_string($t) && $t !== '';
    }

    public static function isSandbox(): bool
    {
        return strtolower((string)(getenv('ASAAS_ENV') ?: 'sandbox')) !== 'production';
    }

    /**
     * Mascara os ultimos 4 digitos de um numero de cartao para log.
     * Nunca loga o numero completo.
     */
    public static function maskCard(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        if (strlen($digits) < 4) {
            return '****';
        }
        $last4 = substr($digits, -4);
        return '****' . $last4;
    }

    /**
     * Extrai um IP real do cliente a partir dos headers HTTP.
     * Valida contra valores obviously invalidos para evitar IP spoofing.
     * Prioriza headers de proxy confiavel (Cloudflare).
     */
    public static function getRealClientIp(): string
    {
        $invalid = [
            '127.0.0.1', '::1', '0.0.0.0',
            '255.255.255.255', '224.0.0.1',
        ];

        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $ip) {
            if ($ip === null || $ip === '') continue;
            $ip = trim(explode(',', $ip)[0]);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
            if (in_array($ip, $invalid, true)) continue;
            return $ip;
        }

        return '0.0.0.0';
    }

    /**
     * Faz uma requisicao autenticada para a API do Asaas.
     *
     * @param string $method GET|POST|PUT|DELETE
     * @param string $path   ex: /customers ou /subscriptions
     * @param array|null $body Dados a serem enviados como JSON (NAO incluir dados de cartao aqui — use createSubscription com parametro separado)
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ControleDeGastos/1.0',
            'access_token: ' . $this->accessToken,
        ];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[Asaas] curl error: ' . $err);
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'conexao'];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'status' => $httpCode, 'data' => $data, 'error' => null];
        }

        $errCode = isset($data['code']) ? (string)$data['code'] : null;
        $errMsg  = isset($data['message']) ? (string)$data['message'] : ('http ' . $httpCode);
        if (isset($data['errors']) && is_array($data['errors'])) {
            $parts = [];
            foreach ($data['errors'] as $e) {
                if (is_array($e) && isset($e['description'])) {
                    $parts[] = substr((string)$e['description'], 0, 120);
                }
            }
            if ($parts !== []) {
                $errMsg .= ' [' . implode('; ', $parts) . ']';
            }
        }

        $safePath = $path;
        if (strlen($safePath) > 60) {
            $safePath = substr($safePath, 0, 57) . '...';
        }
        error_log('[Asaas] api error: method=' . $method
            . ' path=' . $safePath
            . ' http=' . $httpCode
            . ' code=' . ($errCode ?? '')
            . ' msg=' . substr($errMsg, 0, 300)
        );

        return ['ok' => false, 'status' => $httpCode, 'data' => $data, 'error' => $errMsg];
    }

    /**
     * Cria um cliente no Asaas vinculado ao usuario local.
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function createCustomer(string $name, string $email, string $cpf, ?string $externalReference = null): array
    {
        $body = [
            'name' => $name,
            'email' => $email,
            'cpfCnpj' => $cpf,
            'notificationDisabled' => true,
        ];
        if ($externalReference !== null) {
            $body['externalReference'] = $externalReference;
        }
        return $this->request('POST', '/customers', $body);
    }

    /**
     * Lista clientes pelo email. Retorna o primeiro resultado ou null.
     */
    public function findCustomerByEmail(string $email): ?array
    {
        $resp = $this->request('GET', '/customers?email=' . rawurlencode($email) . '&limit=1');
        if (!$resp['ok']) return null;
        $data = $resp['data'] ?? [];
        $results = $data['data'] ?? [];
        if (!is_array($results) || count($results) === 0) return null;
        return is_array($results[0]) ? $results[0] : null;
    }

    /**
     * Recupera um cliente pelo ID.
     */
    public function getCustomer(string $customerId): ?array
    {
        $resp = $this->request('GET', '/customers/' . rawurlencode($customerId));
        if (!$resp['ok']) return null;
        return $resp['data'] ?? null;
    }

    /**
     * Cria ou reutiliza um cliente Asaas para o usuario local.
     * Se o usuario ja tem asaas_customer_id salvo, reutiliza.
     * Senao, busca por email e cria se necessario.
     *
     * @return array{ok:bool, customerId:?string, created:bool, error:?string}
     */
    public function findOrCreateCustomer(
        PDO $db,
        int $userId,
        string $name,
        string $email,
        string $cpf
    ): array {
        $stmt = $db->prepare('SELECT asaas_customer_id FROM usuarios WHERE id = ?');
        $stmt->execute([$userId]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false && $existing !== null && $existing !== '') {
            $customer = $this->getCustomer((string)$existing);
            if ($customer !== null) {
                return ['ok' => true, 'customerId' => (string)$existing, 'created' => false, 'error' => null];
            }
        }

        $found = $this->findCustomerByEmail($email);
        if ($found !== null) {
            $cid = (string)($found['id'] ?? '');
            if ($cid !== '') {
                $upd = $db->prepare('UPDATE usuarios SET asaas_customer_id = ? WHERE id = ?');
                $upd->execute([$cid, $userId]);
                return ['ok' => true, 'customerId' => $cid, 'created' => false, 'error' => null];
            }
        }

        $extRef = 'user_' . $userId;
        $resp = $this->createCustomer($name, $email, $cpf, $extRef);

        if (!$resp['ok']) {
            return ['ok' => false, 'customerId' => null, 'created' => false, 'error' => $resp['error']];
        }

        $cid = (string)($resp['data']['id'] ?? '');
        if ($cid === '') {
            return ['ok' => false, 'customerId' => null, 'created' => false, 'error' => 'no_customer_id'];
        }

        $upd = $db->prepare('UPDATE usuarios SET asaas_customer_id = ? WHERE id = ?');
        $upd->execute([$cid, $userId]);

        return ['ok' => true, 'customerId' => $cid, 'created' => true, 'error' => null];
    }

    /**
     * Cria uma assinatura no Asaas.
     *
     * DADOS DE CARTAO: este metodo aceita $cardData que pode conter
     * informacoes de cartao. Estas informacoes sao transmitidas ao Asaas
     * via HTTPS e DESCARTADAS imediatamente apos a chamada.
     * NENHUM dado de cartao e logado, persistido ou incluido em exceptions.
     *
     * @param array $cardData Campos opcionais de cartao:
     *   - holderName: string
     *   - number: string (numero do cartao)
     *   - expiryMonth: string (MM)
     *   - expiryYear: string (AAAA)
     *   - ccv: string
     *   - holderInfo: array (name, email, cpfCnpj, postalCode, addressNumber, phone, mobilePhone)
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function createSubscription(
        string $customerId,
        string $planSlug,
        float $value,
        string $nextDueDate,
        string $description,
        string $externalReference,
        string $remoteIp,
        array $cardData = []
    ): array {
        $payload = [
            'customer' => $customerId,
            'billingType' => 'CREDIT_CARD',
            'value' => $value,
            'cycle' => 'MONTHLY',
            'nextDueDate' => $nextDueDate,
            'description' => $description,
            'externalReference' => $externalReference,
            'remoteIp' => $remoteIp,
        ];

        if ($cardData !== []) {
            $card = [];
            $holderInfo = [];

            if (isset($cardData['holderName']) && is_string($cardData['holderName'])) {
                $card['holderName'] = $cardData['holderName'];
            }
            if (isset($cardData['number']) && is_string($cardData['number'])) {
                $card['number'] = $cardData['number'];
            }
            if (isset($cardData['expiryMonth']) && is_string($cardData['expiryMonth'])) {
                $card['expiryMonth'] = $cardData['expiryMonth'];
            }
            if (isset($cardData['expiryYear']) && is_string($cardData['expiryYear'])) {
                $card['expiryYear'] = $cardData['expiryYear'];
            }
            if (isset($cardData['ccv']) && is_string($cardData['ccv'])) {
                $card['ccv'] = $cardData['ccv'];
            }

            if (isset($cardData['holderInfo']) && is_array($cardData['holderInfo'])) {
                $hi = $cardData['holderInfo'];
                if (isset($hi['name']))          $holderInfo['name'] = $hi['name'];
                if (isset($hi['email']))          $holderInfo['email'] = $hi['email'];
                if (isset($hi['cpfCnpj']))       $holderInfo['cpfCnpj'] = $hi['cpfCnpj'];
                if (isset($hi['postalCode']))    $holderInfo['postalCode'] = $hi['postalCode'];
                if (isset($hi['addressNumber']))  $holderInfo['addressNumber'] = $hi['addressNumber'];
                if (isset($hi['phone']))          $holderInfo['phone'] = $hi['phone'];
                if (isset($hi['mobilePhone']))    $holderInfo['mobilePhone'] = $hi['mobilePhone'];
            }

            if ($card !== []) {
                $payload['creditCard'] = $card;
            }
            if ($holderInfo !== []) {
                $payload['creditCardHolderInfo'] = $holderInfo;
            }
        }

        $resp = $this->request('POST', '/subscriptions', $payload);
        unset($card, $holderInfo, $cardData, $payload);

        return $resp;
    }

    /**
     * Obtem dados de uma assinatura.
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', '/subscriptions/' . rawurlencode($subscriptionId));
    }

    /**
     * Cancela uma assinatura.
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request('DELETE', '/subscriptions/' . rawurlencode($subscriptionId));
    }

    /**
     * Mapeia erro de API Asaas para mensagem amigavel ao usuario.
     *
     * @return string Mensagem amigavel.
     */
    public static function friendlyError(array $resp): string
    {
        $data = $resp['data'] ?? [];
        $code = isset($data['code']) ? (string)$data['code'] : '';

        if (str_contains($code, 'invalid_card')) {
            return 'Ocorreu um erro com o cartao informado. Verifique os dados e tente novamente.';
        }
        if (str_contains($code, 'card_declined') || str_contains($code, 'refused')) {
            return 'Cartao recusado. Tente outro cartao ou forma de pagamento.';
        }
        if (str_contains($code, 'expired_card')) {
            return 'O cartao esta com a validade vencida. Utilize outro cartao.';
        }
        if (str_contains($code, 'insufficient_balance')) {
            return 'Saldo insuficiente no cartao. Tente outro cartao.';
        }
        if (str_contains($code, 'duplicate_customer')) {
            return 'Ja existe um cadastro com este email. Tente fazer login.';
        }
        if (str_contains($code, 'subscription_already_exists') || str_contains($code, 'duplicate')) {
            return 'Voce ja possui uma assinatura ativa para este plano.';
        }
        if ($resp['status'] === 0 || $resp['error'] === 'conexao') {
            return 'Servico temporariamente indisponivel. Tente novamente em alguns minutos.';
        }
        if ($resp['status'] >= 500) {
            return 'Erro interno no servico de pagamento. Tente novamente mais tarde.';
        }
        if ($resp['status'] === 400 || $resp['status'] === 401 || $resp['status'] === 403) {
            return 'Nao foi possivel processar a assinatura. Entre em contato com o suporte.';
        }
        return 'Ocorreu um erro ao processar sua assinatura. Tente novamente.';
    }
}

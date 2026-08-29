<?php
/**
 * Mailer — envio via Brevo API HTTPS.
 *
 * Variáveis de ambiente (Vercel → Settings → Environment Variables):
 *   BREVO_API_KEY  = chave API em https://app.brevo.com/settings/keys/api
 *   MAIL_FROM      = e-mail verificado no painel Brevo (ex: no-reply@seu-dominio.com)
 *   MAIL_FROM_NAME = nome do remetente (ex: Controle de Gastos)
 *
 * Se BREVO_API_KEY não estiver configurada, o envio falha silenciosamente
 * e o link é logado em error_log (dev local com APP_DEBUG=true).
 */
class Mailer
{
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $rawFrom = trim((string)$this->env('MAIL_FROM'));
        [$name, $addr] = $this->parseFrom($rawFrom);
        $envName = trim((string)$this->env('MAIL_FROM_NAME'));
        $this->from     = $addr;
        $this->fromName = $envName ?: ($name ?: 'Controle de Gastos');
    }

    private function env(string $key): string|false
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return false;
    }

    public function send(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        $to = trim($to);
        $subject = trim($subject);
        if ($to === '' || $subject === '') return false;

        $apiKey = trim((string)$this->env('BREVO_API_KEY'));

        if ($apiKey === '') {
            error_log('[Mailer] BREVO_API_KEY não configurada na Vercel → Settings → Environment Variables');
            return false;
        }

        return $this->sendViaBrevo($to, $subject, $htmlBody, $apiKey);
    }

    private function sendViaBrevo(string $to, string $subject, string $html, string $apiKey): bool
    {
        $payload = json_encode([
            'sender' => [
                'name'  => $this->fromName,
                'email' => $this->from,
            ],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            if (function_exists('curl_init')) {
                $start = microtime(true);
                $ch = curl_init('https://api.brevo.com/v3/smtp/email');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'accept: application/json',
                        'content-type: application/json',
                        'api-key: ' . $apiKey,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_CONNECTTIMEOUT => 10,
                ]);
                error_log('[Mailer:Brevo] POST https://api.brevo.com/v3/smtp/email from=' . $this->from . ' to=' . $this->maskEmail($to));
                $resp = curl_exec($ch);
                $dur = (int)((microtime(true) - $start) * 1000);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                if ($resp === false) {
                    error_log('[Mailer:Brevo CURL_FAIL] from=' . $this->from . ' para=' . $this->maskEmail($to) . ' curl_err=' . ($err ?: 'nenhum') . ' duracao_ms=' . $dur);
                    return false;
                }
                if ($status >= 200 && $status < 300) {
                    $body = json_decode((string)$resp, true);
                    $msgId = is_array($body) ? ($body['messageId'] ?? 'sem-messageId') : 'sem-messageId';
                    error_log('[Mailer:Brevo OK] HTTP ' . $status . ' para=' . $this->maskEmail($to) . ' from=' . $this->from . ' messageId=' . $msgId . ' duracao_ms=' . $dur);
                    return true;
                }
                $body = json_decode((string)$resp, true);
                $msg   = is_array($body) ? ($body['message'] ?? '') : '';
                $ecode = is_array($body) ? ($body['code'] ?? '') : '';
                $ebody = is_array($body) ? ($body['errors'][0]['message'] ?? '') : '';
                error_log('[Mailer:Brevo ERRO] HTTP ' . $status . ' from=' . $this->from . ' para=' . $this->maskEmail($to) . ' curl_err=' . ($err ?: 'nenhum') . ' api_code=' . $ecode . ' msg=' . $msg . ' err_msg=' . $ebody . ' duracao_ms=' . $dur);
                return false;
            }

            if (ini_get('allow_url_fopen')) {
                $start = microtime(true);
                $ctx = stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'timeout'       => 15,
                        'ignore_errors' => true,
                        'header'        => implode("\r\n", [
                            'accept: application/json',
                            'content-type: application/json',
                            'api-key: ' . $apiKey,
                        ]),
                        'content' => $payload,
                    ],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $resp = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $ctx);
                $dur = (int)((microtime(true) - $start) * 1000);
                $status = $this->parseStatus($http_response_header ?? []);
                if ($resp !== false && $status >= 200 && $status < 300) {
                    $body = json_decode((string)$resp, true);
                    $msgId = is_array($body) ? ($body['messageId'] ?? 'sem-messageId') : 'sem-messageId';
                    error_log('[Mailer:Brevo OK] HTTP ' . $status . ' para=' . $this->maskEmail($to) . ' from=' . $this->from . ' messageId=' . $msgId . ' duracao_ms=' . $dur);
                    return true;
                }
                $body = json_decode((string)$resp, true);
                $msg   = is_array($body) ? ($body['message'] ?? '') : '';
                $ecode = is_array($body) ? ($body['code'] ?? '') : '';
                error_log('[Mailer:Brevo ERRO] HTTP ' . $status . ' from=' . $this->from . ' para=' . $this->maskEmail($to) . ' msg=' . $msg . ' code=' . $ecode . ' duracao_ms=' . $dur);
            }
        } catch (Throwable $e) {
            error_log('[Mailer:Brevo] Exceção: ' . $e->getMessage());
        }
        return false;
    }

    private function maskEmail(string $e): string
    {
        if (!str_contains($e, '@')) return '***';
        [$local, $domain] = explode('@', $e, 2);
        return (mb_strlen($local) > 0 ? mb_substr($local, 0, 1) : '*') . '****@' . $domain;
    }

    private function parseStatus(array $h): int
    {
        foreach ($h as $l) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $l, $m)) return (int)$m[1];
        }
        return 0;
    }

    private function parseFrom(string $from): array
    {
        $from = trim($from);
        if (preg_match('/^(.*)<(.*)>\s*$/', $from, $m)) {
            return [trim($m[1], ' "\''), trim($m[2])];
        }
        return ['', $from];
    }
}

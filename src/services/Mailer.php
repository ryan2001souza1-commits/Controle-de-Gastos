<?php
/**
 * Mailer — envio real de e-mail compatível com PHP/Vercel Serverless.
 *
 * Prioridade de envio:
 *   1. Resend API HTTPS  (RESEND_API_KEY + MAIL_FROM)  — recomendado Vercel
 *   2. SMTP direto       (SMTP_HOST/SMTP_USER/SMTP_PASS) — compat legado
 *   3. mail() nativo     — último recurso (falha na Vercel sem sendmail)
 *
 * ENV obrigatórias em produção (Vercel → Settings → Environment Variables):
 *   RESEND_API_KEY = re_xxxxxxxxxxxxxxxxxxxxxxxx  (obter em https://resend.com/api-keys)
 *   MAIL_FROM      = Controle de Gastos <onboarding@resend.dev>  OU seu domínio verificado
 *   MAIL_FROM_NAME = Controle de Gastos  (opcional, extraído de MAIL_FROM se contiver nome)
 *
 * Alternativa SMTP (se já usa SendGrid/Brevo/SES):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, MAIL_FROM
 *
 * Sem credenciais o envio falha silenciosamente (log em error_log) e o
 * AuthService retorna mensagem genérica — nunca expõe o link.
 */
class Mailer
{
    private string $from;
    private string $fromName;

    public function __construct()
    {
        [$name, $addr] = $this->parseFrom((string)getenv('MAIL_FROM'));
        $this->from     = $addr ?: 'onboarding@resend.dev';
        $this->fromName = (string)(getenv('MAIL_FROM_NAME') ?: ($name ?: 'Controle de Gastos'));
        // se MAIL_FROM já veio no formato "Nome <email>", o parse acima extrai ambos
        if ($addr === '' && getenv('MAIL_FROM')) {
            // fallback: getenv retornou apenas string sem parse
            $this->from = (string)getenv('MAIL_FROM');
        }
    }

    public function send(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        $to = trim($to);
        $subject = trim($subject);
        if ($to === '' || $subject === '') return false;

        // 1) Resend HTTPS — funciona na Vercel (outbound HTTPS liberado, porta 25 bloqueada)
        $resendKey = trim((string)getenv('RESEND_API_KEY'));
        if ($resendKey !== '' && $this->from !== '') {
            if ($this->sendViaResend($to, $subject, $htmlBody, $resendKey)) return true;
            // se Resend falhou, tenta SMTP/mail como fallback antes de desistir
            error_log('[Mailer] Resend falhou, tentando fallback SMTP/mail');
        }

        // 2) SMTP direto (legado)
        $smtpHost = trim((string)getenv('SMTP_HOST'));
        if ($smtpHost !== '') {
            if ($this->sendSmtp($to, $subject, $htmlBody, $altBody)) return true;
        }

        // 3) mail() nativo
        $headers  = "From: {$this->fromName} <{$this->from}>\r\n";
        $headers .= "Reply-To: {$this->from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $sent = @mail($to, $subject, $htmlBody, $headers);
        if (!$sent) {
            $e = error_get_last();
            error_log('[Mailer] mail() falhou para ' . $to . ': ' . ($e['message'] ?? 'sem sendmail') . ' — configure RESEND_API_KEY e MAIL_FROM na Vercel');
        }
        return $sent;
    }

    private function sendViaResend(string $to, string $subject, string $html, string $apiKey): bool
    {
        $payload = json_encode([
            'from'    => $this->fromName . ' <' . $this->from . '>',
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Tenta file_get_contents (requere allow_url_fopen + openssl)
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'timeout'       => 12,
                    'ignore_errors' => true,
                    'header'        => implode("\r\n", [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ]),
                    'content' => $payload,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
            $status = $this->parseStatus($http_response_header ?? []);
            if ($resp !== false && $status >= 200 && $status < 300) return true;
            if ($resp !== false) error_log("[Mailer:Resend] HTTP $status: $resp");
            else error_log('[Mailer:Resend] file_get_contents falhou — host bloqueado ou sem openssl');
        }

        // Fallback: cURL se disponível
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
            ]);
            $resp = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp !== false && $status >= 200 && $status < 300) return true;
            error_log("[Mailer:Resend cURL] HTTP $status err=$err resp=$resp");
        }

        return false;
    }

    // — SMTP legado (mantido para quem já usa) —
    private function sendSmtp(string $to, string $subject, string $htmlBody, string $altBody): bool
    {
        $host = trim((string)getenv('SMTP_HOST'));
        $port = (int)(getenv('SMTP_PORT') ?: '587');
        $user = trim((string)getenv('SMTP_USER'));
        $pass = trim((string)getenv('SMTP_PASS'));
        $timeout = 12;
        $addr = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) { error_log("[Mailer:SMTP] connect $host:$port [$errno] $errstr"); return false; }
        stream_set_timeout($fp, $timeout);
        $r = $this->smtpRead($fp); if (!$this->smtpOk($r)) { fclose($fp); return false; }
        $lh = gethostname() ?: 'localhost';
        $this->smtpWrite($fp, "EHLO $lh\r\n"); $r = $this->smtpRead($fp);
        if (!$this->smtpOk($r)) { $this->smtpWrite($fp, "HELO $lh\r\n"); $r = $this->smtpRead($fp); if (!$this->smtpOk($r)) { fclose($fp); return false; } }
        if ($port === 587) {
            $this->smtpWrite($fp, "STARTTLS\r\n"); $r = $this->smtpRead($fp); if (!$this->smtpOk($r)) { fclose($fp); return false; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { error_log('[Mailer:SMTP] STARTTLS fail'); fclose($fp); return false; }
            $this->smtpWrite($fp, "EHLO $lh\r\n"); $this->smtpRead($fp);
        }
        if ($user !== '' && $pass !== '') {
            $this->smtpWrite($fp, "AUTH LOGIN\r\n"); if (!$this->smtpOk($this->smtpRead($fp))) { fclose($fp); return false; }
            $this->smtpWrite($fp, base64_encode($user) . "\r\n"); if (!$this->smtpOk($this->smtpRead($fp))) { fclose($fp); return false; }
            $this->smtpWrite($fp, base64_encode($pass) . "\r\n"); $r = $this->smtpRead($fp); if (!str_starts_with(trim($r), '235')) { error_log("[Mailer:SMTP] AUTH fail $r"); fclose($fp); return false; }
        }
        $this->smtpWrite($fp, "MAIL FROM:<{$this->from}>\r\n"); if (!$this->smtpOk($this->smtpRead($fp))) { fclose($fp); return false; }
        $this->smtpWrite($fp, "RCPT TO:<$to>\r\n"); if (!$this->smtpOk($this->smtpRead($fp))) { fclose($fp); return false; }
        $this->smtpWrite($fp, "DATA\r\n"); if (!$this->smtpOk($this->smtpRead($fp))) { fclose($fp); return false; }
        $msg = "Subject: $subject\r\n" . $this->buildHeaders($to) . "\r\n\r\n" . $htmlBody;
        $this->smtpWrite($fp, $msg . "\r\n.\r\n"); $r = $this->smtpRead($fp); $ok = str_starts_with(trim($r), '250');
        $this->smtpWrite($fp, "QUIT\r\n"); fclose($fp);
        if (!$ok) error_log("[Mailer:SMTP] DATA $r");
        return $ok;
    }
    private function smtpWrite($fp, string $d): void { fwrite($fp, $d); }
    private function smtpRead($fp): string { $l = fgets($fp); return $l !== false ? $l : ''; }
    private function smtpOk(string $l): bool { $c = substr(trim($l), 0, 3); return $c !== '' && $c[0] === '2'; }
    private function buildHeaders(string $to): string {
        return "From: {$this->fromName} <{$this->from}>\r\nReply-To: {$this->from}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nTo: $to\r\n";
    }
    private function parseStatus(array $h): int { foreach ($h as $l) if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $l, $m)) return (int)$m[1]; return 0; }
    private function parseFrom(string $from): array {
        $from = trim($from);
        if (preg_match('/^(.*)<(.*)>\s*$/', $from, $m)) return [trim($m[1], ' "\''), trim($m[2])];
        return ['', $from];
    }
}

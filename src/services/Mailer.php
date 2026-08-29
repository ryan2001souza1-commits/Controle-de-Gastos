<?php
/**
 * Mailer — envio real compatível com Vercel Serverless + Gmail.
 *
 * Ordem de tentativa:
 *   1) Resend API HTTPS (RESEND_API_KEY + MAIL_FROM) — funciona na Vercel (HTTPS liberado)
 *   2) SMTP direto      (SMTP_HOST/SMTP_USER/SMTP_PASS) — Gmail / SendGrid / Brevo
 *   3) mail() nativo    — falha na Vercel (sem sendmail), último recurso
 *
 * PRODUÇÃO (Vercel → Settings → Environment Variables):
 *   Opção A — Resend (recomendado Vercel):
 *     RESEND_API_KEY = re_xxxxxxxx  (https://resend.com/api-keys)
 *     MAIL_FROM      = Controle de Gastos <onboarding@resend.dev> ou domínio verificado
 *
 *   Opção B — Gmail (envio via smtp.gmail.com com Senha de App):
 *     SMTP_HOST = smtp.gmail.com
 *     SMTP_PORT = 587
 *     SMTP_USER = seu.email@gmail.com
 *     SMTP_PASS = sua_senha_de_app_16_letras  (SEM espaços, NÃO é a senha normal!)
 *     MAIL_FROM = seu.email@gmail.com  (DEVE ser igual ao SMTP_USER para Gmail)
 *     MAIL_FROM_NAME = Controle de Gastos
 *     → Gere a Senha de App em: https://myaccount.google.com/apppasswords (precisa 2FA ativo)
 *
 * Localmente sem credenciais, o envio falha e AuthService loga o link em error_log quando APP_DEBUG=true.
 */
class Mailer
{
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $rawFrom = trim((string)$this->env('MAIL_FROM'));
        [$name, $addr] = $this->parseFrom($rawFrom);
        $smtpUser = trim((string)$this->env('SMTP_USER'));

        // Se MAIL_FROM vazio mas SMTP_USER é um e-mail (caso Gmail), usa SMTP_USER como remetente
        if ($addr === '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
            $addr = $smtpUser;
        }
        // Fallback: remetente default da Resend (sandbox).
        // ATENÇÃO: com este remetente a Resend só envia para o e-mail dono da conta.
        // Para enviar a qualquer destinatário, verifique um domínio em resend.com/domains
        // e defina MAIL_FROM com esse domínio.
        if ($addr === '') {
            $addr = 'onboarding@resend.dev';
        }
        $envName = trim((string)$this->env('MAIL_FROM_NAME'));
        $this->from     = $addr;
        $this->fromName = $envName ?: ($name ?: 'Controle de Gastos');
    }

    /**
     * Lê variável de ambiente em qualquer lugar que o runtime a exponha.
     *
     * Por que isto é necessário: no Vercel Serverless (vercel-php@0.9.0), as
     * variáveis configuradas em Settings → Environment Variables são
     * injetadas em $_ENV e $_SERVER, mas getenv() nem sempre as enxerga.
     * Este helper cobre os 3 lugares para garantir leitura em qualquer
     * runtime (Vercel, CLI, Apache, Nginx, etc.).
     */
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

        $resendKey = trim((string)$this->env('RESEND_API_KEY'));
        $smtpHost  = trim((string)$this->env('SMTP_HOST'));

        // Diagnóstico: nenhuma credencial configurada
        if ($resendKey === '' && $smtpHost === '') {
            error_log('[Mailer] NENHUMA credencial de e-mail configurada. Defina RESEND_API_KEY na Vercel → Settings → Environment Variables');
        } else {
            error_log('[Mailer] Credenciais detectadas: RESEND_API_KEY=' . ($resendKey !== '' ? 'sim' : 'não') . ' SMTP_HOST=' . ($smtpHost !== '' ? $smtpHost : 'não'));
        }

        // 1) Resend HTTPS — mais confiável na Vercel (porta 587 pode ser bloqueada)
        if ($resendKey !== '' && $this->from !== '') {
            error_log('[Mailer] Tentando envio via Resend para ' . $to);
            if ($this->sendViaResend($to, $subject, $htmlBody, $resendKey)) {
                error_log('[Mailer] Resend: envio OK para ' . $to);
                return true;
            }
            error_log('[Mailer] Resend falhou, tentando fallback SMTP/mail');
        }

        // 2) SMTP direto (Gmail / SendGrid / Brevo)
        if ($smtpHost !== '') {
            if ($this->sendSmtp($to, $subject, $htmlBody)) return true;
            // Se foi Gmail e falhou, pode ser senha normal ao invés de App Password
            if (str_contains(strtolower($smtpHost), 'gmail')) {
                error_log('[Mailer] Gmail SMTP falhou — verifique se SMTP_PASS é Senha de App (16 letras, https://myaccount.google.com/apppasswords) e não a senha normal. Verifique também se MAIL_FROM == SMTP_USER');
            }
        }

        // 3) mail() nativo — falha na Vercel
        $headers  = "From: {$this->fromName} <{$this->from}>\r\n";
        $headers .= "Reply-To: {$this->from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $sent = @mail($to, $subject, $htmlBody, $headers);
        if (!$sent) {
            $e = error_get_last();
            error_log('[Mailer] mail() falhou para ' . $to . ': ' . ($e['message'] ?? 'sem sendmail') . ' — configure RESEND_API_KEY ou SMTP_HOST na Vercel');
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

        try {
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
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_CONNECTTIMEOUT => 10,
                ]);
                $resp = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($resp !== false && $status >= 200 && $status < 300) return true;
                error_log("[Mailer:Resend cURL] HTTP $status err=" . ($err ?: 'nenhum') . " resp=" . substr((string)$resp, 0, 300));
                return false;
            }

            if (ini_get('allow_url_fopen')) {
                $ctx = stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'timeout'       => 15,
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
                if ($resp !== false) error_log("[Mailer:Resend fopen] HTTP $status: " . substr((string)$resp, 0, 300));
                else error_log('[Mailer:Resend fopen] file_get_contents falhou — sem openssl ou host bloqueado');
            }
        } catch (Throwable $e) {
            error_log('[Mailer:Resend] Exceção: ' . $e->getMessage());
        }
        return false;
    }

    private function sendSmtp(string $to, string $subject, string $htmlBody): bool
    {
        $host = trim((string)$this->env('SMTP_HOST'));
        $port = (int)($this->env('SMTP_PORT') ?: '587');
        $user = trim((string)$this->env('SMTP_USER'));
        $pass = trim((string)$this->env('SMTP_PASS'));
        // Gmail exige que o remetente seja o próprio usuário autenticado
        $from = $this->from;
        if (str_contains(strtolower($host), 'gmail') && filter_var($user, FILTER_VALIDATE_EMAIL)) {
            $from = $user;
        }
        $timeout = 12;
        $addr = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) { error_log("[Mailer:SMTP] connect $host:$port [$errno] $errstr"); return false; }
        stream_set_timeout($fp, $timeout);

        // Banner inicial (pode ser multiline)
        $r = $this->smtpReadMultiline($fp);
        if (!$this->smtpOk($r)) { error_log("[Mailer:SMTP] banner $r"); fclose($fp); return false; }

        $lh = gethostname() ?: 'localhost';
        $this->smtpWrite($fp, "EHLO $lh\r\n");
        $r = $this->smtpReadMultiline($fp);
        if (!$this->smtpOk($r)) {
            $this->smtpWrite($fp, "HELO $lh\r\n");
            $r = $this->smtpReadMultiline($fp);
            if (!$this->smtpOk($r)) { error_log("[Mailer:SMTP] EHLO/HELO $r"); fclose($fp); return false; }
        }

        if ($port === 587) {
            $this->smtpWrite($fp, "STARTTLS\r\n");
            $r = $this->smtpReadMultiline($fp);
            if (!$this->smtpOk($r)) { error_log("[Mailer:SMTP] STARTTLS $r"); fclose($fp); return false; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[Mailer:SMTP] stream_socket_enable_crypto falhou — openssl desabilitado?');
                fclose($fp); return false;
            }
            $this->smtpWrite($fp, "EHLO $lh\r\n");
            $r = $this->smtpReadMultiline($fp);
            if (!$this->smtpOk($r)) { error_log("[Mailer:SMTP] EHLO pós-TLS $r"); fclose($fp); return false; }
        }

        if ($user !== '' && $pass !== '') {
            // Tenta AUTH LOGIN (Gmail/Brevo) — também suporta AUTH PLAIN se LOGIN falhar
            $this->smtpWrite($fp, "AUTH LOGIN\r\n");
            $r = $this->smtpReadMultiline($fp);
            if (str_starts_with($r, '334')) {
                $this->smtpWrite($fp, base64_encode($user) . "\r\n");
                $r = $this->smtpReadMultiline($fp);
                if (!str_starts_with($r, '334')) { error_log("[Mailer:SMTP] AUTH user $r"); fclose($fp); return false; }
                $this->smtpWrite($fp, base64_encode($pass) . "\r\n");
                $r = $this->smtpReadMultiline($fp);
                if (!str_starts_with(trim($r), '235')) { error_log("[Mailer:SMTP] AUTH pass $r"); fclose($fp); return false; }
            } elseif ($this->smtpOk($r)) {
                // Servidor aceitou sem challenge — tenta PLAIN
                $this->smtpWrite($fp, base64_encode("\0$user\0$pass") . "\r\n");
                $r = $this->smtpReadMultiline($fp);
                if (!str_starts_with(trim($r), '235')) { error_log("[Mailer:SMTP] AUTH PLAIN $r"); fclose($fp); return false; }
            } else {
                error_log("[Mailer:SMTP] AUTH LOGIN rejeitado $r"); fclose($fp); return false;
            }
        }

        $this->smtpWrite($fp, "MAIL FROM:<$from>\r\n");
        $r = $this->smtpReadMultiline($fp);
        if (!$this->smtpOk($r)) { error_log("[Mailer:SMTP] MAIL FROM $r"); fclose($fp); return false; }

        $this->smtpWrite($fp, "RCPT TO:<$to>\r\n");
        $r = $this->smtpReadMultiline($fp);
        if (!$this->smtpOk($r)) { error_log("[Mailer:SMTP] RCPT TO $r"); fclose($fp); return false; }

        $this->smtpWrite($fp, "DATA\r\n");
        $r = $this->smtpReadMultiline($fp);
        if (!$this->smtpOk($r) && !str_starts_with($r, '354')) { error_log("[Mailer:SMTP] DATA $r"); fclose($fp); return false; }

        $headers = $this->buildHeaders($to, $from);
        $msg = "Subject: $subject\r\n" . $headers . "\r\n\r\n" . $htmlBody;
        // Dot-stuffing: escapa linhas começando com ponto
        $msg = preg_replace('/^\./m', '..', $msg);
        $this->smtpWrite($fp, $msg . "\r\n.\r\n");
        $r = $this->smtpReadMultiline($fp);
        $ok = str_starts_with(trim($r), '250');
        if (!$ok) error_log("[Mailer:SMTP] DATA end $r");
        $this->smtpWrite($fp, "QUIT\r\n");
        @fclose($fp);
        return $ok;
    }

    private function smtpWrite($fp, string $d): void { fwrite($fp, $d); }
    /** Lê resposta SMTP completa (multiline: linhas com "250-" continuam, "250 " termina) */
    private function smtpReadMultiline($fp): string {
        $resp = '';
        while (($line = fgets($fp)) !== false) {
            $resp .= $line;
            // Formato: "XYZ-" continua, "XYZ " termina
            if (strlen($line) >= 4 && $line[3] === ' ') break;
            // timeout protection
            $m = stream_get_meta_data($fp);
            if ($m['timed_out']) break;
        }
        return $resp;
    }
    private function smtpOk(string $resp): bool {
        if ($resp === '') return false;
        // verifica última linha do multiline
        $lines = explode("\n", trim($resp));
        $last = end($lines);
        $code = substr(trim($last), 0, 3);
        return $code !== '' && $code[0] === '2';
    }
    private function buildHeaders(string $to, string $from): string {
        return "From: {$this->fromName} <{$from}>\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nTo: $to\r\n";
    }
    private function parseStatus(array $h): int { foreach ($h as $l) if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $l, $m)) return (int)$m[1]; return 0; }
    private function parseFrom(string $from): array {
        $from = trim($from);
        if (preg_match('/^(.*)<(.*)>\s*$/', $from, $m)) return [trim($m[1], ' "\''), trim($m[2])];
        return ['', $from];
    }
}

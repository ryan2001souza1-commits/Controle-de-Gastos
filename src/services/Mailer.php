<?php

/**
 * Mailer com suporte a SMTP direto via fsockopen.
 * Requer as variáveis de ambiente:
 *   SMTP_HOST   — host do servidor SMTP (ex: smtp.sendgrid.net)
 *   SMTP_PORT   — porta (default: 587 para TLS)
 *   SMTP_USER   — usuário/auth identity
 *   SMTP_PASS   — senha ou API key
 *   MAIL_FROM   — endereço do remetente (default: no-reply@appdomain)
 *   MAIL_FROM_NAME — nome do remetente (default: Controle de Gastos)
 *   APP_URL     — URL base do app (usado para detectar ssl)
 *
 * Funciona em qualquer ambiente PHP com sockets (Vercel, shared hosting,
 * VPS, localhost). Não requer extensão adicional além de openssl.
 */

class Mailer
{
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $this->from     = $this->env('MAIL_FROM', 'no-reply@' . $this->defaultDomain());
        $this->fromName = $this->env('MAIL_FROM_NAME', 'Controle de Gastos');
    }

    public function send(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        $to = trim($to);
        $subject = trim($subject);
        if ($to === '' || $subject === '') {
            return false;
        }

        $smtpHost = $this->env('SMTP_HOST');
        if ($smtpHost) {
            return $this->sendSmtp($to, $subject, $htmlBody, $altBody);
        }

        $sent = @mail($to, $subject, $htmlBody, $this->buildHeaders($to));
        if (!$sent) {
            error_log("[Mailer] mail() falhou para {$to}: " . error_get_last()['message'] ?? 'desconhecido');
        }
        return $sent;
    }

    private function sendSmtp(string $to, string $subject, string $htmlBody, string $altBody): bool
    {
        $host    = $this->env('SMTP_HOST');
        $port    = (int)($this->env('SMTP_PORT') ?: '587');
        $user    = $this->env('SMTP_USER');
        $pass    = $this->env('SMTP_PASS');
        $timeout = 15;

        $tls = ($port === 465) ? 'ssl' : 'tcp';
        $addr = ($tls === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if (!$fp) {
            error_log("[Mailer] SMTP connect falhou [$errno]: {$errstr} — host={$host}:{$port}");
            return false;
        }

        stream_set_timeout($fp, $timeout);

        $res = $this->smtpRead($fp);
        if (!$this->smtpOk($res)) {
            $this->smtpClose($fp);
            return false;
        }

        // EHLO
        $localHost = gethostname() ?: 'localhost';
        $this->smtpWrite($fp, "EHLO {$localHost}\r\n");
        $res = $this->smtpRead($fp);
        if (!$this->smtpOk($res)) {
            // Try HELO fallback
            $this->smtpWrite($fp, "HELO {$localHost}\r\n");
            $res = $this->smtpRead($fp);
            if (!$this->smtpOk($res)) {
                $this->smtpClose($fp);
                return false;
            }
        }

        // STARTTLS se porta 587
        if ($port === 587) {
            $this->smtpWrite($fp, "STARTTLS\r\n");
            $res = $this->smtpRead($fp);
            if (!$this->smtpOk($res)) {
                $this->smtpClose($fp);
                return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("[Mailer] STARTTLS falhou");
                $this->smtpClose($fp);
                return false;
            }
            // Re-EHLO após TLS
            $this->smtpWrite($fp, "EHLO {$localHost}\r\n");
            $this->smtpRead($fp);
        }

        // AUTH LOGIN
        if ($user && $pass) {
            $this->smtpWrite($fp, "AUTH LOGIN\r\n");
            $res = $this->smtpRead($fp);
            if (!$this->smtpOk($res)) {
                $this->smtpClose($fp);
                return false;
            }

            $this->smtpWrite($fp, base64_encode($user) . "\r\n");
            $res = $this->smtpRead($fp);
            if (!$this->smtpOk($res)) {
                $this->smtpClose($fp);
                return false;
            }

            $this->smtpWrite($fp, base64_encode($pass) . "\r\n");
            $res = $this->smtpRead($fp);
            if (!str_starts_with(trim($res), '235')) {
                error_log("[Mailer] AUTH LOGIN falhou: {$res}");
                $this->smtpClose($fp);
                return false;
            }
        }

        // MAIL FROM
        $fromAddr = $this->from;
        $this->smtpWrite($fp, "MAIL FROM:<{$fromAddr}>\r\n");
        $res = $this->smtpRead($fp);
        if (!$this->smtpOk($res)) {
            $this->smtpClose($fp);
            return false;
        }

        // RCPT TO
        $this->smtpWrite($fp, "RCPT TO:<{$to}>\r\n");
        $res = $this->smtpRead($fp);
        if (!$this->smtpOk($res)) {
            $this->smtpClose($fp);
            return false;
        }

        // DATA
        $this->smtpWrite($fp, "DATA\r\n");
        $res = $this->smtpRead($fp);
        if (!$this->smtpOk($res)) {
            $this->smtpClose($fp);
            return false;
        }

        $headers = $this->buildHeaders($to);
        $message = $this->buildMessage($to, $subject, $htmlBody, $altBody, $headers);

        $this->smtpWrite($fp, $message . "\r\n.\r\n");
        $res = $this->smtpRead($fp);
        $ok = str_starts_with(trim($res), '250');

        $this->smtpWrite($fp, "QUIT\r\n");
        fclose($fp);

        if (!$ok) {
            error_log("[Mailer] SMTP DATA falhou: {$res}");
        }
        return $ok;
    }

    private function smtpWrite($fp, string $data): void
    {
        fwrite($fp, $data);
    }

    private function smtpRead($fp): string
    {
        $line = fgets($fp);
        return $line !== false ? $line : '';
    }

    private function smtpOk(string $line): bool
    {
        $code = substr(trim($line), 0, 3);
        return $code !== '' && $code[0] === '2';
    }

    private function smtpClose($fp): void
    {
        @fclose($fp);
    }

    private function buildHeaders(string $to): string
    {
        $headers = "From: {$this->fromName} <{$this->from}>\r\n";
        $headers .= "Reply-To: {$this->from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";
        $headers .= "To: {$to}\r\n";
        return $headers;
    }

    private function buildMessage(string $to, string $subject, string $htmlBody, string $altBody, string $headers): string
    {
        $eol = "\r\n";
        $msg = "Subject: {$subject}{$eol}";
        $msg .= $headers . $eol . $eol;
        $msg .= $htmlBody;
        return $msg;
    }

    private function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v !== false && $v !== '') ? $v : $default;
    }

    private function defaultDomain(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return str_replace(['https://', 'http://'], '', $scheme . '://' . $host);
    }
}

<?php

class Mailer
{
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $this->from     = getenv('MAIL_FROM') ?: 'no-reply@controle-de-gastos.local';
        $this->fromName = getenv('MAIL_FROM_NAME') ?: 'Controle de Gastos';
    }

    /**
     * Envia e-mail em texto/HTML. Retorna true se enviou, false se falhou.
     * Em ambiente sem SMTP configurado, apenas loga no error_log e retorna false
     * (o caller pode usar o link de fallback para exibir na UI em dev).
     */
    public function send(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        $boundary = 'mime-boundary-' . bin2hex(random_bytes(8));

        $headers  = "From: {$this->fromName} <{$this->from}>\r\n";
        $headers .= "Reply-To: {$this->from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

        $smtpHost = getenv('SMTP_HOST');
        if ($smtpHost) {
            // Ambiente com SMTP real: usar mail() com host configurado externamente
            // Em produção, recomenda-se biblioteca dedicada (PHPMailer/Symfony Mailer).
            ini_set('SMTP', $smtpHost);
            $port = getenv('SMTP_PORT') ?: '587';
            ini_set('smtp_port', $port);
        }

        $sent = @mail($to, $subject, $htmlBody, $headers);

        if (!$sent) {
            error_log("[Mailer] Falha ao enviar e-mail para {$to}. Assunto: {$subject}");
        }

        return $sent;
    }
}

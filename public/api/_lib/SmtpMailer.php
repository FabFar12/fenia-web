<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Delivery mail through Hostinger SMTP (D10: sent from kits@, replies go to
 * soporte@). Authenticated SMTP keeps SPF/DKIM aligned (threat model B4).
 * Recipients are validated before reaching here (Validate::email, D2).
 */
final class SmtpMailer implements MailSender
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $this->config->get('smtp_host');
            $mail->Port = (int) $this->config->get('smtp_port');
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Username = (string) $this->config->get('smtp_user');
            $mail->Password = (string) $this->config->get('smtp_password');
            $mail->Timeout = 15;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string) $this->config->get('mail_from'), 'FENIA');
            $mail->addReplyTo((string) $this->config->get('mail_reply_to'), 'FENIA');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
        } catch (\Throwable $e) {
            // Never leak SMTP details upward; the caller logs and retries.
            throw new \RuntimeException('Mail could not be sent', 0, $e);
        }
    }
}

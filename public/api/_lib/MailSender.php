<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/** Sends one email. SmtpMailer in production; a fake in the tests. */
interface MailSender
{
    /** @throws \RuntimeException when the message could not be handed to the server */
    public function send(string $to, string $subject, string $text, string $html): void;
}

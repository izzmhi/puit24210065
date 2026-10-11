<?php

declare(strict_types=1);

namespace App\Mail;

/** Production delivery: the code is emailed through the SMTP server named in .env. */
final class EmailOtpDelivery implements OtpDelivery
{
    public function __construct(private readonly SmtpClient $smtp, private readonly string $fromAddress)
    {
    }

    public function deliver(string $email, string $name, string $otp, int $ttlSeconds): array
    {
        $minutes = intdiv($ttlSeconds, 60);
        $body = "Your Secure Login verification code is:\n\n"
            . "    " . $otp . "\n\n"
            . "It expires in " . $minutes . " minutes and can be used once.\n"
            . "If you did not try to sign in, you can ignore this message, and you should change your password.\n"
            . "Never share this code with anyone.";

        $this->smtp->send($this->fromAddress, $email, 'Your verification code', $body);
        return ['delivery' => 'email'];
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

/** How a one-time code reaches the user. */
interface OtpDelivery
{
    /**
     * Sends (or otherwise delivers) the code.
     *
     * @return array<string,mixed> Extra fields to merge into the login API response.
     * @throws \RuntimeException when delivery fails.
     */
    public function deliver(string $email, string $name, string $otp, int $ttlSeconds): array;
}

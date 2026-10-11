<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * DEVELOPMENT ONLY. Returns the code in the API response so the flow can be demonstrated
 * without an SMTP server. Config refuses to start with OTP_DELIVERY=dev when
 * APP_ENV=production, so this class can never be active in a production deployment.
 */
final class DevOtpDelivery implements OtpDelivery
{
    public function deliver(string $email, string $name, string $otp, int $ttlSeconds): array
    {
        return ['delivery' => 'development', 'dev_otp' => $otp];
    }
}

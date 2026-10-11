<?php

declare(strict_types=1);

namespace App;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Mail\DevOtpDelivery;
use App\Mail\EmailOtpDelivery;
use App\Mail\OtpDelivery;
use App\Mail\SmtpClient;

/**
 * Wires configuration, database and kernel together, and turns configuration or
 * database failures into safe, generic error responses.
 */
final class Application
{
    public static function respond(?string $envFile = null, ?Request $request = null, ?OtpDelivery $delivery = null): Response
    {
        try {
            $config = Config::fromEnvFile($envFile);
            $request ??= Request::fromGlobals($config->bool('TRUST_PROXY'));
            $pdo = Database::connect($config);
            $kernel = new Kernel($config, $pdo, $delivery ?? self::deliveryFor($config));
            return $kernel->handle($request);
        } catch (ConfigException $e) {
            // Details (setting names only, never values) go to the server log, not to the client.
            error_log('[login-auth] configuration error: ' . $e->getMessage());
            return self::failure(500, 'server_misconfigured', 'The server is not configured correctly.');
        } catch (\PDOException $e) {
            error_log('[login-auth] database connection failed: ' . $e->getMessage());
            return self::failure(503, 'service_unavailable', 'The service is temporarily unavailable.');
        } catch (\Throwable $e) {
            error_log('[login-auth] startup failure: ' . get_class($e) . ': ' . $e->getMessage());
            return self::failure(500, 'server_error', 'Something went wrong on the server.');
        }
    }

    private static function deliveryFor(Config $config): OtpDelivery
    {
        if ($config->otpDelivery() === 'dev') {
            return new DevOtpDelivery(); // Config::validate() already rejected this in production
        }
        return new EmailOtpDelivery(
            new SmtpClient(
                $config->get('SMTP_HOST'),
                $config->smtpPort(),
                $config->smtpSecure(),
                $config->get('SMTP_USER'),
                $config->get('SMTP_PASS'),
            ),
            $config->get('SMTP_FROM'),
        );
    }

    private static function failure(int $status, string $code, string $message): Response
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}

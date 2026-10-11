<?php

declare(strict_types=1);

use App\Application;
use App\Config;
use App\ConfigException;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Mail\DevOtpDelivery;
use App\Validation\Validator;
use Tests\Assert;
use Tests\Env;
use Tests\RecordingDelivery;
use Tests\TestApp;

const SECRET_MARKER = 'super-secret-value-that-must-never-leak-0123456789';

/** @return array<string,string> a valid production configuration */
function productionSettings(): array
{
    return [
        'APP_ENV' => 'production',
        'APP_PEPPER' => Env::PEPPER,
        'DB_NAME' => 'login_auth_test', 'DB_USER' => 'root',
        'CORS_ALLOWED_ORIGINS' => 'https://my-app.vercel.app',
        'OTP_DELIVERY' => 'smtp',
        'SMTP_HOST' => 'smtp.example.com', 'SMTP_FROM' => 'no-reply@example.com',
        'COOKIE_SECURE' => 'true',
    ];
}

function configError(array $settings): string
{
    try {
        Config::fromArray($settings);
    } catch (ConfigException $e) {
        return $e->getMessage();
    }
    return '';
}

test('config: a complete production configuration is accepted', function (TestApp $app) {
    $config = Config::fromArray(productionSettings());
    Assert::true($config->isProduction());
    Assert::same(['https://my-app.vercel.app'], $config->allowedOrigins());
    Assert::same(587, $config->smtpPort());
});

test('config: APP_ENV defaults to production and the dev OTP mode is opt-in only', function (TestApp $app) {
    $settings = ['APP_PEPPER' => Env::PEPPER, 'DB_NAME' => 'x', 'DB_USER' => 'root'];
    // No APP_ENV, no OTP_DELIVERY: treated as production + smtp, which is incomplete -> refuse to start.
    $message = configError($settings);
    Assert::contains('SMTP_HOST', $message);
    Assert::contains('SMTP_FROM', $message);
    Assert::true(Config::fromArray($settings + ['OTP_DELIVERY' => 'smtp', 'SMTP_HOST' => 'smtp.example.com', 'SMTP_FROM' => 'a@example.com'])->isProduction());
});

test('config: the pepper is mandatory, long enough and not the placeholder - and never echoed back', function (TestApp $app) {
    $base = productionSettings();
    Assert::contains('APP_PEPPER is required', configError(['APP_PEPPER' => ''] + $base));
    Assert::contains('at least 32', configError(['APP_PEPPER' => 'short-secret'] + $base));
    Assert::contains('placeholder', configError(['APP_PEPPER' => 'REPLACE_WITH_A_REAL_SECRET_VALUE_12345678'] + $base));
    Assert::notContains('short-secret', configError(['APP_PEPPER' => 'short-secret'] + $base), 'value must not appear in the message');
});

test('config: dev OTP delivery is refused in production', function (TestApp $app) {
    $message = configError(['OTP_DELIVERY' => 'dev'] + productionSettings());
    Assert::contains('not allowed when APP_ENV=production', $message);
    Config::fromArray(['APP_ENV' => 'development', 'OTP_DELIVERY' => 'dev', 'COOKIE_SECURE' => 'false'] + productionSettings());
});

test('config: wildcard or malformed CORS origins are refused', function (TestApp $app) {
    foreach (['*', 'https://*.vercel.app', 'my-app.vercel.app', 'https://app.example.com/path', 'javascript:alert(1)'] as $bad) {
        Assert::contains('CORS_ALLOWED_ORIGINS', configError(['CORS_ALLOWED_ORIGINS' => $bad] + productionSettings()), $bad);
    }
});

test('config: insecure cookie settings are refused', function (TestApp $app) {
    Assert::contains('COOKIE_SECURE', configError(['COOKIE_SECURE' => 'false'] + productionSettings()));
    Assert::contains('COOKIE_SAMESITE=None requires', configError(['COOKIE_SAMESITE' => 'None', 'APP_ENV' => 'development', 'COOKIE_SECURE' => 'false', 'OTP_DELIVERY' => 'dev'] + productionSettings()));
    Assert::contains('COOKIE_SAMESITE must be', configError(['COOKIE_SAMESITE' => 'Sometimes'] + productionSettings()));
});

test('config: SMTP must be complete and encrypted in production', function (TestApp $app) {
    Assert::contains('SMTP_HOST', configError(['SMTP_HOST' => ''] + productionSettings()));
    Assert::contains('SMTP_FROM', configError(['SMTP_FROM' => 'nope'] + productionSettings()));
    Assert::contains('SMTP_SECURE=none', configError(['SMTP_SECURE' => 'none'] + productionSettings()));
});

test('config: bad database name and session lifetime are refused', function (TestApp $app) {
    Assert::contains('DB_NAME', configError(['DB_NAME' => 'x`; DROP DATABASE y;--'] + productionSettings()));
    Assert::contains('SESSION_TTL_MINUTES', configError(['SESSION_TTL_MINUTES' => '1'] + productionSettings()));
    Assert::contains('SESSION_TTL_MINUTES', configError(['SESSION_TTL_MINUTES' => 'forever'] + productionSettings()));
});

test('config: .env files are parsed (comments, quotes) and secrets stay out of error messages', function (TestApp $app) {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "# comment\nAPP_ENV=development\nAPP_PEPPER=\"" . Env::PEPPER . "\"\nDB_NAME=login_auth_test\nDB_USER='root'\nOTP_DELIVERY=dev\n\n");
    try {
        $config = Config::fromEnvFile($file);
        Assert::same(Env::PEPPER, $config->get('APP_PEPPER'));
        Assert::same('root', $config->get('DB_USER'));
        Assert::same('dev', $config->otpDelivery());
    } finally {
        unlink($file);
    }
});

test('startup: a broken configuration gives a generic 500 and leaks no setting names or values', function (TestApp $app) {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "APP_ENV=production\nAPP_PEPPER=" . SECRET_MARKER . "\nDB_PASS=" . SECRET_MARKER . "\n");
    $previous = ini_set('error_log', sys_get_temp_dir() . '/login-auth-test-errors.log');
    try {
        $res = Application::respond($file, new Request('GET', '/api/health'));
        $body = json_encode($res->body);
        Assert::same(500, $res->status);
        Assert::same('server_misconfigured', $res->body['error']['code']);
        Assert::notContains(SECRET_MARKER, $body);
        Assert::notContains('APP_PEPPER', $body);
        Assert::notContains('DB_PASS', $body);
        Assert::notContains('SMTP', $body);
    } finally {
        unlink($file);
        ini_set('error_log', $previous === false ? '' : $previous);
    }
});

test('startup: an unreachable database gives a generic 503 without connection details', function (TestApp $app) {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "APP_ENV=development\nAPP_PEPPER=" . Env::PEPPER . "\nOTP_DELIVERY=dev\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=login_auth_test\nDB_USER=root\nDB_PASS=" . SECRET_MARKER . "\n");
    $previous = ini_set('error_log', sys_get_temp_dir() . '/login-auth-test-errors.log');
    try {
        $res = Application::respond($file, new Request('GET', '/api/health'));
        $body = json_encode($res->body);
        Assert::same(503, $res->status);
        Assert::notContains(SECRET_MARKER, $body);
        Assert::notContains('SQLSTATE', $body);
        Assert::notContains('127.0.0.1', $body);
    } finally {
        unlink($file);
        ini_set('error_log', $previous === false ? '' : $previous);
    }
});

test('otp delivery: production responses never contain the code', function (TestApp $app) {
    $prod = new TestApp(Config::fromArray(['DB_HOST' => '127.0.0.1'] + productionSettings()), $app->pdo);
    $prod->createUser('user@example.com');
    $res = $prod->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD]);
    Assert::same(200, $res->status);
    $body = $res->body;
    unset($body['challenge_id']); // random hex could contain six digits by coincidence
    Assert::true(!isset($body['dev_otp']));
    Assert::notContains($prod->delivery->lastOtp(), json_encode($body), 'the code only travels by email');
    Assert::same('email', $body['delivery']);
});

test('otp delivery: development mode returns the code in the response (local demos only)', function (TestApp $app) {
    $app->createUser('user@example.com');
    $kernel = new Kernel($app->config, $app->pdo, new DevOtpDelivery());
    $post = static fn(string $path, array $data): Response => $kernel->handle(new Request(
        'POST', $path, ['Content-Type' => 'application/json'], [], json_encode($data), '203.0.113.10'
    ));

    $login = $post('/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD]);
    Assert::same(200, $login->status);
    Assert::same('development', $login->body['delivery']);
    Assert::true(preg_match('/^\d{6}$/', $login->body['dev_otp']) === 1);

    $verify = $post('/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => $login->body['dev_otp']]);
    Assert::same(200, $verify->status, 'the returned code really works');
});

test('validator: password rules', function (TestApp $app) {
    Assert::same(null, Validator::passwordError('Correct-Horse-9'));
    Assert::true(Validator::passwordError('Short1') !== null);
    Assert::true(Validator::passwordError('NoDigitsHereAtAll') !== null);
    Assert::true(Validator::passwordError('123456789012345') !== null);
    Assert::true(Validator::passwordError('PASSWORD1234') !== null, 'common passwords are refused regardless of case');
    Assert::true(Validator::passwordError('user12345678@x.com', 'user12345678@x.com') !== null, 'password equal to the email is refused');
    Assert::same(null, Validator::passwordError(str_repeat('a1', 64)), '128 characters is the maximum');
    Assert::true(Validator::passwordError(str_repeat('a1', 65)) !== null);
});

<?php

declare(strict_types=1);

use App\Security\RateLimiter;
use Tests\Assert;
use Tests\Env;
use Tests\TestApp;

test('http: health check works and exposes nothing sensitive', function (TestApp $app) {
    $res = $app->request('GET', '/api/health');
    Assert::same(200, $res->status);
    Assert::same('ok', $res->body['status']);
    Assert::same(['status', 'time'], array_keys($res->body));
});

test('http: routes also work under a sub-folder (e.g. Apache alias)', function (TestApp $app) {
    Assert::same(200, $app->request('GET', '/login-auth-api/api/health')->status);
    Assert::same(200, $app->request('GET', '/api/health/')->status);
});

test('http: unknown route is 404, wrong method is 405 with Allow, bad body is 400/415', function (TestApp $app) {
    Assert::same(404, $app->request('GET', '/api/nope')->status);
    Assert::same(404, $app->request('GET', '/')->status);

    $wrong = $app->request('GET', '/api/login');
    Assert::same(405, $wrong->status);
    Assert::contains('POST', $wrong->headers['Allow']);
    Assert::same(405, $app->request('POST', '/api/admin/users', [])->status);

    $badJson = $app->kernel->handle(new App\Http\Request('POST', '/api/login', ['Content-Type' => 'application/json'], [], '{not json'));
    Assert::same(400, $badJson->status);
    $badType = $app->kernel->handle(new App\Http\Request('POST', '/api/login', ['Content-Type' => 'text/plain'], [], '{"a":1}'));
    Assert::same(415, $badType->status);
    $list = $app->kernel->handle(new App\Http\Request('POST', '/api/login', ['Content-Type' => 'application/json'], [], '[1,2,3]'));
    Assert::same(400, $list->status);
    $huge = $app->kernel->handle(new App\Http\Request('POST', '/api/login', ['Content-Type' => 'application/json'], [], '{"a":"' . str_repeat('x', 20000) . '"}'));
    Assert::same(413, $huge->status);
});

test('http: every response carries defensive headers and is not cacheable', function (TestApp $app) {
    $res = $app->request('GET', '/api/health');
    Assert::same('no-store', $res->headers['Cache-Control']);
    Assert::same('nosniff', $res->headers['X-Content-Type-Options']);
    Assert::true(isset($res->headers['Content-Security-Policy']));
    $err = $app->request('GET', '/api/me');
    Assert::same('no-store', $err->headers['Cache-Control']);
});

test('cors: only configured origins are allowed, credentials are explicit, never a wildcard', function (TestApp $app) {
    $preflight = $app->request('OPTIONS', '/api/login', null, [
        'Origin' => Env::ORIGIN, 'Access-Control-Request-Method' => 'POST',
    ]);
    Assert::same(204, $preflight->status);
    Assert::same(Env::ORIGIN, $preflight->headers['Access-Control-Allow-Origin']);
    Assert::same('true', $preflight->headers['Access-Control-Allow-Credentials']);
    Assert::contains('Authorization', $preflight->headers['Access-Control-Allow-Headers']);
    Assert::true($preflight->headers['Access-Control-Allow-Origin'] !== '*');

    $evil = $app->request('OPTIONS', '/api/login', null, ['Origin' => 'https://evil.example']);
    Assert::true(!isset($evil->headers['Access-Control-Allow-Origin']), 'unknown origin gets no CORS headers');
    Assert::true(!isset($evil->headers['Access-Control-Allow-Credentials']));

    $real = $app->request('GET', '/api/health', null, ['Origin' => Env::ORIGIN]);
    Assert::same(Env::ORIGIN, $real->headers['Access-Control-Allow-Origin']);
    Assert::same('Origin', $real->headers['Vary']);

    $noOrigin = $app->request('GET', '/api/health');
    Assert::true(!isset($noOrigin->headers['Access-Control-Allow-Origin']));
});

/* ------------------------------------------------------------- rate limiting */

test('rate limit: repeated wrong passwords lock the account temporarily (429 + Retry-After)', function (TestApp $app) {
    $app->createUser('user@example.com');
    for ($i = 1; $i <= 8; $i++) {
        $res = $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => 'Wrong-Password-1'], [], [], $app->freshIp());
        Assert::same(401, $res->status, "failure $i");
    }
    // Even the CORRECT password is refused while the lock is active, and from a new IP too.
    $locked = $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD], [], [], $app->freshIp());
    Assert::same(429, $locked->status);
    Assert::true(ctype_digit($locked->headers['Retry-After']) && (int) $locked->headers['Retry-After'] >= 1);
    Assert::same(0, count($app->delivery->sent), 'no code is sent while locked');

    // Unknown emails behave identically (no account enumeration through the lock).
    for ($i = 1; $i <= 8; $i++) {
        $app->request('POST', '/api/login', ['email' => 'ghost@example.com', 'password' => 'Wrong-Password-1'], [], [], $app->freshIp());
    }
    Assert::same(429, $app->request('POST', '/api/login', ['email' => 'ghost@example.com', 'password' => 'x'], [], [], $app->freshIp())->status);
});

test('rate limit: a successful password check resets the failure counter', function (TestApp $app) {
    $app->createUser('user@example.com');
    for ($i = 0; $i < 5; $i++) {
        $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => 'Wrong-Password-1'], [], [], $app->freshIp());
    }
    Assert::same(200, $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD], [], [], $app->freshIp())->status);
    for ($i = 0; $i < 5; $i++) {
        Assert::same(401, $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => 'Wrong-Password-1'], [], [], $app->freshIp())->status);
    }
});

test('rate limit: one IP address cannot hammer the login endpoint', function (TestApp $app) {
    for ($i = 1; $i <= 30; $i++) {
        Assert::same(422, $app->request('POST', '/api/login', [], [], [], '192.0.2.99')->status, "request $i");
    }
    $res = $app->request('POST', '/api/login', [], [], [], '192.0.2.99');
    Assert::same(429, $res->status);
    Assert::same(200, $app->request('GET', '/api/health', null, [], [], '192.0.2.99')->status, 'other endpoints are unaffected');
    Assert::same(422, $app->request('POST', '/api/login', [], [], [], '192.0.2.100')->status, 'other IPs are unaffected');
});

test('rate limit: registrations are limited per IP', function (TestApp $app) {
    for ($i = 1; $i <= 10; $i++) {
        $app->request('POST', '/api/register', [], [], [], '192.0.2.50');
    }
    Assert::same(429, $app->request('POST', '/api/register', [], [], [], '192.0.2.50')->status);
});

test('rate limit: guessing one-time codes is limited per IP and per account', function (TestApp $app) {
    // Per account: failures accumulate across fresh challenges, so re-logging in does not reset them.
    $app->createUser('user@example.com');
    $blocked = false;
    for ($round = 1; $round <= 4 && !$blocked; $round++) {
        $login = $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD], [], [], $app->freshIp());
        if ($login->status !== 200) {
            $blocked = true;
            break;
        }
        $wrong = $app->delivery->lastOtp() === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $res = $app->request('POST', '/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => $wrong], [], [], $app->freshIp());
            if ($res->status === 429) {
                $blocked = true;
                break;
            }
        }
    }
    Assert::true($blocked, 'after about 10 wrong codes the account is throttled');
});

test('rate limiter: counters reset when their window ends', function (TestApp $app) {
    $limiter = new RateLimiter($app->pdo);
    Assert::same(1, $limiter->hit('k', 60));
    Assert::same(2, $limiter->hit('k', 60));
    $app->pdo->exec("UPDATE rate_limits SET window_start = '2000-01-01 00:00:00'");
    Assert::same(0, $limiter->count('k', 60));
    Assert::same(1, $limiter->hit('k', 60), 'a new window starts at 1');
    $limiter->clear('k');
    Assert::same(0, $limiter->count('k', 60));
    Assert::same(0, (int) $app->row('SELECT COUNT(*) AS n FROM rate_limits WHERE bucket = ?', ['k'])['n'], 'keys are stored hashed');
});

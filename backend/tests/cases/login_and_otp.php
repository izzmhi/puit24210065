<?php

declare(strict_types=1);

use App\Security\Tokens;
use Tests\Assert;
use Tests\Env;
use Tests\TestApp;

/** Starts a login and returns [loginResponse, otp]. */
function startLogin(TestApp $app, string $email = 'user@example.com'): array
{
    $login = $app->request('POST', '/api/login', ['email' => $email, 'password' => Env::PASSWORD]);
    Assert::same(200, $login->status, 'password step');
    return [$login, $app->delivery->lastOtp()];
}

function wrongCodeFor(string $otp): string
{
    return $otp === '000000' ? '111111' : '000000';
}

/* ------------------------------------------------------------ password check */

test('login: wrong password and unknown email return the same generic 401', function (TestApp $app) {
    $app->createUser('user@example.com');
    $wrong = $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => 'Wrong-Password-1']);
    $unknown = $app->request('POST', '/api/login', ['email' => 'nobody@example.com', 'password' => 'Wrong-Password-1']);

    Assert::same(401, $wrong->status);
    Assert::same(401, $unknown->status);
    Assert::same($wrong->body, $unknown->body, 'no hint about which part was wrong');
    Assert::same('Invalid email or password.', $wrong->body['error']['message']);
    Assert::same(0, count($app->delivery->sent), 'no code is sent after a failed password');
    Assert::same(0, $app->count('otp_challenges'));
});

test('login: correct password starts 2FA but does NOT create a session', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);

    Assert::true(preg_match('/^[a-f0-9]{64}$/', $login->body['challenge_id']) === 1, 'opaque random challenge id');
    Assert::same(300, $login->body['expires_in']);
    Assert::same('u***@example.com', $login->body['email_hint']);
    Assert::true(!isset($login->body['token']), 'no session token before the code is verified');
    Assert::same(0, $app->count('sessions'));
    Assert::same(1, $app->count('otp_challenges'));
    Assert::true(preg_match('/^\d{6}$/', $otp) === 1);

    // Without the code the user is still locked out.
    Assert::same(401, $app->request('GET', '/api/me')->status);
});

test('login: missing fields are rejected before any password work', function (TestApp $app) {
    Assert::same(422, $app->request('POST', '/api/login', [])->status);
    Assert::same(422, $app->request('POST', '/api/login', ['email' => 'a@b.co'])->status);
    Assert::same(422, $app->request('POST', '/api/login', ['email' => ['x'], 'password' => ['y']])->status);
});

/* ------------------------------------------------------------------- the OTP */

test('otp: codes are always six digits and vary', function (TestApp $app) {
    $seen = [];
    for ($i = 0; $i < 500; $i++) {
        $code = Tokens::otp();
        Assert::true(preg_match('/^\d{6}$/', $code) === 1, "bad code '$code'");
        $seen[$code] = true;
    }
    Assert::true(count($seen) > 400, 'codes should be well distributed');
});

test('otp: only hashes are stored, never the code or the challenge id', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);
    $row = $app->row('SELECT challenge_hash, otp_hash FROM otp_challenges');

    Assert::true($row['otp_hash'] !== $otp && preg_match('/^[a-f0-9]{64}$/', $row['otp_hash']) === 1);
    Assert::true($row['challenge_hash'] !== $login->body['challenge_id']);
    Assert::same(Tokens::hash($login->body['challenge_id']), $row['challenge_hash']);
    // The plain six-digit hash must not be guessable without the server secret.
    Assert::true($row['otp_hash'] !== hash('sha256', $otp));
});

test('otp: correct code opens a session; the token is stored only as a hash', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);

    $verify = $app->request('POST', '/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => $otp]);
    Assert::same(200, $verify->status);
    Assert::same('token', $verify->body['session']);
    $token = $verify->body['token'];
    Assert::true(preg_match('/^[a-f0-9]{64}$/', $token) === 1, '256-bit random token');
    Assert::same('user@example.com', $verify->body['user']['email']);
    Assert::same('user', $verify->body['user']['role']);
    Assert::true(!isset($verify->body['user']['password_hash']));

    $session = $app->row('SELECT token_hash, revoked_at FROM sessions');
    Assert::same(hash('sha256', $token), $session['token_hash']);
    Assert::true($session['token_hash'] !== $token, 'raw token is not stored');
    Assert::same(null, $session['revoked_at']);

    $me = $app->request('GET', '/api/me', null, $app->bearer($token));
    Assert::same(200, $me->status);
    Assert::same('user@example.com', $me->body['user']['email']);
    Assert::true($app->row('SELECT last_login_at FROM users')['last_login_at'] !== null);
});

test('otp: a wrong code is rejected, counted and creates no session', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);

    $res = $app->request('POST', '/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => wrongCodeFor($otp)]);
    Assert::same(401, $res->status);
    Assert::same('incorrect_code', $res->body['error']['code']);
    Assert::same(4, $res->body['error']['details']['attempts_remaining']);
    Assert::same(1, (int) $app->row('SELECT attempts FROM otp_challenges')['attempts']);
    Assert::same(0, $app->count('sessions'));
});

test('otp: the attempt limit locks the code, even for the correct value', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);
    $id = $login->body['challenge_id'];

    for ($i = 1; $i <= 5; $i++) {
        $res = $app->request('POST', '/api/verify-otp', ['challenge_id' => $id, 'otp' => wrongCodeFor($otp)]);
        Assert::same(401, $res->status, "attempt $i");
        Assert::same(5 - $i, $res->body['error']['details']['attempts_remaining']);
    }
    $correct = $app->request('POST', '/api/verify-otp', ['challenge_id' => $id, 'otp' => $otp]);
    Assert::same(429, $correct->status, 'the right code no longer works after 5 failures');
    Assert::same(0, $app->count('sessions'));
});

test('otp: a code can be used only once', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);
    $body = ['challenge_id' => $login->body['challenge_id'], 'otp' => $otp];

    Assert::same(200, $app->request('POST', '/api/verify-otp', $body)->status);
    $replay = $app->request('POST', '/api/verify-otp', $body);
    Assert::same(401, $replay->status);
    Assert::same('invalid_code', $replay->body['error']['code']);
    Assert::same(1, $app->count('sessions'), 'replay must not create a second session');
});

test('otp: an expired code is rejected', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $otp] = startLogin($app);
    $app->pdo->exec("UPDATE otp_challenges SET expires_at = '2000-01-01 00:00:00'");

    $res = $app->request('POST', '/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => $otp]);
    Assert::same(401, $res->status);
    Assert::same('invalid_code', $res->body['error']['code']);
    Assert::same(0, $app->count('sessions'));
});

test('otp: the code expires after exactly five minutes', function (TestApp $app) {
    $app->createUser('user@example.com');
    startLogin($app);
    $row = $app->row('SELECT created_at, expires_at FROM otp_challenges');
    $seconds = strtotime($row['expires_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC');
    Assert::same(300, $seconds);
});

test('otp: unknown or malformed challenges are rejected', function (TestApp $app) {
    $unknown = $app->request('POST', '/api/verify-otp', ['challenge_id' => str_repeat('a', 64), 'otp' => '123456']);
    Assert::same(401, $unknown->status);
    Assert::same(422, $app->request('POST', '/api/verify-otp', ['challenge_id' => 'short', 'otp' => '123456'])->status);
    Assert::same(422, $app->request('POST', '/api/verify-otp', ['challenge_id' => str_repeat('a', 64), 'otp' => '12ab56'])->status);
    Assert::same(422, $app->request('POST', '/api/verify-otp', [])->status);
});

test('otp: signing in again invalidates the earlier code', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$first, $firstOtp] = startLogin($app);
    [$second, $secondOtp] = startLogin($app);

    $old = $app->request('POST', '/api/verify-otp', ['challenge_id' => $first->body['challenge_id'], 'otp' => $firstOtp]);
    Assert::same(401, $old->status);
    $new = $app->request('POST', '/api/verify-otp', ['challenge_id' => $second->body['challenge_id'], 'otp' => $secondOtp]);
    Assert::same(200, $new->status);
});

test('otp: another account cannot use someone else\'s code', function (TestApp $app) {
    $app->createUser('victim@example.com');
    $app->createUser('attacker@example.com');
    [$victim, $victimOtp] = startLogin($app, 'victim@example.com');
    [$attacker] = startLogin($app, 'attacker@example.com');

    // The victim's code does not unlock the attacker's challenge.
    $res = $app->request('POST', '/api/verify-otp', ['challenge_id' => $attacker->body['challenge_id'], 'otp' => $victimOtp]);
    Assert::same(401, $res->status);
    Assert::same(0, $app->count('sessions'));
});

test('otp: resend needs a cooldown, then replaces the previous code', function (TestApp $app) {
    $app->createUser('user@example.com');
    [$login, $oldOtp] = startLogin($app);

    $tooSoon = $app->request('POST', '/api/resend-otp', ['challenge_id' => $login->body['challenge_id']]);
    Assert::same(429, $tooSoon->status);
    Assert::true(isset($tooSoon->headers['Retry-After']));

    // Pretend the first code was issued long ago (but is still valid), so the cooldown has passed.
    $app->pdo->exec("UPDATE otp_challenges SET created_at = '2000-01-01 00:00:00', expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
    $resend = $app->request('POST', '/api/resend-otp', ['challenge_id' => $login->body['challenge_id']]);
    Assert::same(200, $resend->status);
    Assert::true($resend->body['challenge_id'] !== $login->body['challenge_id']);
    Assert::same(2, count($app->delivery->sent));

    $old = $app->request('POST', '/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => $oldOtp]);
    Assert::same(401, $old->status, 'the first code is dead');
    $new = $app->request('POST', '/api/verify-otp', ['challenge_id' => $resend->body['challenge_id'], 'otp' => $app->delivery->lastOtp()]);
    Assert::same(200, $new->status);
});

test('otp: if the email cannot be sent, login fails safely and no code remains valid', function (TestApp $app) {
    $app->createUser('user@example.com');
    $app->delivery->fail = true;
    $res = $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD]);
    Assert::same(503, $res->status);
    Assert::notContains('SMTP', json_encode($res->body), 'internal details are not leaked');
    Assert::same(0, $app->count('otp_challenges'));
});

/* ------------------------------------------------------------------ sessions */

test('session: missing, malformed and unknown tokens are rejected', function (TestApp $app) {
    Assert::same(401, $app->request('GET', '/api/me')->status);
    Assert::same(401, $app->request('GET', '/api/me', null, ['Authorization' => 'Bearer not-a-token'])->status);
    Assert::same(401, $app->request('GET', '/api/me', null, ['Authorization' => 'Bearer ' . str_repeat('0', 64)])->status);
    Assert::same(401, $app->request('GET', '/api/me', null, ['Authorization' => 'Basic abc'])->status);
    Assert::same(401, $app->request('GET', '/api/me', null, [], ['sid' => str_repeat('0', 64)])->status);
});

test('session: an expired session is rejected', function (TestApp $app) {
    $app->createUser('user@example.com');
    $token = $app->signIn('user@example.com');
    Assert::same(200, $app->request('GET', '/api/me', null, $app->bearer($token))->status);

    $app->pdo->exec("UPDATE sessions SET expires_at = '2000-01-01 00:00:00'");
    $res = $app->request('GET', '/api/me', null, $app->bearer($token));
    Assert::same(401, $res->status);
});

test('session: sessions last as long as SESSION_TTL_MINUTES says', function (TestApp $app) {
    $custom = Env::app(['SESSION_TTL_MINUTES' => '10']);
    $custom->createUser('user@example.com');
    $custom->signIn('user@example.com');
    $row = $custom->row('SELECT created_at, expires_at FROM sessions');
    Assert::same(600, strtotime($row['expires_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC'));
});

test('session: logout revokes the token on the server', function (TestApp $app) {
    $app->createUser('user@example.com');
    $token = $app->signIn('user@example.com');

    $out = $app->request('POST', '/api/logout', null, $app->bearer($token));
    Assert::same(200, $out->status);
    Assert::true($app->row('SELECT revoked_at FROM sessions')['revoked_at'] !== null);
    Assert::same(401, $app->request('GET', '/api/me', null, $app->bearer($token))->status, 'old token is dead');
});

test('session: logging out with an already-invalid token is harmless', function (TestApp $app) {
    Assert::same(200, $app->request('POST', '/api/logout', null, ['Authorization' => 'Bearer ' . str_repeat('1', 64)])->status);
    Assert::same(200, $app->request('POST', '/api/logout')->status);
});

test('session: web sign-in uses an HttpOnly cookie and never returns the token in the body', function (TestApp $app) {
    $app->createUser('user@example.com');
    $web = ['X-Client-Type' => 'web', 'Origin' => Env::ORIGIN];
    $login = $app->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => Env::PASSWORD], $web);

    // A page from another site cannot complete the sign-in.
    $evil = $app->request('POST', '/api/verify-otp', [
        'challenge_id' => $login->body['challenge_id'], 'otp' => $app->delivery->lastOtp(),
    ], ['X-Client-Type' => 'web', 'Origin' => 'https://evil.example']);
    Assert::same(403, $evil->status);

    $verify = $app->request('POST', '/api/verify-otp', [
        'challenge_id' => $login->body['challenge_id'], 'otp' => $app->delivery->lastOtp(),
    ], $web);
    Assert::same(200, $verify->status, 'the rejected cross-origin attempt did not burn the code');
    Assert::same('cookie', $verify->body['session']);
    Assert::true(!isset($verify->body['token']), 'token is not exposed to scripts');

    Assert::same(1, count($verify->cookies));
    $cookie = $verify->cookies[0];
    Assert::same('sid', $cookie['name']);
    Assert::same(true, $cookie['options']['httponly']);
    Assert::same('Lax', $cookie['options']['samesite']);
    Assert::same('/', $cookie['options']['path']);

    $me = $app->request('GET', '/api/me', null, $web, ['sid' => $cookie['value']]);
    Assert::same(200, $me->status);

    // CSRF: logout via cookie needs an allowed Origin AND the custom header.
    $noOrigin = $app->request('POST', '/api/logout', null, ['X-Client-Type' => 'web'], ['sid' => $cookie['value']]);
    Assert::same(403, $noOrigin->status);
    $wrongOrigin = $app->request('POST', '/api/logout', null, ['X-Client-Type' => 'web', 'Origin' => 'https://evil.example'], ['sid' => $cookie['value']]);
    Assert::same(403, $wrongOrigin->status);
    $noHeader = $app->request('POST', '/api/logout', null, ['Origin' => Env::ORIGIN], ['sid' => $cookie['value']]);
    Assert::same(403, $noHeader->status);
    Assert::same(200, $app->request('GET', '/api/me', null, [], ['sid' => $cookie['value']])->status, 'still signed in after rejected logouts');

    $out = $app->request('POST', '/api/logout', null, $web, ['sid' => $cookie['value']]);
    Assert::same(200, $out->status);
    Assert::same('', $out->cookies[0]['value'], 'cookie is cleared');
    Assert::same(401, $app->request('GET', '/api/me', null, [], ['sid' => $cookie['value']])->status);
});

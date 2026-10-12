<?php

declare(strict_types=1);

use Tests\Assert;
use Tests\Env;
use Tests\TestApp;

test('admin: an administrator can list users, and password hashes are never included', function (TestApp $app) {
    $app->createUser('admin@example.com', 'admin', Env::PASSWORD, 'Admin Person');
    $app->createUser('student@example.com', 'user', Env::PASSWORD, 'Student Person');
    $token = $app->signIn('admin@example.com');

    $res = $app->request('GET', '/api/admin/users', null, $app->bearer($token));
    Assert::same(200, $res->status);
    Assert::same(2, $res->body['total']);
    Assert::same(2, count($res->body['users']));
    Assert::same(['admins' => 1, 'users' => 1, 'active_sessions' => 1], $res->body['summary']);

    // Admins see how a password is stored (algorithm + salt), but never the full hash.
    $json = json_encode($res->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    Assert::notContains('password_hash', $json);
    foreach ($app->pdo->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN) as $fullHash) {
        Assert::notContains($fullHash, $json, 'the full hash is never sent');
        Assert::notContains(substr($fullHash, -20), $json, 'not even the end of the hash');
    }
    Assert::same(['id', 'name', 'email', 'role', 'created_at', 'last_login_at', 'password_storage'], array_keys($res->body['users'][0]));
    Assert::true(str_ends_with($res->body['users'][0]['password_storage'], '…'), 'preview is cut off');
    Assert::true(preg_match('/^\$(argon2id\$|2y\$)/', $res->body['users'][0]['password_storage']) === 1, 'shows the algorithm');
});

test('admin: a normal user is refused (403) on admin routes', function (TestApp $app) {
    $app->createUser('student@example.com');
    $token = $app->signIn('student@example.com');

    $res = $app->request('GET', '/api/admin/users', null, $app->bearer($token));
    Assert::same(403, $res->status);
    Assert::same('forbidden', $res->body['error']['code']);
    Assert::notContains('student@example.com', json_encode($res->body), 'no user data in the refusal');
});

test('admin: unauthenticated and revoked sessions get 401 on admin routes', function (TestApp $app) {
    Assert::same(401, $app->request('GET', '/api/admin/users')->status);

    $app->createUser('admin@example.com', 'admin');
    $token = $app->signIn('admin@example.com');
    $app->request('POST', '/api/logout', null, $app->bearer($token));
    Assert::same(401, $app->request('GET', '/api/admin/users', null, $app->bearer($token))->status);
});

test('admin: the role is read from the database, so client-side tampering has no effect', function (TestApp $app) {
    $app->createUser('student@example.com');
    $token = $app->signIn('student@example.com');

    $forged = $app->request(
        'GET',
        '/api/admin/users',
        null,
        $app->bearer($token) + ['X-Role' => 'admin', 'X-User-Role' => 'admin'],
        [],
        '203.0.113.10',
        ['role' => 'admin', 'admin' => '1'],
    );
    Assert::same(403, $forged->status);

    // Promotion happens only in the database (bin/set-role.php); it applies on the next request.
    $app->pdo->exec("UPDATE users SET role = 'admin' WHERE email = 'student@example.com'");
    Assert::same(200, $app->request('GET', '/api/admin/users', null, $app->bearer($token))->status);
    $app->pdo->exec("UPDATE users SET role = 'user' WHERE email = 'student@example.com'");
    Assert::same(403, $app->request('GET', '/api/admin/users', null, $app->bearer($token))->status, 'demotion also applies immediately');
});

test('admin: pagination parameters are bounded and safely parsed', function (TestApp $app) {
    $app->createUser('admin@example.com', 'admin');
    for ($i = 1; $i <= 3; $i++) {
        $app->createUser("user$i@example.com");
    }
    $token = $app->signIn('admin@example.com');

    $page = $app->request('GET', '/api/admin/users', null, $app->bearer($token), [], '203.0.113.10', ['limit' => '2', 'offset' => '1']);
    Assert::same(2, count($page->body['users']));
    Assert::same(4, $page->body['total']);

    $junk = $app->request('GET', '/api/admin/users', null, $app->bearer($token), [], '203.0.113.10', ['limit' => "1; DROP TABLE users", 'offset' => '-5']);
    Assert::same(200, $junk->status);
    Assert::same(4, count($junk->body['users']));
});

test('admin emails: a listed email becomes admin only after password + code, never by registering', function (TestApp $app) {
    $app = Env::app(['ADMIN_EMAILS' => 'Boss@Example.com, second@example.com']);

    $reg = $app->request('POST', '/api/register', ['name' => 'The Boss', 'email' => 'boss@example.com', 'password' => Env::PASSWORD]);
    Assert::same('user', $reg->body['user']['role'], 'registering does not give admin');

    // Password correct but code not entered yet: still a normal user in the database.
    $app->request('POST', '/api/login', ['email' => 'boss@example.com', 'password' => Env::PASSWORD]);
    Assert::same('user', $app->row('SELECT role FROM users WHERE email = ?', ['boss@example.com'])['role']);

    $token = $app->signIn('boss@example.com');
    $me = $app->request('GET', '/api/me', null, $app->bearer($token));
    Assert::same('admin', $me->body['user']['role'], 'admin after the emailed code');
    Assert::same(200, $app->request('GET', '/api/admin/users', null, $app->bearer($token))->status);
});

test('admin emails: the name does not matter and unlisted emails stay users', function (TestApp $app) {
    $app = Env::app(['ADMIN_EMAILS' => 'boss@example.com']);
    $app->request('POST', '/api/register', ['name' => 'Eric Addo', 'email' => 'someone@example.com', 'password' => Env::PASSWORD]);
    $token = $app->signIn('someone@example.com');
    $me = $app->request('GET', '/api/me', null, $app->bearer($token));
    Assert::same('user', $me->body['user']['role'], 'a name never grants admin');
    Assert::same(403, $app->request('GET', '/api/admin/users', null, $app->bearer($token))->status);
});

test('admin emails: invalid entries are refused at start-up', function (TestApp $app) {
    Assert::throws(App\ConfigException::class, fn() => Env::config(['ADMIN_EMAILS' => 'boss@example.com, not-an-email']));
});

test('http: /me returns only public fields for the current user', function (TestApp $app) {
    $app->createUser('student@example.com');
    $token = $app->signIn('student@example.com');
    $me = $app->request('GET', '/api/me', null, $app->bearer($token));
    Assert::same(['id', 'name', 'email', 'role', 'created_at', 'last_login_at'], array_keys($me->body['user']));
});

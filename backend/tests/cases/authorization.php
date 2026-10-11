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

    $json = json_encode($res->body);
    Assert::notContains('password', $json);
    Assert::notContains('$argon2', $json);
    Assert::notContains('$2y$', $json);
    Assert::same(['id', 'name', 'email', 'role', 'created_at', 'last_login_at'], array_keys($res->body['users'][0]));
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

test('http: /me returns only public fields for the current user', function (TestApp $app) {
    $app->createUser('student@example.com');
    $token = $app->signIn('student@example.com');
    $me = $app->request('GET', '/api/me', null, $app->bearer($token));
    Assert::same(['id', 'name', 'email', 'role', 'created_at', 'last_login_at'], array_keys($me->body['user']));
});

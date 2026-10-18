<?php

declare(strict_types=1);

use App\Security\Passwords;
use Tests\Assert;
use Tests\Env;
use Tests\TestApp;

test('register: creates an ordinary user and returns no secrets', function (TestApp $app) {
    $res = $app->request('POST', '/api/register', [
        'name' => 'Amina Yusuf', 'email' => 'Amina@Example.com', 'password' => Env::PASSWORD,
    ]);
    Assert::same(201, $res->status);
    Assert::same('student', $res->body['user']['role']);
    Assert::same('amina@example.com', $res->body['user']['email'], 'email is normalised to lower case');
    $json = json_encode($res->body);
    Assert::notContains('password', $json);
    Assert::notContains('hash', $json);
    Assert::same(1, $app->count('users'));
});

test('register: nobody becomes admin by registering (not even the first user, not by sending role=admin)', function (TestApp $app) {
    $first = $app->request('POST', '/api/register', [
        'name' => 'First User', 'email' => 'first@example.com', 'password' => Env::PASSWORD,
    ]);
    $sneaky = $app->request('POST', '/api/register', [
        'name' => 'Sneaky User', 'email' => 'sneaky@example.com', 'password' => Env::PASSWORD, 'role' => 'admin',
    ]);
    Assert::same('student', $first->body['user']['role']);
    Assert::same('student', $sneaky->body['user']['role']);
    Assert::same(0, (int) $app->row("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")['n']);
});

test('register: rejects a duplicate email, including different letter case', function (TestApp $app) {
    $body = ['name' => 'Dup User', 'email' => 'dup@example.com', 'password' => Env::PASSWORD];
    Assert::same(201, $app->request('POST', '/api/register', $body)->status);

    $again = $app->request('POST', '/api/register', $body);
    Assert::same(409, $again->status);
    Assert::same('email_taken', $again->body['error']['code']);

    $upper = $app->request('POST', '/api/register', ['email' => 'DUP@EXAMPLE.COM'] + $body);
    Assert::same(409, $upper->status);
    Assert::same(1, $app->count('users'));
});

test('register: validates name, email and password on the server', function (TestApp $app) {
    $valid = ['name' => 'Valid Name', 'email' => 'valid@example.com', 'password' => Env::PASSWORD];
    $cases = [
        'short password' => [['password' => 'Abc123xyz'] + $valid, 'password'],
        'no digit' => [['password' => 'OnlyLettersHere'] + $valid, 'password'],
        'no letter' => [['password' => '1234567890123'] + $valid, 'password'],
        'common password' => [['password' => 'Password1234'] + $valid, 'password'],
        'too long' => [['password' => str_repeat('a1', 70)] + $valid, 'password'],
        'bad email' => [['email' => 'not-an-email'] + $valid, 'email'],
        'empty name' => [['name' => ' '] + $valid, 'name'],
        'html in name' => [['name' => '<b>Bold</b>'] + $valid, 'name'],
        'non-string fields' => [['name' => ['x'], 'email' => 123, 'password' => true], 'name'],
    ];
    foreach ($cases as $label => [$body, $field]) {
        $res = $app->request('POST', '/api/register', $body, [], [], $app->freshIp());
        Assert::same(422, $res->status, $label);
        Assert::true(isset($res->body['error']['details'][$field]), "$label should report the '$field' field");
    }
    Assert::same(0, $app->count('users'));
    Assert::same(201, $app->request('POST', '/api/register', $valid, [], [], $app->freshIp())->status);
});

test('register: SQL-injection style input is stored literally and breaks nothing', function (TestApp $app) {
    $name = "Robert'); DROP TABLE users;-- O\"Brien";
    $res = $app->request('POST', '/api/register', [
        'name' => $name, 'email' => 'bobby@example.com', 'password' => Env::PASSWORD,
    ]);
    Assert::same(201, $res->status);
    Assert::same($name, $app->row('SELECT name FROM users WHERE email = ?', ['bobby@example.com'])['name']);

    $login = $app->request('POST', '/api/login', ['email' => "' OR '1'='1' -- ", 'password' => "' OR '1'='1"]);
    Assert::same(401, $login->status, 'injection attempt is just a failed login, not a 500');
    Assert::same(1, $app->count('users'));
});

test('passwords: stored as salted, peppered hashes - never plaintext', function (TestApp $app) {
    foreach (['one@example.com', 'two@example.com'] as $email) {
        Assert::same(201, $app->request('POST', '/api/register', [
            'name' => 'Same Password', 'email' => $email, 'password' => Env::PASSWORD,
        ], [], [], $app->freshIp())->status);
    }
    $hashes = $app->pdo->query('SELECT password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($hashes as $hash) {
        Assert::notContains(Env::PASSWORD, $hash, 'plaintext must not appear in the hash');
        Assert::true(preg_match('/^\$(argon2id|2y)\$/', $hash) === 1, 'hash uses Argon2id or bcrypt');
        Assert::false(password_verify(Env::PASSWORD, $hash), 'plain password_verify fails: the pepper is part of the hash input');
        Assert::true((new Passwords(Env::PEPPER))->verify(Env::PASSWORD, $hash), 'correct pepper verifies');
        Assert::false((new Passwords(str_repeat('x', 40)))->verify(Env::PASSWORD, $hash), 'wrong pepper fails');
    }
    Assert::true($hashes[0] !== $hashes[1], 'same password, different salt -> different hash');
});

test('passwords: pepper must be long enough', function (TestApp $app) {
    Assert::throws(InvalidArgumentException::class, fn() => new Passwords('too-short'));
});

<?php

declare(strict_types=1);

use Tests\Assert;
use Tests\Env;
use Tests\TestApp;

/**
 * An admin and a student, both signed in.
 *
 * @return array{0:string, 1:string, 2:int} admin token, student token, student id
 */
function adminAndStudent(TestApp $app): array
{
    $app->createUser('admin@example.com', 'admin', Env::PASSWORD, 'Admin Person');
    $studentId = $app->createUser('student@example.com', 'student', Env::PASSWORD, 'Student Person');
    return [$app->signIn('admin@example.com'), $app->signIn('student@example.com'), $studentId];
}

test('admin tools: every admin endpoint refuses students (403) and visitors (401)', function (TestApp $app) {
    [, $student] = adminAndStudent($app);
    $endpoints = [
        ['GET', '/api/admin/users', null],
        ['POST', '/api/admin/delete-user', ['user_id' => 1]],
        ['POST', '/api/admin/restore-user', ['user_id' => 1]],
        ['GET', '/api/admin/restore-points', null],
        ['POST', '/api/admin/restore-points', ['label' => 'x']],
        ['POST', '/api/admin/restore-points/apply', ['id' => 1]],
        ['GET', '/api/admin/backup', null],
        ['GET', '/api/admin/reports', null],
        ['POST', '/api/admin/password-demo', ['password' => 'x']],
    ];
    foreach ($endpoints as [$method, $path, $body]) {
        Assert::same(403, $app->request($method, $path, $body, $app->bearer($student))->status, "student on $path");
        Assert::same(401, $app->request($method, $path, $body)->status, "visitor on $path");
    }
    Assert::same(0, (int) $app->row('SELECT COUNT(*) AS n FROM users WHERE deleted_at IS NOT NULL')['n'], 'nothing was changed');
});

/* ------------------------------------------------------------ deleting accounts */

test('delete: the account is marked as deleted (kept in the database), its sessions end and it cannot log in', function (TestApp $app) {
    [$admin, $student, $studentId] = adminAndStudent($app);

    $res = $app->request('POST', '/api/admin/delete-user', ['user_id' => $studentId], $app->bearer($admin));
    Assert::same(200, $res->status);
    Assert::same('deleted', $res->body['user']['status']);

    $row = $app->row('SELECT email, deleted_at FROM users WHERE id = ?', [$studentId]);
    Assert::same('student@example.com', $row['email'], 'the row is kept, only marked');
    Assert::true($row['deleted_at'] !== null);

    Assert::same(401, $app->request('GET', '/api/me', null, $app->bearer($student))->status, 'the old session no longer works');
    $login = $app->request('POST', '/api/login', ['email' => 'student@example.com', 'password' => Env::PASSWORD], [], [], $app->freshIp());
    Assert::same(401, $login->status);
    Assert::same('Invalid email or password.', $login->body['error']['message'], 'same generic message as a wrong password');

    $list = $app->request('GET', '/api/admin/users', null, $app->bearer($admin));
    Assert::same(1, $list->body['summary']['deleted']);
    Assert::same(0, $list->body['summary']['students']);
    Assert::same(409, $app->request('POST', '/api/admin/delete-user', ['user_id' => $studentId], $app->bearer($admin))->status);
});

test('delete: the server refuses any session of an account marked as deleted, even if the session was not ended', function (TestApp $app) {
    [, $student, $studentId] = adminAndStudent($app);
    // Mark as deleted directly in the database, leaving the session untouched.
    $app->pdo->exec("UPDATE users SET deleted_at = '2026-01-01 00:00:00' WHERE id = $studentId");
    Assert::same(401, $app->request('GET', '/api/me', null, $app->bearer($student))->status);
});

test('delete: a deleted account can be restored and logs in with the same password', function (TestApp $app) {
    [$admin, , $studentId] = adminAndStudent($app);
    $app->request('POST', '/api/admin/delete-user', ['user_id' => $studentId], $app->bearer($admin));

    $res = $app->request('POST', '/api/admin/restore-user', ['user_id' => $studentId], $app->bearer($admin));
    Assert::same(200, $res->status);
    Assert::same('active', $res->body['user']['status']);
    $app->signIn('student@example.com'); // asserts that login + code succeed
    Assert::same(409, $app->request('POST', '/api/admin/restore-user', ['user_id' => $studentId], $app->bearer($admin))->status);
});

test('delete: an admin cannot delete their own account; bad ids are refused', function (TestApp $app) {
    [$admin] = adminAndStudent($app);
    $adminId = (int) $app->row("SELECT id FROM users WHERE email = 'admin@example.com'")['id'];
    Assert::same(422, $app->request('POST', '/api/admin/delete-user', ['user_id' => $adminId], $app->bearer($admin))->status);
    Assert::same(422, $app->request('POST', '/api/admin/delete-user', ['user_id' => 'abc'], $app->bearer($admin))->status);
    Assert::same(404, $app->request('POST', '/api/admin/delete-user', ['user_id' => 999], $app->bearer($admin))->status);
});

test('admin tools: browser (cookie) admin actions need the CSRF headers', function (TestApp $app) {
    $app->createUser('admin@example.com', 'admin');
    $studentId = $app->createUser('student@example.com');
    $web = ['X-Client-Type' => 'web', 'Origin' => Env::ORIGIN];
    $login = $app->request('POST', '/api/login', ['email' => 'admin@example.com', 'password' => Env::PASSWORD], $web);
    $verify = $app->request('POST', '/api/verify-otp', ['challenge_id' => $login->body['challenge_id'], 'otp' => $app->delivery->lastOtp()], $web);
    $cookie = ['sid' => $verify->cookies[0]['value']];

    Assert::same(403, $app->request('POST', '/api/admin/delete-user', ['user_id' => $studentId], [], $cookie)->status, 'no CSRF headers');
    Assert::same(null, $app->row('SELECT deleted_at FROM users WHERE id = ?', [$studentId])['deleted_at'], 'nothing deleted');
    Assert::same(200, $app->request('POST', '/api/admin/delete-user', ['user_id' => $studentId], $web, $cookie)->status);
    Assert::same(200, $app->request('GET', '/api/admin/reports', null, [], $cookie)->status, 'reading needs no CSRF headers');
});

/* --------------------------------------------------------------- restore points */

test('restore points: applying one changes only roles and deleted status, never names, emails or passwords', function (TestApp $app) {
    [$admin, , $studentId] = adminAndStudent($app);
    $otherId = $app->createUser('other@example.com', 'student', Env::PASSWORD, 'Other Person');

    $created = $app->request('POST', '/api/admin/restore-points', ['label' => 'Before changes'], $app->bearer($admin));
    Assert::same(201, $created->status);
    Assert::same(3, $created->body['restore_point']['user_count']);
    $pointId = $created->body['restore_point']['id'];

    // After the restore point: configuration changes AND data changes.
    $app->pdo->exec("UPDATE users SET role = 'admin' WHERE id = $studentId");
    $app->request('POST', '/api/admin/delete-user', ['user_id' => $otherId], $app->bearer($admin));
    $app->pdo->exec("UPDATE users SET name = 'Renamed Later', password_hash = 'changed-hash' WHERE id = $studentId");
    $laterId = $app->createUser('later@example.com', 'admin', Env::PASSWORD, 'Created Later');

    $applied = $app->request('POST', '/api/admin/restore-points/apply', ['id' => $pointId], $app->bearer($admin));
    Assert::same(200, $applied->status);
    Assert::same(2, $applied->body['changed']);
    Assert::same(1, $applied->body['accounts_created_later']);

    $student = $app->row('SELECT name, role, password_hash FROM users WHERE id = ?', [$studentId]);
    Assert::same('student', $student['role'], 'the role is restored');
    Assert::same('Renamed Later', $student['name'], 'data is not changed');
    Assert::same('changed-hash', $student['password_hash'], 'the password is not changed');
    Assert::same(null, $app->row('SELECT deleted_at FROM users WHERE id = ?', [$otherId])['deleted_at'], 'the deleted status is restored');
    Assert::same('admin', $app->row('SELECT role FROM users WHERE id = ?', [$laterId])['role'], 'accounts created later are untouched');

    $list = $app->request('GET', '/api/admin/restore-points', null, $app->bearer($admin));
    Assert::same('Before changes', $list->body['restore_points'][0]['label']);
});

test('restore points: one that would remove your own admin access is refused; bad input is refused', function (TestApp $app) {
    [$admin, , $studentId] = adminAndStudent($app);
    $point = $app->request('POST', '/api/admin/restore-points', [], $app->bearer($admin))->body['restore_point'];
    Assert::true(str_starts_with($point['label'], 'Restore point '), 'a default name is given');

    // The student is promoted after the restore point and tries to apply it.
    $app->pdo->exec("UPDATE users SET role = 'admin' WHERE id = $studentId");
    $newAdmin = $app->signIn('student@example.com');
    $res = $app->request('POST', '/api/admin/restore-points/apply', ['id' => $point['id']], $app->bearer($newAdmin));
    Assert::same(409, $res->status);
    Assert::same('admin', $app->row('SELECT role FROM users WHERE id = ?', [$studentId])['role'], 'nothing was changed');

    Assert::same(404, $app->request('POST', '/api/admin/restore-points/apply', ['id' => 999], $app->bearer($admin))->status);
    Assert::same(422, $app->request('POST', '/api/admin/restore-points', ['label' => str_repeat('x', 101)], $app->bearer($admin))->status);
});

/* ----------------------------------------------------------------------- backup */

test('backup: the full SQL backup contains every table and all rows, without plain passwords or the pepper', function (TestApp $app) {
    [$admin] = adminAndStudent($app);
    $res = $app->request('GET', '/api/admin/backup', null, $app->bearer($admin));
    Assert::same(200, $res->status);
    $sql = $res->body['content'];
    Assert::true(str_ends_with($res->body['filename'], '.sql'));
    Assert::same(2, $res->body['tables']['users']);

    foreach (['users', 'otp_challenges', 'sessions', 'rate_limits', 'auth_events', 'restore_points'] as $table) {
        Assert::contains("DROP TABLE IF EXISTS `$table`;", $sql);
        Assert::contains("CREATE TABLE IF NOT EXISTS $table", $sql);
    }
    Assert::contains('INSERT INTO `users`', $sql);
    Assert::contains("'student@example.com'", $sql);
    $hash = $app->row("SELECT password_hash FROM users WHERE email = 'student@example.com'")['password_hash'];
    Assert::contains($hash, $sql, "password hashes are included so a restore keeps everyone's password");
    Assert::notContains(Env::PASSWORD, $sql, 'no plain passwords');
    Assert::notContains(Env::PEPPER, $sql, 'the pepper is not in the database, so not in the backup');
    Assert::contains('SET FOREIGN_KEY_CHECKS = 1;', $sql);
});

/* ---------------------------------------------------------------------- reports */

test('reports: logins, failed logins and admin actions are counted; passwords never appear', function (TestApp $app) {
    [$admin, , $studentId] = adminAndStudent($app);
    $app->request('POST', '/api/login', ['email' => 'student@example.com', 'password' => 'Wrong-Password-1'], [], [], $app->freshIp());
    $app->request('POST', '/api/login', ['email' => 'nobody@example.com', 'password' => 'Wrong-Password-1'], [], [], $app->freshIp());
    $app->request('POST', '/api/admin/delete-user', ['user_id' => $studentId], $app->bearer($admin));

    $res = $app->request('GET', '/api/admin/reports', null, $app->bearer($admin));
    Assert::same(200, $res->status);
    $events = $res->body['last_7_days']['events'];
    Assert::same(2, $events['login_success'] ?? 0);
    Assert::same(2, $events['login_failed'] ?? 0);
    Assert::same(1, $events['user_deleted'] ?? 0);
    Assert::same(7, count($res->body['last_7_days']['logins_per_day']));
    Assert::same(2, array_sum(array_column($res->body['last_7_days']['logins_per_day'], 'logins')));
    Assert::same(1, $res->body['users']['deleted']);
    Assert::same('user_deleted', $res->body['recent_events'][0]['event']);

    $json = json_encode($res->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Assert::notContains('Wrong-Password-1', $json);
    Assert::notContains(Env::PASSWORD, $json);
    Assert::contains('unknown email n***@example.com', $json, 'unknown emails are masked');
});

/* ------------------------------------------------------- salt and pepper demo */

test('password demo: same password, different salt, different hash; the pepper is never shown and nothing is saved', function (TestApp $app) {
    [$admin] = adminAndStudent($app);
    $res = $app->request('POST', '/api/admin/password-demo', ['password' => 'Demo-Password-1'], $app->bearer($admin));
    Assert::same(200, $res->status);
    $d = $res->body;

    Assert::false($d['same_hash'], 'hashing the same password twice gives different hashes');
    Assert::true($d['salt_1'] !== '' && $d['salt_1'] !== $d['salt_2'], 'because each hash gets its own random salt');
    Assert::contains($d['salt_1'], $d['hash_1'], 'the salt is stored inside the hash');
    Assert::true($d['verify_correct_password']);
    Assert::false($d['verify_wrong_password']);
    Assert::false($d['verify_without_pepper'], 'without the pepper the hash cannot be checked');
    Assert::true(str_ends_with($d['peppered_preview'], '…'));

    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Assert::notContains(Env::PEPPER, $json);
    Assert::notContains(base64_encode(hash_hmac('sha256', 'Demo-Password-1', Env::PEPPER, true)), $json, 'the full peppered value is not shown');
    Assert::same(2, $app->count('users'), 'nothing was saved');
    Assert::same(0, (int) $app->row("SELECT COUNT(*) AS n FROM auth_events WHERE detail LIKE '%Demo-Password%'")['n'], 'the sample is not logged');
    Assert::same(422, $app->request('POST', '/api/admin/password-demo', ['password' => ''], $app->bearer($admin))->status);
});

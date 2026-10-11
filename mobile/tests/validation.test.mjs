// Run with:  npm test   (Node's built-in test runner; no extra packages needed)
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  validateName, validateEmail, validatePassword, validateOtp, normalizeEmail,
  PASSWORD_MIN, PASSWORD_MAX,
} from '../src/lib/validation.ts';

test('name: accepts normal names and rejects empty, tiny, huge or markup', () => {
  assert.equal(validateName('Amina Yusuf'), null);
  assert.equal(validateName('  Zoë   Núñez '), null);
  assert.ok(validateName(''));
  assert.ok(validateName('A'));
  assert.ok(validateName('x'.repeat(101)));
  assert.ok(validateName('<script>alert(1)</script>'));
});

test('email: normalises and validates', () => {
  assert.equal(normalizeEmail('  Jane@Example.COM '), 'jane@example.com');
  assert.equal(validateEmail('jane@example.com'), null);
  assert.equal(validateEmail('  JANE@Example.com '), null);
  for (const bad of ['', 'jane', 'jane@', '@example.com', 'jane@example', 'ja ne@example.com']) {
    assert.ok(validateEmail(bad), `should reject "${bad}"`);
  }
  assert.ok(validateEmail(`${'a'.repeat(200)}@example.com`));
});

test('password: minimum length is enforced', () => {
  assert.equal(PASSWORD_MIN, 12);
  assert.ok(validatePassword('Abc123xyz'));
  assert.ok(validatePassword('A1' + 'b'.repeat(PASSWORD_MIN - 3)));
  assert.equal(validatePassword('A1' + 'b'.repeat(PASSWORD_MIN - 2)), null);
});

test('password: needs a letter and a number, and not a common password', () => {
  assert.ok(validatePassword('OnlyLettersHere'));
  assert.ok(validatePassword('1234567890123'));
  assert.ok(validatePassword('Password1234'), 'common password, any case');
  assert.ok(validatePassword('user12345678@x.com', 'USER12345678@x.com'), 'same as email');
  assert.equal(validatePassword('Correct-Horse-9'), null);
});

test('password: maximum length', () => {
  assert.equal(validatePassword('a1'.repeat(PASSWORD_MAX / 2)), null);
  assert.ok(validatePassword('a1'.repeat(PASSWORD_MAX / 2 + 1)));
});

test('otp: exactly six digits', () => {
  assert.equal(validateOtp('123456'), null);
  assert.equal(validateOtp(' 123456 '), null);
  for (const bad of ['', '12345', '1234567', '12a456', '12 456']) assert.ok(validateOtp(bad), bad);
});

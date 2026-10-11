<?php

declare(strict_types=1);

namespace Tests;

use App\Config;
use App\Database;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Mail\OtpDelivery;
use App\Security\Passwords;
use PDO;

/** Thrown when an assertion fails. */
final class AssertionFailed extends \RuntimeException
{
}

final class Assert
{
    public static int $count = 0;

    public static function true(bool $condition, string $message = 'Expected condition to be true'): void
    {
        self::$count++;
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    public static function false(bool $condition, string $message = 'Expected condition to be false'): void
    {
        self::true(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::$count++;
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '') . 'expected ' . self::show($expected) . ', got ' . self::show($actual)
            );
        }
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        self::true(str_contains($haystack, $needle), $message !== '' ? $message : "Expected to find '$needle'");
    }

    public static function notContains(string $needle, string $haystack, string $message = ''): void
    {
        self::true(!str_contains($haystack, $needle), $message !== '' ? $message : "Did not expect to find '$needle'");
    }

    public static function throws(string $class, callable $fn, string $message = ''): \Throwable
    {
        self::$count++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "expected $class, got " . get_class($e));
        }
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "expected $class to be thrown");
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}

/** Records the one-time codes that would have been emailed. */
final class RecordingDelivery implements OtpDelivery
{
    /** @var list<array{email:string,otp:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function deliver(string $email, string $name, string $otp, int $ttlSeconds): array
    {
        if ($this->fail) {
            throw new \RuntimeException('simulated SMTP outage');
        }
        $this->sent[] = ['email' => $email, 'otp' => $otp];
        return ['delivery' => 'email'];
    }

    public function lastOtp(): string
    {
        Assert::true($this->sent !== [], 'No OTP was delivered');
        return $this->sent[count($this->sent) - 1]['otp'];
    }
}

/** Test database and configuration helpers. */
final class Env
{
    public const PEPPER = 'unit-test-pepper-0123456789-abcdefghijklmnop';
    public const ORIGIN = 'http://localhost:8081';
    public const PASSWORD = 'Correct-Horse-9';

    /** @var array<string,string> */
    private static array $db = [];
    private static ?PDO $pdo = null;

    /** @var array<string,string> */
    private static array $hashCache = [];

    public static function init(): void
    {
        $fromFile = [];
        $file = dirname(__DIR__) . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#' && str_contains($line, '=')) {
                    [$k, $v] = explode('=', $line, 2);
                    $fromFile[trim($k)] = trim($v, " \t\"'");
                }
            }
        }
        $pick = static function (string $testKey, string $envKey, string $default) use ($fromFile): string {
            $fromEnv = getenv($testKey);
            if ($fromEnv !== false && $fromEnv !== '') {
                return $fromEnv;
            }
            return $fromFile[$envKey] ?? $default;
        };
        self::$db = [
            'DB_HOST' => $pick('TEST_DB_HOST', 'DB_HOST', '127.0.0.1'),
            'DB_PORT' => $pick('TEST_DB_PORT', 'DB_PORT', '3306'),
            'DB_USER' => $pick('TEST_DB_USER', 'DB_USER', 'root'),
            'DB_PASS' => $pick('TEST_DB_PASS', 'DB_PASS', ''),
            // Tests wipe their tables, so they use a separate database and refuse any other name.
            'DB_NAME' => getenv('TEST_DB_NAME') ?: 'login_auth_test',
        ];
        if (!preg_match('/^[A-Za-z0-9_]*test[A-Za-z0-9_]*$/i', self::$db['DB_NAME'])) {
            throw new \RuntimeException('Refusing to run: the test database name must contain "test".');
        }

        $server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', self::$db['DB_HOST'], (int) self::$db['DB_PORT']),
            self::$db['DB_USER'],
            self::$db['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $name = self::$db['DB_NAME'];
        $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `$name`");
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $server->exec($statement);
            }
        }
        self::$pdo = Database::connect(self::config());
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \LogicException('Env::init() has not run.');
        }
        return self::$pdo;
    }

    /** @param array<string,mixed> $overrides */
    public static function config(array $overrides = []): Config
    {
        return Config::fromArray($overrides + self::$db + [
            'APP_ENV' => 'testing',
            'APP_PEPPER' => self::PEPPER,
            'CORS_ALLOWED_ORIGINS' => self::ORIGIN,
            'OTP_DELIVERY' => 'dev',
        ]);
    }

    public static function reset(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['sessions', 'otp_challenges', 'rate_limits', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string,mixed> $configOverrides */
    public static function app(array $configOverrides = []): TestApp
    {
        return new TestApp(self::config($configOverrides), self::pdo());
    }

    /** Hashes are cached per password so tests that only need "a user" stay fast. */
    public static function hashFor(string $password): string
    {
        return self::$hashCache[$password] ??= (new Passwords(self::PEPPER))->hash($password);
    }
}

/** A Kernel wired to the test database with a recording OTP delivery. */
final class TestApp
{
    public RecordingDelivery $delivery;
    public Kernel $kernel;
    private int $ipCounter = 0;

    public function __construct(public Config $config, public PDO $pdo)
    {
        $this->delivery = new RecordingDelivery();
        $this->kernel = new Kernel($config, $pdo, $this->delivery);
    }

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,string>     $headers
     * @param array<string,string>     $cookies
     * @param array<string,mixed>      $query
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        array $cookies = [],
        string $ip = '203.0.113.10',
        array $query = [],
    ): Response {
        if ($json !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }
        return $this->kernel->handle(new Request(
            $method,
            $path,
            $headers,
            $cookies,
            $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
            $ip,
            $query,
        ));
    }

    /** Inserts a user directly (fast). Use register() to test the real endpoint. */
    public function createUser(string $email, string $role = 'user', string $password = Env::PASSWORD, string $name = 'Test User'): int
    {
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $email, Env::hashFor($password), $role, gmdate('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Full password + OTP sign-in for a native client. Returns the session token. */
    public function signIn(string $email, string $password = Env::PASSWORD): string
    {
        $login = $this->request('POST', '/api/login', ['email' => $email, 'password' => $password]);
        Assert::same(200, $login->status, 'login step should succeed');
        $verify = $this->request('POST', '/api/verify-otp', [
            'challenge_id' => $login->body['challenge_id'],
            'otp' => $this->delivery->lastOtp(),
        ]);
        Assert::same(200, $verify->status, 'verify step should succeed');
        return (string) $verify->body['token'];
    }

    /** @return array<string,string> */
    public function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /** @return array<string,mixed>|false */
    public function row(string $sql, array $params = []): array|false
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }

    /** A different client IP each call, for tests that must not trip the per-IP limits. */
    public function freshIp(): string
    {
        $this->ipCounter++;
        return '198.51.100.' . $this->ipCounter;
    }
}

final class Harness
{
    /** @var list<array{name:string,fn:callable}> */
    private static array $tests = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        $filter = $argv[1] ?? null;
        $passed = 0;
        $failed = [];

        foreach (self::$tests as $test) {
            if ($filter !== null && stripos($test['name'], $filter) === false) {
                continue;
            }
            Env::reset();
            try {
                ($test['fn'])(Env::app());
                $passed++;
                echo "  PASS  {$test['name']}\n";
            } catch (\Throwable $e) {
                $failed[] = $test['name'];
                $where = $e instanceof AssertionFailed ? '' : get_class($e) . ': ';
                echo "  FAIL  {$test['name']}\n        {$where}{$e->getMessage()}\n";
                if (!$e instanceof AssertionFailed) {
                    echo "        at {$e->getFile()}:{$e->getLine()}\n";
                }
            }
        }

        echo "\n" . $passed . ' passed, ' . count($failed) . ' failed (' . Assert::$count . " assertions)\n";
        return $failed === [] ? 0 : 1;
    }
}

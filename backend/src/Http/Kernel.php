<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use App\Mail\OtpDelivery;
use App\Security\Passwords;
use App\Security\RateLimiter;
use App\Services\AdminService;
use App\Services\AuditLog;
use App\Services\AuthService;
use App\Util;
use PDO;

/**
 * Routing, CORS, CSRF protection, authentication and authorization for the REST API.
 * handle() returns a Response object instead of printing, so it can be unit-tested.
 */
final class Kernel
{
    public const SESSION_COOKIE = 'sid';

    private readonly AuthService $auth;
    private readonly AdminService $admin;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        OtpDelivery $delivery,
    ) {
        $passwords = new Passwords($config->get('APP_PEPPER'));
        $limiter = new RateLimiter($pdo);
        $audit = new AuditLog($pdo);
        $this->auth = new AuthService($pdo, $config, $passwords, $limiter, $delivery, $audit);
        $this->admin = new AdminService($pdo, $passwords, $limiter, $audit);
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                $response = new Response(204);
            } else {
                $response = $this->dispatch($request);
            }
        } catch (HttpException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->details !== []) {
                $error['details'] = $e->details;
            }
            $response = Response::json(['error' => $error], $e->status);
            foreach ($e->headers as $name => $value) {
                $response->withHeader($name, $value);
            }
        } catch (\Throwable $e) {
            // Unexpected failure: log the detail on the server, tell the client nothing specific.
            error_log('[login-auth] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $response = Response::json(['error' => ['code' => 'server_error', 'message' => 'Something went wrong on the server.']], 500);
        }

        return $this->decorate($request, $response);
    }

    /* ----------------------------------------------------------------- routing */

    private function dispatch(Request $request): Response
    {
        $routes = [
            '/health' => ['GET' => fn(): Response => $this->health()],
            '/register' => ['POST' => fn(): Response => $this->register($request)],
            '/login' => ['POST' => fn(): Response => $this->login($request)],
            '/verify-otp' => ['POST' => fn(): Response => $this->verifyOtp($request)],
            '/resend-otp' => ['POST' => fn(): Response => $this->resendOtp($request)],
            '/me' => ['GET' => fn(): Response => $this->me($request)],
            '/logout' => ['POST' => fn(): Response => $this->logout($request)],
            '/admin/users' => ['GET' => fn(): Response => $this->adminUsers($request)],
            '/admin/delete-user' => ['POST' => fn(): Response => $this->adminAction($request, fn(array $a, array $in) => ['user' => $this->admin->deleteUser($a, $in['user_id'] ?? null, $request->ip), 'message' => 'The account was marked as deleted.'])],
            '/admin/restore-user' => ['POST' => fn(): Response => $this->adminAction($request, fn(array $a, array $in) => ['user' => $this->admin->restoreUser($a, $in['user_id'] ?? null, $request->ip), 'message' => 'The account is active again.'])],
            '/admin/restore-points' => [
                'GET' => fn(): Response => $this->adminRead($request, fn() => ['restore_points' => $this->admin->listRestorePoints()]),
                'POST' => fn(): Response => $this->adminAction($request, fn(array $a, array $in) => ['restore_point' => $this->admin->createRestorePoint($a, $in['label'] ?? null, $request->ip), 'message' => 'Restore point saved.'], 201),
            ],
            '/admin/restore-points/apply' => ['POST' => fn(): Response => $this->adminAction($request, fn(array $a, array $in) => $this->admin->applyRestorePoint($a, $in['id'] ?? null, $request->ip) + ['message' => 'Restore point applied. Only roles and deleted status were changed.'])],
            '/admin/backup' => ['GET' => fn(): Response => $this->adminRead($request, fn(array $a) => $this->admin->backup($a, $request->ip))],
            '/admin/reports' => ['GET' => fn(): Response => $this->adminRead($request, fn() => $this->admin->reports())],
            '/admin/password-demo' => ['POST' => fn(): Response => $this->adminAction($request, fn(array $a, array $in) => $this->admin->passwordDemo($a, $in['password'] ?? null))],
        ];

        $path = $request->routePath();
        if (!isset($routes[$path])) {
            throw new HttpException(404, 'not_found', 'Not found.');
        }
        if (!isset($routes[$path][$request->method])) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed.', [], [
                'Allow' => implode(', ', array_keys($routes[$path])) . ', OPTIONS',
            ]);
        }
        return $routes[$path][$request->method]();
    }

    /* ---------------------------------------------------------------- endpoints */

    private function health(): Response
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (\Throwable) {
            return Response::json(['status' => 'degraded'], 503);
        }
        return Response::json(['status' => 'ok', 'time' => gmdate('c')]);
    }

    private function register(Request $request): Response
    {
        $user = $this->auth->register($request->json(), $request->ip);
        return Response::json([
            'message' => 'Account created. You can now sign in.',
            'user' => $user,
        ], 201);
    }

    private function login(Request $request): Response
    {
        $result = $this->auth->login($request->json(), $request->ip);
        return Response::json(['message' => 'Password accepted. Enter the verification code to finish signing in.'] + $result);
    }

    private function resendOtp(Request $request): Response
    {
        $result = $this->auth->resendOtp($request->json(), $request->ip);
        return Response::json(['message' => 'A new verification code has been issued.'] + $result);
    }

    private function verifyOtp(Request $request): Response
    {
        $isWeb = $this->isWebClient($request);
        if ($isWeb) {
            $this->assertAllowedOrigin($request);
        }

        $result = $this->auth->verifyOtp($request->json(), $request->ip, $request->header('user-agent'));
        $body = ['message' => 'Signed in successfully.', 'user' => $result['user'], 'expires_at' => $result['expires_at']];
        $response = new Response(200);

        if ($isWeb) {
            // Browsers never see the token: it lives in an HttpOnly cookie that scripts cannot read.
            $body['session'] = 'cookie';
            $response->withCookie(self::SESSION_COOKIE, $result['token'], $this->cookieOptions(
                Util::now() + $this->config->sessionTtlSeconds()
            ));
        } else {
            // Native apps receive the token once and keep it in Expo SecureStore.
            $body['session'] = 'token';
            $body['token'] = $result['token'];
        }
        $response->body = $body;
        return $response;
    }

    private function me(Request $request): Response
    {
        $session = $this->requireSession($request);
        return Response::json(['user' => $session['user']]);
    }

    private function logout(Request $request): Response
    {
        [$token, $fromCookie] = $this->extractToken($request);
        if ($fromCookie) {
            $this->assertCsrfSafe($request);
        }

        // Logging out is idempotent: an already-invalid session just clears the cookie.
        try {
            $this->auth->logout($this->auth->authenticate($token), $request->ip);
        } catch (HttpException $e) {
            if ($e->status !== 401) {
                throw $e;
            }
        }

        $response = Response::json(['message' => 'You have been signed out.']);
        if ($fromCookie) {
            $response->withCookie(self::SESSION_COOKIE, '', $this->cookieOptions(1));
        }
        return $response;
    }

    private function adminUsers(Request $request): Response
    {
        $this->requireAdmin($request, false);
        $limit = filter_var($request->query['limit'] ?? 100, FILTER_VALIDATE_INT);
        $offset = filter_var($request->query['offset'] ?? 0, FILTER_VALIDATE_INT);
        return Response::json($this->admin->listUsers($limit === false ? 100 : $limit, $offset === false ? 0 : $offset));
    }

    /**
     * The admin check for every admin endpoint: a valid session whose role (read from the database)
     * is "admin". State-changing requests made with the browser cookie also need the CSRF headers.
     *
     * @return array<string,mixed> the admin user
     */
    private function requireAdmin(Request $request, bool $changesState): array
    {
        [$token, $fromCookie] = $this->extractToken($request);
        if ($changesState && $fromCookie) {
            $this->assertCsrfSafe($request);
        }
        $session = $this->auth->authenticate($token);
        $this->auth->requireRole($session['user'], 'admin'); // enforced here, whatever the app shows
        return $session['user'];
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $read */
    private function adminRead(Request $request, callable $read): Response
    {
        return Response::json($read($this->requireAdmin($request, false)));
    }

    /** @param callable(array<string,mixed>, array<string,mixed>): array<string,mixed> $action */
    private function adminAction(Request $request, callable $action, int $status = 200): Response
    {
        $admin = $this->requireAdmin($request, true);
        return Response::json($action($admin, $request->json()), $status);
    }

    /* --------------------------------------------------------- authentication */

    /** @return array{user:array<string,mixed>, session_id:int} */
    private function requireSession(Request $request): array
    {
        [$token] = $this->extractToken($request);
        return $this->auth->authenticate($token);
    }

    /** @return array{0: ?string, 1: bool} token and whether it came from the cookie */
    private function extractToken(Request $request): array
    {
        $header = $request->header('authorization');
        if ($header !== null && preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return [$m[1], false];
        }
        $cookie = $request->cookies[self::SESSION_COOKIE] ?? null;
        return [is_string($cookie) && $cookie !== '' ? $cookie : null, is_string($cookie) && $cookie !== ''];
    }

    private function isWebClient(Request $request): bool
    {
        return $request->header('x-client-type') === 'web';
    }

    /* ------------------------------------------------------------- CORS / CSRF */

    private function isAllowedOrigin(?string $origin): bool
    {
        return $origin !== null && in_array($origin, $this->config->allowedOrigins(), true);
    }

    private function assertAllowedOrigin(Request $request): void
    {
        if (!$this->isAllowedOrigin($request->header('origin'))) {
            throw new HttpException(403, 'origin_not_allowed', 'This origin is not allowed.');
        }
    }

    /**
     * Cookie-authenticated, state-changing requests must come from an allowed origin and
     * carry a custom header (which browsers only send after a CORS preflight).
     */
    private function assertCsrfSafe(Request $request): void
    {
        $this->assertAllowedOrigin($request);
        if (!$this->isWebClient($request)) {
            throw new HttpException(403, 'csrf_check_failed', 'Request rejected.');
        }
    }

    /** @return array<string,mixed> */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->config->cookieSecure(),
            'httponly' => true,
            'samesite' => $this->config->cookieSameSite(),
        ];
    }

    private function decorate(Request $request, Response $response): Response
    {
        $response->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        if ($this->config->isProduction()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        // CORS: only exact, configured origins are echoed back. Never "*", because the
        // responses may involve credentials (cookies).
        $origin = $request->header('origin');
        if ($this->isAllowedOrigin($origin)) {
            $response->withHeader('Access-Control-Allow-Origin', (string) $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
            if ($request->method === 'OPTIONS') {
                $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                    ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Client-Type')
                    ->withHeader('Access-Control-Max-Age', '600');
            }
        } elseif ($origin !== null) {
            $response->withHeader('Vary', 'Origin');
        }
        return $response;
    }
}

<?php
namespace App\Core;

/**
 * Simple session-based authentication helper.
 *
 * Stores the Supabase access_token + user profile in $_SESSION
 * after a successful login or signup.
 */
class Auth
{
    /**
     * Role capability map for the web app.
     */
    private const PERMISSIONS = [
        'player' => [],
        'instructor' => [
            'matchmaking.manage',
            'players.invite',
            'teams.manage',
            'video.manage',
            'instructor.view',
        ],
        'admin' => [
            'matchmaking.manage',
            'players.invite',
            'teams.manage',
            'video.manage',
            'instructor.view',
            'admin.view',
            'announcements.manage',
            'challenges.manage',
            'users.manage',
        ],
    ];

    /** Ensure session is started */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** Store auth data after successful login/signup */
    public static function login(array $authResponse, array $account): void
    {
        self::boot();
        session_regenerate_id(true);
        $_SESSION['access_token']  = $authResponse['access_token']  ?? '';
        $_SESSION['refresh_token'] = $authResponse['refresh_token'] ?? '';
        $_SESSION['user']          = $authResponse['user']          ?? [];
        $_SESSION['account']       = $account;
        unset($_SESSION['_csrf']);
    }

    /** Destroy session */
    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    /** Is a user currently logged in? */
    public static function check(): bool
    {
        self::boot();
        return !empty($_SESSION['access_token']);
    }

    /** Get stored access token */
    public static function token(): string
    {
        self::boot();
        return $_SESSION['access_token'] ?? '';
    }

    /** Get the accounts-table row for the logged-in user */
    public static function account(): array
    {
        self::boot();
        return $_SESSION['account'] ?? [];
    }

    /** Get Supabase auth.users row */
    public static function user(): array
    {
        self::boot();
        return $_SESSION['user'] ?? [];
    }

    /** Shortcut: user's uid (from accounts table) */
    public static function uid(): string
    {
        return self::account()['uid'] ?? '';
    }

    /** Shortcut: user's display name */
    public static function name(): string
    {
        return self::account()['fname'] ?? 'Guest';
    }

    /** Shortcut: user's avatar URL */
    public static function avatar(): string
    {
        return self::account()['image_url'] ?? '';
    }

    /** Shortcut: account role */
    public static function role(): string
    {
        $account = self::account();
        $role = $account['role'] ?? $account['login_type'] ?? $account['level'] ?? 'player';
        if (!is_string($role) || trim($role) === '') {
            return 'player';
        }
        $role = strtolower(trim($role));
        return in_array($role, ['player', 'instructor', 'admin'], true) ? $role : 'player';
    }

    /** Is current user an instructor (or admin)? */
    public static function isInstructor(): bool
    {
        return in_array(self::role(), ['instructor', 'admin'], true);
    }

    /** Is current user an admin? */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Check whether current user has a named permission. */
    public static function can(string $permission): bool
    {
        $role = self::role();
        $allowed = self::PERMISSIONS[$role] ?? [];
        return in_array($permission, $allowed, true);
    }

    /** Redirect to dashboard if current user lacks a required permission. */
    public static function requirePermission(string $permission, string $message = 'You are not allowed to access that area.'): void
    {
        self::requireAuth();
        if (!self::can($permission)) {
            self::flash('error', $message);
            header('Location: /dashboard');
            exit;
        }
    }

    /** Refresh the cached account data from a fresh DB row */
    public static function refreshAccount(array $account): void
    {
        self::boot();
        $_SESSION['account'] = $account;
    }

    /** Redirect to login if not authenticated */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    /** Redirect to dashboard if current user is not instructor or admin */
    public static function requireInstructor(): void
    {
        self::requirePermission('instructor.view', 'Instructor permissions are required for this action.');
    }

    /** Redirect to dashboard if current user is not admin */
    public static function requireAdmin(): void
    {
        self::requirePermission('admin.view', 'Admin permissions are required for this action.');
    }

    /** Store a flash message for the next request */
    public static function flash(string $key, string $message): void
    {
        self::boot();
        $_SESSION['_flash'][$key] = $message;
    }

    /** Retrieve and clear a flash message */
    public static function getFlash(string $key): string
    {
        self::boot();
        $msg = $_SESSION['_flash'][$key] ?? '';
        unset($_SESSION['_flash'][$key]);
        return $msg;
    }

    /** Get (or create) CSRF token for forms */
    public static function csrfToken(): string
    {
        self::boot();
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** Verify a submitted CSRF token */
    public static function verifyCsrf(?string $token): bool
    {
        self::boot();
        if (!is_string($token) || $token === '') {
            return false;
        }
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }
}

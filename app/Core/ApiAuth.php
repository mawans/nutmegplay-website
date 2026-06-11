<?php
namespace App\Core;

/**
 * API Authentication helper for mobile app (React Native).
 *
 * Uses Supabase JWT tokens passed in Authorization header.
 * Validates the token against Supabase and loads the account.
 */
class ApiAuth
{
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

    private static ?array $currentUser = null;
    private static ?array $currentAccount = null;

    /**
     * Extract Bearer token from Authorization header.
     */
    public static function getBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Authenticate the API request using Supabase JWT.
     * Returns the account row or null if invalid.
     */
    public static function authenticate(): ?array
    {
        if (self::$currentAccount !== null) {
            return self::$currentAccount;
        }

        $token = self::getBearerToken();
        if (!$token) {
            return null;
        }

        $sb = SupabaseClient::getInstance();
        $user = $sb->authGetUser($token);

        if (!$user || !empty($user['error']) || empty($user['id'])) {
            return null;
        }

        self::$currentUser = $user;

        // Load account from accounts table
        $account = $sb->from('accounts')->select('*')
            ->eq('uid', $user['id'])->single()->execute();

        if (!$account || !empty($account['error'])) {
            return null;
        }

        self::$currentAccount = $account;
        return $account;
    }

    /**
     * Require authentication — sends 401 and exits if not authenticated.
     */
    public static function require(): array
    {
        $account = self::authenticate();
        if (!$account) {
            http_response_code(401);
            echo json_encode(['error' => true, 'message' => 'Unauthorized']);
            exit;
        }
        return $account;
    }

    /**
     * Get the authenticated Supabase auth user.
     */
    public static function user(): ?array
    {
        return self::$currentUser;
    }

    /**
     * Get the authenticated account row.
     */
    public static function account(): ?array
    {
        return self::$currentAccount;
    }

    /**
     * Get the user's UID (Supabase auth UUID).
     */
    public static function uid(): string
    {
        return self::$currentAccount['uid'] ?? '';
    }

    /**
     * Get the user's role.
     */
    public static function role(): string
    {
        $account = self::$currentAccount ?? [];
        $role = $account['role'] ?? $account['login_type'] ?? $account['level'] ?? 'player';
        if (!is_string($role) || trim($role) === '') {
            return 'player';
        }
        $role = strtolower(trim($role));
        return in_array($role, ['player', 'instructor', 'admin'], true) ? $role : 'player';
    }

    /**
     * Check if user is instructor or admin.
     */
    public static function isInstructor(): bool
    {
        return in_array(self::role(), ['instructor', 'admin'], true);
    }

    /**
     * Check if user is admin.
     */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /**
     * Check whether current API user has a named permission.
     */
    public static function can(string $permission): bool
    {
        $role = self::role();
        $allowed = self::PERMISSIONS[$role] ?? [];
        return in_array($permission, $allowed, true);
    }

    /**
     * Require a named permission for an API request.
     */
    public static function requirePermission(string $permission, string $message = 'Forbidden'): array
    {
        $account = self::require();
        if (!self::can($permission)) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => $message]);
            exit;
        }
        return $account;
    }

    /**
     * Reset state between requests (for testing).
     */
    public static function reset(): void
    {
        self::$currentUser = null;
        self::$currentAccount = null;
    }
}

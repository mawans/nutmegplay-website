<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `accounts` table.
 * Manages user profiles (separate from Supabase auth.users).
 */
class AccountService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    /** Get account by its auto-increment id */
    public function getById(int $id): ?array
    {
        $row = $this->db->from('accounts')->select('*')->eq('id', (string)$id)->single()->execute();
        return ($row && empty($row['error'])) ? $this->normalizeAccount($row) : null;
    }

    /** Get account by Supabase auth UID */
    public function getByUid(string $uid): ?array
    {
        $row = $this->db->from('accounts')->select('*')->eq('uid', $uid)->single()->execute();
        return ($row && empty($row['error'])) ? $this->normalizeAccount($row) : null;
    }

    /** Get account by email */
    public function getByEmail(string $email): ?array
    {
        $row = $this->db->from('accounts')->select('*')->eq('email', $email)->single()->execute();
        return ($row && empty($row['error'])) ? $this->normalizeAccount($row) : null;
    }

    /** List all accounts (optionally filter by club) */
    public function list(?string $clubAssign = null, int $limit = 50): array
    {
        $clubKey = $clubAssign === null ? 'all' : $clubAssign;
        return Cache::remember("accounts:list:$clubKey:$limit", 30, function () use ($clubAssign, $limit): array {
            $q = $this->db->from('accounts')->select('*');
            if ($clubAssign !== null) {
                $q->eq('club_assign', $clubAssign);
            }
            $rows = $q->order('fname')->limit($limit)->execute();
            if (!$rows || !empty($rows['error'])) {
                return [];
            }
            return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
        });
    }

    /** List accounts by role */
    public function listByRole(string $role, int $limit = 100): array
    {
        $role = strtolower($role);
        $attempts = ['role', 'login_type', 'level'];

        foreach ($attempts as $column) {
            $rows = $this->db->from('accounts')->select('*')
                ->eq($column, $role)
                ->order('fname')->limit($limit)->execute();

            if (is_array($rows) && empty($rows['error'])) {
                return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
            }
        }

        return [];
    }

    /** List recent players (newest first) */
    public function listRecentPlayers(int $limit = 10): array
    {
        $rows = $this->db->from('accounts')->select('*')
            ->eq('role', 'player')
            ->order('created_at', false)
            ->limit($limit)
            ->execute();

        if (!$rows || !empty($rows['error'])) {
            // Fallback for older schemas that may not expose `role`.
            $rows = $this->db->from('accounts')->select('*')
                ->eq('login_type', 'player')
                ->order('created_at', false)
                ->limit($limit)
                ->execute();
        }

        if (!$rows || !empty($rows['error'])) {
            return [];
        }

        return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
    }

    /** List all player accounts (newest first) */
    public function listAllPlayers(int $limit = 300): array
    {
        $rows = $this->db->from('accounts')->select('*')
            ->eq('role', 'player')
            ->order('created_at', false)
            ->limit($limit)
            ->execute();

        if (!$rows || !empty($rows['error'])) {
            $rows = $this->db->from('accounts')->select('*')
                ->eq('login_type', 'player')
                ->order('created_at', false)
                ->limit($limit)
                ->execute();
        }

        if (!$rows || !empty($rows['error'])) {
            return [];
        }

        return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
    }

    /** List recent accounts by roles (newest first) */
    public function listRecentByRoles(array $roles, int $limit = 10): array
    {
        $allowed = ['player', 'instructor', 'admin'];
        $roles = array_values(array_filter(array_map(
            static fn($role): string => strtolower(trim((string)$role)),
            $roles
        ), static fn(string $role): bool => in_array($role, $allowed, true)));

        if (empty($roles)) {
            return [];
        }

        $cacheKey = 'accounts:list_recent_roles:' . implode('-', $roles) . ':' . $limit;
        return Cache::remember($cacheKey, 30, function () use ($roles, $limit): array {
            $roleConditions = implode(',', array_map(
                static fn(string $role): string => 'role.eq.' . $role,
                $roles
            ));

            $rows = $this->db->from('accounts')->select('*')
                ->orFilter($roleConditions)
                ->order('created_at', false)
                ->limit($limit)
                ->execute();

            if (!$rows || !empty($rows['error'])) {
                $legacyConditions = implode(',', array_map(
                    static fn(string $role): string => 'login_type.eq.' . $role,
                    $roles
                ));

                $rows = $this->db->from('accounts')->select('*')
                    ->orFilter($legacyConditions)
                    ->order('created_at', false)
                    ->limit($limit)
                    ->execute();
            }

            if (!$rows || !empty($rows['error'])) {
                return [];
            }

            return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
        });
    }

    /** Search players by first name, last name, or email */
    public function searchPlayers(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $safeTerm = str_replace([',', '(', ')'], ' ', $term);
        $conditions = sprintf(
            'fname.ilike.*%1$s*,lname.ilike.*%1$s*,email.ilike.*%1$s*',
            $safeTerm
        );

        $rows = $this->db->from('accounts')->select('*')
            ->eq('role', 'player')
            ->orFilter($conditions)
            ->order('created_at', false)
            ->limit($limit)
            ->execute();

        if (!$rows || !empty($rows['error'])) {
            $rows = $this->db->from('accounts')->select('*')
                ->eq('login_type', 'player')
                ->orFilter($conditions)
                ->order('created_at', false)
                ->limit($limit)
                ->execute();
        }

        if (!$rows || !empty($rows['error'])) {
            return [];
        }

        return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
    }

    /** Search accounts by name (ilike) */
    public function search(string $term, int $limit = 20): array
    {
        $rows = $this->db->from('accounts')->select('*')
            ->ilike('fname', "%{$term}%")
            ->limit($limit)->execute();
        if (!$rows || !empty($rows['error'])) {
            return [];
        }
        return array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
    }

    /** Create a new account row after signup */
    public function create(array $data): ?array
    {
        $payload = $data;
        $result = $this->db->from('accounts')->insert($payload);

        if ($this->isMissingRoleColumnError($result) && array_key_exists('role', $payload)) {
            $roleValue = $payload['role'];
            unset($payload['role']);
            if (!array_key_exists('login_type', $payload) && is_string($roleValue)) {
                $payload['login_type'] = strtolower(trim($roleValue));
            }
            $result = $this->db->from('accounts')->insert($payload);
        }

        if (!$result || !empty($result['error'])) {
            return null;
        }

        $row = $result[0] ?? $result;
        return is_array($row) ? $this->normalizeAccount($row) : null;
    }

    /** Update account profile */
    public function update(int $id, array $data): ?array
    {
        $payload = $data;
        $result = $this->db->from('accounts')->eq('id', (string)$id)->update($payload);

        if ($this->isMissingRoleColumnError($result) && array_key_exists('role', $payload)) {
            $roleValue = $payload['role'];
            unset($payload['role']);
            if (!array_key_exists('login_type', $payload) && is_string($roleValue)) {
                $payload['login_type'] = strtolower(trim($roleValue));
            }
            $result = $this->db->from('accounts')->eq('id', (string)$id)->update($payload);
        }

        if (!$result || !empty($result['error'])) {
            return null;
        }

        $row = $result[0] ?? $result;
        return is_array($row) ? $this->normalizeAccount($row) : null;
    }

    /** Update account by UID */
    public function updateByUid(string $uid, array $data): ?array
    {
        $payload = $data;
        $result = $this->db->from('accounts')->eq('uid', $uid)->update($payload);

        if ($this->isMissingRoleColumnError($result) && array_key_exists('role', $payload)) {
            $roleValue = $payload['role'];
            unset($payload['role']);
            if (!array_key_exists('login_type', $payload) && is_string($roleValue)) {
                $payload['login_type'] = strtolower(trim($roleValue));
            }
            $result = $this->db->from('accounts')->eq('uid', $uid)->update($payload);
        }

        if (!$result || !empty($result['error'])) {
            return null;
        }

        $row = $result[0] ?? $result;
        return is_array($row) ? $this->normalizeAccount($row) : null;
    }

    /** Delete an account */
    public function delete(int $id): bool
    {
        $result = $this->db->from('accounts')->eq('id', (string)$id)->delete();
        return $result !== null && empty($result['error']);
    }

    /** Count all accounts */
    public function count(): int
    {
        return Cache::remember('accounts:count', 60, fn(): int => $this->db->from('accounts')->countRows());
    }

    /** Count accounts by role */
    public function countByRole(string $role): int
    {
        $role = strtolower($role);
        $attempts = ['role', 'login_type', 'level'];

        foreach ($attempts as $column) {
            $rows = $this->db->from('accounts')->select('id')
                ->eq($column, $role)->execute();
            if (is_array($rows) && empty($rows['error'])) {
                return count($rows);
            }
        }

        return 0;
    }

    /** @param array<string,mixed> $result */
    private function isMissingRoleColumnError(array|null $result): bool
    {
        if (!is_array($result) || empty($result['error'])) {
            return false;
        }

        $message = strtolower((string)($result['message'] ?? ''));
        return str_contains($message, 'column')
            && str_contains($message, 'role');
    }

    /** @param array<string,mixed> $row */
    private function normalizeAccount(array $row): array
    {
        $role = $row['role'] ?? $row['login_type'] ?? $row['level'] ?? 'player';
        if (!is_string($role)) {
            $role = 'player';
        }
        $role = strtolower(trim($role));
        if (!in_array($role, ['player', 'instructor', 'admin'], true)) {
            $role = 'player';
        }
        $row['role'] = $role;
        return $row;
    }
}

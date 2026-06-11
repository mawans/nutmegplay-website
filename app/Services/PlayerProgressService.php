<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `player_progress` table.
 * Tracks OVR rating and current XP per player.
 */
class PlayerProgressService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    /** Get progress for a user */
    public function getByUser(string $userId): ?array
    {
        return Cache::remember("player_progress:user:$userId", 20, function () use ($userId): ?array {
            $row = $this->db->from('player_progress')->select('*')
                ->eq('user_id', $userId)->single()->execute();
            return ($row && empty($row['error'])) ? $this->normalize($row) : null;
        });
    }

    /** Get leaderboard (top players by OVR) */
    public function leaderboard(int $limit = 20): array
    {
        return Cache::remember("player_progress:leaderboard:$limit", 30, function () use ($limit): array {
            $rows = $this->db->from('player_progress')->select('*')
                ->order('ovr', false)->limit($limit)->execute();
            if (!$rows || !empty($rows['error'])) {
                return [];
            }
            return array_map(fn(array $row): array => $this->normalize($row), $rows);
        });
    }

    /** Create initial progress row for a new user */
    public function create(string $userId, int $ovr = 50, int $xp = 0): ?array
    {
        $result = $this->db->from('player_progress')->insert([
            'user_id'    => $userId,
            'ovr'        => $ovr,
            'current_xp' => $xp,
        ]);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Update OVR / XP */
    public function update(string $userId, array $data): ?array
    {
        $result = $this->db->from('player_progress')
            ->eq('user_id', $userId)->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Add XP to player and auto-level OVR */
    public function addXp(string $userId, int $xpGained): ?array
    {
        $current = $this->getByUser($userId);
        if (!$current) return null;

        $newXp  = (int)($current['current_xp'] ?? 0) + $xpGained;
        $newOvr = (int)($current['ovr'] ?? 50);

        // Simple leveling: every 100 XP = +1 OVR (cap at 99)
        while ($newXp >= 100 && $newOvr < 99) {
            $newXp  -= 100;
            $newOvr += 1;
        }

        return $this->update($userId, [
            'current_xp' => $newXp,
            'ovr'        => $newOvr,
        ]);
    }

    /** Recalculate OVR and current XP from a total XP amount */
    public function syncFromTotalXp(string $userId, int $totalXp): ?array
    {
        $ovr = 50;
        $currentXp = max(0, $totalXp);

        while ($currentXp >= 100 && $ovr < 99) {
            $currentXp -= 100;
            $ovr += 1;
        }

        $existing = $this->getByUser($userId);
        if (!$existing) {
            return $this->create($userId, $ovr, $currentXp);
        }

        return $this->update($userId, [
            'current_xp' => $currentXp,
            'ovr' => $ovr,
        ]);
    }

    /** Normalize legacy vs current field names used by older views */
    private function normalize(array $row): array
    {
        $ovr = (int)($row['ovr'] ?? 50);
        $currentXp = (int)($row['current_xp'] ?? ($row['xp'] ?? 0));
        $row['ovr'] = $ovr;
        $row['current_xp'] = $currentXp;
        $row['xp'] = $currentXp;
        $row['level'] = (int)($row['level'] ?? max(1, $ovr - 49));
        return $row;
    }
}

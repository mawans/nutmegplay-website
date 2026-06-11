<?php
namespace App\Services;

use App\Core\SupabaseClient;

/**
 * Service for the `xp_history` table.
 * Logs XP gains from matches.
 */
class XpHistoryService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    /** Get XP history for a user */
    public function getByUser(string $userId, int $limit = 50): array
    {
        $rows = $this->db->from('xp_history')->select('*')
            ->eq('user_id', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Total XP earned by a user */
    public function totalXp(string $userId): int
    {
        $rows = $this->getByUser($userId, 10000);
        return array_sum(array_column($rows, 'xp_gained'));
    }

    /** Get XP history entries linked to a match */
    public function getByMatch(string $matchId): array
    {
        $rows = $this->db->from('xp_history')->select('*')
            ->eq('match_id', $matchId)
            ->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Get a single XP history entry for one user in one match */
    public function getByMatchAndUser(string $matchId, string $userId): ?array
    {
        $row = $this->db->from('xp_history')->select('*')
            ->eq('match_id', $matchId)
            ->eq('user_id', $userId)
            ->single()
            ->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** Log an XP gain */
    public function log(string $userId, int $matchId, int $xpGained, string $reason = ''): ?array
    {
        $result = $this->db->from('xp_history')->insert([
            'user_id'   => $userId,
            'match_id'  => $matchId,
            'xp_gained' => $xpGained,
            'reason'    => $reason,
        ]);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function createMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $result = $this->db->from('xp_history')->insert($rows);
        return ($result && empty($result['error'])) ? $result : [];
    }

    /** Delete XP history for a match */
    public function deleteByMatch(string $matchId): bool
    {
        $result = $this->db->from('xp_history')->eq('match_id', $matchId)->delete();
        return $result !== null && empty($result['error']);
    }
}

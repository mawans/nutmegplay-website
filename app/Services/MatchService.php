<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `matchs` table.
 */
class MatchService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->from('matchs')->select('*')->eq('id', (string)$id)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** @param array<int, int> $ids */
    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int)$id,
            $ids
        ), static fn(int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->from('matchs')->select('*')
            ->filter('id', 'in', '(' . implode(',', $ids) . ')')
            ->execute();

        return ($rows && empty($rows['error']) && is_array($rows)) ? $rows : [];
    }

    /** All matches ordered by date descending */
    public function list(int $limit = 50): array
    {
        $rows = $this->db->from('matchs')->select('*')
            ->order('date', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Upcoming / pending matches */
    public function listUpcoming(int $limit = 20): array
    {
        $rows = $this->db->from('matchs')->select('*')
            ->eq('match_status', 'pending')
            ->order('date')->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Completed matches */
    public function listCompleted(int $limit = 20): array
    {
        $rows = $this->db->from('matchs')->select('*')
            ->eq('match_status', 'completed')
            ->order('date', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Matches involving a specific user (using OR filter in a single query) */
    public function listByUser(string $userId, int $limit = 20): array
    {
        return Cache::remember("matches:list_by_user:$userId:$limit", 20, function () use ($userId, $limit): array {
            $rows = $this->db->from('matchs')->select('*')
                ->orFilter('challenger_id.eq.' . $userId . ',opponent_id.eq.' . $userId)
                ->order('date', false)->limit($limit)->execute();
            return ($rows && empty($rows['error'])) ? $rows : [];
        });
    }

    /** Upcoming (pending) matches for a specific user */
    public function listUpcomingByUser(string $userId, int $limit = 20): array
    {
        return Cache::remember("matches:list_upcoming_by_user:$userId:$limit", 20, function () use ($userId, $limit): array {
            $rows = $this->db->from('matchs')->select('*')
                ->eq('match_status', 'pending')
                ->orFilter('challenger_id.eq.' . $userId . ',opponent_id.eq.' . $userId)
                ->order('date')->limit($limit)->execute();
            return ($rows && empty($rows['error'])) ? $rows : [];
        });
    }

    /** Completed matches for a specific user */
    public function listCompletedByUser(string $userId, int $limit = 20): array
    {
        $rows = $this->db->from('matchs')->select('*')
            ->eq('match_status', 'completed')
            ->orFilter('challenger_id.eq.' . $userId . ',opponent_id.eq.' . $userId)
            ->order('date', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Pending challenges (challange_status = 'pending') */
    public function listPendingChallenges(int $limit = 20): array
    {
        $rows = $this->db->from('matchs')->select('*')
            ->eq('challange_status', 'pending')
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Pending challenges where a specific user is the opponent */
    public function listPendingForUser(string $userId, int $limit = 20): array
    {
        $rows = $this->db->from('matchs')->select('*')
            ->eq('challange_status', 'pending')
            ->eq('opponent_id', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Create a new match */
    public function create(array $data): ?array
    {
        $result = $this->db->from('matchs')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Update match */
    public function update(int $id, array $data): ?array
    {
        $result = $this->db->from('matchs')->eq('id', (string)$id)->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Accept a challenge */
    public function acceptChallenge(int $id): ?array
    {
        return $this->update($id, ['challange_status' => 'accepted']);
    }

    /** Decline a challenge */
    public function declineChallenge(int $id): ?array
    {
        return $this->update($id, ['challange_status' => 'declined']);
    }

    /** Count matches */
    public function count(): int
    {
        return Cache::remember('matches:count', 60, fn(): int => $this->db->from('matchs')->countRows());
    }

    /** Count completed matches */
    public function countCompleted(): int
    {
        return Cache::remember('matches:count_completed', 60, fn(): int => $this->db->from('matchs')
            ->eq('match_status', 'completed')
            ->countRows());
    }
}

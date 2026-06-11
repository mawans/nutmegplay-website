<?php
namespace App\Services;

use App\Core\SupabaseClient;

/**
 * Service for the `match_video_analysis` table.
 */
class MatchVideoAnalysisService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getByMatchId(int $matchId): ?array
    {
        $row = $this->db->from('match_video_analysis')->select('*')
            ->eq('match_id', (string)$matchId)
            ->single()
            ->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    public function listByStatus(string $status, int $limit = 10): array
    {
        $rows = $this->db->from('match_video_analysis')->select('*')
            ->eq('processing_status', $status)
            ->order('queued_at')
            ->limit($limit)
            ->execute();

        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /**
     * Same as listByStatus() but throws if the query failed. Use this when an
     * empty result must be distinguished from a Supabase outage — e.g. before
     * making destructive decisions like terminating a Runpod pod.
     */
    public function listByStatusStrict(string $status, int $limit = 10): array
    {
        $rows = $this->db->from('match_video_analysis')->select('*')
            ->eq('processing_status', $status)
            ->order('queued_at')
            ->limit($limit)
            ->execute();

        if (!is_array($rows)) {
            throw new \RuntimeException('Supabase returned an unexpected response while listing match_video_analysis.');
        }

        if (!empty($rows['error'])) {
            $message = is_string($rows['message'] ?? null) ? (string)$rows['message'] : 'Supabase reported an error listing match_video_analysis.';
            throw new \RuntimeException($message);
        }

        return $rows;
    }

    /**
     * @param array<int, int> $matchIds
     * @return array<int, array<string, mixed>>
     */
    public function listByMatchIds(array $matchIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $matchId): int => (int)$matchId,
            $matchIds
        ), static fn(int $matchId): bool => $matchId > 0)));

        if ($normalizedIds === []) {
            return [];
        }

        $rows = $this->db->from('match_video_analysis')->select('*')
            ->filter('match_id', 'in', '(' . implode(',', $normalizedIds) . ')')
            ->execute();

        if (!$rows || !empty($rows['error']) || !is_array($rows)) {
            return [];
        }

        $byMatchId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $matchId = (int)($row['match_id'] ?? 0);
            if ($matchId > 0) {
                $byMatchId[$matchId] = $row;
            }
        }

        return $byMatchId;
    }

    public function create(array $data): ?array
    {
        $result = $this->db->from('match_video_analysis')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function updateByMatchId(int $matchId, array $data): ?array
    {
        $result = $this->db->from('match_video_analysis')
            ->eq('match_id', (string)$matchId)
            ->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function upsertByMatchId(int $matchId, array $data): ?array
    {
        $existing = $this->getByMatchId($matchId);
        if ($existing) {
            return $this->updateByMatchId($matchId, $data);
        }

        $data['match_id'] = $matchId;
        return $this->create($data);
    }
}

<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for AI match stats keyed by jersey number.
 */
class MatchJerseyStatService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getByMatch(int|string $matchId): array
    {
        $rows = $this->db->from('match_jersey_stats')->select('*')
            ->eq('match_id', (string)$matchId)
            ->order('jersey_number')
            ->execute();

        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    public function getByMatchId(int|string $matchId): array
    {
        return $this->getByMatch($matchId);
    }

    public function getByMatchAndNumber(int|string $matchId, int $jerseyNumber): ?array
    {
        $row = $this->db->from('match_jersey_stats')->select('*')
            ->eq('match_id', (string)$matchId)
            ->eq('jersey_number', (string)$jerseyNumber)
            ->single()
            ->execute();

        return ($row && empty($row['error'])) ? $row : null;
    }

    public function upsertByMatchAndNumber(int|string $matchId, int $jerseyNumber, array $data): ?array
    {
        $existing = $this->getByMatchAndNumber($matchId, $jerseyNumber);
        if ($existing && !empty($existing['id'])) {
            return $this->update((string)$existing['id'], $data);
        }

        return $this->create($data);
    }

    public function deleteByMatch(int|string $matchId): bool
    {
        $result = $this->db->from('match_jersey_stats')
            ->eq('match_id', (string)$matchId)
            ->delete();

        return $result !== null && empty($result['error']);
    }

    public function groupedByTeam(int|string $matchId): array
    {
        $grouped = ['blue' => [], 'red' => []];
        foreach ($this->getByMatch($matchId) as $row) {
            $team = strtolower((string)($row['team_color'] ?? ''));
            if (!isset($grouped[$team])) {
                continue;
            }
            $grouped[$team][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $matchIds
     * @return array<int, array<string, array<int, array<string, mixed>>>>
     */
    public function groupedByMatchIds(array $matchIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $matchId): int => (int)$matchId,
            $matchIds
        ), static fn(int $matchId): bool => $matchId > 0)));

        $grouped = [];
        foreach ($normalizedIds as $matchId) {
            $grouped[$matchId] = ['blue' => [], 'red' => []];
        }

        if ($normalizedIds === []) {
            return $grouped;
        }

        $rows = $this->db->from('match_jersey_stats')->select('*')
            ->filter('match_id', 'in', '(' . implode(',', $normalizedIds) . ')')
            ->execute();

        if (!$rows || !empty($rows['error']) || !is_array($rows)) {
            return $grouped;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $matchId = (int)($row['match_id'] ?? 0);
            $team = strtolower((string)($row['team_color'] ?? ''));
            if ($matchId <= 0 || !isset($grouped[$matchId][$team])) {
                continue;
            }

            $grouped[$matchId][$team][] = $row;
        }

        foreach ($grouped as &$teams) {
            foreach (['blue', 'red'] as $team) {
                usort($teams[$team], static function (array $left, array $right): int {
                    return (int)($left['jersey_number'] ?? 0) <=> (int)($right['jersey_number'] ?? 0);
                });
            }
        }
        unset($teams);

        return $grouped;
    }

    public function getByPlayer(string $playerUid, int $limit = 200): array
    {
        $rows = $this->db->from('match_jersey_stats')->select('*')
            ->eq('player_uid', $playerUid)
            ->order('updated_at', false)->limit($limit)
            ->execute();

        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    public function getUserTotals(string $playerUid): array
    {
        return Cache::remember("match_jersey_stats:user_totals:$playerUid", 20, function () use ($playerUid): array {
            $rows = $this->getByPlayer($playerUid, 1000);
            $totals = [
                'matches' => count($rows),
                'goals' => 0,
                'assists' => 0,
                'distance_meters' => 0,
                'distance_km' => 0.0,
                'successful_passes' => 0,
                'passes_attempted' => 0,
                'pass_accuracy' => 0.0,
                'successful_dribbles' => 0,
                'dribbles_attempted' => 0,
                'dribble_accuracy' => 0.0,
                'interceptions' => 0,
                'duels_won' => 0,
                'shots' => 0,
                'top_speed_kmh' => 0.0,
                'minutes_played' => 0,
            ];

            foreach ($rows as $row) {
                $totals['goals'] += (int)($row['goals'] ?? 0);
                $totals['assists'] += (int)($row['assists'] ?? 0);
                $totals['distance_meters'] += (int)($row['distance_meters'] ?? 0);
                $totals['successful_passes'] += (int)($row['successful_passes'] ?? 0);
                $totals['passes_attempted'] += (int)($row['passes_attempted'] ?? 0);
                $totals['successful_dribbles'] += (int)($row['successful_dribbles'] ?? 0);
                $totals['dribbles_attempted'] += (int)($row['dribbles_attempted'] ?? 0);
                $totals['interceptions'] += (int)($row['interceptions'] ?? 0);
                $totals['duels_won'] += (int)($row['duels_won'] ?? 0);
                $totals['shots'] += (int)($row['shots'] ?? 0);
                $totals['top_speed_kmh'] = max($totals['top_speed_kmh'], (float)($row['top_speed_kmh'] ?? 0));
                $totals['minutes_played'] += (int)($row['minutes_played'] ?? 0);
            }

            $totals['distance_km'] = round($totals['distance_meters'] / 1000, 1);
            $totals['top_speed_kmh'] = round($totals['top_speed_kmh'], 1);
            $totals['pass_accuracy'] = $this->percentage($totals['successful_passes'], $totals['passes_attempted']);
            $totals['dribble_accuracy'] = $this->percentage($totals['successful_dribbles'], $totals['dribbles_attempted']);

            return $totals;
        });
    }

    public function create(array $data): ?array
    {
        $result = $this->db->from('match_jersey_stats')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function createMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $result = $this->db->from('match_jersey_stats')->insert($rows);
        return ($result && empty($result['error'])) ? $result : [];
    }

    private function update(string $id, array $data): ?array
    {
        $result = $this->db->from('match_jersey_stats')
            ->eq('id', $id)
            ->update($data);

        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    private function percentage(int $value, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($value / $total) * 100, 1);
    }
}

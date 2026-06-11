<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `match_stats` table.
 */
class MatchStatsService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    /** Get stats for a specific match entry */
    public function getById(string $id): ?array
    {
        $row = $this->db->from('match_stats')->select('*')->eq('id', $id)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** Get all stats for a single match */
    public function getByMatch(string $matchId): array
    {
        $rows = $this->db->from('match_stats')->select('*')
            ->eq('match_id', $matchId)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Get all stats for a player across matches */
    public function getByUser(string $userId, int $limit = 50): array
    {
        $rows = $this->db->from('match_stats')->select('*')
            ->eq('user_id', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Get match stats for a specific user in a specific match */
    public function getByMatchAndUser(string $matchId, string $userId): ?array
    {
        $row = $this->db->from('match_stats')->select('*')
            ->eq('match_id', $matchId)
            ->eq('user_id', $userId)
            ->single()
            ->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** Aggregate stats for a player: totals */
    public function getUserTotals(string $userId): array
    {
        return Cache::remember("match_stats:user_totals:$userId", 20, function () use ($userId): array {
            $stats = $this->getByUser($userId, 1000);
            $totals = $this->emptyTotals();

            if (empty($stats)) {
                return $totals;
            }

            $totals['matches'] = count($stats);

            foreach ($stats as $s) {
                $totals['goals'] += $this->asInt($s, ['goals']);
                $totals['assists'] += $this->asInt($s, ['assists']);
                $totals['distance_meters'] += $this->asInt($s, ['distance_meters', 'distance']);
                $totals['sprints'] += $this->asInt($s, ['sprints']);
                $totals['successful_passes'] += $this->asInt($s, ['successful_passes', 'passes']);
                $totals['passes_attempted'] += $this->asInt($s, ['passes_attempted']);
                $totals['successful_dribbles'] += $this->asInt($s, ['successful_dribbles', 'dribbles']);
                $totals['dribbles_attempted'] += $this->asInt($s, ['dribbles_attempted']);
                $totals['interceptions'] += $this->asInt($s, ['interceptions']);
                $totals['duels_won'] += $this->asInt($s, ['duels_won', 'duels']);
                $totals['minutes_played'] += $this->asInt($s, ['minutes_played']);
                $totals['team_win_streak_best'] = max(
                    $totals['team_win_streak_best'],
                    $this->asInt($s, ['team_win_streak'])
                );

                if ($this->asBool($s, ['clean_sheet'])) {
                    $totals['clean_sheets']++;
                }
                if ($this->asBool($s, ['match_winning_goal'])) {
                    $totals['match_winning_goals']++;
                }

                $result = strtolower((string)($s['result'] ?? ''));
                if ($result === 'win') {
                    $totals['wins']++;
                } elseif ($result === 'draw') {
                    $totals['draws']++;
                } elseif ($result === 'loss') {
                    $totals['losses']++;
                }
            }

            $totals['pass_accuracy'] = $this->percentage(
                $totals['successful_passes'],
                $totals['passes_attempted']
            );
            $totals['dribble_accuracy'] = $this->percentage(
                $totals['successful_dribbles'],
                $totals['dribbles_attempted']
            );
            $totals['win_rate'] = $this->percentage($totals['wins'], $totals['matches']);
            $totals['distance_km'] = round($totals['distance_meters'] / 1000, 1);

            $totals['distance'] = $totals['distance_meters'];
            $totals['passes'] = $totals['successful_passes'];
            $totals['dribbles'] = $totals['successful_dribbles'];
            $totals['duels'] = $totals['duels_won'];

            return $totals;
        });
    }

    /** Create match stats row */
    public function create(array $data): ?array
    {
        $result = $this->db->from('match_stats')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function createMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $result = $this->db->from('match_stats')->insert($rows);
        return ($result && empty($result['error'])) ? $result : [];
    }

    /** Update match stats */
    public function update(string $id, array $data): ?array
    {
        $result = $this->db->from('match_stats')->eq('id', $id)->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Upsert a single match stats row by user + match */
    public function upsertByMatchAndUser(string $matchId, string $userId, array $data): ?array
    {
        $existing = $this->getByMatchAndUser($matchId, $userId);
        if ($existing && !empty($existing['id'])) {
            return $this->update((string)$existing['id'], $data);
        }

        return $this->create($data);
    }

    /** Delete all stats rows for a match */
    public function deleteByMatch(string $matchId): bool
    {
        $result = $this->db->from('match_stats')->eq('match_id', $matchId)->delete();
        return $result !== null && empty($result['error']);
    }

    /** @return array<string,int|float> */
    private function emptyTotals(): array
    {
        return [
            'matches' => 0,
            'goals' => 0,
            'assists' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0,
            'distance_meters' => 0,
            'distance_km' => 0.0,
            'sprints' => 0,
            'successful_passes' => 0,
            'passes_attempted' => 0,
            'pass_accuracy' => 0.0,
            'successful_dribbles' => 0,
            'dribbles_attempted' => 0,
            'dribble_accuracy' => 0.0,
            'interceptions' => 0,
            'duels_won' => 0,
            'clean_sheets' => 0,
            'match_winning_goals' => 0,
            'minutes_played' => 0,
            'team_win_streak_best' => 0,
            // Legacy aliases expected by some views.
            'distance' => 0,
            'passes' => 0,
            'dribbles' => 0,
            'duels' => 0,
            'win_rate' => 0.0,
        ];
    }

    /** @param array<string,mixed> $row @param string[] $keys */
    private function asInt(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (int)$row[$key];
            }
        }
        return 0;
    }

    /** @param array<string,mixed> $row @param string[] $keys */
    private function asBool(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return filter_var($row[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        return false;
    }

    private function percentage(int $value, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return round(($value / $total) * 100, 1);
    }
}

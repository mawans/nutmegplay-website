<?php
namespace App\Services;

use App\Core\SupabaseClient;

/**
 * Service for fixed per-match jersey slots.
 */
class MatchJerseySlotService
{
    private const BLUE_NUMBERS = [1, 2, 3, 4, 5];
    private const RED_NUMBERS = [6, 7, 8, 9, 10];

    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getByMatch(int $matchId): array
    {
        $rows = $this->db->from('match_jersey_slots')->select('*')
            ->eq('match_id', (string)$matchId)
            ->order('jersey_number')
            ->execute();

        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    public function getByMatchAndNumber(int $matchId, int $jerseyNumber): ?array
    {
        $row = $this->db->from('match_jersey_slots')->select('*')
            ->eq('match_id', (string)$matchId)
            ->eq('jersey_number', (string)$jerseyNumber)
            ->single()
            ->execute();

        return ($row && empty($row['error'])) ? $row : null;
    }

    public function ensureDefaults(int $matchId): array
    {
        $existing = $this->getByMatch($matchId);
        $existingNumbers = [];
        foreach ($existing as $row) {
            $existingNumbers[(int)($row['jersey_number'] ?? 0)] = true;
        }

        foreach ($this->defaultSlots($matchId) as $slot) {
            $number = (int)$slot['jersey_number'];
            if (isset($existingNumbers[$number])) {
                continue;
            }

            $this->create($slot);
        }

        return $this->getByMatch($matchId);
    }

    public function replaceAssignments(int $matchId, array $assignments): array
    {
        $slots = $this->ensureDefaults($matchId);
        $byNumber = [];
        foreach ($slots as $slot) {
            $byNumber[(int)($slot['jersey_number'] ?? 0)] = $slot;
        }

        foreach ($this->defaultSlots($matchId) as $defaultSlot) {
            $number = (int)$defaultSlot['jersey_number'];
            $payload = [
                'player_uid' => null,
                'player_name' => null,
                'updated_at' => gmdate('c'),
            ];

            if (isset($assignments[$number]) && is_array($assignments[$number])) {
                $payload['player_uid'] = $this->nullableString($assignments[$number]['player_uid'] ?? null);
                $payload['player_name'] = $this->nullableString($assignments[$number]['player_name'] ?? null);
            }

            $existing = $byNumber[$number] ?? null;
            if ($existing && !empty($existing['id'])) {
                $this->update((string)$existing['id'], $payload);
            } else {
                $this->create($defaultSlot + $payload);
            }
        }

        return $this->getByMatch($matchId);
    }

    public function groupedByTeam(int $matchId): array
    {
        $grouped = ['blue' => [], 'red' => []];
        foreach ($this->ensureDefaults($matchId) as $slot) {
            $team = strtolower((string)($slot['team_color'] ?? ''));
            if (!isset($grouped[$team])) {
                continue;
            }
            $grouped[$team][] = $slot;
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
            $grouped[$matchId] = $this->defaultGroupedSlots($matchId);
        }

        if ($normalizedIds === []) {
            return $grouped;
        }

        $rows = $this->db->from('match_jersey_slots')->select('*')
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
            $number = (int)($row['jersey_number'] ?? 0);
            if ($matchId <= 0 || !isset($grouped[$matchId][$team]) || $number <= 0) {
                continue;
            }

            $grouped[$matchId][$team][$number] = $row;
        }

        foreach ($grouped as &$teams) {
            foreach (['blue', 'red'] as $team) {
                ksort($teams[$team]);
                $teams[$team] = array_values($teams[$team]);
            }
        }
        unset($teams);

        return $grouped;
    }

    private function create(array $data): ?array
    {
        $result = $this->db->from('match_jersey_slots')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    private function update(string $id, array $data): ?array
    {
        $result = $this->db->from('match_jersey_slots')
            ->eq('id', $id)
            ->update($data);

        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function defaultSlots(int $matchId): array
    {
        $rows = [];
        foreach (self::BLUE_NUMBERS as $number) {
            $rows[] = [
                'match_id' => $matchId,
                'team_color' => 'blue',
                'jersey_number' => $number,
                'player_uid' => null,
                'player_name' => null,
                'updated_at' => gmdate('c'),
            ];
        }

        foreach (self::RED_NUMBERS as $number) {
            $rows[] = [
                'match_id' => $matchId,
                'team_color' => 'red',
                'jersey_number' => $number,
                'player_uid' => null,
                'player_name' => null,
                'updated_at' => gmdate('c'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function defaultGroupedSlots(int $matchId): array
    {
        $grouped = ['blue' => [], 'red' => []];
        foreach ($this->defaultSlots($matchId) as $slot) {
            $team = (string)$slot['team_color'];
            $grouped[$team][(int)$slot['jersey_number']] = $slot;
        }

        return $grouped;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

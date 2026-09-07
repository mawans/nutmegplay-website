<?php
namespace App\Services;

use RuntimeException;

/**
 * Manages weekly challenges: creation, tracking, progress updates, leaderboards
 * Uses Supabase PostgREST API via SupabaseClient
 */
class WeeklyChallengesService
{
    private $sb;
    private const VALID_METRICS = ['goals', 'assists', 'distance_meters', 'sprints', 'passes', 'matches_won', 'duels_won', 'interceptions'];
    private const VALID_DIFFICULTIES = ['easy', 'medium', 'hard'];

    public function __construct($supabaseClient = null)
    {
        $this->sb = $supabaseClient ?? \App\Core\SupabaseClient::getInstance();
        if (!$this->sb) {
            throw new RuntimeException('Supabase client not available');
        }
    }

    /**
     * Create a new weekly challenge
     */
    public function createChallenge(array $data): int
    {
        if (!in_array($data['metric'] ?? '', self::VALID_METRICS, true)) {
            throw new RuntimeException('Invalid metric: ' . ($data['metric'] ?? ''));
        }

        if (!in_array($data['difficulty'] ?? 'medium', self::VALID_DIFFICULTIES, true)) {
            $data['difficulty'] = 'medium';
        }

        $result = $this->sb->from('weekly_challenges')->insert([
            'title' => $data['title'] ?? 'Unnamed Challenge',
            'description' => $data['description'] ?? '',
            'metric' => $data['metric'],
            'target_value' => (int)($data['target_value'] ?? 5),
            'xp_reward' => (int)($data['xp_reward'] ?? 100),
            'difficulty' => $data['difficulty'] ?? 'medium',
            'is_team_challenge' => (bool)($data['is_team_challenge'] ?? false),
            'start_date' => $data['start_date'] ?? date('Y-m-d'),
            'end_date' => $data['end_date'] ?? date('Y-m-d', strtotime('+7 days')),
            'is_active' => (bool)($data['is_active'] ?? true),
            'is_repeating' => (bool)($data['is_repeating'] ?? true),
            'created_by' => $data['created_by'] ?? null,
        ]);

        if (!$result || !is_array($result) || empty($result)) {
            throw new RuntimeException('Failed to create challenge');
        }

        return (int)$result[0]['id'];
    }

    /**
     * Get active challenges for current week
     */
    public function getActiveChallenges(): array
    {
        try {
            $today = date('Y-m-d');
            $result = $this->sb->from('weekly_challenges')
                ->select('*')
                ->eq('is_active', 'true')
                ->filter('start_date', 'lte', $today)
                ->filter('end_date', 'gte', $today)
                ->order('difficulty', false)
                ->order('created_at', false)
                ->execute();

            return is_array($result) ? array_filter($result, 'is_array') : [];
        } catch (\Throwable $e) {
            error_log('Failed to get active challenges: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get leaderboard for a specific challenge this week
     */
    public function getChallengeLeaderboard(int $challengeId, ?string $weekStartDate = null): array
    {
        $weekStartDate = $weekStartDate ?? $this->getWeekStart(date('Y-m-d'));

        try {
            $result = $this->sb->from('challenge_participation')
                ->select('id, user_id, current_progress, position_rank, is_winner')
                ->eq('challenge_id', (string)$challengeId)
                ->eq('week_start_date', $weekStartDate)
                ->filter('status', 'in', '(in_progress,completed)')
                ->order('current_progress', false)
                ->order('updated_at', false)
                ->limit(100)
                ->execute();

            if (
                !is_array($result)
                || isset($result['error'])
                || !array_is_list($result)
            ) {
                return [];
            }

            $userIds = array_values(array_unique(array_filter(array_map(
                static fn($entry) => is_array($entry)
                    ? (string)($entry['user_id'] ?? '')
                    : '',
                $result
            ))));
            $accountsById = [];
            if ($userIds !== []) {
                $accounts = $this->sb->from('accounts')
                    ->select('uid, fname, lname, email')
                    ->filter('uid', 'in', '(' . implode(',', $userIds) . ')')
                    ->execute();

                if (is_array($accounts) && !isset($accounts['error']) && array_is_list($accounts)) {
                    foreach ($accounts as $account) {
                        if (is_array($account) && !empty($account['uid'])) {
                            $accountsById[(string)$account['uid']] = $account;
                        }
                    }
                }
            }

            // Reformat response to flatten account data and add rank
            $leaderboard = [];
            foreach ($result as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $account = $accountsById[(string)($entry['user_id'] ?? '')] ?? [];
                $leaderboard[] = [
                    'id' => $entry['id'] ?? null,
                    'user_id' => $entry['user_id'] ?? null,
                    'current_progress' => $entry['current_progress'] ?? 0,
                    'position_rank' => $entry['position_rank'] ?? null,
                    'is_winner' => (bool)($entry['is_winner'] ?? false),
                    'rank' => count($leaderboard) + 1,
                    'fname' => $account['fname'] ?? 'Unknown',
                    'lname' => $account['lname'] ?? '',
                    'email' => $account['email'] ?? '',
                ];
            }
            return $leaderboard;
        } catch (\Throwable $e) {
            error_log('Failed to get challenge leaderboard: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Enroll a user in a challenge
     */
    public function enrollUserInChallenge(int $challengeId, string $userId, ?string $weekStartDate = null): bool
    {
        $weekStartDate = $weekStartDate ?? $this->getWeekStart(date('Y-m-d'));
        
        try {
            // Get challenge to get target value
            $challenges = $this->sb->from('weekly_challenges')
                ->select('target_value, metric')
                ->eq('id', (string)$challengeId)
                ->execute();

            if (!$challenges || empty($challenges)) {
                return false;
            }

            $challenge = $challenges[0];
            $weekNum = (int)date('W', strtotime($weekStartDate));
            $yearNum = (int)date('Y', strtotime($weekStartDate));

            // Insert participation record
            $result = $this->sb->from('challenge_participation')->insert([
                'challenge_id' => $challengeId,
                'user_id' => $userId,
                'week_start_date' => $weekStartDate,
                'week_number' => $weekNum,
                'year_number' => $yearNum,
                'target_value' => $challenge['target_value'],
                'current_progress' => 0,
                'status' => 'in_progress',
            ]);

            return $result !== null && !empty($result);
        } catch (\Throwable $e) {
            error_log('Failed to enroll user in challenge: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update player progress based on match stats
     */
    public function updatePlayerProgress(string $userId, int $matchId): void
    {
        try {
            $stats = new MatchStatsService($this->sb);
            $userStats = $stats->getUserTotals($userId);

            $activeChallenges = $this->getActiveChallenges();
            $weekStart = $this->getWeekStart(date('Y-m-d'));

            foreach ($activeChallenges as $challenge) {
                $metric = $challenge['metric'];
                $currentValue = (int)($userStats[$metric] ?? 0);
                $targetValue = (int)$challenge['target_value'];
                $isCompleted = $currentValue >= $targetValue;

                // Update participation record
                $this->sb->from('challenge_participation')
                    ->eq('challenge_id', (string)$challenge['id'])
                    ->eq('user_id', $userId)
                    ->eq('week_start_date', $weekStart)
                    ->update([
                        'current_progress' => $currentValue,
                        'status' => $isCompleted ? 'completed' : 'in_progress',
                        'completed_at' => $isCompleted ? gmdate('c') : null,
                        'updated_at' => gmdate('c'),
                    ]);

                // Auto-award XP if completed
                if ($isCompleted) {
                    $this->awardChallengeCompletion($challenge['id'], $userId, $weekStart);
                }
            }
        } catch (\Throwable $e) {
            error_log('Failed to update player progress: ' . $e->getMessage());
        }
    }

    /**
     * Award XP and mark challenge as completed
     */
    private function awardChallengeCompletion(int $challengeId, string $userId, string $weekStartDate): void
    {
        try {
            // Get XP reward from challenge
            $challenges = $this->sb->from('weekly_challenges')
                ->select('xp_reward')
                ->eq('id', (string)$challengeId)
                ->execute();

            if (!$challenges || empty($challenges)) {
                return;
            }

            $xpReward = (int)$challenges[0]['xp_reward'];

            // Check if already awarded
            $existing = $this->sb->from('challenge_participation')
                ->select('id')
                ->eq('challenge_id', (string)$challengeId)
                ->eq('user_id', $userId)
                ->eq('week_start_date', $weekStartDate)
                ->filter('xp_awarded', 'gt', '0')
                ->execute();

            if ($existing && !empty($existing)) {
                return;  // Already awarded
            }

            // Award XP
            $this->sb->from('challenge_participation')
                ->eq('challenge_id', (string)$challengeId)
                ->eq('user_id', $userId)
                ->eq('week_start_date', $weekStartDate)
                ->update([
                    'xp_awarded' => $xpReward,
                    'is_winner' => true,
                ]);

            // Add XP to player progress
            $progress = new PlayerProgressService($this->sb);
            $progress->addXp($userId, $xpReward, 'challenge_completion');

            // Send notification
            $notification = new NotificationService($this->sb);
            $notification->notify($userId, 'challenge_completed', 'Challenge Completed!', "You earned $xpReward XP!");
        } catch (\Throwable $e) {
            error_log('Failed to award challenge completion: ' . $e->getMessage());
        }
    }

    /**
     * Get overall leaderboard across all challenges
     */
    public function getOverallLeaderboard(?string $weekStartDate = null, int $limit = 20): array
    {
        $weekStartDate = $weekStartDate ?? $this->getWeekStart(date('Y-m-d'));

        try {
            // This would require a more complex query - for now return empty
            // In production, you'd use a database view or stored procedure
            return [];
        } catch (\Throwable $e) {
            error_log('Failed to get overall leaderboard: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user's challenge history
     */
    public function getUserChallengeHistory(string $userId, int $limit = 10): array
    {
        try {
            $result = $this->sb->from('challenge_participation')
                ->select('*')
                ->eq('user_id', $userId)
                ->eq('status', 'completed')
                ->order('completed_at', false)
                ->limit($limit)
                ->execute();

            return is_array($result) ? array_filter($result, 'is_array') : [];
        } catch (\Throwable $e) {
            error_log('Failed to get user challenge history: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Archive completed week
     */
    public function archiveWeek(string $weekStartDate): void
    {
        try {
            $weekNum = (int)date('W', strtotime($weekStartDate));
            $yearNum = (int)date('Y', strtotime($weekStartDate));

            // Get all challenges for this week
            $challenges = $this->sb->from('challenge_participation')
                ->select('DISTINCT challenge_id')
                ->eq('week_start_date', $weekStartDate)
                ->execute();

            if (!$challenges) {
                return;
            }

            // For each challenge, create a results record
            foreach ($challenges as $row) {
                $challengeId = $row['challenge_id'];

                // Get top 3 participants
                $top3 = $this->sb->from('challenge_participation')
                    ->select('user_id, current_progress')
                    ->eq('challenge_id', (string)$challengeId)
                    ->eq('week_start_date', $weekStartDate)
                    ->order('current_progress', false)
                    ->limit(3)
                    ->execute();

                $topUsers = array_column($top3 ?: [], 'user_id');
                $topScores = array_column($top3 ?: [], 'current_progress');

                // Insert results
                $this->sb->from('weekly_challenge_results')->insert([
                    'challenge_id' => $challengeId,
                    'week_start_date' => $weekStartDate,
                    'week_number' => $weekNum,
                    'year_number' => $yearNum,
                    'winner_user_id' => $topUsers[0] ?? null,
                    'top_3_users' => json_encode($topUsers),
                    'top_3_scores' => json_encode($topScores),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Failed to archive week: ' . $e->getMessage());
        }
    }

    /**
     * Helper: Get Monday of current/given week
     */
    private function getWeekStart(string $date): string
    {
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('w', $timestamp);
        
        if ($dayOfWeek === 0) {
            $dayOfWeek = 7;  // Sunday = 7
        }
        
        $daysToMonday = $dayOfWeek - 1;
        return date('Y-m-d', $timestamp - ($daysToMonday * 86400));
    }
}

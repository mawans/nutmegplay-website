<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\WeeklyChallengesService;

class WeeklyChallengesController extends Controller
{
    /** GET /weekly-challenges – View active challenges and leaderboards */
    public function index(): void
    {
        Auth::requireAuth();

        $uid = Auth::uid();
        $activeChallenges = [];
        $leaderboards = [];
        $userProgress = [];
        $overallLeaderboard = [];
        $userHistory = [];
        $setupError = null;

        try {
            $challenges = new WeeklyChallengesService(\App\Core\SupabaseClient::getInstance());
            $activeChallenges = $challenges->getActiveChallenges();

            foreach ($activeChallenges as $challenge) {
                $cid = $challenge['id'] ?? null;
                if ($cid === null) {
                    continue;
                }
                $leaderboards[$cid] = $challenges->getChallengeLeaderboard((int)$cid);
                $userProgress[$cid] = array_values(array_filter(
                    $leaderboards[$cid],
                    fn($entry) => ($entry['user_id'] ?? null) === $uid
                ))[0] ?? null;
            }

            $overallLeaderboard = $challenges->getOverallLeaderboard();
            $userHistory = $challenges->getUserChallengeHistory($uid, 10);
        } catch (\Throwable $e) {
            $this->reportException('weekly-challenges-index', $e);
            $setupError = 'Weekly challenges could not be loaded right now. The database tables may not be set up yet.';
        }

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/weekly-challenges.php', [
            'account'          => Auth::account(),
            'activeChallenges' => $activeChallenges,
            'leaderboards'     => $leaderboards,
            'userProgress'     => $userProgress,
            'overallLeaderboard' => $overallLeaderboard,
            'userHistory'      => $userHistory,
            'isInstructor'     => Auth::isInstructor(),
            'setupError'       => $setupError,
        ]);
    }

    /** GET /weekly-challenges/{id} – View specific challenge details */
    public function details(int $id): void
    {
        Auth::requireAuth();

        $uid = Auth::uid();
        $challenges = new WeeklyChallengesService();

        $leaderboard = $challenges->getChallengeLeaderboard($id);
        $userProgress = array_values(array_filter(
            $leaderboard,
            fn($entry) => $entry['user_id'] === $uid
        ))[0] ?? null;

        $this->renderRaw(BASE_PATH . '/app/views/challenges/challenge-detail.php', [
            'challengeId' => $id,
            'leaderboard' => $leaderboard,
            'userProgress' => $userProgress,
        ]);
    }

    /** POST /weekly-challenges/create – Admin only: Create new challenge */
    public function create(): void
    {
        Auth::requireAuth();
        Auth::requireInstructor();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        $challenges = new WeeklyChallengesService();

        $uid = Auth::uid();
        try {
            $challengeId = $challenges->createChallenge([
                'title' => $_POST['title'] ?? 'New Challenge',
                'description' => $_POST['description'] ?? '',
                'metric' => $_POST['metric'] ?? 'goals',
                'target_value' => (int)($_POST['target_value'] ?? 5),
                'xp_reward' => (int)($_POST['xp_reward'] ?? 100),
                'difficulty' => $_POST['difficulty'] ?? 'medium',
                'is_team_challenge' => (bool)($_POST['is_team_challenge'] ?? false),
                'start_date' => $_POST['start_date'] ?? date('Y-m-d'),
                'end_date' => $_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days')),
                'is_repeating' => (bool)($_POST['is_repeating'] ?? true),
                'created_by' => $uid,
            ]);

            echo json_encode(['success' => true, 'challenge_id' => $challengeId]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /weekly-challenges/{id}/enroll – User enrolls in a challenge */
    public function enroll(int $id): void
    {
        Auth::requireAuth();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        $uid = Auth::uid();
        $challenges = new WeeklyChallengesService();

        try {
            $enrolled = $challenges->enrollUserInChallenge($id, $uid);

            if ($enrolled) {
                echo json_encode(['success' => true, 'message' => 'Enrolled in challenge!']);
                
                // Send notification
                $notification = new \App\Services\NotificationService();
                $notification->notify($uid, 'challenge_enrolled', 'Challenge Started!', 'You are now participating in this week\'s challenge.');
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Could not enroll in challenge']);
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /** GET /api/weekly-challenges/leaderboard/{id} – JSON leaderboard for AJAX */
    public function leaderboardJson(int $id): void
    {
        Auth::requireAuth();

        $challenges = new WeeklyChallengesService();
        $leaderboard = $challenges->getChallengeLeaderboard($id);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'leaderboard' => $leaderboard,
            'updated_at' => gmdate('c'),
        ]);
        exit;
    }

    /** GET /api/weekly-challenges/overall – Overall leaderboard JSON */
    public function overallLeaderboardJson(): void
    {
        Auth::requireAuth();

        $challenges = new WeeklyChallengesService();
        $leaderboard = $challenges->getOverallLeaderboard(null, 50);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'leaderboard' => $leaderboard,
            'updated_at' => gmdate('c'),
        ]);
        exit;
    }

    /** POST /api/weekly-challenges/archive-week – Cron job: Archive completed week.
     *
     * Authenticates either via X-Cron-Secret (unattended cron) using
     * hash_equals to defeat timing attacks, OR by an admin session
     * (manual run from the dashboard). Both fail closed.
     */
    public function archiveWeek(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(403);
            exit;
        }

        $authorized = false;

        $configured = trim((string)(getenv('NUTMEG_CRON_SECRET') ?: ''));
        $provided   = (string)($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
        if ($configured !== '' && $provided !== '' && hash_equals($configured, $provided)) {
            $authorized = true;
        }

        if (!$authorized && \App\Core\Auth::check() && \App\Core\Auth::isAdmin()) {
            $authorized = true;
        }

        if (!$authorized) {
            http_response_code(403);
            exit;
        }

        try {
            $challenges = new WeeklyChallengesService();
            $lastWeek = date('Y-m-d', strtotime('-7 days'));
            $challenges->archiveWeek($lastWeek);

            echo json_encode(['success' => true, 'week' => $lastWeek]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

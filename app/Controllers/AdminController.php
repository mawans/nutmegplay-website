<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\SupabaseClient;
use App\Services\AccountService;
use App\Services\MatchService;
use App\Services\ClubService;
use App\Services\PlayerProgressService;
use App\Services\MatchStatsService;
use App\Services\AnnouncementService;
use App\Services\NotificationService;
use App\Services\VideoAnalysisService;
use App\Services\B2VideoStorageService;

class AdminController extends Controller
{
    /** Helper to silently send a notification (never throws) */
    private function notify(string $userId, string $type, string $title, string $message, string $icon = 'notifications', string $link = ''): void
    {
        try {
            (new NotificationService())->send($userId, $type, $title, $message, $icon, $link);
        } catch (\Throwable $e) { /* ignore */ }
    }

    private function notifyAll(array $userIds, string $type, string $title, string $message, string $icon = 'notifications', string $link = ''): void
    {
        try {
            (new NotificationService())->sendToMany($userIds, $type, $title, $message, $icon, $link);
        } catch (\Throwable $e) { /* ignore */ }
    }

    /** GET /admin – main admin panel */
    public function index(): void
    {
        Auth::requireAdmin();

        $accounts  = new AccountService();
        $matches   = new MatchService();
        $clubs     = new ClubService();
        $progress  = new PlayerProgressService();
        $announcements = new AnnouncementService();
        $challengeSvc  = new \App\Services\ChallengeTemplateService();

        // Stats
        $allPlayers     = $accounts->list(null, 200);
        $totalPlayers   = count($allPlayers);
        $totalMatches   = $matches->count();
        $totalClubs     = $clubs->count();
        $completedCount = $matches->countCompleted();
        $instructors    = $accounts->listByRole('instructor', 100);
        $admins         = $accounts->listByRole('admin', 100);

        // All matches for management
        $allMatches      = $matches->list(100);
        $pendingMatches  = $matches->listPendingChallenges(50);
        $upcomingMatches = $matches->listUpcoming(50);

        // Challenges
        $allChallenges   = $challengeSvc->list(100);

        $account = Auth::account();
        $error   = Auth::getFlash('error');
        $success = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/admin.php', [
            'account'          => $account,
            'allPlayers'       => $allPlayers,
            'totalPlayers'     => $totalPlayers,
            'totalMatches'     => $totalMatches,
            'totalClubs'       => $totalClubs,
            'completedCount'   => $completedCount,
            'instructors'      => $instructors,
            'admins'           => $admins,
            'allMatches'       => $allMatches,
            'pendingMatches'   => $pendingMatches,
            'upcomingMatches'  => $upcomingMatches,
            'allChallenges'    => $allChallenges,
            'error'            => $error,
            'success'          => $success,
        ]);
    }

    /** POST /admin/user/create – create a new user */
    public function createUser(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $fname    = trim($_POST['fname'] ?? '');
        $lname    = trim($_POST['lname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $country  = trim($_POST['country'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $role     = strtolower(trim($_POST['role'] ?? 'player'));

        if (!in_array($role, ['player', 'instructor', 'admin'], true)) {
            $role = 'player';
        }

        if (!$email || !$password || !$fname) {
            Auth::flash('error', 'Name, email, and password are required.');
            header('Location: /admin');
            exit;
        }

        // Create auth user in Supabase.
        // Prefer Admin API (service role) to avoid signup email rate limits.
        try {
            $sb = SupabaseClient::getInstance();
            $authResult = $sb->hasServiceRoleKey()
                ? $sb->authAdminCreateUser($email, $password, ['fname' => $fname], true)
                : $sb->authSignUp($email, $password, ['fname' => $fname]);
        } catch (\Throwable $e) {
            $this->reportException('admin-create-user', $e);
            Auth::flash('error', 'Authentication service is unavailable right now. Please try again later.');
            header('Location: /admin');
            exit;
        }

        if (!$authResult || !empty($authResult['error'])) {
            $msg = $authResult['message'] ?? 'Registration failed.';
            if (str_contains(strtolower($msg), 'rate limit')) {
                $msg = 'Too many signup emails were requested. Wait a minute and try again, or use local Supabase/service role configuration.';
            }
            Auth::flash('error', $msg);
            header('Location: /admin');
            exit;
        }

        $uid = $authResult['user']['id'] ?? ($authResult['id'] ?? '');

        // Create accounts row
        $accounts = new AccountService();
        $account = $accounts->create([
            'fname'    => $fname,
            'lname'    => $lname,
            'email'    => $email,
            'uid'      => $uid,
            'country'  => $country,
            'position' => $position,
            'role'     => $role,
            'level'    => 1,
        ]);

        // Create initial player progress
        if ($uid) {
            $progress = new PlayerProgressService();
            $progress->create($uid, 50, 0);
        }

        // Notification: user created
        if ($uid) {
            $this->notify($uid, NotificationService::TYPE_USER_CREATED, 'Welcome to FiveStats!', "Your account has been created as {$role}. Welcome aboard!", 'person_add', '/dashboard');
        }

        Auth::flash('success', "User '{$fname}' created successfully as {$role}.");
        header('Location: /admin');
        exit;
    }

    /** POST /admin/user/role – update user role */
    public function updateUserRole(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = strtolower(trim($_POST['role'] ?? 'player'));

        if (!in_array($role, ['player', 'instructor', 'admin'], true)) {
            $role = 'player';
        }

        if (!$userId) {
            Auth::flash('error', 'Invalid user selected.');
            header('Location: /admin');
            exit;
        }

        $accounts = new AccountService();
        $updated = $accounts->update($userId, ['role' => $role]);

        if ($updated) {
            // Notification: role changed
            $targetUid = $updated['uid'] ?? '';
            if ($targetUid) {
                $this->notify($targetUid, NotificationService::TYPE_ROLE_CHANGED, 'Role Updated', "Your role has been changed to {$role}.", 'badge', '/dashboard');
            }
            Auth::flash('success', "User role updated to '{$role}' successfully.");
        } else {
            Auth::flash('error', 'Failed to update user role.');
        }

        header('Location: /admin');
        exit;
    }

    /** POST /admin/user/delete – delete a user */
    public function deleteUser(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $userId = (int)($_POST['user_id'] ?? 0);

        if (!$userId) {
            Auth::flash('error', 'Invalid user selected.');
            header('Location: /admin');
            exit;
        }

        // Prevent self-deletion
        $currentAccount = Auth::account();
        if ((int)($currentAccount['id'] ?? 0) === $userId) {
            Auth::flash('error', 'You cannot delete your own account.');
            header('Location: /admin');
            exit;
        }

        $accounts = new AccountService();
        $deleted = $accounts->delete($userId);

        if ($deleted) {
            Auth::flash('success', 'User deleted successfully.');
        } else {
            Auth::flash('error', 'Failed to delete user.');
        }

        header('Location: /admin');
        exit;
    }

    /** POST /admin/match/approve – approve/decline a match */
    public function approveMatch(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        $action  = strtolower(trim($_POST['action'] ?? ''));

        if (!$matchId || !in_array($action, ['accept', 'decline', 'complete'], true)) {
            Auth::flash('error', 'Invalid match action.');
            header('Location: /admin');
            exit;
        }

        $matches = new MatchService();

        $match = $matches->getById($matchId);

        if ($action === 'accept') {
            $result = $matches->acceptChallenge($matchId);
        } elseif ($action === 'decline') {
            $result = $matches->declineChallenge($matchId);
        } elseif ($action === 'complete') {
            $result = $matches->update($matchId, ['match_status' => 'completed']);
        }

        if (!empty($result)) {
            // Notify both players about match status
            $playerIds = array_filter([
                (string)($match['challenger_id'] ?? ''),
                (string)($match['opponent_id'] ?? ''),
            ]);
            if ($action === 'accept') {
                $this->notifyAll($playerIds, NotificationService::TYPE_MATCH_APPROVED, 'Match Approved', 'Your match has been approved and scheduled.', 'check_circle', '/fixtures');
            } elseif ($action === 'decline') {
                $this->notifyAll($playerIds, NotificationService::TYPE_MATCH_REJECTED, 'Match Declined', 'Your match request has been declined.', 'cancel', '/challenges');
            } elseif ($action === 'complete') {
                $this->notifyAll($playerIds, NotificationService::TYPE_MATCH_COMPLETED, 'Match Completed', 'A match has been marked as completed. Check your stats!', 'emoji_events', '/match-history');
            }
            Auth::flash('success', 'Match ' . $action . 'd successfully.');
        } else {
            Auth::flash('error', 'Failed to update match.');
        }

        header('Location: /admin');
        exit;
    }

    /** POST /admin/match/location – assign location and pitch */
    public function assignLocation(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $matchId  = (int)($_POST['match_id'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $pitch    = trim($_POST['pitch'] ?? '');
        $date     = trim($_POST['date'] ?? '');
        $time     = trim($_POST['time'] ?? '');

        if (!$matchId) {
            Auth::flash('error', 'Invalid match selected.');
            header('Location: /admin');
            exit;
        }

        $data = [];
        if ($location) {
            $fullLocation = $pitch ? "{$location} – Pitch {$pitch}" : $location;
            $data['location'] = $fullLocation;
        }
        if ($date) $data['date'] = $date;
        if ($time) $data['time'] = $time;

        if (empty($data)) {
            Auth::flash('error', 'Please fill in at least one field.');
            header('Location: /admin');
            exit;
        }

        $matches = new MatchService();
        $match = $matches->getById($matchId);
        $result = $matches->update($matchId, $data);

        if ($result) {
            // Notify players about fixture update
            $playerIds = array_filter([
                (string)($match['challenger_id'] ?? ''),
                (string)($match['opponent_id'] ?? ''),
            ]);
            $this->notifyAll($playerIds, NotificationService::TYPE_FIXTURE_SET, 'Fixture Updated', 'Match location/schedule has been updated. Check your fixtures!', 'event', '/fixtures');
            Auth::flash('success', 'Match location/schedule updated successfully.');
        } else {
            Auth::flash('error', 'Failed to update match details.');
        }

        header('Location: /admin');
        exit;
    }

    /** POST /admin/match/video – assign video URL to a match */
    public function assignVideo(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $matchId  = (int)($_POST['match_id'] ?? 0);
        $videoUrl = trim($_POST['video_url'] ?? '');

        if (!$matchId || !$videoUrl) {
            Auth::flash('error', 'Match and video URL are required.');
            header('Location: /admin');
            exit;
        }

        $matches = new MatchService();
        $match = $matches->getById($matchId);
        $canQueueAi = $this->isWebsiteHostedVideoUrl($videoUrl)
            || $this->isAiStoredVideoUrl($videoUrl)
            || $this->isB2StoredVideoUrl($videoUrl);
        $sourceType = $this->adminVideoSourceType($videoUrl);
        $result = $matches->update($matchId, [
            'video_url'    => $videoUrl,
            'video_status' => $canQueueAi ? 'queued' : 'external_url',
        ]);

        if ($result) {
            if ($canQueueAi) {
                $autoProcess = !in_array(strtolower(trim((string)(getenv('NUTMEG_AI_AUTO_PROCESS') ?: '1'))), ['0', 'false', 'no'], true);
                try {
                    $analysis = new VideoAnalysisService();
                    $analysis->queueAnalysis($matchId, $videoUrl, Auth::uid(), $sourceType);
                    if ($autoProcess) {
                        $analysis->processMatchVideo($matchId);
                    }
                } catch (\Throwable $e) {
                    $this->reportException('admin-queue-ai', $e);
                    Auth::flash('error', 'Video was assigned, but AI processing could not be started right now.');
                    header('Location: /admin');
                    exit;
                }
            }

            // Notify players about video
            $playerIds = array_filter([
                (string)($match['challenger_id'] ?? ''),
                (string)($match['opponent_id'] ?? ''),
            ]);
            $this->notifyAll($playerIds, NotificationService::TYPE_VIDEO_UPLOADED, 'Video Available', 'A video has been uploaded for your match. Watch it now!', 'videocam', '/video-upload');
            Auth::flash('success', $canQueueAi
                ? (($autoProcess ?? false)
                    ? 'Video assigned and AI processing completed successfully.'
                    : 'Video assigned and AI processing queued successfully.')
                : 'Video assigned to match successfully. AI processing only runs for videos stored by the AI service.');
        } else {
            Auth::flash('error', 'Failed to assign video.');
        }

        header('Location: /admin');
        exit;
    }

    /** POST /admin/announcement – create announcement */
    public function createAnnouncement(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /admin');
            exit;
        }

        $title   = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$title || !$message) {
            Auth::flash('error', 'Title and message are required.');
            header('Location: /admin');
            exit;
        }

        $sb = SupabaseClient::getInstance();
        $result = $sb->from('announcement')->insert([
            'title'   => $title,
            'message' => $message,
            'user_id' => Auth::uid(),
        ]);

        if ($result && empty($result['error'])) {
            // Notify all users about announcement
            $accounts = new AccountService();
            $allUsers = $accounts->list(null, 500);
            $allUids = array_filter(array_map(fn($u) => (string)($u['uid'] ?? ''), $allUsers));
            $this->notifyAll($allUids, NotificationService::TYPE_ANNOUNCEMENT, $title, $message, 'campaign', '/dashboard');
            Auth::flash('success', 'Announcement published.');
        } else {
            Auth::flash('error', 'Failed to create announcement.');
        }

        header('Location: /admin');
        exit;
    }

    /** POST /admin/challenge/create – create a challenge template */
    public function createChallenge(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request.');
            header('Location: /admin');
            exit;
        }

        $title       = trim($_POST['challenge_title'] ?? '');
        $description = trim($_POST['challenge_description'] ?? '');
        $type        = strtolower(trim($_POST['challenge_type'] ?? 'daily'));
        $xpReward    = max(1, (int)($_POST['xp_reward'] ?? 50));
        $targetValue = max(1, (int)($_POST['target_value'] ?? 1));
        $metric      = strtolower(trim($_POST['metric'] ?? 'goals'));
        $startDate   = trim($_POST['start_date'] ?? '');
        $endDate     = trim($_POST['end_date'] ?? '');

        if (!in_array($type, ['daily', 'weekly', 'monthly'], true)) {
            $type = 'daily';
        }
        if (!in_array($metric, ['goals', 'assists', 'wins', 'matches', 'distance', 'passes', 'dribbles'], true)) {
            $metric = 'goals';
        }

        if (!$title) {
            Auth::flash('error', 'Challenge title is required.');
            header('Location: /admin');
            exit;
        }

        $challenges = new \App\Services\ChallengeTemplateService();
        $data = [
            'title'        => $title,
            'description'  => $description,
            'type'         => $type,
            'xp_reward'    => $xpReward,
            'target_value' => $targetValue,
            'metric'       => $metric,
            'is_active'    => true,
            'created_by'   => Auth::uid(),
        ];
        if ($startDate) $data['start_date'] = $startDate;
        if ($endDate) $data['end_date'] = $endDate;

        $created = $challenges->create($data);

        if ($created) {
            // Notify all players about new challenge
            $accounts = new AccountService();
            $allUsers = $accounts->list(null, 500);
            $allUids = array_filter(array_map(fn($u) => (string)($u['uid'] ?? ''), $allUsers));
            $this->notifyAll($allUids, NotificationService::TYPE_CHALLENGE_NEW, 'New Challenge!', "{$title} — Earn {$xpReward} XP!", 'emoji_events', '/challenges');
            Auth::flash('success', "Challenge '{$title}' created successfully.");
        } else {
            Auth::flash('error', 'Failed to create challenge.');
        }

        header('Location: /admin');
        exit;
    }

    private function isAiStoredVideoUrl(string $videoUrl): bool
    {
        $base = trim((string)(getenv('NUTMEG_AI_FASTAPI_URL') ?: ''));
        if ($base === '') {
            return false;
        }

        $normalizedBase = preg_replace('#/(analyze|analyze-url|analyze-upload|upload-video|analyze-stored|health)/?$#', '', rtrim($base, '/'));
        $normalizedBase = is_string($normalizedBase) ? rtrim($normalizedBase, '/') : rtrim($base, '/');
        $normalizedUrl = rtrim(trim($videoUrl), '/');

        return $normalizedUrl !== '' && str_starts_with($normalizedUrl, $normalizedBase . '/videos/');
    }

    private function adminVideoSourceType(string $videoUrl): string
    {
        if ($this->isB2StoredVideoUrl($videoUrl)) {
            return 'b2_storage';
        }

        if ($this->isWebsiteHostedVideoUrl($videoUrl)) {
            return 'local_upload';
        }

        if ($this->isAiStoredVideoUrl($videoUrl)) {
            return 'remote_upload';
        }

        return 'external_url';
    }

    private function isB2StoredVideoUrl(string $videoUrl): bool
    {
        return B2VideoStorageService::isConfigured()
            && (new B2VideoStorageService())->isManagedUrl($videoUrl);
    }

    private function isWebsiteHostedVideoUrl(string $videoUrl): bool
    {
        $normalizedUrl = rtrim(trim($videoUrl), '/');
        if ($normalizedUrl === '') {
            return false;
        }

        if (str_starts_with($normalizedUrl, '/videos/') || str_starts_with($normalizedUrl, '/public/videos/')) {
            return true;
        }

        $websiteBase = trim((string)(getenv('NUTMEG_WEBSITE_URL') ?: ''));
        if ($websiteBase === '') {
            return false;
        }

        $normalizedBase = rtrim($websiteBase, '/');
        return str_starts_with($normalizedUrl, $normalizedBase . '/videos/')
            || str_starts_with($normalizedUrl, $normalizedBase . '/public/videos/');
    }

    /** POST /admin/challenge/delete – delete a challenge */
    public function deleteChallenge(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request.');
            header('Location: /admin');
            exit;
        }

        $id = (int)($_POST['challenge_id'] ?? 0);
        if (!$id) {
            Auth::flash('error', 'Invalid challenge selected.');
            header('Location: /admin');
            exit;
        }

        $challenges = new \App\Services\ChallengeTemplateService();
        $deleted = $challenges->delete($id);

        Auth::flash($deleted ? 'success' : 'error', $deleted ? 'Challenge deleted.' : 'Failed to delete challenge.');
        header('Location: /admin');
        exit;
    }

    /** POST /admin/challenge/toggle – toggle active state */
    public function toggleChallenge(): void
    {
        Auth::requireAdmin();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request.');
            header('Location: /admin');
            exit;
        }

        $id     = (int)($_POST['challenge_id'] ?? 0);
        $active = ($_POST['is_active'] ?? '1') === '1';

        if (!$id) {
            Auth::flash('error', 'Invalid challenge selected.');
            header('Location: /admin');
            exit;
        }

        $challenges = new \App\Services\ChallengeTemplateService();
        $result = $challenges->toggleActive($id, $active);

        Auth::flash($result ? 'success' : 'error', $result ? 'Challenge status updated.' : 'Failed to update challenge.');
        header('Location: /admin');
        exit;
    }
}

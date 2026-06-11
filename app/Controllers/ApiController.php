<?php
namespace App\Controllers;

use App\Core\ApiAuth;
use App\Core\SupabaseClient;
use App\Services\AccountService;
use App\Services\MatchService;
use App\Services\MatchJerseySlotService;
use App\Services\MatchJerseyStatService;
use App\Services\MatchVideoAnalysisService;
use App\Services\MatchStatsService;
use App\Services\ClubService;
use App\Services\PlayerProgressService;
use App\Services\XpHistoryService;
use App\Services\NotificationService;
use App\Services\AnnouncementService;
use App\Services\ChallengeTemplateService;
use App\Services\InviteService;
use App\Services\VideoAnalysisService;

/**
 * REST API Controller for the React Native mobile app.
 *
 * All methods return JSON. Authentication via Supabase JWT Bearer token.
 */
class ApiController
{
    private function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        echo json_encode($data);
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private function accountLabel(array $account): string
    {
        $name = trim((string)($account['fname'] ?? '') . ' ' . (string)($account['lname'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string)($account['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        $email = trim((string)($account['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        return 'Player';
    }

    private function clubForAccount(array $account, ClubService $clubSvc): ?array
    {
        $clubAssign = trim((string)($account['club_assign'] ?? ''));
        if ($clubAssign !== '' && ctype_digit($clubAssign)) {
            return $clubSvc->getById((int)$clubAssign);
        }

        return null;
    }

    private function resolveParticipant(string $rawId, AccountService $accountSvc, ClubService $clubSvc): ?array
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return null;
        }

        $account = $accountSvc->getByUid($rawId);
        if ($account) {
            $club = $this->clubForAccount($account, $clubSvc);
            return [
                'uid' => (string)($account['uid'] ?? ''),
                'account' => $account,
                'club' => $club,
                'label' => $club['name'] ?? $this->accountLabel($account),
            ];
        }

        if (ctype_digit($rawId)) {
            $club = $clubSvc->getById((int)$rawId);
            if ($club && !empty($club['owner'])) {
                $ownerUid = (string)$club['owner'];
                $ownerAccount = $accountSvc->getByUid($ownerUid) ?? ['uid' => $ownerUid];
                return [
                    'uid' => $ownerUid,
                    'account' => $ownerAccount,
                    'club' => $club,
                    'label' => $club['name'] ?? $this->accountLabel($ownerAccount),
                ];
            }
        }

        return null;
    }

    private function userParticipatesInMatch(array $match, string $uid): bool
    {
        $participantIds = [
            (string)($match['challenger_id'] ?? ''),
            (string)($match['opponent_id'] ?? ''),
        ];
        if (in_array($uid, $participantIds, true)) {
            return true;
        }

        $clubAssign = trim((string)(ApiAuth::account()['club_assign'] ?? ''));
        return $clubAssign !== '' && in_array($clubAssign, $participantIds, true);
    }

    private function canAcceptMatch(array $match): bool
    {
        if (ApiAuth::isAdmin()) {
            return true;
        }

        $uid = ApiAuth::uid();
        return $uid !== '' && $uid === (string)($match['opponent_id'] ?? '');
    }

    private function canDeclineMatch(array $match): bool
    {
        if (ApiAuth::isAdmin()) {
            return true;
        }

        $uid = ApiAuth::uid();
        return $uid !== '' && in_array($uid, [
            (string)($match['challenger_id'] ?? ''),
            (string)($match['opponent_id'] ?? ''),
        ], true);
    }

    private function enrichMatch(array $match, AccountService $accountSvc, ClubService $clubSvc): array
    {
        foreach (['challenger', 'opponent'] as $side) {
            $key = $side . '_id';
            $rawId = trim((string)($match[$key] ?? ''));
            if ($rawId === '') {
                continue;
            }

            $participant = $this->resolveParticipant($rawId, $accountSvc, $clubSvc);
            if ($participant !== null) {
                $match[$side . '_club'] = $participant['club'];
                $match[$side . '_account'] = $participant['account'];
            }
        }

        return $match;
    }

    /* ================================================================== */
    /*  AUTH                                                               */
    /* ================================================================== */

    /** POST /api/auth/login — Authenticate and return token + account */
    public function login(): void
    {
        $input = $this->input();
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            $this->json(['error' => true, 'message' => 'Email and password required'], 400);
            return;
        }

        $sb = SupabaseClient::getInstance();
        $auth = $sb->authSignIn($email, $password);

        if (!$auth || !empty($auth['error']) || empty($auth['access_token'])) {
            $msg = $auth['message'] ?? 'Invalid credentials';
            $this->json(['error' => true, 'message' => $msg], 401);
            return;
        }

        // Get or create account row
        $accountSvc = new AccountService();
        $account = $accountSvc->getByUid($auth['user']['id'] ?? '');

        if (!$account && !empty($auth['user'])) {
            $user = $auth['user'];
            $accountSvc->create([
                'uid'   => $user['id'],
                'email' => $user['email'] ?? $email,
                'fname' => $user['user_metadata']['fname'] ?? explode('@', $email)[0],
                'role'  => 'player',
            ]);
            $account = $accountSvc->getByUid($user['id']);

            // Create initial player progress
            $ppSvc = new PlayerProgressService();
            $ppSvc->create($user['id'], 50, 0);
        }

        $this->json([
            'access_token'  => $auth['access_token'],
            'refresh_token' => $auth['refresh_token'] ?? '',
            'user'          => $auth['user'] ?? [],
            'account'       => $account,
        ]);
    }

    /** POST /api/auth/register — Create account */
    public function register(): void
    {
        $input = $this->input();
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $fname    = trim($input['fname'] ?? '');
        $country  = trim($input['country'] ?? '');
        $position = trim($input['position'] ?? '');

        if (!$email || !$password) {
            $this->json(['error' => true, 'message' => 'Email and password required'], 400);
            return;
        }

        $sb = SupabaseClient::getInstance();
        $auth = $sb->authSignUp($email, $password, ['fname' => $fname]);

        if (!$auth || !empty($auth['error'])) {
            $msg = $auth['message'] ?? 'Registration failed';
            $this->json(['error' => true, 'message' => $msg], 400);
            return;
        }

        $uid = $auth['id'] ?? $auth['user']['id'] ?? '';
        if ($uid) {
            $accountSvc = new AccountService();
            $accountSvc->create([
                'uid'      => $uid,
                'email'    => $email,
                'fname'    => $fname ?: explode('@', $email)[0],
                'country'  => $country,
                'position' => $position,
                'role'     => 'player',
            ]);

            $ppSvc = new PlayerProgressService();
            $ppSvc->create($uid, 50, 0);
        }

        $this->json([
            'message' => 'Account created successfully',
            'user'    => $auth['user'] ?? $auth,
        ]);
    }

    /* ================================================================== */
    /*  DASHBOARD                                                          */
    /* ================================================================== */

    /** GET /api/dashboard — Dashboard data (stats, upcoming, announcements) */
    public function dashboard(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();

        $accountSvc  = new AccountService();
        $matchSvc    = new MatchService();
        $clubSvc     = new ClubService();
        $ppSvc       = new PlayerProgressService();
        $statsSvc    = new MatchStatsService();
        $announceSvc = new AnnouncementService();
        $challengeSvc = new ChallengeTemplateService();

        $progress   = $ppSvc->getByUser($uid);
        $upcoming   = $matchSvc->listUpcomingByUser($uid, 5);
        $completed  = $matchSvc->listCompletedByUser($uid, 5);
        $totals     = $statsSvc->getUserTotals($uid);
        $announcements = $announceSvc->list(5);
        $challenges = $challengeSvc->listActive(5);

        // Get club info if assigned
        $club = null;
        if (!empty($account['club_assign'])) {
            $club = $clubSvc->getById($account['club_assign']);
        }

        $this->json([
            'account'       => $account,
            'progress'      => $progress,
            'club'          => $club,
            'upcoming'      => $upcoming,
            'completed'     => $completed,
            'stats'         => $totals,
            'announcements' => $announcements,
            'challenges'    => $challenges,
            'counts'        => [
                'players'  => $accountSvc->count(),
                'matches'  => $matchSvc->count(),
                'clubs'    => $clubSvc->count(),
            ],
        ]);
    }

    /* ================================================================== */
    /*  PROFILE                                                            */
    /* ================================================================== */

    /** GET /api/profile — Current user profile with stats */
    public function profile(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();

        $ppSvc    = new PlayerProgressService();
        $statsSvc = new MatchStatsService();
        $xpSvc    = new XpHistoryService();

        $progress = $ppSvc->getByUser($uid);
        $totals   = $statsSvc->getUserTotals($uid);
        $recentStats = $statsSvc->getByUser($uid, 10);
        $xpHistory = $xpSvc->getByUser($uid, 20);

        $this->json([
            'account'      => $account,
            'progress'     => $progress,
            'stats'        => $totals,
            'recent_stats' => $recentStats,
            'xp_history'   => $xpHistory,
        ]);
    }

    /** POST /api/profile — Update profile */
    public function updateProfile(): void
    {
        $account = ApiAuth::require();
        $input = $this->input();

        $allowed = ['fname', 'lname', 'username', 'country', 'position', 'image_url'];
        $data = array_intersect_key($input, array_flip($allowed));

        if (empty($data)) {
            $this->json(['error' => true, 'message' => 'No valid fields to update'], 400);
            return;
        }

        $accountSvc = new AccountService();
        $updated = $accountSvc->updateByUid(ApiAuth::uid(), $data);

        if ($updated) {
            $this->json(['message' => 'Profile updated', 'account' => $updated]);
        } else {
            $this->json(['error' => true, 'message' => 'Update failed'], 500);
        }
    }

    /* ================================================================== */
    /*  PLAYER STATS (real data — replaces hardcoded stats)                */
    /* ================================================================== */

    /** GET /api/player/stats — Full player stats for the playercard screen */
    public function playerStats(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();

        $ppSvc    = new PlayerProgressService();
        $statsSvc = new MatchStatsService();

        $progress = $ppSvc->getByUser($uid);
        $totals   = $statsSvc->getUserTotals($uid);
        $recent   = $statsSvc->getByUser($uid, 20);

        // Calculate 6-stat breakdown for FIFA-style card
        $sixStats = $this->calculateSixStats($totals);

        $this->json([
            'account'    => $account,
            'progress'   => $progress,
            'totals'     => $totals,
            'recent'     => $recent,
            'six_stats'  => $sixStats,
        ]);
    }

    /** Calculate PAC/SHO/PAS/DRI/DEF/PHY from match stats */
    private function calculateSixStats(array $totals): array
    {
        $matches = max(1, $totals['matches'] ?? 1);

        // Pace — based on sprints & distance per match
        $sprintsPerMatch = ($totals['sprints'] ?? 0) / $matches;
        $distPerMatch = ($totals['distance_km'] ?? 0) / $matches;
        $pac = min(99, max(40, (int)(50 + $sprintsPerMatch * 3 + $distPerMatch * 2)));

        // Shooting — goals per match ratio
        $goalsPerMatch = ($totals['goals'] ?? 0) / $matches;
        $sho = min(99, max(40, (int)(50 + $goalsPerMatch * 15)));

        // Passing — pass accuracy
        $passAcc = $totals['pass_accuracy'] ?? 0;
        $pas = min(99, max(40, (int)(40 + $passAcc * 0.6)));

        // Dribbling — dribble accuracy
        $driAcc = $totals['dribble_accuracy'] ?? 0;
        $dri = min(99, max(40, (int)(40 + $driAcc * 0.6)));

        // Defense — interceptions & duels per match
        $intPerMatch = ($totals['interceptions'] ?? 0) / $matches;
        $duelsPerMatch = ($totals['duels_won'] ?? 0) / $matches;
        $def = min(99, max(40, (int)(45 + $intPerMatch * 5 + $duelsPerMatch * 3)));

        // Physical — minutes played, distance, duels
        $minsPerMatch = ($totals['minutes_played'] ?? 0) / $matches;
        $phy = min(99, max(40, (int)(45 + $minsPerMatch * 0.3 + $distPerMatch * 3)));

        return [
            'pac' => $pac,
            'sho' => $sho,
            'pas' => $pas,
            'dri' => $dri,
            'def' => $def,
            'phy' => $phy,
        ];
    }

    /* ================================================================== */
    /*  MATCHES                                                            */
    /* ================================================================== */

    /** GET /api/matches — List matches for user */
    public function matches(): void
    {
        ApiAuth::require();
        $uid = ApiAuth::uid();

        $matchSvc = new MatchService();
        $upcoming  = $matchSvc->listUpcomingByUser($uid, 20);
        $completed = $matchSvc->listCompletedByUser($uid, 20);

        $accountSvc = new AccountService();
        $clubSvc = new ClubService();
        $enrichMatch = function (array $match) use ($accountSvc, $clubSvc): array {
            return $this->enrichMatch($match, $accountSvc, $clubSvc);
        };

        $this->json([
            'upcoming'  => array_map($enrichMatch, $upcoming),
            'completed' => array_map($enrichMatch, $completed),
        ]);
    }

    /** GET /api/matches/pending — Pending challenges for user */
    public function matchesPending(): void
    {
        ApiAuth::require();
        $uid = ApiAuth::uid();

        $matchSvc = new MatchService();
        $pending = ApiAuth::isInstructor()
            ? $matchSvc->listPendingChallenges()
            : $matchSvc->listPendingForUser($uid);

        $this->json(['pending' => $pending]);
    }

    /** POST /api/matches/create — Create a match challenge */
    public function createMatch(): void
    {
        ApiAuth::requirePermission('matchmaking.manage', 'Instructor permissions are required.');

        $input = $this->input();

        $required = ['challenger_id', 'opponent_id'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $this->json(['error' => true, 'message' => "Field '$field' is required"], 400);
                return;
            }
        }

        $matchSvc = new MatchService();
        $accountSvc = new AccountService();
        $clubSvc = new ClubService();
        $challenger = $this->resolveParticipant((string)$input['challenger_id'], $accountSvc, $clubSvc);
        $opponent   = $this->resolveParticipant((string)$input['opponent_id'], $accountSvc, $clubSvc);
        if ($challenger === null || $opponent === null || $challenger['uid'] === '' || $opponent['uid'] === '') {
            $this->json(['error' => true, 'message' => 'Both match participants must resolve to valid accounts.'], 400);
            return;
        }
        if ($challenger['uid'] === $opponent['uid']) {
            $this->json(['error' => true, 'message' => 'Challenger and opponent must be different.'], 400);
            return;
        }

        $data = [
            'challanger'       => $challenger['label'],
            'opponent'         => $opponent['label'],
            'challenger_id'    => $challenger['uid'],
            'opponent_id'      => $opponent['uid'],
            'challange_status' => 'pending',
            'match_status'     => 'pending',
            'location'         => $input['location'] ?? '',
            'loc_codrinates'   => $input['loc_codrinates'] ?? '',
            'date'             => $input['date'] ?? '',
            'time'             => $input['time'] ?? '',
        ];

        $match = $matchSvc->create($data);
        if ($match) {
            $matchId = (int)($match['id'] ?? 0);
            if ($matchId > 0) {
                (new MatchJerseySlotService())->ensureDefaults($matchId);
            }
            $notifSvc = new NotificationService();
            $notifSvc->send(
                $opponent['uid'],
                NotificationService::TYPE_MATCH_CREATED,
                'New Match Challenge',
                $challenger['label'] . ' has challenged you to a match!',
                'sports_soccer',
                '/challenges'
            );
            $notifSvc->send(
                $challenger['uid'],
                NotificationService::TYPE_MATCH_CREATED,
                'Challenge Sent',
                'Your match challenge to ' . $opponent['label'] . ' has been sent.',
                'sports_soccer',
                '/matchmaking'
            );
            $this->json(['message' => 'Match created', 'match' => $match]);
        } else {
            $this->json(['error' => true, 'message' => 'Failed to create match'], 500);
        }
    }

    /** POST /api/matches/respond — Accept or decline a match */
    public function respondMatch(): void
    {
        ApiAuth::require();

        $input = $this->input();

        $matchId = $input['match_id'] ?? '';
        $action  = $input['action'] ?? ''; // accept or decline

        if (!$matchId || !in_array($action, ['accept', 'decline'])) {
            $this->json(['error' => true, 'message' => 'match_id and action (accept/decline) required'], 400);
            return;
        }

        $matchSvc = new MatchService();
        $match = $matchSvc->getById((int)$matchId);
        if (!$match) {
            $this->json(['error' => true, 'message' => 'Match not found'], 404);
            return;
        }

        if (strtolower((string)($match['challange_status'] ?? 'pending')) !== 'pending') {
            $this->json(['error' => true, 'message' => 'This match challenge has already been processed.'], 409);
            return;
        }

        if ($action === 'accept' && !$this->canAcceptMatch($match)) {
            $this->json(['error' => true, 'message' => 'Only the challenged player or an admin can accept this match.'], 403);
            return;
        }

        if ($action === 'decline' && !$this->canDeclineMatch($match)) {
            $this->json(['error' => true, 'message' => 'Only the match participants or an admin can decline this match.'], 403);
            return;
        }

        if ($action === 'accept') {
            $result = $matchSvc->acceptChallenge($matchId);
        } else {
            $result = $matchSvc->declineChallenge($matchId);
        }

        if ($result) {
            $this->json(['message' => "Match {$action}ed", 'match' => $result]);
        } else {
            $this->json(['error' => true, 'message' => 'Failed to update match'], 500);
        }
    }

    /** POST /api/matches/stats — Record match stats (after match completion).
     *
     * Restricted to instructors / admins ('matchmaking.manage'). Without
     * this gate any player could POST inflated goal / assist counts for
     * any match they participated in and the XP calculator would happily
     * reward them. The supported, trustworthy stat path is the AI video
     * analysis; this manual endpoint is for staff back-filling or
     * correcting stats. The caller specifies which player the stats are
     * for via input.user_id (defaulting to themselves only as a
     * convenience).
     */
    public function recordMatchStats(): void
    {
        ApiAuth::requirePermission('matchmaking.manage');
        $input = $this->input();
        $uid = trim((string)($input['user_id'] ?? ApiAuth::uid()));

        $matchId = $input['match_id'] ?? '';
        if (!$matchId) {
            $this->json(['error' => true, 'message' => 'match_id required'], 400);
            return;
        }

        $matchSvc = new MatchService();
        $match = $matchSvc->getById((int)$matchId);
        if (!$match) {
            $this->json(['error' => true, 'message' => 'Match not found'], 404);
            return;
        }

        if (!$this->userParticipatesInMatch($match, $uid)) {
            $this->json(['error' => true, 'message' => 'That user did not participate in this match.'], 403);
            return;
        }

        $statsSvc = new MatchStatsService();
        $ppSvc    = new PlayerProgressService();
        $xpSvc    = new XpHistoryService();

        $statsData = [
            'match_id'           => $matchId,
            'user_id'            => $uid,
            'goals'              => (int)($input['goals'] ?? 0),
            'assists'            => (int)($input['assists'] ?? 0),
            'distance_meters'    => (int)($input['distance_meters'] ?? 0),
            'sprints'            => (int)($input['sprints'] ?? 0),
            'successful_passes'  => (int)($input['successful_passes'] ?? 0),
            'passes_attempted'   => (int)($input['passes_attempted'] ?? 0),
            'successful_dribbles'=> (int)($input['successful_dribbles'] ?? 0),
            'dribbles_attempted' => (int)($input['dribbles_attempted'] ?? 0),
            'interceptions'      => (int)($input['interceptions'] ?? 0),
            'duels_won'          => (int)($input['duels_won'] ?? 0),
            'minutes_played'     => (int)($input['minutes_played'] ?? 0),
            'clean_sheet'        => (bool)($input['clean_sheet'] ?? false),
            'match_winning_goal' => (bool)($input['match_winning_goal'] ?? false),
            'result'             => $input['result'] ?? 'draw',
        ];

        $created = $statsSvc->upsertByMatchAndUser((string)$matchId, $uid, $statsData);
        if (!$created) {
            $this->json(['error' => true, 'message' => 'Failed to record stats'], 500);
            return;
        }

        $xpEntry = $xpSvc->getByMatchAndUser((string)$matchId, $uid);
        $xpAwarded = $xpEntry === null;
        $xpGained = 0;
        if ($xpAwarded) {
            $xpGained = $this->calculateXp($statsData);
        }

        if ($xpAwarded && $xpGained > 0) {
            $ppSvc->addXp($uid, $xpGained);
            $xpSvc->log($uid, $matchId, $xpGained, 'Match performance');
        }

        $this->json([
            'message'   => 'Stats recorded',
            'stats'     => $created,
            'xp_gained' => $xpGained,
            'xp_awarded' => $xpAwarded && $xpGained > 0,
            'progress'  => $ppSvc->getByUser($uid),
        ]);
    }

    /** POST /api/video-analysis/request — Queue a Drive video for AI analysis. */
    public function requestVideoAnalysis(): void
    {
        $account = ApiAuth::require();
        $input = $this->input();

        $matchId = (int)($input['match_id'] ?? 0);
        $driveFileId = trim((string)($input['drive_file_id'] ?? ''));
        $teamColor = strtolower(trim((string)($input['team_color'] ?? '')));
        $jerseyNumber = (int)($input['jersey_number'] ?? 0);

        if ($matchId <= 0 || $driveFileId === '' || !in_array($teamColor, ['blue', 'red'], true)) {
            $this->json(['error' => true, 'message' => 'Match, Drive video, and team color are required.'], 400);
            return;
        }

        $validNumbers = $teamColor === 'blue' ? [1, 2, 3, 4, 5] : [6, 7, 8, 9, 10];
        if (!in_array($jerseyNumber, $validNumbers, true)) {
            $this->json(['error' => true, 'message' => 'Choose a valid jersey number for the selected team.'], 400);
            return;
        }

        $matchService = new MatchService();
        $match = $matchService->getById($matchId);
        if (!$match) {
            $this->json(['error' => true, 'message' => 'Match not found.'], 404);
            return;
        }

        $uid = ApiAuth::uid();
        if (!ApiAuth::isInstructor() && !$this->userParticipatesInMatch($match, $uid)) {
            $this->json(['error' => true, 'message' => 'You can only analyze videos for your matches.'], 403);
            return;
        }

        $analysisRows = new MatchVideoAnalysisService();
        $existingAnalysis = $analysisRows->getByMatchId($matchId);
        $existingStatus = strtolower(trim((string)($existingAnalysis['processing_status'] ?? '')));
        if (in_array($existingStatus, ['queued', 'processing'], true)) {
            $this->ensureAiWorkerStarting();
            $kicked = false;
            try {
                $kicked = (new VideoAnalysisService())->kickBackgroundProcessing($matchId);
            } catch (\Throwable $e) {
                error_log('[api-video-analysis-rekick] ' . $e->getMessage());
            }
            $this->json([
                'message' => 'AI analysis is already running for this match.',
                'match_id' => $matchId,
                'status' => $existingStatus,
                'kicked' => $kicked,
            ]);
            return;
        }

        $playerName = $this->accountLabel($account);
        $slotService = new MatchJerseySlotService();
        $slots = $slotService->ensureDefaults($matchId);
        $assignments = [];
        foreach ($slots as $slot) {
            $number = (int)($slot['jersey_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }
            $assignments[$number] = [
                'player_uid' => $slot['player_uid'] ?? null,
                'player_name' => $slot['player_name'] ?? null,
            ];
        }
        $assignments[$jerseyNumber] = [
            'player_uid' => $uid,
            'player_name' => $playerName,
        ];
        $slotService->replaceAssignments($matchId, $assignments);

        $downloadUrl = 'https://drive.usercontent.google.com/download?' . http_build_query([
            'id' => $driveFileId,
            'export' => 'download',
        ]);

        try {
            $analysis = new VideoAnalysisService();
            $analysis->queueAnalysis($matchId, $downloadUrl, $uid, 'external_url');
            $this->ensureAiWorkerStarting();
            $started = $analysis->kickBackgroundProcessing($matchId);

            $this->json([
                'message' => $started
                    ? 'AI analysis started.'
                    : 'AI analysis queued and will start shortly.',
                'match_id' => $matchId,
                'status' => 'queued',
                'jersey_number' => $jerseyNumber,
                'team_color' => $teamColor,
            ], 202);
        } catch (\Throwable $e) {
            error_log('[api-video-analysis-request] ' . $e->getMessage());
            $this->json(['error' => true, 'message' => 'The AI analysis could not be queued right now.'], 500);
        }
    }

    /** GET /api/video-analysis/status?match_id=&jersey_number= */
    public function videoAnalysisStatus(): void
    {
        ApiAuth::require();
        $matchId = (int)($_GET['match_id'] ?? 0);
        $jerseyNumber = (int)($_GET['jersey_number'] ?? 0);

        if ($matchId <= 0) {
            $this->json(['error' => true, 'message' => 'match_id is required.'], 400);
            return;
        }

        $match = (new MatchService())->getById($matchId);
        if (!$match) {
            $this->json(['error' => true, 'message' => 'Match not found.'], 404);
            return;
        }

        if (!ApiAuth::isInstructor() && !$this->userParticipatesInMatch($match, ApiAuth::uid())) {
            $this->json(['error' => true, 'message' => 'Forbidden.'], 403);
            return;
        }

        $progress = (new VideoAnalysisService())->getLiveProgress($matchId);
        $stats = $jerseyNumber > 0
            ? (new MatchJerseyStatService())->getByMatchAndNumber($matchId, $jerseyNumber)
            : null;

        $this->json([
            'progress' => $progress,
            'stats' => $stats,
        ]);
    }

    /** POST /api/video-analysis/kick — Nudge an already queued analysis. */
    public function kickVideoAnalysis(): void
    {
        ApiAuth::require();
        $input = $this->input();
        $matchId = (int)($input['match_id'] ?? 0);

        if ($matchId <= 0) {
            $this->json(['error' => true, 'message' => 'match_id is required.'], 400);
            return;
        }

        $match = (new MatchService())->getById($matchId);
        if (!$match) {
            $this->json(['error' => true, 'message' => 'Match not found.'], 404);
            return;
        }

        if (!ApiAuth::isInstructor() && !$this->userParticipatesInMatch($match, ApiAuth::uid())) {
            $this->json(['error' => true, 'message' => 'Forbidden.'], 403);
            return;
        }

        $analysis = (new MatchVideoAnalysisService())->getByMatchId($matchId);
        $status = strtolower(trim((string)($analysis['processing_status'] ?? '')));
        if (!in_array($status, ['queued', 'processing'], true)) {
            $this->json(['message' => 'No active analysis needs starting.', 'kicked' => false, 'status' => $status]);
            return;
        }

        try {
            $this->ensureAiWorkerStarting();
            $kicked = (new VideoAnalysisService())->kickBackgroundProcessing($matchId);
            $this->json([
                'message' => $kicked ? 'AI analysis worker started.' : 'AI analysis remains queued.',
                'kicked' => $kicked,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            error_log('[api-video-analysis-kick] ' . $e->getMessage());
            $this->json(['error' => true, 'message' => 'The AI worker could not be started right now.'], 500);
        }
    }

    /** POST /api/video-analysis/stop — Stop the requesting user's active analysis. */
    public function stopVideoAnalysis(): void
    {
        ApiAuth::require();
        $input = $this->input();
        $matchId = (int)($input['match_id'] ?? 0);

        if ($matchId <= 0) {
            $this->json(['error' => true, 'message' => 'match_id is required.'], 400);
            return;
        }

        $analysisService = new MatchVideoAnalysisService();
        $analysis = $analysisService->getByMatchId($matchId);
        if (!$analysis) {
            $this->json(['error' => true, 'message' => 'No AI job exists for this video.'], 404);
            return;
        }

        if (!ApiAuth::isInstructor() && (string)($analysis['uploaded_by'] ?? '') !== ApiAuth::uid()) {
            $this->json(['error' => true, 'message' => 'Only the user who requested this analysis can stop it.'], 403);
            return;
        }

        $status = strtolower(trim((string)($analysis['processing_status'] ?? '')));
        if (!in_array($status, ['queued', 'processing'], true)) {
            $this->json(['message' => 'No active analysis needs stopping.', 'stopped' => false, 'status' => $status]);
            return;
        }

        $message = 'AI processing was stopped manually.';
        $analysisService->updateByMatchId($matchId, [
            'processing_status' => 'failed',
            'error_message' => $message,
            'updated_at' => gmdate('c'),
        ]);
        (new MatchService())->update($matchId, ['video_status' => 'failed']);

        $this->json(['message' => $message, 'stopped' => true, 'status' => 'failed']);
    }

    private function ensureAiWorkerStarting(): void
    {
        try {
            $worker = new \App\Services\RunpodPodService();
            if (!$worker->isConfigured()) {
                return;
            }
            $status = $worker->getStatus(true);
            if (in_array(strtolower((string)($status['state'] ?? '')), ['stopped', 'unconfigured'], true)) {
                $worker->startPod(true);
            }
        } catch (\Throwable $e) {
            error_log('[api-video-analysis-worker-start] ' . $e->getMessage());
        }
    }

    /** Calculate XP from match stats */
    private function calculateXp(array $stats): int
    {
        $xp = 25; // Base XP for playing

        $xp += ($stats['goals'] ?? 0) * 20;
        $xp += ($stats['assists'] ?? 0) * 10;
        $xp += ($stats['successful_passes'] ?? 0) * 1;
        $xp += ($stats['successful_dribbles'] ?? 0) * 3;
        $xp += ($stats['interceptions'] ?? 0) * 5;
        $xp += ($stats['duels_won'] ?? 0) * 3;

        if ($stats['clean_sheet'] ?? false) $xp += 15;
        if ($stats['match_winning_goal'] ?? false) $xp += 25;

        $result = strtolower($stats['result'] ?? '');
        if ($result === 'win') $xp += 30;
        elseif ($result === 'draw') $xp += 10;

        return $xp;
    }

    /** GET /api/matches/history — Match history with stats */
    public function matchHistory(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();

        $statsSvc = new MatchStatsService();
        $matchSvc = new MatchService();

        $totals = $statsSvc->getUserTotals($uid);
        $recent = $statsSvc->getByUser($uid, 50);
        $matches = $matchSvc->listByUser($uid, 50);

        $this->json([
            'totals'  => $totals,
            'recent'  => $recent,
            'matches' => $matches,
        ]);
    }

    /* ================================================================== */
    /*  CLUBS / TEAMS                                                      */
    /* ================================================================== */

    /** GET /api/clubs — List all clubs */
    public function clubs(): void
    {
        ApiAuth::require();

        $clubSvc = new ClubService();
        $clubs = $clubSvc->list(100);

        $this->json(['clubs' => $clubs]);
    }

    /** GET /api/clubs/my — Current user's club */
    public function myClub(): void
    {
        $account = ApiAuth::require();

        if (empty($account['club_assign'])) {
            $this->json(['club' => null, 'members' => []]);
            return;
        }

        $clubSvc    = new ClubService();
        $accountSvc = new AccountService();

        $club   = $clubSvc->getById($account['club_assign']);
        $members = $accountSvc->list($account['club_assign']);

        $this->json([
            'club'    => $club,
            'members' => $members,
        ]);
    }

    /** POST /api/clubs/create — Create a club */
    public function createClub(): void
    {
        $account = ApiAuth::requirePermission('teams.manage', 'Instructor permissions are required.');
        $input = $this->input();

        $name = trim($input['name'] ?? '');
        if (!$name) {
            $this->json(['error' => true, 'message' => 'Club name required'], 400);
            return;
        }

        $clubSvc = new ClubService();
        $ownerUid = ApiAuth::uid();
        if ($ownerUid !== '' && $clubSvc->getByOwner($ownerUid)) {
            $this->json(['error' => true, 'message' => 'You already own a club.'], 409);
            return;
        }

        $club = $clubSvc->create([
            'name'          => $name,
            'owner'         => $ownerUid,
            'logo'          => $input['logo'] ?? '',
            'level'         => 1,
            'ranking'       => 0,
            'number_player' => 1,
            'team_active'   => true,
        ]);

        if ($club) {
            // Update user's club_assign
            $accountSvc = new AccountService();
            $accountSvc->updateByUid(ApiAuth::uid(), [
                'club_assign' => $club['id'] ?? ($club[0]['id'] ?? ''),
            ]);

            $this->json(['message' => 'Club created', 'club' => $club]);
        } else {
            $this->json(['error' => true, 'message' => 'Failed to create club'], 500);
        }
    }

    /* ================================================================== */
    /*  INVITES                                                            */
    /* ================================================================== */

    /** GET /api/invites — List invites for current user */
    public function invites(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();

        $invSvc = new InviteService();
        $received = $invSvc->listForUser($uid);
        $sent     = $invSvc->listByUser($uid);

        $this->json([
            'received' => $received,
            'sent'     => $sent,
        ]);
    }

    /** POST /api/invites/send — Send a club invite */
    public function sendInvite(): void
    {
        $account = ApiAuth::requirePermission('players.invite', 'Instructor permissions are required.');
        $input = $this->input();

        $sentTo = trim((string)($input['sent_to'] ?? ''));
        if (!$sentTo) {
            $this->json(['error' => true, 'message' => 'sent_to required'], 400);
            return;
        }

        if ($sentTo === ApiAuth::uid()) {
            $this->json(['error' => true, 'message' => 'You cannot invite yourself.'], 400);
            return;
        }

        $invSvc = new InviteService();
        $clubSvc = new ClubService();
        $accountSvc = new AccountService();

        if (!$accountSvc->getByUid($sentTo)) {
            $this->json(['error' => true, 'message' => 'Invite recipient not found.'], 404);
            return;
        }

        $club = null;
        if (!empty($account['club_assign'])) {
            $club = $clubSvc->getById($account['club_assign']);
        }

        if (!$club || empty($club['id'])) {
            $this->json(['error' => true, 'message' => 'You must belong to a club before sending invites.'], 400);
            return;
        }

        $invite = $invSvc->create([
            'club'    => $club ? json_encode($club) : '{}',
            'sent_by' => ApiAuth::uid(),
            'sent_to' => $sentTo,
            'status'  => 'pending',
        ]);

        if ($invite) {
            // Notify the invitee
            $notifSvc = new NotificationService();
            $notifSvc->send(
                $sentTo,
                'invite_received',
                'Team Invite',
                ($account['fname'] ?? 'Someone') . ' invited you to join ' . ($club['name'] ?? 'their club'),
                'people',
                '/teams'
            );
            $this->json(['message' => 'Invite sent', 'invite' => $invite]);
        } else {
            $this->json(['error' => true, 'message' => 'Failed to send invite'], 500);
        }
    }

    /** POST /api/invites/respond — Accept or decline invite */
    public function respondInvite(): void
    {
        ApiAuth::require();
        $input = $this->input();

        $inviteId = (int)($input['invite_id'] ?? 0);
        $action   = $input['action'] ?? '';

        if (!$inviteId || !in_array($action, ['accept', 'decline'])) {
            $this->json(['error' => true, 'message' => 'invite_id and action (accept/decline) required'], 400);
            return;
        }

        $invSvc = new InviteService();
        $invite = $invSvc->getById($inviteId);
        if (!$invite) {
            $this->json(['error' => true, 'message' => 'Invite not found'], 404);
            return;
        }

        if ((string)($invite['sent_to'] ?? '') !== ApiAuth::uid()) {
            $this->json(['error' => true, 'message' => 'You are not allowed to respond to this invite.'], 403);
            return;
        }

        if (strtolower((string)($invite['status'] ?? 'pending')) !== 'pending') {
            $this->json(['error' => true, 'message' => 'This invite has already been processed.'], 409);
            return;
        }

        if ($action === 'accept') {
            $clubData = is_string($invite['club'] ?? null) ? json_decode($invite['club'], true) : ($invite['club'] ?? null);
            if (!$clubData || empty($clubData['id'])) {
                $this->json(['error' => true, 'message' => 'This invite does not point to a valid club.'], 400);
                return;
            }

            $result = $invSvc->acceptPendingForUser($inviteId, ApiAuth::uid());

            if ($result) {
                $accountSvc = new AccountService();
                $accountSvc->updateByUid(ApiAuth::uid(), ['club_assign' => $clubData['id']]);
            }
        } else {
            $result = $invSvc->declinePendingForUser($inviteId, ApiAuth::uid());
        }

        if ($result) {
            $this->json(['message' => "Invite {$action}ed"]);
        } else {
            $this->json(['error' => true, 'message' => 'Failed to respond to invite'], 500);
        }
    }

    /* ================================================================== */
    /*  ANNOUNCEMENTS                                                      */
    /* ================================================================== */

    /** GET /api/announcements — List announcements */
    public function announcements(): void
    {
        ApiAuth::require();

        $announceSvc = new AnnouncementService();
        $list = $announceSvc->list(50);

        $this->json(['announcements' => $list]);
    }

    /** POST /api/announcements — Create announcement */
    public function createAnnouncement(): void
    {
        $account = ApiAuth::requirePermission('announcements.manage', 'Admin permissions are required.');
        $input = $this->input();

        $announceSvc = new AnnouncementService();
        $data = [
            'title'   => $input['title'] ?? $input['location'] ?? '',
            'message' => $input['message'] ?? '',
            'user_id' => ApiAuth::uid(),
            'club'    => $account['club_assign'] ?? '',
        ];

        if (!empty($input['location'])) $data['location'] = $input['location'];
        if (!empty($input['date']))     $data['date'] = $input['date'];
        if (!empty($input['time']))     $data['time'] = $input['time'];
        if (!empty($input['response'])) $data['response'] = $input['response'];

        $created = $announceSvc->create($data);
        if ($created) {
            $this->json(['message' => 'Announcement created', 'announcement' => $created]);
        } else {
            $this->json(['error' => true, 'message' => 'Failed to create announcement'], 500);
        }
    }

    /* ================================================================== */
    /*  CHALLENGES (templates)                                             */
    /* ================================================================== */

    /** GET /api/challenges — Active challenge templates */
    public function challenges(): void
    {
        ApiAuth::require();
        $uid = ApiAuth::uid();

        $challengeSvc = new ChallengeTemplateService();
        $statsSvc     = new MatchStatsService();

        $active = $challengeSvc->listActive(20);
        $totals = $statsSvc->getUserTotals($uid);

        // Add progress for each challenge
        foreach ($active as &$challenge) {
            $metric = $challenge['metric'] ?? 'matches';
            $target = (int)($challenge['target_value'] ?? 1);
            $current = 0;

            switch ($metric) {
                case 'goals':    $current = $totals['goals'] ?? 0; break;
                case 'assists':  $current = $totals['assists'] ?? 0; break;
                case 'wins':     $current = $totals['wins'] ?? 0; break;
                case 'matches':  $current = $totals['matches'] ?? 0; break;
                case 'distance': $current = (int)($totals['distance_km'] ?? 0); break;
                case 'passes':   $current = $totals['successful_passes'] ?? 0; break;
                case 'dribbles': $current = $totals['successful_dribbles'] ?? 0; break;
            }

            $challenge['progress'] = [
                'current' => $current,
                'target'  => $target,
                'percent' => $target > 0 ? min(100, round(($current / $target) * 100)) : 0,
            ];
        }

        $this->json(['challenges' => $active]);
    }

    /* ================================================================== */
    /*  NOTIFICATIONS                                                      */
    /* ================================================================== */

    /** GET /api/notifications — List user notifications */
    public function notifications(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();

        $notifSvc = new NotificationService();
        $list   = $notifSvc->listForUser($uid, 50);
        $unread = $notifSvc->countUnread($uid);

        $this->json([
            'notifications' => $list,
            'unread_count'  => $unread,
        ]);
    }

    /** POST /api/notifications/read — Mark notification as read */
    public function markNotificationRead(): void
    {
        ApiAuth::require();
        $input = $this->input();

        $id = $input['id'] ?? '';
        if (!$id) {
            $this->json(['error' => true, 'message' => 'id required'], 400);
            return;
        }

        $notifSvc = new NotificationService();
        $notifSvc->markRead(ApiAuth::uid(), (int)$id);

        $this->json(['message' => 'Marked as read']);
    }

    /** POST /api/notifications/read-all — Mark all as read */
    public function markAllNotificationsRead(): void
    {
        $account = ApiAuth::require();
        $notifSvc = new NotificationService();
        $notifSvc->markAllRead(ApiAuth::uid());

        $this->json(['message' => 'All marked as read']);
    }

    /* ================================================================== */
    /*  LEADERBOARD                                                        */
    /* ================================================================== */

    /** GET /api/leaderboard — Top players by OVR */
    public function leaderboard(): void
    {
        ApiAuth::require();

        $ppSvc      = new PlayerProgressService();
        $accountSvc = new AccountService();
        $statsSvc   = new MatchStatsService();

        $board = $ppSvc->leaderboard(20);

        // Enrich with account info
        foreach ($board as &$entry) {
            $userId = $entry['user_id'] ?? '';
            if ($userId) {
                $acc = $accountSvc->getByUid($userId);
                if ($acc) {
                    $entry['fname']     = $acc['fname'] ?? '';
                    $entry['image_url'] = $acc['image_url'] ?? '';
                    $entry['position']  = $acc['position'] ?? '';
                    $entry['country']   = $acc['country'] ?? '';
                }
                $totals = $statsSvc->getUserTotals($userId);
                $entry['goals'] = $totals['goals'] ?? 0;
            }
        }

        $this->json(['leaderboard' => $board]);
    }

    /* ================================================================== */
    /*  PLAYERS SEARCH                                                     */
    /* ================================================================== */

    /** GET /api/players/search?q=name — Search for players */
    public function searchPlayers(): void
    {
        ApiAuth::require();

        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            $this->json(['players' => []]);
            return;
        }

        $accountSvc = new AccountService();
        $players = $accountSvc->search($q);

        $this->json(['players' => $players]);
    }

    /* ================================================================== */
    /*  FIXTURES                                                           */
    /* ================================================================== */

    /** GET /api/fixtures — Upcoming and completed fixtures */
    public function fixtures(): void
    {
        ApiAuth::require();

        $matchSvc = new MatchService();
        $accountSvc = new AccountService();
        $clubSvc  = new ClubService();

        $upcoming  = $matchSvc->listUpcoming(20);
        $completed = $matchSvc->listCompleted(20);

        $enrichMatch = function (array $match) use ($accountSvc, $clubSvc): array {
            return $this->enrichMatch($match, $accountSvc, $clubSvc);
        };

        $this->json([
            'upcoming'  => array_map($enrichMatch, $upcoming),
            'completed' => array_map($enrichMatch, $completed),
        ]);
    }

    /* ================================================================== */
    /*  DIVISION / RANKING (dynamic, replaces hardcoded)                   */
    /* ================================================================== */

    /** GET /api/division — Team division status */
    public function division(): void
    {
        $account = ApiAuth::require();

        if (empty($account['club_assign'])) {
            $this->json(['division' => null]);
            return;
        }

        $clubSvc  = new ClubService();
        $matchSvc = new MatchService();
        $statsSvc = new MatchStatsService();

        $club = $clubSvc->getById($account['club_assign']);
        if (!$club) {
            $this->json(['division' => null]);
            return;
        }

        // Calculate division from club ranking/level
        $ranking = (int)($club['ranking'] ?? 0);
        $level   = (int)($club['level'] ?? 1);

        $divisions = [
            ['name' => 'Bronze III', 'min' => 0],
            ['name' => 'Bronze II',  'min' => 5],
            ['name' => 'Bronze I',   'min' => 10],
            ['name' => 'Silver III', 'min' => 20],
            ['name' => 'Silver II',  'min' => 30],
            ['name' => 'Silver I',   'min' => 45],
            ['name' => 'Gold III',   'min' => 60],
            ['name' => 'Gold II',    'min' => 80],
            ['name' => 'Gold I',     'min' => 100],
            ['name' => 'Diamond',    'min' => 130],
        ];

        $currentDiv = $divisions[0];
        $nextDiv = $divisions[1] ?? null;
        foreach ($divisions as $i => $div) {
            if ($ranking >= $div['min']) {
                $currentDiv = $div;
                $nextDiv = $divisions[$i + 1] ?? null;
            }
        }

        // Get club match stats
        $uid = ApiAuth::uid();
        $totals = $statsSvc->getUserTotals($uid);

        $this->json([
            'division' => [
                'name'     => $currentDiv['name'],
                'points'   => $ranking,
                'next'     => $nextDiv,
                'club'     => $club,
                'record'   => [
                    'matches' => $totals['matches'] ?? 0,
                    'wins'    => $totals['wins'] ?? 0,
                    'draws'   => $totals['draws'] ?? 0,
                    'losses'  => $totals['losses'] ?? 0,
                ],
            ],
        ]);
    }

    /* ================================================================== */
    /*  CORS Preflight                                                     */
    /* ================================================================== */

    /** OPTIONS handler for CORS preflight */
    public function options(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        http_response_code(204);
    }
}

<?php
namespace App\Controllers;

use App\Core\ApiAuth;
use App\Core\Cache;
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
use App\Services\B2VideoStorageService;
use App\Services\AiProgressService;

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

    private function logAiApi(string $event, array $context = []): void
    {
        $context['event'] = $event;
        $context['uid'] = ApiAuth::uid();
        $context['time'] = gmdate('c');
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('[ai-api] ' . (is_string($encoded) ? $encoded : $event));
    }

    private function creditPacks(): array
    {
        return [
            'welcome' => ['credits' => 1, 'amount_cents' => 100, 'name' => 'Welcome Offer'],
            'solo' => ['credits' => 3, 'amount_cents' => 349, 'name' => 'Solo Pack'],
            'team' => ['credits' => 6, 'amount_cents' => 599, 'name' => 'Team Pack'],
            'champion' => ['credits' => 13, 'amount_cents' => 999, 'name' => 'Champion Pack'],
        ];
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

    /** POST /api/account/delete — Reauthenticate and permanently delete the current account. */
    public function deleteAccount(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();
        $email = trim((string)(ApiAuth::user()['email'] ?? $account['email'] ?? ''));
        $password = (string)($this->input()['password'] ?? '');

        if ($password === '') {
            $this->json(['error' => true, 'message' => 'Enter your password to delete your account.'], 422);
            return;
        }

        if ($uid === '' || $email === '') {
            $this->json(['error' => true, 'message' => 'Your account identity could not be verified.'], 400);
            return;
        }

        $sb = SupabaseClient::getInstance();
        if (!$sb->hasServiceRoleKey()) {
            $this->json(['error' => true, 'message' => 'Account deletion is temporarily unavailable.'], 503);
            return;
        }

        $reauth = $sb->authSignIn($email, $password);
        $reauthUid = (string)($reauth['user']['id'] ?? '');
        if (!$reauth || !empty($reauth['error']) || $reauthUid !== $uid) {
            $this->json(['error' => true, 'message' => 'The password you entered is incorrect.'], 403);
            return;
        }

        // Remove user-owned rows first. Core statistics also cascade from
        // accounts.uid, but explicit cleanup supports older production schemas.
        $ownedRows = [
            ['notifications', 'user_id'],
            ['stat_unlocks', 'user_id'],
            ['credit_transactions', 'user_id'],
            ['user_credits', 'user_id'],
            ['challenge_participation', 'user_id'],
            ['challenge_achievements', 'user_id'],
            ['match_stats', 'user_id'],
            ['xp_history', 'user_id'],
            ['player_progress', 'user_id'],
            ['invites', 'sent_to'],
            ['invites', 'sent_by'],
        ];

        foreach ($ownedRows as [$table, $column]) {
            $deleted = SupabaseClient::getInstance()->from($table)->eq($column, $uid)->delete();
            if ($deleted === null || !empty($deleted['error'])) {
                error_log("Account deletion failed while cleaning {$table}.{$column} for {$uid}");
                $this->json(['error' => true, 'message' => 'Your account could not be deleted completely. Please try again.'], 500);
                return;
            }
        }

        $accountDeleted = SupabaseClient::getInstance()
            ->from('accounts')
            ->eq('uid', $uid)
            ->delete();
        if ($accountDeleted === null || !empty($accountDeleted['error'])) {
            $this->json(['error' => true, 'message' => 'Your account could not be deleted. Please try again.'], 500);
            return;
        }

        $authDeleted = $sb->authAdminDeleteUser($uid);
        if ($authDeleted === null || !empty($authDeleted['error'])) {
            error_log("Account data was removed but Auth deletion failed for {$uid}");
            $this->json(['error' => true, 'message' => 'Your profile data was removed, but sign-in removal needs support assistance.'], 500);
            return;
        }

        $this->json(['message' => 'Your account has been permanently deleted.']);
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

    /** GET /api/player/analysis-stats — AI totals plus paid match history. */
    public function playerAnalysisStats(): void
    {
        $account = ApiAuth::require();
        $uid = ApiAuth::uid();
        $db = SupabaseClient::getInstance();

        $unlocks = $db->from('stat_unlocks')
            ->select('match_id, shirt_number, created_at')
            ->eq('user_id', $uid)
            ->order('created_at', false)
            ->limit(200)
            ->execute();
        $unlocks = ($unlocks && empty($unlocks['error']) && is_array($unlocks))
            ? $unlocks
            : [];

        $matchIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['match_id'] ?? 0),
            $unlocks
        ))));
        $matches = (new MatchService())->getByIds($matchIds);
        $matchesById = [];
        foreach ($matches as $match) {
            $matchesById[(int)($match['id'] ?? 0)] = $match;
        }

        $statsByMatch = (new MatchJerseyStatService())->groupedByMatchIds($matchIds);
        $analysisRows = [];
        if ($matchIds !== []) {
            $analysisRows = $db->from('match_video_analysis')
                ->select('match_id, video_url, processing_status, processed_at, error_message')
                ->filter('match_id', 'in', '(' . implode(',', $matchIds) . ')')
                ->execute();
            if (!$analysisRows || !empty($analysisRows['error']) || !is_array($analysisRows)) {
                $analysisRows = [];
            }
        }
        $analysisByMatch = [];
        foreach ($analysisRows as $analysisRow) {
            $analysisByMatch[(int)($analysisRow['match_id'] ?? 0)] = $analysisRow;
        }

        $history = [];
        $statRows = [];
        foreach ($unlocks as $unlock) {
            $matchId = (int)($unlock['match_id'] ?? 0);
            $shirtNumber = (int)($unlock['shirt_number'] ?? 0);
            $teams = $statsByMatch[$matchId] ?? ['blue' => [], 'red' => []];
            $matchStats = null;
            foreach (array_merge($teams['blue'] ?? [], $teams['red'] ?? []) as $row) {
                // The paid unlock is the authorization record. Jersey stats are
                // match-level data and must remain readable even if a lineup
                // assignment is later edited or another device claims a slot.
                if ((int)($row['jersey_number'] ?? 0) === $shirtNumber) {
                    $matchStats = $row;
                    break;
                }
            }

            if (is_array($matchStats)) {
                $statRows[] = $matchStats;
            }

            $match = $matchesById[$matchId] ?? [];
            $analysis = $analysisByMatch[$matchId] ?? [];
            $analysisVideoUrl = (string)(
                $analysis['video_url']
                ?? $match['video_url']
                ?? ''
            );
            $history[] = [
                'match_id' => $matchId,
                'shirt_number' => $shirtNumber,
                'paid_at' => $unlock['created_at'] ?? null,
                'date' => $match['date'] ?? null,
                'time' => $match['time'] ?? null,
                'challanger' => $match['challanger'] ?? 'Team A',
                'opponent' => $match['opponent'] ?? 'Team B',
                'location' => $match['location'] ?? null,
                'processing_status' => $analysis['processing_status'] ?? 'queued',
                'processed_at' => $analysis['processed_at'] ?? null,
                'error_message' => $analysis['error_message'] ?? null,
                'video_url' => $analysisVideoUrl,
                'stats' => is_array($matchStats)
                    ? $this->aggregateAiStatRows([$matchStats])
                    : null,
            ];
        }

        $this->json([
            'account' => $account,
            'totals' => $this->aggregateAiStatRows($statRows),
            'history' => $history,
        ]);
    }

    private function aggregateAiStatRows(array $rows): array
    {
        $keys = [
            'goals',
            'assists',
            'distance_meters',
            'sprints',
            'successful_passes',
            'passes_attempted',
            'successful_dribbles',
            'dribbles_attempted',
            'interceptions',
            'duels_won',
            'shots',
            'shots_on_target',
            'minutes_played',
        ];
        $totals = array_fill_keys($keys, 0);
        $totals['matches'] = count($rows);
        $totals['top_speed_kmh'] = 0.0;

        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $totals[$key] += (int)($row[$key] ?? 0);
            }
            $totals['top_speed_kmh'] = max(
                $totals['top_speed_kmh'],
                (float)($row['top_speed_kmh'] ?? 0)
            );
        }

        $totals['distance_km'] = round($totals['distance_meters'] / 1000, 1);
        $totals['pass_accuracy'] = $totals['passes_attempted'] > 0
            ? round(($totals['successful_passes'] / $totals['passes_attempted']) * 100, 1)
            : 0.0;
        $totals['dribble_accuracy'] = $totals['dribbles_attempted'] > 0
            ? round(($totals['successful_dribbles'] / $totals['dribbles_attempted']) * 100, 1)
            : 0.0;
        $totals['shot_accuracy'] = $totals['shots'] > 0
            ? round(($totals['shots_on_target'] / $totals['shots']) * 100, 1)
            : 0.0;
        $totals['top_speed_kmh'] = round($totals['top_speed_kmh'], 1);

        return $totals;
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

    /** POST /api/credits/checkout — Create a Stripe Checkout Session for credits. */
    public function createCreditCheckout(): void
    {
        $account = ApiAuth::require();
        $input = $this->input();
        $packKey = strtolower(trim((string)($input['pack_key'] ?? 'solo')));
        $packs = $this->creditPacks();
        $pack = $packs[$packKey] ?? null;
        if ($pack === null) {
            $this->json(['error' => true, 'message' => 'Unknown credit pack.'], 400);
            return;
        }

        $secretKey = trim((string)(getenv('STRIPE_SECRET_KEY') ?: getenv('NUTMEG_STRIPE_SECRET_KEY') ?: ''));
        if ($secretKey === '') {
            $this->json(['error' => true, 'message' => 'Stripe checkout is not configured.'], 503);
            return;
        }

        $uid = ApiAuth::uid();
        $email = trim((string)($account['email'] ?? ''));
        $appScheme = trim((string)(getenv('NUTMEG_APP_SCHEME') ?: 'fivestats'));
        $successUrl = $this->checkoutReturnUrl(
            (string)($input['success_url'] ?? ''),
            $appScheme . '://stripe-success',
            [
                'session_id' => '{CHECKOUT_SESSION_ID}',
                'pack' => $packKey,
            ]
        );
        $cancelUrl = $this->checkoutReturnUrl(
            (string)($input['cancel_url'] ?? ''),
            $appScheme . '://stripe-cancel',
            ['pack' => $packKey]
        );

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $uid,
            'metadata[user_id]' => $uid,
            'metadata[pack_key]' => $packKey,
            'metadata[credits]' => (string)$pack['credits'],
            'payment_intent_data[metadata][user_id]' => $uid,
            'payment_intent_data[metadata][pack_key]' => $packKey,
            'payment_intent_data[metadata][credits]' => (string)$pack['credits'],
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => (string)$pack['amount_cents'],
            'line_items[0][price_data][product_data][name]' => 'FiveStats ' . $pack['name'],
            'line_items[0][price_data][product_data][description]' => $pack['credits'] . ' AI stats credit' . ($pack['credits'] === 1 ? '' : 's'),
        ];
        if ($email !== '') {
            $payload['customer_email'] = $email;
        }

        try {
            $session = $this->stripeRequest('POST', '/v1/checkout/sessions', $payload, $secretKey);
            $url = trim((string)($session['url'] ?? ''));
            if ($url === '') {
                throw new \RuntimeException('Stripe did not return a checkout URL.');
            }

            $this->json([
                'url' => $url,
                'session_id' => $session['id'] ?? null,
                'pack_key' => $packKey,
                'credits' => (int)$pack['credits'],
            ]);
        } catch (\Throwable $e) {
            error_log('[stripe-checkout] ' . $e->getMessage());
            $this->json(['error' => true, 'message' => 'Could not start Stripe checkout.'], 502);
        }
    }

    /** POST /api/credits/confirm — Confirm a returned Checkout Session and grant credits. */
    public function confirmCreditCheckout(): void
    {
        ApiAuth::require();
        $input = $this->input();
        $sessionId = trim((string)($input['session_id'] ?? ''));
        if ($sessionId === '') {
            $this->json(['error' => true, 'message' => 'Missing Stripe session.'], 400);
            return;
        }

        $secretKey = trim((string)(getenv('STRIPE_SECRET_KEY') ?: getenv('NUTMEG_STRIPE_SECRET_KEY') ?: ''));
        if ($secretKey === '') {
            $this->json(['error' => true, 'message' => 'Stripe checkout is not configured.'], 503);
            return;
        }

        try {
            $session = $this->stripeRequest('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [], $secretKey);
            $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
            if (trim((string)($metadata['user_id'] ?? '')) !== ApiAuth::uid()) {
                $this->json(['error' => true, 'message' => 'This checkout session belongs to another user.'], 403);
                return;
            }

            $paymentStatus = strtolower(trim((string)($session['payment_status'] ?? '')));
            if ($paymentStatus !== 'paid') {
                $this->json([
                    'credited' => false,
                    'status' => $paymentStatus ?: 'unpaid',
                    'message' => 'Payment is not complete yet.',
                ], 202);
                return;
            }

            $grant = $this->grantCreditsForStripeSession($session);
            $summary = $this->creditSummary(ApiAuth::uid());
            $this->json([
                'credited' => true,
                'already_credited' => (bool)($grant['already_credited'] ?? false),
                'credits_added' => (int)($grant['credits'] ?? 0),
                'balance' => (int)($summary['balance'] ?? 0),
                'welcome_used' => (bool)($summary['welcome_used'] ?? false),
            ]);
        } catch (\Throwable $e) {
            error_log('[stripe-confirm] ' . $e->getMessage());
            $this->json(['error' => true, 'message' => 'Could not confirm Stripe payment.'], 502);
        }
    }

    /** POST /api/stripe/webhook — Stripe signed webhook. */
    public function stripeWebhook(): void
    {
        $rawBody = (string)file_get_contents('php://input');
        $signature = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
        $webhookSecret = trim((string)(getenv('STRIPE_WEBHOOK_SECRET') ?: getenv('NUTMEG_STRIPE_WEBHOOK_SECRET') ?: ''));

        if ($webhookSecret === '') {
            $this->json(['error' => true, 'message' => 'Stripe webhook secret is not configured.'], 503);
            return;
        }

        if (!$this->verifyStripeWebhookSignature($rawBody, $signature, $webhookSecret)) {
            $this->json(['error' => true, 'message' => 'Invalid Stripe signature.'], 400);
            return;
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            $this->json(['error' => true, 'message' => 'Invalid Stripe payload.'], 400);
            return;
        }

        $type = (string)($event['type'] ?? '');
        $session = $event['data']['object'] ?? null;
        if ($type === 'checkout.session.completed' && is_array($session)) {
            try {
                $this->grantCreditsForStripeSession($session);
            } catch (\Throwable $e) {
                error_log('[stripe-webhook] ' . $e->getMessage());
                $this->json(['error' => true, 'message' => 'Credit grant failed.'], 500);
                return;
            }
        }

        $this->json(['received' => true]);
    }

    /** POST /api/video-analysis/request — Queue a B2 video for AI analysis. */
    public function requestVideoAnalysis(): void
    {
        $account = ApiAuth::require();
        $input = $this->input();

        $matchId = (int)($input['match_id'] ?? 0);
        $requestedVideoUrl = trim((string)($input['video_url'] ?? ''));
        $clipMetadata = [];
        foreach (['recording_id', 'file_name', 'folder_name', 'folder_path', 'video_part_number', 'video_group_key', 'camera_number', 'storage_version', 'ai_video_url'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if ($value !== '') {
                $clipMetadata[$key] = $value;
            }
        }
        $teamColor = strtolower(trim((string)($input['team_color'] ?? '')));
        $jerseyNumber = (int)($input['jersey_number'] ?? 0);
        $this->logAiApi('request:start', [
            'match_id' => $matchId,
            'jersey_number' => $jerseyNumber,
            'team_color' => $teamColor,
            'has_video_url' => $requestedVideoUrl !== '',
        ]);

        if ($matchId <= 0 || !in_array($teamColor, ['blue', 'red'], true)) {
            $this->logAiApi('request:validation_failed', [
                'match_id' => $matchId,
                'jersey_number' => $jerseyNumber,
                'team_color' => $teamColor,
                'reason' => 'missing required fields',
            ]);
            $this->json(['error' => true, 'message' => 'Match and team color are required.'], 400);
            return;
        }

        $validNumbers = $teamColor === 'blue' ? [1, 2, 3, 4, 5] : [6, 7, 8, 9, 10];
        if (!in_array($jerseyNumber, $validNumbers, true)) {
            $this->logAiApi('request:validation_failed', [
                'match_id' => $matchId,
                'jersey_number' => $jerseyNumber,
                'team_color' => $teamColor,
                'reason' => 'invalid jersey for team',
            ]);
            $this->json(['error' => true, 'message' => 'Choose a valid jersey number for the selected team.'], 400);
            return;
        }

        $matchService = new MatchService();
        $match = $matchService->getById($matchId);
        if (!$match) {
            $this->logAiApi('request:match_not_found', ['match_id' => $matchId]);
            $this->json(['error' => true, 'message' => 'Match not found.'], 404);
            return;
        }

        $uid = ApiAuth::uid();
        if (!ApiAuth::isInstructor() && !$this->userParticipatesInMatch($match, $uid)) {
            $this->logAiApi('request:forbidden', [
                'match_id' => $matchId,
                'uid' => $uid,
            ]);
            $this->json(['error' => true, 'message' => 'You can only analyze videos for your matches.'], 403);
            return;
        }

        $matchVideoUrl = trim((string)($match['video_url'] ?? ''));
        if ($requestedVideoUrl === '') {
            $requestedVideoUrl = $matchVideoUrl;
        }
        if ($requestedVideoUrl === '') {
            $this->json(['error' => true, 'message' => 'This match does not have a video available.'], 422);
            return;
        }
        $isManagedB2 = B2VideoStorageService::isConfigured()
            && (new B2VideoStorageService())->isManagedUrl($requestedVideoUrl);
        if (!$isManagedB2) {
            $this->json(['error' => true, 'message' => 'Only FiveStats Cloud videos can be analyzed.'], 422);
            return;
        }

        $playerName = $this->accountLabel($account);
        $downloadUrl = $requestedVideoUrl;
        $this->rememberAnalysisRequester(
            $matchId,
            $jerseyNumber,
            $uid,
            $playerName
        );

        $analysisRows = new MatchVideoAnalysisService();
        $existingAnalysis = $analysisRows->getByMatchId($matchId);
        $existingStatus = strtolower(trim((string)($existingAnalysis['processing_status'] ?? '')));
        if ($existingStatus === 'failed') {
            $recoveredAnalysis = (new VideoAnalysisService())
                ->recoverCompletedAnalysisFromPersistedStats($matchId);
            if (is_array($recoveredAnalysis)) {
                $existingAnalysis = $recoveredAnalysis;
                $existingStatus = 'processed';
            }
        }

        // Mobile clients no longer start GPU processing. The server/website
        // owns AI inference: new uploaded videos are discovered by cron,
        // queued once, processed by RunPod, and stored in the database. This
        // endpoint now only unlocks/returns already-persisted stats.
        $progress = (new VideoAnalysisService())->getLiveProgress($matchId);
        if ($existingStatus === 'processed') {
            if (!ApiAuth::isInstructor() && !$this->userHasStatUnlock($uid, $matchId, $jerseyNumber)) {
                $this->json([
                    'error' => true,
                    'message' => 'Use one credit to unlock these saved stats.',
                    'match_id' => $matchId,
                    'status' => 'processed',
                    'progress' => $progress,
                    'worker_started' => false,
                    'worker_state' => 'not_needed',
                ], 402);
                return;
            }

            $playerStats = $this->unlockProcessedJerseyStats(
                $matchId,
                $jerseyNumber,
                $uid,
                $playerName,
                $existingAnalysis
            );

            if (is_array($playerStats)) {
                $this->json([
                    'message' => 'Saved match stats unlocked.',
                    'match_id' => $matchId,
                    'status' => 'processed',
                    'progress' => $progress,
                    'stats' => $playerStats,
                    'worker_started' => false,
                    'worker_state' => 'not_needed',
                ]);
                return;
            }

            $this->json([
                'error' => true,
                'message' => 'This match is analyzed, but this jersey result is missing. Please contact support.',
                'match_id' => $matchId,
                'status' => 'processed',
                'progress' => $progress,
                'worker_started' => false,
                'worker_state' => 'not_needed',
            ], 422);
            return;
        }

        if (in_array($existingStatus, ['queued', 'processing'], true)) {
            $this->json([
                'message' => 'Stats are being prepared automatically on the server.',
                'match_id' => $matchId,
                'status' => $existingStatus,
                'progress' => $progress,
                'worker_started' => false,
                'worker_state' => 'server_managed',
            ], 202);
            return;
        }

        $this->json([
            'error' => true,
            'message' => $existingStatus === 'failed'
                ? 'Server-side AI processing failed for this video. Please contact support or wait for the server retry.'
                : 'Stats are not ready yet. New server videos are analyzed automatically.',
            'match_id' => $matchId,
            'status' => $existingStatus !== '' ? $existingStatus : 'pending',
            'progress' => $progress,
            'worker_started' => false,
            'worker_state' => 'server_managed',
        ], $existingStatus === 'failed' ? 409 : 425);
        return;

        if ($existingStatus === 'failed') {
            $failedAt = strtotime((string)($existingAnalysis['updated_at'] ?? '')) ?: 0;
            $isRecentFailure = $failedAt > 0 && (time() - $failedAt) < 900;
            $failureMessage = trim((string)($existingAnalysis['error_message'] ?? ''));
            $wasManualStop = str_contains(strtolower($failureMessage), 'stopped manually')
                || str_contains(strtolower($failureMessage), 'you stopped the analysis');
            $failureOutput = is_array($existingAnalysis['ai_output'] ?? null)
                ? $existingAnalysis['ai_output']
                : [];
            $wasClearedForRetry = ($failureOutput['cleared_for_retry'] ?? false) === true
                || ($failureOutput['cleared_for_new_ai'] ?? false) === true
                || str_contains(strtolower($failureMessage), 'processing was cleared');
            if ($isRecentFailure && !$wasManualStop && !$wasClearedForRetry) {
                $message = $failureMessage !== '' ? $failureMessage : 'AI processing failed. Please start AI again.';
                $this->logAiApi('request:recent_failed_blocked', [
                    'match_id' => $matchId,
                    'message' => $message,
                ]);
                $this->json([
                    'error' => true,
                    'message' => $message,
                    'match_id' => $matchId,
                    'status' => 'failed',
                    'progress' => (new VideoAnalysisService())->getLiveProgress($matchId),
                ], 409);
                return;
            }
        }
        if (in_array($existingStatus, ['queued', 'processing'], true)) {
            $this->logAiApi('request:already_running', [
                'match_id' => $matchId,
                'status' => $existingStatus,
                'progress_stage' => $existingAnalysis['progress_stage'] ?? null,
                'progress_percent' => $existingAnalysis['progress_percent'] ?? null,
                'progress_message' => $existingAnalysis['progress_message'] ?? null,
            ]);
            $workerStart = $this->ensureAiWorkerStarting($matchId);
            if ($this->isTerminalWorkerStartFailure($workerStart)) {
                (new VideoAnalysisService())->failAnalysis($matchId, (string)$workerStart['message']);
                $this->json([
                    'error' => true,
                    'message' => (string)$workerStart['message'],
                    'match_id' => $matchId,
                    'status' => 'failed',
                    'refunded' => true,
                ], 503);
                return;
            }
            $kicked = false;
            try {
                $kicked = (new VideoAnalysisService())->kickBackgroundProcessing($matchId);
            } catch (\Throwable $e) {
                $this->logAiApi('request:rekick_exception', [
                    'match_id' => $matchId,
                    'message' => $e->getMessage(),
                ]);
            }
            $this->logAiApi('request:already_running_result', [
                'match_id' => $matchId,
                'status' => $existingStatus,
                'kicked' => $kicked,
                'worker' => $workerStart,
            ]);
            $this->json([
                'message' => 'AI analysis is already running for this match.',
                'match_id' => $matchId,
                'status' => $existingStatus,
                'kicked' => $kicked,
                'worker_started' => $workerStart['started'],
                'worker_state' => $workerStart['state'],
                'worker_message' => $workerStart['message'],
            ]);
            return;
        }

        $existingAnalysis = $analysisRows->getByMatchId($matchId);
        $existingStatus = strtolower(trim((string)($existingAnalysis['processing_status'] ?? '')));
        if ($existingStatus === 'processed') {
            $playerStats = $this->unlockProcessedJerseyStats(
                $matchId,
                $jerseyNumber,
                $uid,
                $playerName,
                $existingAnalysis
            );

            if (is_array($playerStats)) {
                $progress = (new VideoAnalysisService())->getLiveProgress($matchId);
                $this->logAiApi('request:already_processed_unlocked', [
                    'match_id' => $matchId,
                    'uid' => $uid,
                    'jersey_number' => $jerseyNumber,
                ]);
                $this->json([
                    'message' => 'Your match has already been analyzed. Stats unlocked.',
                    'match_id' => $matchId,
                    'status' => 'processed',
                    'progress' => $progress,
                    'stats' => $playerStats,
                    'worker_started' => false,
                    'worker_state' => 'not_needed',
                ]);
                return;
            }

            // A completed match is immutable from the mobile unlock flow. If a
            // legacy result is missing this jersey, report the data problem
            // instead of spending GPU time analyzing the same video again.
            $this->logAiApi('request:processed_stats_missing', [
                'match_id' => $matchId,
                'uid' => $uid,
                'jersey_number' => $jerseyNumber,
            ]);
            $this->json([
                'error' => true,
                'message' => 'This match is already analyzed, but this player result is missing. Please contact support.',
                'match_id' => $matchId,
                'status' => 'processed',
                'worker_started' => false,
                'worker_state' => 'not_needed',
            ], 422);
            return;
        }

        try {
            $analysis = new VideoAnalysisService();
            $sourceType = B2VideoStorageService::isConfigured()
                && (new B2VideoStorageService())->isManagedUrl($downloadUrl)
                ? 'b2_storage'
                : 'external_url';
            $analysis->queueAnalysis($matchId, $downloadUrl, $uid, $sourceType, $clipMetadata);
            $this->logAiApi('request:queued', [
                'match_id' => $matchId,
                'uid' => $uid,
                'jersey_number' => $jerseyNumber,
                'team_color' => $teamColor,
                'download_url_host' => parse_url($downloadUrl, PHP_URL_HOST),
                'recording_id' => $clipMetadata['recording_id'] ?? null,
                'file_name' => $clipMetadata['file_name'] ?? null,
                'video_part_number' => $clipMetadata['video_part_number'] ?? null,
            ]);
            $workerStart = $this->ensureAiWorkerStarting($matchId);
            if ($this->isTerminalWorkerStartFailure($workerStart)) {
                $analysis->failAnalysis($matchId, (string)$workerStart['message']);
                $this->json([
                    'error' => true,
                    'message' => (string)$workerStart['message'],
                    'match_id' => $matchId,
                    'status' => 'failed',
                    'refunded' => true,
                    'worker_started' => false,
                    'worker_state' => $workerStart['state'],
                    'worker_error' => $workerStart['error'],
                ], 503);
                return;
            }
            $started = $analysis->kickBackgroundProcessing($matchId);
            $this->logAiApi('request:kick_result', [
                'match_id' => $matchId,
                'started' => $started,
                'worker' => $workerStart,
            ]);

            $this->json([
                'message' => $workerStart['message'] !== ''
                    ? $workerStart['message']
                    : ($started
                        ? 'AI analysis started.'
                        : 'AI analysis queued and will start shortly.'),
                'match_id' => $matchId,
                'status' => 'queued',
                'jersey_number' => $jerseyNumber,
                'team_color' => $teamColor,
                'worker_started' => $workerStart['started'],
                'worker_state' => $workerStart['state'],
                'worker_error' => $workerStart['error'],
            ], 202);
        } catch (\Throwable $e) {
            $this->logAiApi('request:exception', [
                'match_id' => $matchId,
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1200),
            ]);
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

        $analysisService = new VideoAnalysisService();
        $progress = $analysisService->getLiveProgress($matchId);
        $automation = [
            'server_managed' => true,
            'message' => 'AI processing is started by the website cron, not by the mobile app.',
        ];
        $stats = null;
        if ($jerseyNumber > 0) {
            $candidateStats = (new MatchJerseyStatService())->getByMatchAndNumber(
                $matchId,
                $jerseyNumber
            );
            if (
                !is_array($candidateStats)
                && strtolower(trim((string)($progress['status'] ?? ''))) === 'processed'
            ) {
                $candidateStats = $this->unlockProcessedJerseyStats(
                    $matchId,
                    $jerseyNumber,
                    ApiAuth::uid(),
                    $this->accountLabel(ApiAuth::account() ?? []),
                    (new MatchVideoAnalysisService())->getByMatchId($matchId) ?? []
                );
            }
            if (ApiAuth::isInstructor() || $this->userHasStatUnlock(
                ApiAuth::uid(),
                $matchId,
                $jerseyNumber
            )) {
                $stats = $candidateStats;
            }
        }

        $this->json([
            'progress' => $progress,
            'stats' => $stats,
            'stats_available' => is_array($candidateStats ?? null),
            'automation' => $automation,
        ]);
        $this->logAiApi('status:response', [
            'match_id' => $matchId,
            'jersey_number' => $jerseyNumber,
            'progress' => $progress,
            'has_stats' => $stats !== null,
            'automation' => $automation,
        ]);
    }

    private function unlockProcessedJerseyStats(
        int $matchId,
        int $jerseyNumber,
        string $uid,
        string $playerName,
        array $analysis
    ): ?array {
        $statService = new MatchJerseyStatService();
        $playerStats = $statService->assignPlayerToJersey(
            $matchId,
            $jerseyNumber,
            $uid,
            $playerName
        ) ?: $statService->getByMatchAndNumber($matchId, $jerseyNumber);

        if (is_array($playerStats)) {
            return $playerStats;
        }

        $normalizedRows = $this->decodeJsonArray($analysis['normalized_stats'] ?? []);
        foreach ($normalizedRows as $row) {
            if (!is_array($row) || (int)($row['jersey_number'] ?? 0) !== $jerseyNumber) {
                continue;
            }

            $row['match_id'] = (string)$matchId;
            $row['jersey_number'] = $jerseyNumber;
            $row['team_color'] = strtolower(trim((string)($row['team_color'] ?? '')))
                ?: ($jerseyNumber <= 5 ? 'blue' : 'red');
            $row['player_uid'] = $uid;
            $row['player_name'] = $playerName;
            $row['updated_at'] = gmdate('c');

            $created = $statService->upsertByMatchAndNumber($matchId, $jerseyNumber, $row);
            if (is_array($created)) {
                $this->logAiApi('request:rebuilt_processed_jersey_stats', [
                    'match_id' => $matchId,
                    'uid' => $uid,
                    'jersey_number' => $jerseyNumber,
                ]);
                return $created;
            }

            return null;
        }

        return null;
    }

    private function rememberAnalysisRequester(
        int $matchId,
        int $jerseyNumber,
        string $uid,
        string $playerName
    ): void {
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

    }

    private function userHasStatUnlock(string $uid, int $matchId, int $jerseyNumber): bool
    {
        if ($uid === '' || $matchId <= 0 || $jerseyNumber <= 0) {
            return false;
        }

        try {
            $row = SupabaseClient::getInstance()
                ->from('stat_unlocks')
                ->select('id')
                ->eq('user_id', $uid)
                ->eq('match_id', (string)$matchId)
                ->eq('shirt_number', (string)$jerseyNumber)
                ->single()
                ->execute();
            return is_array($row) && empty($row['error']) && !empty($row['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
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
            $this->logAiApi('kick:not_active', [
                'match_id' => $matchId,
                'status' => $status,
            ]);
            $this->json(['message' => 'No active analysis needs starting.', 'kicked' => false, 'status' => $status]);
            return;
        }

        try {
            $workerStart = $this->ensureAiWorkerStarting($matchId);
            if ($this->isTerminalWorkerStartFailure($workerStart)) {
                (new VideoAnalysisService())->failAnalysis($matchId, (string)$workerStart['message']);
                $this->json([
                    'error' => true,
                    'message' => (string)$workerStart['message'],
                    'kicked' => false,
                    'status' => 'failed',
                    'refunded' => true,
                    'worker_started' => false,
                    'worker_state' => $workerStart['state'],
                    'worker_error' => $workerStart['error'],
                ], 503);
                return;
            }
            $kicked = (new VideoAnalysisService())->kickBackgroundProcessing($matchId);
            $this->logAiApi('kick:result', [
                'match_id' => $matchId,
                'status' => $status,
                'kicked' => $kicked,
                'worker' => $workerStart,
            ]);
            $this->json([
                'message' => $workerStart['message'] !== ''
                    ? $workerStart['message']
                    : ($kicked ? 'AI analysis worker started.' : 'AI analysis remains queued.'),
                'kicked' => $kicked,
                'status' => $status,
                'worker_started' => $workerStart['started'],
                'worker_state' => $workerStart['state'],
                'worker_error' => $workerStart['error'],
            ]);
        } catch (\Throwable $e) {
            $this->logAiApi('kick:exception', [
                'match_id' => $matchId,
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1200),
            ]);
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
        $metadata = is_array($analysis['ai_output'] ?? null) ? $analysis['ai_output'] : [];
        $selectedClip = is_array($metadata['selected_clip'] ?? null) ? $metadata['selected_clip'] : null;
        $stoppedOutput = $metadata + [
            'stopped_manually' => true,
            'stopped_at' => gmdate('c'),
        ];
        $stoppedOutput['stopped_manually'] = true;
        $stoppedOutput['stopped_at'] = gmdate('c');
        if ($selectedClip !== null) {
            $stoppedOutput['selected_clip'] = $selectedClip;
        }
        $analysisService->updateByMatchId($matchId, [
            'processing_status' => 'failed',
            'error_message' => $message,
            'ai_output' => $stoppedOutput,
            'progress_stage' => 'failed',
            'progress_percent' => 5,
            'progress_message' => $message,
            'updated_at' => gmdate('c'),
        ]);
        (new AiProgressService())->failed($matchId, $message);
        (new MatchService())->update($matchId, ['video_status' => 'failed']);

        $this->json(['message' => $message, 'stopped' => true, 'status' => 'failed']);
    }

    /**
     * Ensure a configured Runpod worker is running for an active AI request.
     *
     * @return array{started:bool,state:string,message:string,error:string}
     */
    private function ensureAiWorkerStarting(int $matchId): array
    {
        $result = [
            'started' => false,
            'state' => 'unconfigured',
            'message' => '',
            'error' => '',
        ];

        try {
            $instanceKey = \App\Services\RunpodPodService::preferredInstanceKey();
            $worker = new \App\Services\RunpodPodService($instanceKey);
            if (!$worker->isConfigured()) {
                $result['message'] = 'AI analysis is queued, but the Runpod worker is not configured.';
                return $result;
            }

            $status = $worker->getStatus(true);
            $state = strtolower(trim((string)($status['state'] ?? '')));
            $result['state'] = $state !== '' ? $state : 'unknown';

            if (in_array($state, ['ready', 'starting', 'stopping'], true)) {
                $result['message'] = $state === 'ready'
                    ? 'AI worker is ready. Analysis is starting.'
                    : 'Runpod is already starting the AI worker.';
                return $result;
            }

            // Retry provisioning for stopped, unavailable, error, and stale
            // placeholder states. The queue remains durable if Runpod has no
            // capacity, and the mobile monitor will nudge this endpoint again.
            $started = \App\Services\RunpodPodService::startLeastCostAvailable();
            $startedStatus = is_array($started['status'] ?? null) ? $started['status'] : [];
            $startedState = strtolower(trim((string)($startedStatus['state'] ?? 'starting')));

            $result['started'] = true;
            $result['state'] = $startedState !== '' ? $startedState : 'starting';
            $result['message'] = 'GPU requested from Runpod. Waiting for the AI worker to boot.';

            try {
                (new \App\Services\AiProgressService())->queuedStage(
                    $matchId,
                    'gpu_requested',
                    6,
                    $result['message']
                );
            } catch (\Throwable $progressError) {
                error_log('[api-video-analysis-worker-progress] ' . $progressError->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('[api-video-analysis-worker-start] ' . $e->getMessage());
            $result['state'] = 'queued';
            $result['error'] = $e->getMessage();
            $errorText = strtolower($e->getMessage());
            if (str_contains($errorText, 'no gpu')) {
                $result['message'] = 'No GPU is available right now. Your credit has been refunded. Please start AI again later.';
            } elseif ($this->isRunpodAuthOrConfigError($e->getMessage())) {
                $result['message'] = 'AI worker configuration failed: Runpod API key/settings are not accepted. Your credit has been refunded. Please try again after admin fixes Runpod.';
            } else {
                $result['message'] = 'Your analysis is queued. Runpod startup will retry automatically.';
            }
        }

        return $result;
    }

    private function isTerminalWorkerStartFailure(array $workerStart): bool
    {
        $text = strtolower(trim(
            (string)($workerStart['error'] ?? '')
            . ' '
            . (string)($workerStart['message'] ?? '')
        ));

        return str_contains($text, 'no gpu')
            || str_contains($text, 'gpu is available')
            || str_contains($text, 'gpu available')
            || $this->isRunpodAuthOrConfigError($text);
    }

    private function isRunpodAuthOrConfigError(string $message): bool
    {
        $text = strtolower($message);
        return str_contains($text, 'api key')
            || str_contains($text, 'http 401')
            || str_contains($text, 'http 403')
            || str_contains($text, 'unauthorized')
            || str_contains($text, 'forbidden')
            || str_contains($text, 'not configured');
    }

    /**
     * Status polling is the one dependable recurring signal from the mobile
     * app. Use it to retry provisioning and queue pickup when shared-hosting
     * cron or background execution is delayed.
     */
    private function nudgeQueuedVideoAnalysis(
        int $matchId,
        VideoAnalysisService $analysisService
    ): array {
        $cacheKey = 'nutmeg:video-analysis:auto-nudge:' . $matchId;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = [
            'attempted' => true,
            'kicked' => false,
            'worker_started' => false,
            'worker_state' => 'queued',
            'message' => 'AI automation retry is in progress.',
        ];

        // Set the throttle before network calls so overlapping mobile polls do
        // not rent multiple pods while the first Runpod request is in flight.
        Cache::put($cacheKey, $result, 45);

        try {
            $workerStart = $this->ensureAiWorkerStarting($matchId);
            if ($this->isTerminalWorkerStartFailure($workerStart)) {
                $analysisService->failAnalysis($matchId, (string)$workerStart['message']);
                $result['worker_started'] = false;
                $result['worker_state'] = 'failed';
                $result['message'] = (string)$workerStart['message'];
                Cache::put($cacheKey, $result, 45);
                return $result;
            }
            $result['worker_started'] = (bool)($workerStart['started'] ?? false);
            $result['worker_state'] = (string)($workerStart['state'] ?? 'queued');
            $result['message'] = (string)($workerStart['message'] ?? $result['message']);
            $result['kicked'] = $analysisService->kickBackgroundProcessing($matchId);
        } catch (\Throwable $e) {
            error_log('[api-video-analysis-auto-nudge] ' . $e->getMessage());
            $result['message'] = 'AI analysis remains queued and will retry automatically.';
        }

        Cache::put($cacheKey, $result, 45);
        return $result;
    }

    private function stripeRequest(string $method, string $path, array $payload, string $secretKey): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required for Stripe checkout.');
        }

        $url = 'https://api.stripe.com' . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($error !== '') {
            throw new \RuntimeException('Stripe cURL error: ' . $error);
        }

        $decoded = json_decode((string)$response, true);
        if ($httpCode >= 400) {
            $message = is_array($decoded)
                ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? $response)
                : (string)$response;
            throw new \RuntimeException('Stripe HTTP ' . $httpCode . ': ' . $message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Stripe returned an invalid response.');
        }

        return $decoded;
    }

    private function checkoutReturnUrl(string $requestedUrl, string $fallbackUrl, array $params): string
    {
        $baseUrl = trim($requestedUrl);
        if (!$this->isAllowedCheckoutReturnUrl($baseUrl)) {
            $baseUrl = $fallbackUrl;
        }

        return $this->appendQueryParams($baseUrl, $params);
    }

    private function isAllowedCheckoutReturnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
        if (in_array($scheme, ['fivestats', 'nutmegplay', 'exp', 'exps'], true)) {
            return true;
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        return in_array($host, ['localhost', '127.0.0.1', 'nutmegplay.fr', 'fivestats.fr'], true);
    }

    private function appendQueryParams(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        $pairs = [];
        foreach ($params as $key => $value) {
            $rawValue = (string)$value;
            $pairs[] = rawurlencode((string)$key) . '=' . (
                $rawValue === '{CHECKOUT_SESSION_ID}'
                    ? $rawValue
                    : rawurlencode($rawValue)
            );
        }

        return $url . $separator . implode('&', $pairs);
    }

    private function verifyStripeWebhookSignature(string $payload, string $header, string $secret): bool
    {
        if ($payload === '' || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function grantCreditsForStripeSession(array $session): array
    {
        $sessionId = trim((string)($session['id'] ?? ''));
        if ($sessionId === '') {
            throw new \RuntimeException('Stripe session id is missing.');
        }

        $paymentStatus = strtolower(trim((string)($session['payment_status'] ?? '')));
        if ($paymentStatus !== 'paid') {
            throw new \RuntimeException('Stripe session is not paid.');
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $uid = trim((string)($metadata['user_id'] ?? $session['client_reference_id'] ?? ''));
        $packKey = strtolower(trim((string)($metadata['pack_key'] ?? 'solo')));
        $packs = $this->creditPacks();
        $pack = $packs[$packKey] ?? null;
        if ($uid === '' || $pack === null) {
            throw new \RuntimeException('Stripe session metadata is missing user or pack details.');
        }

        $credits = (int)($metadata['credits'] ?? $pack['credits']);
        if ($credits <= 0) {
            $credits = (int)$pack['credits'];
        }

        $db = SupabaseClient::getInstance();
        $transaction = [
            'user_id' => $uid,
            'amount' => $credits,
            'kind' => 'purchase',
            'pack_key' => $packKey,
            'stripe_session_id' => $sessionId,
            'note' => 'Stripe credit purchase: ' . $pack['name'],
        ];
        $inserted = $db->from('credit_transactions')->insert($transaction);
        if (!$inserted || !empty($inserted['error'])) {
            $message = strtolower((string)($inserted['message'] ?? ''));
            if (str_contains($message, 'duplicate') || str_contains($message, 'unique')) {
                return [
                    'already_credited' => true,
                    'credits' => 0,
                    'pack_key' => $packKey,
                ];
            }

            throw new \RuntimeException('Could not record Stripe credit transaction.');
        }

        $summary = $this->creditSummary($uid);
        $existingBalance = (int)($summary['balance'] ?? 0);
        $welcomeUsed = (bool)($summary['welcome_used'] ?? false);
        $creditRow = [
            'user_id' => $uid,
            'balance' => $existingBalance + $credits,
            'welcome_used' => $welcomeUsed || $packKey === 'welcome',
            'updated_at' => gmdate('c'),
        ];

        if ($summary['exists'] ?? false) {
            $updated = SupabaseClient::getInstance()
                ->from('user_credits')
                ->eq('user_id', $uid)
                ->update($creditRow);
            if (!$updated || !empty($updated['error'])) {
                throw new \RuntimeException('Could not update user credit balance.');
            }
        } else {
            $created = SupabaseClient::getInstance()->from('user_credits')->insert($creditRow);
            if (!$created || !empty($created['error'])) {
                throw new \RuntimeException('Could not create user credit balance.');
            }
        }

        return [
            'already_credited' => false,
            'credits' => $credits,
            'pack_key' => $packKey,
        ];
    }

    private function creditSummary(string $uid): array
    {
        if ($uid === '') {
            return ['exists' => false, 'balance' => 0, 'welcome_used' => false];
        }

        $row = SupabaseClient::getInstance()
            ->from('user_credits')
            ->select('balance, welcome_used')
            ->eq('user_id', $uid)
            ->single()
            ->execute();

        if (!$row || !empty($row['error'])) {
            return ['exists' => false, 'balance' => 0, 'welcome_used' => false];
        }

        return [
            'exists' => true,
            'balance' => (int)($row['balance'] ?? 0),
            'welcome_used' => (bool)($row['welcome_used'] ?? false),
        ];
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

    /** GET /api/videos/library — List Backblaze-hosted recordings. */
    public function videoLibrary(): void
    {
        ApiAuth::require();

        if (!B2VideoStorageService::isConfigured()) {
            $this->json([
                'matches' => [],
                'storage' => 'backblaze_b2',
                'message' => 'Backblaze video storage is not configured.',
            ], 503);
            return;
        }

        try {
            $objects = (new B2VideoStorageService())->listVideos(500, '');
            $matchIds = array_values(array_unique(array_filter(array_map(
                static fn(array $video): int => (int)($video['match_id'] ?? 0),
                $objects
            ))));
            $matchesById = [];
            foreach ((new MatchService())->getByIds($matchIds) as $match) {
                $matchesById[(int)($match['id'] ?? 0)] = $match;
            }

            $videos = [];
            foreach ($objects as $object) {
                $matchId = (int)($object['match_id'] ?? 0);
                $match = $matchesById[$matchId] ?? [];
                $timestamp = (int)($object['modified_at'] ?? 0);
                $recordedAt = $timestamp > 0 ? gmdate('c', $timestamp) : null;
                $rawVideoUrl = (string)($object['url'] ?? '');
                $videoUrl = $rawVideoUrl;
                if ($videoUrl !== '' && $timestamp > 0) {
                    $videoUrl .= (str_contains($videoUrl, '?') ? '&' : '?') . 'v=' . $timestamp;
                }
                $videos[] = [
                    'id' => $matchId > 0 ? $matchId : ('b2-' . (string)($object['file_id'] ?? '')),
                    'match_id' => $matchId > 0 ? $matchId : null,
                    'recording_id' => (string)($object['file_id'] ?? ''),
                    'b2_source' => true,
                    'storage_source' => 'backblaze_b2',
                    'object_name' => (string)($object['object_name'] ?? ''),
                    'folder_name' => (string)($object['folder_name'] ?? ''),
                    'folder_path' => (string)($object['folder_path'] ?? ''),
                    'video_group_key' => (string)($object['video_group_key'] ?? ''),
                    'video_part_number' => isset($object['video_part_number']) ? (int)$object['video_part_number'] : null,
                    'camera_number' => isset($object['camera_number']) ? (int)$object['camera_number'] : null,
                    'file_name' => (string)($object['file_name'] ?? $object['name'] ?? 'Match video'),
                    'display_title' => $matchId > 0
                        ? trim((string)($match['challanger'] ?? 'Team A'))
                            . ' vs. '
                            . trim((string)($match['opponent'] ?? 'Team B'))
                        : (string)($object['recording_title'] ?? $object['folder_name'] ?? $object['name'] ?? 'Match video'),
                    'challanger' => $match['challanger'] ?? 'FiveStats',
                    'opponent' => $match['opponent'] ?? 'Video',
                    'date' => $match['date'] ?? $object['recording_date'] ?? ($timestamp > 0 ? gmdate('Y-m-d', $timestamp) : null),
                    'time' => $match['time'] ?? $object['recording_time'] ?? ($timestamp > 0 ? gmdate('H:i:s', $timestamp) : null),
                    'recording_at' => $recordedAt,
                    'location' => $match['location'] ?? $object['recording_location'] ?? 'FiveStats Cloud',
                    'video_url' => $videoUrl,
                    'storage_version' => $timestamp > 0 ? (string)$timestamp : null,
                    'thumbnail_url' => null,
                    'video_status' => $match['video_status'] ?? 'available',
                    'match_status' => $match['match_status'] ?? 'available',
                    'display_status' => 'available',
                    'size_bytes' => (int)($object['size_bytes'] ?? 0),
                    'jerseyStatsRows' => [],
                    'statsRows' => [],
                ];
            }

            $this->json([
                'matches' => $videos,
                'storage' => 'backblaze_b2',
            ]);
        } catch (\Throwable $e) {
            error_log('[b2-video-library] ' . $e->getMessage());
            $this->json([
                'error' => true,
                'message' => 'Videos could not be loaded from Backblaze right now.',
            ], 503);
        }
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

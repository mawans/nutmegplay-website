<?php
use App\Core\Router;
use App\Controllers\{
    LandingController,
    HomeController,
    EventsController,
    FixturesController,
    ChallengesController,
    InstructorController,
    MatchHistoryController,
    PlayersController,
    TeamController,
    VideoController,
    VideoAnalysisController,
    AuthController,
    AdminController,
    NotificationController,
    ProfileController,
    ApiController,
    WeeklyChallengesController
};

$router = new Router();

// ─── Auth routes ──────────────────────────────────────────
$router->get('/login', [AuthController::class, 'login']);
$router->get('/login-alt', [AuthController::class, 'loginAlt']);
$router->get('/register', [AuthController::class, 'register']);
$router->get('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('/reset-password', [AuthController::class, 'resetPassword']);
$router->post('/login', [AuthController::class, 'handleLogin']);
$router->post('/register', [AuthController::class, 'handleRegister']);
$router->post('/forgot-password', [AuthController::class, 'handleForgotPassword']);
$router->post('/reset-password', [AuthController::class, 'handleResetPassword']);
$router->get('/logout', [AuthController::class, 'logoutRedirect']);
$router->post('/logout', [AuthController::class, 'logout']);

// ─── Landing page (public) ────────────────────────────────
$router->get('/', [LandingController::class, 'index']);
$router->get('/landing', [LandingController::class, 'index']);

// ─── Dashboard routes (GET) ──────────────────────────────
$router->get('/dashboard', [HomeController::class, 'dashboard']);
$router->get('/matchmaking', [EventsController::class, 'matchmaking']);
$router->get('/fixtures', [FixturesController::class, 'upcoming']);
$router->get('/challenges', [ChallengesController::class, 'index']);
$router->get('/instructor', [InstructorController::class, 'index']);
$router->get('/match-history', [MatchHistoryController::class, 'index']);
$router->get('/players', [PlayersController::class, 'index']);
$router->get('/players/all', [PlayersController::class, 'all']);
$router->get('/teams', [TeamController::class, 'index']);
$router->get('/video/upload', [VideoController::class, 'upload']);
$router->get('/video-upload', [VideoController::class, 'upload']);
$router->get('/video-upload/progress', [VideoController::class, 'progress']);
$router->get('/video-upload/ai/status', [VideoController::class, 'aiStatus']);
$router->get('/video-analysis/stream', [VideoAnalysisController::class, 'stream']);

// ─── API / POST actions ──────────────────────────────────
$router->post('/matchmaking', [EventsController::class, 'createMatch']);
$router->post('/challenges', [ChallengesController::class, 'respond']);
$router->post('/teams', [TeamController::class, 'createClub']);
$router->post('/teams/member/remove', [TeamController::class, 'removeMember']);
$router->post('/teams/member/move', [TeamController::class, 'moveMember']);
$router->post('/players', [PlayersController::class, 'invite']);
$router->post('/video-upload/ai/start', [VideoController::class, 'startAi']);
$router->post('/video-upload/ai/migrate', [VideoController::class, 'migrateAi']);
$router->post('/video-upload/ai/redeploy', [VideoController::class, 'redeployAi']);
$router->post('/video-upload/ai/stop', [VideoController::class, 'stopAi']);
$router->post('/video-upload/ai/cancel', [VideoController::class, 'cancelAi']);
$router->post('/video-upload/ai/retry', [VideoAnalysisController::class, 'retry']);
$router->post('/video-analysis/callback', [VideoAnalysisController::class, 'callback']);
$router->post('/video-analysis/kick', [VideoAnalysisController::class, 'kick']);
$router->post('/video-upload/video/delete', [VideoController::class, 'deleteStoredVideo']);
$router->post('/video-upload/lineup', [VideoController::class, 'saveLineup']);
$router->post('/video-upload', [VideoController::class, 'handleUpload']);
$router->post('/profile', [ProfileController::class, 'update']);

// ─── Profile routes ───────────────────────────────────────
$router->get('/profile', [ProfileController::class, 'index']);

// ─── Notification routes ──────────────────────────────────
$router->get('/notifications', [NotificationController::class, 'index']);
$router->get('/notifications/api', [NotificationController::class, 'apiLatest']);
$router->post('/notifications/read', [NotificationController::class, 'markRead']);
$router->post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
$router->post('/notifications/delete', [NotificationController::class, 'deleteNotification']);

// ─── Admin routes ─────────────────────────────────────────
$router->get('/admin', [AdminController::class, 'index']);
$router->post('/admin/user/create', [AdminController::class, 'createUser']);
$router->post('/admin/user/role', [AdminController::class, 'updateUserRole']);
$router->post('/admin/user/delete', [AdminController::class, 'deleteUser']);
$router->post('/admin/match/approve', [AdminController::class, 'approveMatch']);
$router->post('/admin/match/location', [AdminController::class, 'assignLocation']);
$router->post('/admin/match/video', [AdminController::class, 'assignVideo']);
$router->post('/admin/announcement', [AdminController::class, 'createAnnouncement']);
$router->post('/admin/challenge/create', [AdminController::class, 'createChallenge']);
$router->post('/admin/challenge/delete', [AdminController::class, 'deleteChallenge']);
$router->post('/admin/challenge/toggle', [AdminController::class, 'toggleChallenge']);

// ─── Mobile App REST API (/api/*) ────────────────────────
// Auth
$router->post('/api/auth/login',       [ApiController::class, 'login']);
$router->post('/api/auth/register',    [ApiController::class, 'register']);

// Dashboard
$router->get('/api/dashboard',         [ApiController::class, 'dashboard']);

// Profile
$router->get('/api/profile',           [ApiController::class, 'profile']);
$router->post('/api/profile',          [ApiController::class, 'updateProfile']);

// Player Stats (real data for playercard)
$router->get('/api/player/stats',      [ApiController::class, 'playerStats']);

// Matches
$router->get('/api/matches',           [ApiController::class, 'matches']);
$router->get('/api/matches/pending',   [ApiController::class, 'matchesPending']);
$router->get('/api/matches/history',   [ApiController::class, 'matchHistory']);
$router->post('/api/matches/create',   [ApiController::class, 'createMatch']);
$router->post('/api/matches/respond',  [ApiController::class, 'respondMatch']);
$router->post('/api/matches/stats',    [ApiController::class, 'recordMatchStats']);
$router->post('/api/video-analysis/request', [ApiController::class, 'requestVideoAnalysis']);
$router->post('/api/video-analysis/kick',    [ApiController::class, 'kickVideoAnalysis']);
$router->post('/api/video-analysis/stop',    [ApiController::class, 'stopVideoAnalysis']);
$router->get('/api/video-analysis/status',   [ApiController::class, 'videoAnalysisStatus']);

// Clubs
$router->get('/api/clubs',             [ApiController::class, 'clubs']);
$router->get('/api/clubs/my',          [ApiController::class, 'myClub']);
$router->post('/api/clubs/create',     [ApiController::class, 'createClub']);

// Invites
$router->get('/api/invites',           [ApiController::class, 'invites']);
$router->post('/api/invites/send',     [ApiController::class, 'sendInvite']);
$router->post('/api/invites/respond',  [ApiController::class, 'respondInvite']);

// Announcements
$router->get('/api/announcements',     [ApiController::class, 'announcements']);
$router->post('/api/announcements',    [ApiController::class, 'createAnnouncement']);

// Challenges
$router->get('/api/challenges',        [ApiController::class, 'challenges']);

// Notifications
$router->get('/api/notifications',              [ApiController::class, 'notifications']);
$router->post('/api/notifications/read',        [ApiController::class, 'markNotificationRead']);
$router->post('/api/notifications/read-all',    [ApiController::class, 'markAllNotificationsRead']);

// Leaderboard & Players
$router->get('/api/leaderboard',       [ApiController::class, 'leaderboard']);
$router->get('/api/players/search',    [ApiController::class, 'searchPlayers']);

// Fixtures & Division
$router->get('/api/fixtures',          [ApiController::class, 'fixtures']);
$router->get('/api/division',          [ApiController::class, 'division']);

// ─── Weekly Challenges routes ────────────────────────────
$router->get('/weekly-challenges',                          [WeeklyChallengesController::class, 'index']);
$router->get('/weekly-challenges/:id',                      [WeeklyChallengesController::class, 'details']);
$router->post('/weekly-challenges/create',                  [WeeklyChallengesController::class, 'create']);
$router->post('/weekly-challenges/:id/enroll',              [WeeklyChallengesController::class, 'enroll']);
$router->get('/api/weekly-challenges/leaderboard/:id',      [WeeklyChallengesController::class, 'leaderboardJson']);
$router->get('/api/weekly-challenges/overall',              [WeeklyChallengesController::class, 'overallLeaderboardJson']);
$router->post('/api/weekly-challenges/archive-week',        [WeeklyChallengesController::class, 'archiveWeek']);

return $router;

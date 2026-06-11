<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\AccountService;
use App\Services\PlayerProgressService;
use App\Services\InviteService;
use App\Services\MatchStatsService;
use App\Services\MatchService;
use App\Services\MatchJerseyStatService;
use App\Services\NotificationService;

class PlayersController extends Controller
{
    /** GET /players */
    public function index(): void
    {
        Auth::requireAuth();

        $uid = Auth::uid();
        $accounts = new AccountService();
        $progress = new PlayerProgressService();
        $stats    = new MatchStatsService();
        $matches  = new MatchService();
        $jerseyStats = new MatchJerseyStatService();

        $account        = Auth::account();
        $isAdmin        = Auth::isAdmin();
        $clubAssign     = $account['club_assign'] ?? null;
        $clubAssign     = ($clubAssign !== null && trim((string)$clubAssign) !== '') ? trim((string)$clubAssign) : null;

        // Admins see latest registered players and instructors. Others see players from their own team.
        $players        = $isAdmin
            ? $accounts->listRecentByRoles(['player', 'instructor'], 10)
            : ($clubAssign ? $accounts->list($clubAssign, 50) : []);
        $leaderboard    = $progress->leaderboard(20);
        $playerProgress = $progress->getByUser($uid);
        $userTotals     = $stats->getUserTotals($uid);
        $aiTotals       = $jerseyStats->getUserTotals($uid);
        $recentMatches  = $matches->listByUser($uid, 5);

        // Resolve team name
        $teamName = null;
        if ($clubAssign) {
            $clubService = new \App\Services\ClubService();
            $club = $clubService->getById((int)$clubAssign);
            $teamName = $club['name'] ?? null;
        }

        $error          = Auth::getFlash('error');
        $success        = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/players.php', [
            'account'        => $account,
            'players'        => $players,
            'leaderboard'    => $leaderboard,
            'playerProgress' => $playerProgress,
            'userTotals'     => $userTotals,
            'aiTotals'       => $aiTotals,
            'recentMatches'  => $recentMatches,
            'teamName'       => $teamName,
            'hasTeam'        => $clubAssign !== null,
            'isAdmin'        => $isAdmin,
            'error'          => $error,
            'success'        => $success,
        ]);
    }

    /** GET /players/all – admin-only all players list */
    public function all(): void
    {
        Auth::requireAdmin();

        $accounts = new AccountService();
        $query = trim((string)($_GET['q'] ?? ''));
        $allPlayers = $accounts->listAllPlayers(300);

        $players = $allPlayers;
        if ($query !== '') {
            $needle = strtolower($query);
            $players = array_values(array_filter($allPlayers, static function (array $player) use ($needle): bool {
                $name = strtolower(trim((string)($player['fname'] ?? '') . ' ' . (string)($player['lname'] ?? '')));
                $email = strtolower((string)($player['email'] ?? ''));
                return str_contains($name, $needle) || str_contains($email, $needle);
            }));
        }

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/players-all.php', [
            'account' => Auth::account(),
            'players' => $players,
            'allPlayers' => $allPlayers,
            'query'   => $query,
            'error'   => Auth::getFlash('error'),
            'success' => Auth::getFlash('success'),
        ]);
    }

    /** POST /players – send invite to a player */
    public function invite(): void
    {
        Auth::requirePermission('players.invite', 'Only instructors and admins can send player invites.');
        $account = Auth::account();
        $fromUid = (string)($account['uid'] ?? '');

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /players');
            exit;
        }

        $sentTo = trim($_POST['sent_to'] ?? '');
        if ($sentTo && $fromUid && $sentTo !== $fromUid) {
            $accounts = new AccountService();
            if (!$accounts->getByUid($sentTo)) {
                Auth::flash('error', 'Selected player was not found.');
                header('Location: /players');
                exit;
            }

            $invites = new InviteService();
            $created = $invites->create([
                'club'    => json_encode(['id' => $account['club_assign'] ?? '', 'name' => '']),
                'sent_by' => $fromUid,
                'sent_to' => $sentTo,
                'status'  => 'pending',
            ]);
            if ($created) {
                try {
                    (new NotificationService())->send($sentTo, NotificationService::TYPE_INVITE_RECEIVED, 'Team Invite!', 'You have received a team invite. Check your teams page!', 'mail', '/teams');
                } catch (\Throwable $e) { /* ignore */ }
            }
            Auth::flash($created ? 'success' : 'error', $created ? 'Invite sent.' : 'Failed to send invite.');
        } else {
            Auth::flash('error', 'Please select a valid player.');
        }

        header('Location: /players');
        exit;
    }
}

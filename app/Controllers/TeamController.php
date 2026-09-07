<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\ClubService;
use App\Services\InviteService;
use App\Services\AccountService;
use App\Services\NotificationService;

class TeamController extends Controller
{
    /** GET /teams */
    public function index(): void
    {
        if (!Auth::check()) {
            (new PublicPageController())->teams();
            return;
        }

        Auth::requireAuth();

        $clubs    = new ClubService();
        $invites  = new InviteService();
        $accounts = new AccountService();
        $uid      = Auth::uid();
        $account  = Auth::account();

        $clubAssign   = $account['club_assign'] ?? null;
        $clubAssign   = ($clubAssign !== null && trim((string)$clubAssign) !== '') ? trim((string)$clubAssign) : null;

        // Get user's own club (by ownership OR by club_assign)
        $myClub       = $clubs->getByOwner($uid);
        if (!$myClub && $clubAssign) {
            $myClub = $clubs->getById((int)$clubAssign);
        }

        // Get team members (players assigned to the same club)
        $teamMembers  = $clubAssign ? $accounts->list($clubAssign, 50) : [];

        $myInvites    = $invites->listForUser($uid, 10);
        $isInstructor = Auth::isInstructor();
        $allClubs     = $isInstructor ? $clubs->list(50) : [];
        $allPlayers   = $isInstructor ? $accounts->list(null, 300) : [];
        $error        = Auth::getFlash('error');
        $success      = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/teams.php', [
            'account'     => $account,
            'clubs'       => $allClubs,
            'myClub'      => $myClub,
            'teamMembers' => $teamMembers,
            'myInvites'   => $myInvites,
            'isInstructor' => $isInstructor,
            'players'     => $allPlayers,
            'error'       => $error,
            'success'     => $success,
        ]);
    }

    /** POST /teams – create a new club */
    public function createClub(): void
    {
        Auth::requirePermission('teams.manage', 'Only instructors and admins can create teams.');
        $account = Auth::account();
        $ownerUid = (string)($account['uid'] ?? '');

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /teams');
            exit;
        }

        $data = [
            'name'          => trim($_POST['name'] ?? ''),
            'owner'         => $ownerUid,
            'level'         => 1,
            'ranking'       => 0,
            'number_player' => 1,
            'team_active'   => true,
        ];

        if ($data['name']) {
            $clubs = new ClubService();
            if ($ownerUid && $clubs->getByOwner($ownerUid)) {
                Auth::flash('error', 'You already own a club.');
            } else {
                $created = $clubs->create($data);
                if ($created) {
                    // Notify about team creation
                    try {
                        (new NotificationService())->send($ownerUid, NotificationService::TYPE_TEAM_CREATED, 'Team Created!', "Your team '{$data['name']}' has been created successfully.", 'shield', '/teams');
                    } catch (\Throwable $e) { /* ignore */ }
                    Auth::flash('success', 'Club created successfully.');
                } else {
                    Auth::flash('error', 'Could not create club. The name may already be taken.');
                }
            }
        } else {
            Auth::flash('error', 'Club name is required.');
        }

        header('Location: /teams');
        exit;
    }

    /** POST /teams/member/remove – instructor removes player from club */
    public function removeMember(): void
    {
        Auth::requirePermission('teams.manage', 'Only instructors and admins can manage teams.');

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /teams');
            exit;
        }

        $playerUid = trim($_POST['player_uid'] ?? '');
        if ($playerUid === '') {
            Auth::flash('error', 'Player could not be identified.');
            header('Location: /teams');
            exit;
        }

        $accounts = new AccountService();
        $player = $accounts->getByUid($playerUid);
        if (!$player) {
            Auth::flash('error', 'Player was not found.');
            header('Location: /teams');
            exit;
        }

        $updated = $accounts->updateByUid($playerUid, ['club_assign' => null]);
        Auth::flash($updated ? 'success' : 'error', $updated ? 'Player removed from team.' : 'Could not remove player from team.');
        header('Location: /teams');
        exit;
    }

    /** POST /teams/member/move – instructor moves player to another club */
    public function moveMember(): void
    {
        Auth::requirePermission('teams.manage', 'Only instructors and admins can manage teams.');

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /teams');
            exit;
        }

        $playerUid = trim($_POST['player_uid'] ?? '');
        $targetClubId = (int)($_POST['target_club_id'] ?? 0);

        if ($playerUid === '' || $targetClubId <= 0) {
            Auth::flash('error', 'Select a valid player and destination team.');
            header('Location: /teams');
            exit;
        }

        $accounts = new AccountService();
        $player = $accounts->getByUid($playerUid);
        if (!$player) {
            Auth::flash('error', 'Player was not found.');
            header('Location: /teams');
            exit;
        }

        $clubs = new ClubService();
        $targetClub = $clubs->getById($targetClubId);
        if (!$targetClub) {
            Auth::flash('error', 'Destination team was not found.');
            header('Location: /teams');
            exit;
        }

        $updated = $accounts->updateByUid($playerUid, ['club_assign' => (string)$targetClubId]);
        Auth::flash($updated ? 'success' : 'error', $updated ? 'Player moved successfully.' : 'Could not move player.');
        header('Location: /teams');
        exit;
    }
}

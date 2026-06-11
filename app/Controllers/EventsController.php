<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\MatchService;
use App\Services\MatchJerseySlotService;
use App\Services\ClubService;
use App\Services\AccountService;
use App\Services\AnnouncementService;
use App\Services\NotificationService;

class EventsController extends Controller
{
    /** GET /matchmaking */
    public function matchmaking(): void
    {
        Auth::requirePermission('matchmaking.manage', 'Only instructors and admins can schedule matches.');

        $clubs   = new ClubService();
        $matches = new MatchService();
        $accounts = new AccountService();
        $announcements = new AnnouncementService();
        $account = Auth::account();
        $uid     = Auth::uid();

        $allClubs           = $clubs->list(50);
        $allPlayers         = $accounts->list(null, 100);
        $isInstructor = Auth::isInstructor();
        $upcomingMatches    = $matches->listUpcoming(20);
        $pendingChallenges  = $matches->listPendingChallenges(20);
        $latestAnnouncements = $announcements->list(5);
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/matchmaking.php', [
            'account'           => $account,
            'clubs'             => $allClubs,
            'players'           => $allPlayers,
            'upcomingMatches'   => $upcomingMatches,
            'pendingChallenges' => $pendingChallenges,
            'announcements'     => $latestAnnouncements,
            'error'             => $error,
            'success'           => $success,
            'isInstructor'      => $isInstructor,
        ]);
    }

    /** POST /matchmaking – create a new match / challenge */
    public function createMatch(): void
    {
        Auth::requirePermission('matchmaking.manage', 'Only instructors and admins can schedule matches.');
        $account = Auth::account();
        $uid = (string)($account['uid'] ?? '');

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /matchmaking');
            exit;
        }

        $accounts = new AccountService();
        $challengerId = trim($_POST['challenger_id'] ?? $uid);
        $challenger = $accounts->getByUid($challengerId);
        $challangerName = trim((string)($challenger['fname'] ?? $challenger['username'] ?? ''));
        if ($challengerId === '' || $challangerName === '') {
            Auth::flash('error', 'Please select a valid challenger.');
            header('Location: /matchmaking');
            exit;
        }

        $opponentId   = trim($_POST['opponent_id'] ?? '');
        $opponentName = trim($_POST['opponent'] ?? '');

        // If opponent_id provided (from dropdown), look up their name
        if ($opponentId && !$opponentName) {
            $opp = $accounts->getByUid($opponentId);
            if ($opp) {
                $opponentName = $opp['fname'] ?? $opp['username'] ?? 'Opponent';
            }
        }

        // If only name provided (no uid), try to find them by name
        if ($opponentName && !$opponentId) {
            $results = $accounts->search($opponentName, 1);
            if (!empty($results)) {
                $opponentId = $results[0]['uid'] ?? '';
            }
        }

        if ($opponentId === '' || $opponentId === $challengerId) {
            Auth::flash('error', 'Please select a valid opponent.');
            header('Location: /matchmaking');
            exit;
        }

        $location = trim($_POST['location'] ?? '');
        if ($location === '') {
            Auth::flash('error', 'Location is required when scheduling a match.');
            header('Location: /matchmaking');
            exit;
        }

        $date = trim($_POST['date'] ?? '');
        $time = trim($_POST['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = date('H:i');
        }

        $data = [
            'challanger'      => $challangerName,
            'opponent'        => $opponentName,
            'challange_status'=> 'pending',
            'location'        => $location,
            'loc_codrinates'  => trim($_POST['loc_codrinates'] ?? ''),
            'date'            => $date,
            'time'            => $time,
            'match_status'    => 'pending',
            'challenger_id'   => $challengerId,
            'opponent_id'     => $opponentId,
        ];

        $matches = new MatchService();
        $created = $matches->create($data);
        if ($created) {
            $matchId = (int)($created['id'] ?? 0);
            if ($matchId > 0) {
                (new MatchJerseySlotService())->ensureDefaults($matchId);
            }
            // Notify the opponent about the challenge
            try {
                $ns = new NotificationService();
                $ns->send($opponentId, NotificationService::TYPE_MATCH_CREATED, 'New Match Challenge!', "{$challangerName} has challenged you to a match on {$date}.", 'sports_soccer', '/challenges');
                // Notify challenger too
                $ns->send($challengerId, NotificationService::TYPE_MATCH_CREATED, 'Challenge Sent', "Your challenge to {$opponentName} has been sent.", 'sports_soccer', '/matchmaking');
            } catch (\Throwable $e) { /* ignore */ }
            Auth::flash('success', 'Challenge sent successfully.');
        } else {
            Auth::flash('error', 'Could not create match challenge. Please try again.');
        }

        header('Location: /matchmaking');
        exit;
    }
}

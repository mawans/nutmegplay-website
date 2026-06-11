<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\MatchService;
use App\Services\PlayerProgressService;
use App\Services\MatchStatsService;
use App\Services\AccountService;
use App\Services\NotificationService;

class ChallengesController extends Controller
{
    /** GET /challenges */
    public function index(): void
    {
        Auth::requireAuth();

        $uid = Auth::uid();
        $matches  = new MatchService();
        $progress = new PlayerProgressService();
        $stats    = new MatchStatsService();

        // Admins can review all pending challenges. Everyone else only sees their own.
        $isInstructor = Auth::isInstructor();
        $pendingChallenges = Auth::isAdmin()
            ? $matches->listPendingChallenges(20)
            : $matches->listPendingForUser($uid, 20);
        $playerProgress    = $progress->getByUser($uid);
        $leaderboard       = $progress->leaderboard(10);
        $userTotals        = $stats->getUserTotals($uid);
        $account = Auth::account();
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');

        $accounts = new AccountService();
        $accountRows = $accounts->list(null, 200);
        $byUid = [];
        foreach ($accountRows as $row) {
            $accountUid = (string)($row['uid'] ?? '');
            if ($accountUid !== '') {
                $byUid[$accountUid] = $row;
            }
        }
        foreach ($leaderboard as &$entry) {
            $entryUid = (string)($entry['user_id'] ?? '');
            if ($entryUid !== '' && isset($byUid[$entryUid])) {
                $entry['fname'] = $byUid[$entryUid]['fname'] ?? '';
                $entry['lname'] = $byUid[$entryUid]['lname'] ?? '';
            }
            $entryTotals = $entryUid !== '' ? $stats->getUserTotals($entryUid) : [];
            $entry['total_goals'] = (int)($entryTotals['goals'] ?? 0);
        }
        unset($entry);

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/challenges.php', [
            'account'           => $account,
            'pendingChallenges' => $pendingChallenges,
            'playerProgress'    => $playerProgress,
            'leaderboard'       => $leaderboard,
            'userTotals'        => $userTotals,
            'error'             => $error,
            'success'           => $success,
            'isInstructor'      => $isInstructor,
        ]);
    }

    /** POST /challenges – accept or decline */
    public function respond(): void
    {
        Auth::requireAuth();
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /challenges');
            exit;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        $action  = $_POST['action'] ?? '';

        if ($matchId) {
            $matches = new MatchService();
            $match = $matches->getById($matchId);

            if (!$match) {
                Auth::flash('error', 'Challenge could not be found.');
                header('Location: /challenges');
                exit;
            }

            if (($match['challange_status'] ?? '') !== 'pending') {
                Auth::flash('error', 'This challenge has already been processed.');
                header('Location: /challenges');
                exit;
            }

            $uid = Auth::uid();
            $isAdmin = Auth::isAdmin();
            $isOpponent = $uid !== '' && $uid === (string)($match['opponent_id'] ?? '');
            $isChallenger = $uid !== '' && $uid === (string)($match['challenger_id'] ?? '');

            if ($action === 'accept') {
                if (!$isAdmin && !$isOpponent) {
                    Auth::flash('error', 'Only the challenged player or an admin can accept this challenge.');
                    header('Location: /challenges');
                    exit;
                }

                $matches->acceptChallenge($matchId);
                // Notify both players
                try {
                    $ns = new NotificationService();
                    foreach (array_filter([(string)($match['challenger_id'] ?? ''), (string)($match['opponent_id'] ?? '')]) as $pid) {
                        $ns->send($pid, NotificationService::TYPE_CHALLENGE_ACCEPTED, 'Challenge Accepted!', 'A match challenge has been accepted.', 'check_circle', '/fixtures');
                    }
                } catch (\Throwable $e) { /* ignore */ }
                Auth::flash('success', 'Challenge accepted.');
            } elseif ($action === 'decline') {
                if (!$isAdmin && !$isOpponent && !$isChallenger) {
                    Auth::flash('error', 'Only the match participants or an admin can decline this challenge.');
                    header('Location: /challenges');
                    exit;
                }

                $matches->declineChallenge($matchId);
                // Notify challenger
                try {
                    $cid = (string)($match['challenger_id'] ?? '');
                    if ($cid) {
                        (new NotificationService())->send($cid, NotificationService::TYPE_CHALLENGE_DECLINED, 'Challenge Declined', 'Your match challenge was declined.', 'cancel', '/matchmaking');
                    }
                } catch (\Throwable $e) { /* ignore */ }
                Auth::flash('success', 'Challenge declined.');
            } else {
                Auth::flash('error', 'Invalid challenge action.');
            }
        } else {
            Auth::flash('error', 'Challenge could not be found.');
        }

        // Redirect back to the page that submitted the form
        $referer = $_SERVER['HTTP_REFERER'] ?? '/challenges';
        $path = parse_url($referer, PHP_URL_PATH) ?? '/challenges';
        if (!str_starts_with($path, '/')) {
            $path = '/challenges';
        }
        header('Location: ' . $path);
        exit;
    }
}

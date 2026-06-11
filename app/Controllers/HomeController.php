<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\AccountService;
use App\Services\MatchService;
use App\Services\ClubService;
use App\Services\PlayerProgressService;
use App\Services\MatchStatsService;
use App\Services\AnnouncementService;

class HomeController extends Controller
{
    public function dashboard(): void
    {
        Auth::requireAuth();

        $uid = Auth::uid();
        $accounts  = new AccountService();
        $matches   = new MatchService();
        $clubs     = new ClubService();
        $progress  = new PlayerProgressService();
        $stats     = new MatchStatsService();

        $announcements = new AnnouncementService();

        $totalPlayers  = $accounts->count();
        $totalMatches  = $matches->count();
        $totalClubs    = $clubs->count();
        $completedMatches = $matches->countCompleted();
        $latestAnnouncements = $announcements->list(5);

        $playerProgress = $progress->getByUser($uid);
        $userTotals     = $stats->getUserTotals($uid);
        $totalXpEarned  = 0;
        if (is_array($playerProgress)) {
            $totalXpEarned = max(
                0,
                (((int)($playerProgress['ovr'] ?? 50)) - 50) * 100 + (int)($playerProgress['current_xp'] ?? 0)
            );
        }
        $recentMatches  = $matches->listByUser($uid, 5);
        $upcomingMatches = $matches->listUpcomingByUser($uid, 5);
        $account = Auth::account();
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/home.php', [
            'account'          => $account,
            'totalPlayers'     => $totalPlayers,
            'totalMatches'     => $totalMatches,
            'totalClubs'       => $totalClubs,
            'completedMatches' => $completedMatches,
            'playerProgress'   => $playerProgress,
            'userTotals'       => $userTotals,
            'totalXpEarned'    => $totalXpEarned,
            'recentMatches'    => $recentMatches,
            'upcomingMatches'  => $upcomingMatches,
            'announcements'    => $latestAnnouncements,
            'error'            => $error,
            'success'          => $success,
        ]);
    }
}

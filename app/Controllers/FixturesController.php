<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\MatchService;

class FixturesController extends Controller
{
    public function upcoming(): void
    {
        Auth::requireAuth();

        $uid     = Auth::uid();
        $matches = new MatchService();
        $account = Auth::account();

        // Fetch user-specific upcoming matches, with fallback to all upcoming
        $upcomingMatches = $uid
            ? $matches->listUpcomingByUser($uid, 20)
            : $matches->listUpcoming(20);

        // Also fetch user's completed matches for reference
        $completedMatches = $uid
            ? $matches->listCompletedByUser($uid, 10)
            : $matches->listCompleted(10);

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/fixtures.php', [
            'account'          => $account,
            'upcomingMatches'  => $upcomingMatches,
            'completedMatches' => $completedMatches,
        ]);
    }
}

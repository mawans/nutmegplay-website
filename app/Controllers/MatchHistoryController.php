<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\MatchService;
use App\Services\MatchStatsService;

class MatchHistoryController extends Controller
{
    public function index(): void
    {
        Auth::requireAuth();

        $uid = Auth::uid();
        $matches = new MatchService();
        $stats   = new MatchStatsService();
        $account = Auth::account();
        $role = strtolower((string)($account['role'] ?? 'player'));

        // Players see only their own match history.
        // Elevated roles can still view global history.
        $userMatches = $role === 'player'
            ? $matches->listByUser($uid, 40)
            : $matches->list(40);
        $userTotals  = $stats->getUserTotals($uid);

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/match-history.php', [
            'account'     => $account,
            'userMatches' => $userMatches,
            'userTotals'  => $userTotals,
        ]);
    }
}

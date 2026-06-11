<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\PlayerProgressService;
use App\Services\MatchStatsService;
use App\Services\XpHistoryService;
use App\Services\MatchService;
use App\Services\AccountService;
use App\Services\ClubService;

class InstructorController extends Controller
{
    public function index(): void
    {
        Auth::requireInstructor();

        $uid = Auth::uid();
        $progress    = new PlayerProgressService();
        $statsService = new MatchStatsService();
        $xpService   = new XpHistoryService();
        $matchService = new MatchService();
        $accountService = new AccountService();
        $clubService = new ClubService();

        $playerProgress = $progress->getByUser($uid);
        $userTotals     = $statsService->getUserTotals($uid);
        $xpHistory      = $xpService->getByUser($uid, 10);
        $recentMatches  = $matchService->list(12);
        $account        = Auth::account();

        $allMatches = $matchService->list(1000);
        $totalMatches = count($allMatches);
        $completed = 0;
        $accepted = 0;
        $declined = 0;
        foreach ($allMatches as $m) {
            $matchStatus = (string)($m['match_status'] ?? '');
            $challengeStatus = (string)($m['challange_status'] ?? '');
            if ($matchStatus === 'completed') {
                $completed++;
            }
            if ($challengeStatus === 'accepted') {
                $accepted++;
            } elseif ($challengeStatus === 'declined') {
                $declined++;
            }
        }

        // Local report model values
        $revenuePerCompletedMatch = 1200.0;
        $payoutPerCompletedMatch = 450.0;
        $opsCostPerMatch = 150.0;

        $grossRevenue = $completed * $revenuePerCompletedMatch;
        $paidOut = $completed * $payoutPerCompletedMatch;
        $operationalCosts = $totalMatches * $opsCostPerMatch;
        $netRevenue = $grossRevenue - $paidOut - $operationalCosts;
        $pendingCollection = $accepted * $revenuePerCompletedMatch;

        $reports = [
            'total_matches' => $totalMatches,
            'completed_matches' => $completed,
            'accepted_matches' => $accepted,
            'declined_matches' => $declined,
            'players_total' => $accountService->countByRole('player'),
            'instructors_total' => $accountService->countByRole('instructor'),
            'teams_total' => $clubService->count(),
            'gross_revenue' => $grossRevenue,
            'paid_out' => $paidOut,
            'operational_costs' => $operationalCosts,
            'net_revenue' => $netRevenue,
            'pending_collection' => $pendingCollection,
        ];

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/instructor.php', [
            'account'        => $account,
            'playerProgress' => $playerProgress,
            'userTotals'     => $userTotals,
            'xpHistory'      => $xpHistory,
            'recentMatches'  => $recentMatches,
            'reports'        => $reports,
        ]);
    }
}

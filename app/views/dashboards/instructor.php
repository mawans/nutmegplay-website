<?php
$pageTitle = 'Instructor Dashboard';
$pageHeading = 'Instructor Dashboard';
$pageDescription = 'Manage training sessions and player analytics';
$currentPage = 'instructor';
$playerProgress = $playerProgress ?? [];
$userTotals = $userTotals ?? [];
$xpHistory = $xpHistory ?? [];
$reports = $reports ?? [];
$recentMatches = $recentMatches ?? [];
$account = $account ?? [];
$instructorLevel = (int)($playerProgress['level'] ?? max(1, ((int)($playerProgress['ovr'] ?? 50)) - 49));

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
<?php require_once BASE_PATH . '/includes/topnav.php'; ?>

<div id="main-content" class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">

<div class="p-4 md:p-6 lg:p-8 w-full flex-1">
<div class="w-full">

<!-- Instructor Reports -->
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">monitoring</span> Operations & Financial Reports</h3>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    <div class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-xs uppercase text-muted font-semibold">Revenue</p>
        <p class="text-xl font-bold text-primary">$<?= number_format((float)($reports['gross_revenue'] ?? 0), 2) ?></p>
    </div>
    <div class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-xs uppercase text-muted font-semibold">Paid</p>
        <p class="text-xl font-bold text-warning">$<?= number_format((float)($reports['paid_out'] ?? 0), 2) ?></p>
    </div>
    <div class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-xs uppercase text-muted font-semibold">Costs</p>
        <p class="text-xl font-bold">$<?= number_format((float)($reports['operational_costs'] ?? 0), 2) ?></p>
    </div>
    <div class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-xs uppercase text-muted font-semibold">Net Revenue</p>
        <p class="text-xl font-bold <?= ((float)($reports['net_revenue'] ?? 0)) >= 0 ? 'text-primary' : 'text-accent-danger' ?>">
            $<?= number_format((float)($reports['net_revenue'] ?? 0), 2) ?>
        </p>
    </div>
</div>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
    <div class="p-3 rounded-lg border border-slate-200 dark:border-[#264531]">
        <p class="text-xs text-muted uppercase">Matches</p>
        <p class="text-lg font-bold"><?= (int)($reports['total_matches'] ?? 0) ?></p>
    </div>
    <div class="p-3 rounded-lg border border-slate-200 dark:border-[#264531]">
        <p class="text-xs text-muted uppercase">Completed</p>
        <p class="text-lg font-bold"><?= (int)($reports['completed_matches'] ?? 0) ?></p>
    </div>
    <div class="p-3 rounded-lg border border-slate-200 dark:border-[#264531]">
        <p class="text-xs text-muted uppercase">Players</p>
        <p class="text-lg font-bold"><?= (int)($reports['players_total'] ?? 0) ?></p>
    </div>
    <div class="p-3 rounded-lg border border-slate-200 dark:border-[#264531]">
        <p class="text-xs text-muted uppercase">Teams</p>
        <p class="text-lg font-bold"><?= (int)($reports['teams_total'] ?? 0) ?></p>
    </div>
    <div class="p-3 rounded-lg border border-slate-200 dark:border-[#264531]">
        <p class="text-xs text-muted uppercase">Pending Collection</p>
        <p class="text-lg font-bold">$<?= number_format((float)($reports['pending_collection'] ?? 0), 2) ?></p>
    </div>
</div>
</div>

<?php if (!empty($recentMatches)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">history</span> Recent Matches (Everyone)</h3>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead>
<tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
    <th class="p-3">Match</th>
    <th class="p-3">Date</th>
    <th class="p-3">Location</th>
    <th class="p-3">Challenge</th>
    <th class="p-3">Status</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
<?php foreach ($recentMatches as $m): ?>
<tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
    <td class="p-3 font-medium"><?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?></td>
    <td class="p-3 text-xs font-mono"><?= htmlspecialchars((string)($m['date'] ?? '')) ?></td>
    <td class="p-3"><?= htmlspecialchars((string)($m['location'] ?? '—')) ?></td>
    <td class="p-3"><?= htmlspecialchars(ucfirst((string)($m['challange_status'] ?? 'pending'))) ?></td>
    <td class="p-3"><?= htmlspecialchars(ucfirst((string)($m['match_status'] ?? 'pending'))) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<!-- Player Progress from DB -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
<div class="bg-white dark:bg-card-dark rounded-xl p-5 border border-slate-200 dark:border-[#264531]">
    <p class="text-xs text-muted uppercase font-semibold mb-1">OVR Rating</p>
    <p class="text-3xl font-bold text-primary"><?= (int)($playerProgress['ovr'] ?? 50) ?></p>
    <p class="text-xs text-muted mt-1">Out of 99</p>
</div>
<div class="bg-white dark:bg-card-dark rounded-xl p-5 border border-slate-200 dark:border-[#264531]">
    <p class="text-xs text-muted uppercase font-semibold mb-1">Current XP</p>
    <p class="text-3xl font-bold"><?= (int)($playerProgress['current_xp'] ?? 0) ?></p>
    <div class="w-full bg-slate-200 dark:bg-black rounded-full h-1.5 mt-2">
        <div class="bg-primary h-1.5 rounded-full" style="width: <?= min(100, (int)($playerProgress['current_xp'] ?? 0)) ?>%"></div>
    </div>
</div>
<div class="bg-white dark:bg-card-dark rounded-xl p-5 border border-slate-200 dark:border-[#264531]">
    <p class="text-xs text-muted uppercase font-semibold mb-1">Total Goals</p>
    <p class="text-3xl font-bold"><?= (int)($userTotals['goals'] ?? 0) ?></p>
</div>
<div class="bg-white dark:bg-card-dark rounded-xl p-5 border border-slate-200 dark:border-[#264531]">
    <p class="text-xs text-muted uppercase font-semibold mb-1">Win Rate</p>
    <p class="text-3xl font-bold text-primary">
        <?php
        $totalGames = (int)($userTotals['matches'] ?? 0);
        $wins = (int)($userTotals['wins'] ?? 0);
        echo $totalGames > 0 ? round(($wins / $totalGames) * 100) : 0;
        ?>%
    </p>
</div>
</div>

<!-- XP History Log -->
<?php if (!empty($xpHistory)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">timeline</span> Recent XP Gains</h3>
<div class="space-y-2">
<?php foreach ($xpHistory as $xp): ?>
<div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-[#16261d]">
    <div>
        <p class="text-sm font-medium">+<?= (int)($xp['xp_gained'] ?? 0) ?> XP</p>
        <p class="text-xs text-muted"><?= htmlspecialchars($xp['reason'] ?? 'Match completion') ?></p>
    </div>
    <span class="text-xs text-muted font-mono"><?= htmlspecialchars(substr($xp['created_at'] ?? '', 0, 10)) ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- Status Badge -->
<div class="flex gap-2 mb-6">
<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20 hover:bg-primary/20 hover:shadow-lg transition-all duration-200 cursor-pointer">
<span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span> System Online
</span>
</div>

<!-- Main Grid -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<div class="lg:col-span-8 flex flex-col gap-6">

<!-- Player Stats Overview -->
<section class="bg-white dark:bg-surface-dark rounded-xl p-5 shadow-sm border border-slate-200 dark:border-[#264531]">
<h3 class="text-xl font-bold flex items-center gap-2 mb-4">
<span class="material-symbols-outlined text-primary">analytics</span> Player Stats Overview
</h3>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="text-center p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-2xl font-bold text-primary"><?= $instructorLevel ?></p>
        <p class="text-xs text-muted mt-1">Current Level</p>
    </div>
    <div class="text-center p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-2xl font-bold"><?= (int)($userTotals['matches'] ?? 0) ?></p>
        <p class="text-xs text-muted mt-1">Total Matches</p>
    </div>
    <div class="text-center p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-2xl font-bold"><?= (int)($userTotals['goals'] ?? 0) ?></p>
        <p class="text-xs text-muted mt-1">Goals Scored</p>
    </div>
    <div class="text-center p-4 rounded-lg bg-slate-50 dark:bg-[#16261d]">
        <p class="text-2xl font-bold"><?= (int)($userTotals['assists'] ?? 0) ?></p>
        <p class="text-xs text-muted mt-1">Assists</p>
    </div>
</div>
</section>

</div>
<div class="lg:col-span-4 flex flex-col gap-6">

<!-- Quick Links -->
<section class="bg-white dark:bg-surface-dark rounded-xl p-5 shadow-sm border border-slate-200 dark:border-[#264531]">
<h3 class="text-xl font-bold flex items-center gap-2 mb-4">
<span class="material-symbols-outlined text-primary">link</span> Quick Actions
</h3>
<div class="space-y-2">
    <a href="/matchmaking" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
        <span class="material-symbols-outlined text-primary">add_circle</span>
        <span class="text-sm font-medium">Create New Match</span>
    </a>
    <a href="/teams" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
        <span class="material-symbols-outlined text-primary">manage_accounts</span>
        <span class="text-sm font-medium">Manage Team Members</span>
    </a>
    <a href="/players" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
        <span class="material-symbols-outlined text-primary">person_search</span>
        <span class="text-sm font-medium">View Players</span>
    </a>
    <a href="/video-upload" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
        <span class="material-symbols-outlined text-primary">cloud_upload</span>
        <span class="text-sm font-medium">Upload Video</span>
    </a>
    <a href="/match-history" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
        <span class="material-symbols-outlined text-primary">history</span>
        <span class="text-sm font-medium">Match History</span>
    </a>
</div>
</section>

<!-- Account Info -->
<section class="bg-white dark:bg-surface-dark rounded-xl p-5 shadow-sm border border-slate-200 dark:border-[#264531]">
<h3 class="text-xl font-bold flex items-center gap-2 mb-4">
<span class="material-symbols-outlined text-primary">person</span> Account
</h3>
<div class="space-y-3 text-sm">
    <div class="flex justify-between p-2 rounded bg-slate-50 dark:bg-[#16261d]">
        <span class="text-muted">Username</span>
        <span class="font-medium"><?= htmlspecialchars($account['username'] ?? '—') ?></span>
    </div>
    <div class="flex justify-between p-2 rounded bg-slate-50 dark:bg-[#16261d]">
        <span class="text-muted">Position</span>
        <span class="font-medium"><?= htmlspecialchars($account['position'] ?? '—') ?></span>
    </div>
    <div class="flex justify-between p-2 rounded bg-slate-50 dark:bg-[#16261d]">
        <span class="text-muted">Role</span>
        <span class="font-medium"><?= htmlspecialchars($account['role'] ?? 'player') ?></span>
    </div>
</div>
</section>

</div>
</div>
</div>
</div>
</div>
</div>

</div>
</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

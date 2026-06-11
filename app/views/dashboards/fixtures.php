<?php
$pageTitle = 'Upcoming Fixtures';
$pageHeading = 'Upcoming Fixtures';
$pageDescription = 'Manage matches, camera links, and team rosters';
$currentPage = 'fixtures';
$upcomingMatches = $upcomingMatches ?? [];
$completedMatches = $completedMatches ?? [];
$account = $account ?? [];
$canManageMatchmaking = \App\Core\Auth::can('matchmaking.manage');

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
<?php require_once BASE_PATH . '/includes/topnav.php'; ?>

<div id="main-content" class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">

<div class="p-4 md:p-6 lg:p-8 w-full flex-1">
<div class="w-full">

<!-- Dynamic Upcoming Fixtures from DB -->
<?php if (!empty($upcomingMatches)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">calendar_today</span> Your Upcoming Fixtures</h3>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead>
<tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
    <th class="p-3">Match</th>
    <th class="p-3">Date / Time</th>
    <th class="p-3">Location</th>
    <th class="p-3">Status</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
<?php foreach ($upcomingMatches as $m): ?>
<tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
    <td class="p-3 font-medium"><?= htmlspecialchars($m['challanger'] ?? 'TBD') ?> <span class="text-muted">vs</span> <?= htmlspecialchars($m['opponent'] ?? 'TBD') ?></td>
    <td class="p-3 font-mono text-xs"><?= htmlspecialchars($m['date'] ?? '') ?> <?= htmlspecialchars($m['time'] ?? '') ?></td>
    <td class="p-3"><?= htmlspecialchars($m['location'] ?? '—') ?></td>
    <td class="p-3">
        <span class="px-2 py-1 rounded text-xs font-bold <?= ($m['challange_status'] ?? '') === 'accepted' ? 'bg-primary/20 text-primary' : 'bg-warning/20 text-warning' ?>">
            <?= ucfirst($m['challange_status'] ?? 'pending') ?>
        </span>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-8 text-center border border-slate-200 dark:border-[#264531] mb-6">
<span class="material-symbols-outlined text-5xl text-muted mb-2">event_busy</span>
<?php if ($canManageMatchmaking): ?>
<p class="text-muted">No upcoming fixtures. <a href="/matchmaking" class="text-primary hover:underline">Create a match</a> to get started.</p>
<?php else: ?>
<p class="text-muted">No upcoming fixtures yet. Instructors schedule matches and set locations.</p>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- Match Detail Cards -->
<?php if (!empty($upcomingMatches)): ?>
<div class="grid grid-cols-1 xl:grid-cols-2 2xl:grid-cols-3 gap-6 pb-12">
<?php foreach ($upcomingMatches as $idx => $fixture): ?>
<article class="bg-white dark:bg-surface-dark border border-slate-200 dark:border-white/5 rounded-2xl overflow-hidden shadow-xl flex flex-col group hover:border-primary/30 transition-all duration-300">
<div class="p-5 border-b border-slate-200 dark:border-white/5 bg-gradient-to-r from-slate-50 dark:from-surface-dark to-white/5 relative">
<div class="flex justify-between items-start mb-3">
<div class="flex flex-col">
<span class="text-xs font-bold uppercase tracking-wider text-muted mb-1">Match <?= $idx + 1 ?></span>
<h3 class="text-xl font-bold"><?= htmlspecialchars($fixture['challanger'] ?? 'TBD') ?> vs <?= htmlspecialchars($fixture['opponent'] ?? 'TBD') ?></h3>
</div>
<span class="px-3 py-1.5 rounded-lg text-xs font-bold <?= ($fixture['challange_status'] ?? '') === 'accepted' ? 'bg-primary/10 text-primary' : 'bg-warning/10 text-warning' ?>">
<?= ucfirst($fixture['challange_status'] ?? 'pending') ?>
</span>
</div>
</div>
<div class="p-5 flex-1">
<div class="grid grid-cols-2 gap-4 mb-4">
<div class="bg-slate-50 dark:bg-[#16261d] p-3 rounded-lg">
<p class="text-xs text-muted uppercase font-bold mb-1">Date</p>
<p class="text-sm font-medium"><?= htmlspecialchars($fixture['date'] ?? 'TBD') ?></p>
</div>
<div class="bg-slate-50 dark:bg-[#16261d] p-3 rounded-lg">
<p class="text-xs text-muted uppercase font-bold mb-1">Time</p>
<p class="text-sm font-medium"><?= htmlspecialchars($fixture['time'] ?? 'TBD') ?></p>
</div>
</div>
<div class="bg-slate-50 dark:bg-[#16261d] p-3 rounded-lg">
<p class="text-xs text-muted uppercase font-bold mb-1">Location</p>
<p class="text-sm font-medium"><?= htmlspecialchars($fixture['location'] ?? 'Not specified') ?></p>
</div>
</div>
</article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-12 text-center border border-slate-200 dark:border-[#264531]">
<span class="material-symbols-outlined text-5xl text-muted mb-3 block">sports_soccer</span>
<h3 class="text-lg font-bold mb-2">No Fixtures Scheduled</h3>
<?php if ($canManageMatchmaking): ?>
<p class="text-muted text-sm mb-4">Head to the matchmaking page to schedule and review challenges.</p>
<a href="/matchmaking" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-black font-bold rounded-lg hover:bg-green-400 transition-colors">
<span class="material-symbols-outlined text-sm">add_circle</span> Create Match
</a>
<?php else: ?>
<p class="text-muted text-sm mb-4">Your instructor will publish recent fixtures for everyone.</p>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- Completed Matches -->
<?php if (!empty($completedMatches)): ?>
<div class="mt-8">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">check_circle</span> Recent Completed Matches</h3>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead>
<tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
    <th class="p-3">Match</th>
    <th class="p-3">Date</th>
    <th class="p-3">Result</th>
    <th class="p-3">Location</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
<?php foreach ($completedMatches as $cm): ?>
<tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
    <td class="p-3 font-medium"><?= htmlspecialchars($cm['challanger'] ?? 'TBD') ?> <span class="text-muted">vs</span> <?= htmlspecialchars($cm['opponent'] ?? 'TBD') ?></td>
    <td class="p-3 font-mono text-xs"><?= htmlspecialchars($cm['date'] ?? '') ?></td>
    <td class="p-3 font-bold"><?= htmlspecialchars($cm['result'] ?? '—') ?></td>
    <td class="p-3 text-muted"><?= htmlspecialchars($cm['location'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>
<?php endif; ?>
</div>
</div>

</div>
</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

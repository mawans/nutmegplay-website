<?php
$pageTitle = 'Players';
$pageHeading = 'Players Dashboard';
$pageDescription = 'Manage your player stats and season progress';
$currentPage = 'players';
$players = $players ?? [];
$leaderboard = $leaderboard ?? [];
$account = $account ?? [];
$playerProgress = $playerProgress ?? [];
$userTotals = $userTotals ?? [];
$aiTotals = $aiTotals ?? [];
$recentMatches = $recentMatches ?? [];
$teamName = $teamName ?? null;
$hasTeam = $hasTeam ?? false;
$isAdmin = $isAdmin ?? false;
$error = $error ?? '';
$success = $success ?? '';
$csrfToken = \App\Core\Auth::csrfToken();
$canInvitePlayers = \App\Core\Auth::can('players.invite');

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
<?php require_once BASE_PATH . '/includes/topnav.php'; ?>

<div id="main-content" class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">

<div class="p-4 md:p-6 lg:p-8 w-full flex-1">
<div class="w-full">

<?php if ($error): ?>
<div class="bg-accent-danger/10 border border-accent-danger/30 text-accent-danger rounded-xl p-4 mb-6 flex items-center gap-3">
<span class="material-symbols-outlined">error</span>
<p class="text-sm font-medium"><?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="bg-primary/10 border border-primary/30 text-primary rounded-xl p-4 mb-6 flex items-center gap-3">
<span class="material-symbols-outlined">check_circle</span>
<p class="text-sm font-medium"><?= htmlspecialchars($success) ?></p>
</div>
<?php endif; ?>

<!-- Dynamic Player Stats from DB -->
<?php
$_ut = $userTotals ?? ['matches' => 0, 'goals' => 0, 'assists' => 0, 'wins' => 0];
// Fetch user totals if available through players controller leaderboard
if (empty($userTotals)) {
    $_ut = ['matches' => count($players), 'goals' => 0, 'assists' => 0, 'wins' => 0];
}
?>

<!-- All Players from DB -->
<?php if (!$hasTeam && !$isAdmin): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-12 text-center border border-slate-200 dark:border-[#264531] mb-6">
    <span class="material-symbols-outlined text-5xl text-muted mb-3 block">group_off</span>
    <h3 class="text-lg font-bold mb-2">No Team Assigned</h3>
    <p class="text-muted text-sm">You are not assigned to any team yet. Join a team to see your teammates here.</p>
    <a href="/teams" class="inline-block mt-4 px-6 py-2 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors text-sm">Go to Teams</a>
</div>
<?php elseif (!empty($players)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<div class="flex items-start justify-between gap-3 mb-4">
    <h3 class="text-lg font-bold flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">groups</span>
        <?php if ($isAdmin): ?>
            10 Recent Players & Instructors
        <?php else: ?>
            <?= $teamName ? htmlspecialchars($teamName) . ' — ' : '' ?>Team Players (<?= count($players) ?>)
        <?php endif; ?>
    </h3>
    <?php if ($isAdmin): ?>
    <a href="/players/all" class="inline-flex items-center gap-1 px-3 py-1.5 bg-primary/10 text-primary hover:bg-primary hover:text-white text-xs font-bold rounded-lg transition-colors">
        <span class="material-symbols-outlined" style="font-size:16px">manage_search</span>
        View all players
    </a>
    <?php endif; ?>
</div>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead>
<tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
    <th class="p-3">#</th>
    <th class="p-3">Name</th>
    <th class="p-3">Role</th>
    <th class="p-3">Position</th>
    <th class="p-3">Country</th>
    <th class="p-3">Level</th>
    <th class="p-3">Action</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
<?php foreach ($players as $i => $p): ?>
<tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
    <td class="p-3 text-muted"><?= $i + 1 ?></td>
    <td class="p-3 font-medium flex items-center gap-2">
        <div class="size-8 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold shrink-0">
            <?= strtoupper(substr($p['fname'] ?? '?', 0, 1)) ?>
        </div>
        <?= htmlspecialchars($p['fname'] ?? 'Unknown') ?>
    </td>
    <td class="p-3">
        <span class="px-2 py-0.5 rounded bg-primary/10 text-primary text-xs font-bold"><?= htmlspecialchars(ucfirst((string)($p['role'] ?? 'player'))) ?></span>
    </td>
    <td class="p-3"><?= htmlspecialchars($p['position'] ?? '—') ?></td>
    <td class="p-3"><?= htmlspecialchars($p['country'] ?? '—') ?></td>
    <td class="p-3"><span class="px-2 py-0.5 rounded bg-primary/10 text-primary text-xs font-bold">Lv <?= (int)($p['level'] ?? 1) ?></span></td>
    <td class="p-3">
        <?php if ($isAdmin): ?>
        <span class="text-xs text-muted">Admin view</span>
        <?php elseif ($canInvitePlayers && (($p['uid'] ?? '') !== ($account['uid'] ?? ''))): ?>
        <form method="POST" action="/players" class="inline" hx-boost="false">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="sent_to" value="<?= htmlspecialchars($p['uid'] ?? '') ?>">
            <button type="submit" class="text-xs text-primary hover:underline font-bold">Invite</button>
        </form>
        <?php elseif (($p['uid'] ?? '') !== ($account['uid'] ?? '')): ?>
        <span class="text-xs text-muted">Instructor only</span>
        <?php else: ?>
        <span class="text-xs text-muted">You</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($isAdmin): ?>
<p class="text-xs text-muted mt-3">Showing the latest 10 player and instructor registrations. Use "View all players" for the full directory and search.</p>
<?php endif; ?>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-12 text-center border border-slate-200 dark:border-[#264531] mb-6">
    <span class="material-symbols-outlined text-5xl text-muted mb-3 block">groups</span>
    <h3 class="text-lg font-bold mb-2"><?= $isAdmin ? 'No players found' : ($teamName ? htmlspecialchars($teamName) : 'Your Team') ?></h3>
    <p class="text-muted text-sm"><?= $isAdmin ? 'No recent players are available right now.' : ($canInvitePlayers ? 'No other players on your team yet. Invite players from the Teams page.' : 'No other players on your team yet. Ask an instructor to add teammates.') ?></p>
</div>
<?php endif; ?>
<section class="grid grid-cols-2 gap-4 md:grid-cols-4 mb-6">
<div class="flex flex-col items-start gap-2 rounded-xl border border-slate-200 bg-white p-4 md:p-5 shadow-sm hover:shadow-md transition-shadow dark:border-white/10 dark:bg-surface-dark hover:border-primary/30 dark:hover:border-primary/30">
<div class="flex items-center gap-2">
<img src="/public/assets/fivestats-logo.png" alt="" class="size-5 rounded-md">
<span class="text-sm font-medium text-muted">Matches</span>
</div>
<p class="text-2xl md:text-3xl font-bold tracking-tight dark:text-white"><?= max((int)($userTotals['matches'] ?? 0), (int)($aiTotals['matches'] ?? 0)) ?></p>
</div>
<div class="flex flex-col items-start gap-2 rounded-xl border border-slate-200 bg-white p-4 md:p-5 shadow-sm hover:shadow-md transition-shadow dark:border-white/10 dark:bg-surface-dark hover:border-primary/30 dark:hover:border-primary/30">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-[20px]">emoji_events</span>
<span class="text-sm font-medium text-muted">Wins</span>
</div>
<p class="text-2xl md:text-3xl font-bold tracking-tight dark:text-white"><?= (int)($userTotals['wins'] ?? 0) ?></p>
</div>
<div class="flex flex-col items-start gap-2 rounded-xl border border-slate-200 bg-white p-4 md:p-5 shadow-sm hover:shadow-md transition-shadow dark:border-white/10 dark:bg-surface-dark hover:border-primary/30 dark:hover:border-primary/30">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-[20px]">sync_alt</span>
<span class="text-sm font-medium text-muted">Passes</span>
</div>
<p class="text-2xl md:text-3xl font-bold tracking-tight dark:text-white"><?= (int)($aiTotals['successful_passes'] ?? 0) ?></p>
</div>
<div class="flex flex-col items-start gap-2 rounded-xl border border-slate-200 bg-white p-4 md:p-5 shadow-sm hover:shadow-md transition-shadow dark:border-white/10 dark:bg-surface-dark hover:border-primary/30 dark:hover:border-primary/30">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-[20px]">straighten</span>
<span class="text-sm font-medium text-muted">Distance</span>
</div>
<p class="text-2xl md:text-3xl font-bold tracking-tight dark:text-white"><?= number_format((float)($aiTotals['distance_km'] ?? 0), 1) ?> km</p>
</div>
</section>
<?php
$pp = $playerProgress;
$pOvr = (int)($pp['ovr'] ?? 50);
$pLevel = (int)($pp['level'] ?? max(1, $pOvr - 49));
$pXp = (int)($pp['current_xp'] ?? ($pp['xp'] ?? 0));
$pXpNext = $pLevel * 200;
$pXpPct = $pXpNext > 0 ? min(100, round(($pXp / $pXpNext) * 100)) : 0;
$pPosition = strtoupper($account['position'] ?? 'CM');
$pName = ($account['fname'] ?? 'Player');
$winRate = (int)($userTotals['matches'] ?? 0) > 0 ? round(((int)($userTotals['wins'] ?? 0) / (int)($userTotals['matches'] ?? 1)) * 100) : 0;
?>
<section class="flex flex-col gap-6 lg:flex-row mb-6">
<div class="flex flex-1 flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 md:p-8 shadow-sm dark:border-white/10 dark:bg-surface-dark">
<div class="mb-6 flex w-full items-center justify-between px-4">
<h3 class="text-xl font-bold dark:text-white">Player Card</h3>
<span class="text-xs font-bold text-primary bg-primary/10 px-3 py-1 rounded-full">OVR <?= $pOvr ?></span>
</div>
<div class="relative flex h-[320px] w-[220px] flex-col overflow-hidden rounded-t-[2rem] rounded-b-[2.5rem] bg-gradient-to-br from-primary/30 via-primary/10 to-primary/5 p-1 shadow-2xl ring-4 ring-primary/20 transition-transform hover:scale-105">
<div class="relative flex h-full w-full flex-col items-center border border-primary/20 bg-gradient-to-b from-black/5 to-black/30 p-4">
<div class="absolute left-5 top-5 flex flex-col items-center">
<span class="text-4xl font-black text-primary"><?= $pOvr ?></span>
<span class="text-lg font-bold text-primary/80"><?= $pPosition ?></span>
</div>
<div class="mt-16 flex flex-col items-center">
<div class="size-24 rounded-full bg-primary/20 flex items-center justify-center text-primary text-4xl font-bold">
<?= strtoupper(substr($pName, 0, 1)) ?>
</div>
</div>
<div class="relative z-10 mt-4 w-full text-center">
<h2 class="text-xl font-black uppercase tracking-wider"><?= htmlspecialchars($pName) ?></h2>
<p class="text-xs text-muted">Level <?= $pLevel ?></p>
</div>
<div class="mt-3 grid w-full grid-cols-3 gap-x-2 gap-y-1 px-2 text-center">
<div><span class="font-black text-primary"><?= (int)($aiTotals['successful_passes'] ?? 0) ?></span><br><span class="text-[10px] text-muted">PAS</span></div>
<div><span class="font-black text-primary"><?= (int)($aiTotals['shots'] ?? 0) ?></span><br><span class="text-[10px] text-muted">SHT</span></div>
<div><span class="font-black text-primary"><?= (int)($userTotals['wins'] ?? 0) ?></span><br><span class="text-[10px] text-muted">WIN</span></div>
</div>
</div>
</div>
</div>
<div class="flex flex-1 flex-col gap-6">
<!-- Progression -->
<div class="flex flex-1 flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-surface-dark">
<div class="mb-6 flex items-center justify-between">
<h3 class="text-lg md:text-xl font-bold dark:text-white">Progression</h3>
<span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary">Level <?= $pLevel ?></span>
</div>
<div class="flex flex-col gap-2 mb-4">
<div class="flex justify-between items-end">
<div>
<span class="text-xs font-bold uppercase text-muted">Current Level</span>
<div class="text-3xl font-black"><?= $pLevel ?></div>
</div>
<div class="text-right">
<span class="text-sm font-bold text-primary"><?= number_format($pXp) ?> XP</span>
<span class="text-xs font-medium text-muted"> / <?= number_format($pXpNext) ?> XP</span>
</div>
</div>
<div class="relative h-4 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
<div class="absolute top-0 left-0 h-full rounded-full bg-primary transition-all duration-500" style="width: <?= $pXpPct ?>%"></div>
</div>
<p class="text-xs text-muted"><?= number_format(max(0, $pXpNext - $pXp)) ?> XP needed for next level</p>
</div>
<div class="grid grid-cols-2 gap-3">
<div class="bg-slate-50 dark:bg-white/5 p-3 rounded-xl text-center">
<p class="text-lg font-bold text-primary"><?= $winRate ?>%</p>
<p class="text-xs text-muted">Win Rate</p>
</div>
<div class="bg-slate-50 dark:bg-white/5 p-3 rounded-xl text-center">
<p class="text-lg font-bold text-primary"><?= (int)($userTotals['matches'] ?? 0) ?></p>
<p class="text-xs text-muted">Played</p>
</div>
</div>
</div>
</div>
</section>
<section class="grid grid-cols-2 gap-4 md:grid-cols-5 mb-6">
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-surface-dark">
    <p class="text-xs font-semibold uppercase tracking-wider text-muted">Dribbles</p>
    <p class="mt-2 text-2xl font-bold text-primary"><?= (int)($aiTotals['successful_dribbles'] ?? 0) ?></p>
</div>
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-surface-dark">
    <p class="text-xs font-semibold uppercase tracking-wider text-muted">Shots</p>
    <p class="mt-2 text-2xl font-bold text-primary"><?= (int)($aiTotals['shots'] ?? 0) ?></p>
</div>
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-surface-dark">
    <p class="text-xs font-semibold uppercase tracking-wider text-muted">Interceptions</p>
    <p class="mt-2 text-2xl font-bold text-primary"><?= (int)($aiTotals['interceptions'] ?? 0) ?></p>
</div>
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-surface-dark">
    <p class="text-xs font-semibold uppercase tracking-wider text-muted">Duels Won</p>
    <p class="mt-2 text-2xl font-bold text-primary"><?= (int)($aiTotals['duels_won'] ?? 0) ?></p>
</div>
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-surface-dark">
    <p class="text-xs font-semibold uppercase tracking-wider text-muted">Top Speed</p>
    <p class="mt-2 text-2xl font-bold text-primary"><?= number_format((float)($aiTotals['top_speed_kmh'] ?? 0), 1) ?> km/h</p>
</div>
</section>
<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-surface-dark mb-6">
<div class="mb-4 flex items-center justify-between">
<h3 class="text-lg md:text-xl font-bold dark:text-white">Recent Matches</h3>
<a class="text-sm font-medium text-primary hover:text-green-600 transition-colors" href="/match-history">See all →</a>
</div>
<div class="overflow-x-auto -mx-6 px-6">
<?php if (!empty($recentMatches)): ?>
<table class="w-full text-left">
<thead class="border-b border-slate-200 text-xs uppercase text-muted dark:border-white/10">
<tr>
<th class="py-3 font-medium">Match</th>
<th class="py-3 font-medium">Score</th>
<th class="py-3 font-medium">Status</th>
<th class="py-3 font-medium text-right">Date</th>
</tr>
</thead>
<tbody class="text-sm dark:text-white">
<?php foreach (array_slice($recentMatches, 0, 5) as $m): ?>
<?php
$recentScoreHome = (int)($m['score_home'] ?? ($m['score_challanger'] ?? 0));
$recentScoreAway = (int)($m['score_away'] ?? ($m['score_opponent'] ?? 0));
?>
<tr class="border-b border-slate-100 last:border-0 dark:border-white/5">
<td class="py-3">
<div class="flex items-center gap-3">
<div class="h-8 w-8 rounded-full bg-primary/20 flex items-center justify-center text-xs font-bold text-primary">
<?= strtoupper(substr($m['opponent'] ?? 'O', 0, 1)) ?>
</div>
<span><?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?></span>
</div>
</td>
<td class="py-3 font-mono font-bold"><?= $recentScoreHome ?> - <?= $recentScoreAway ?></td>
<td class="py-3">
<span class="rounded px-2 py-1 text-xs font-bold <?= ($m['match_status'] ?? '') === 'completed' ? 'bg-primary/20 text-primary' : 'bg-warning/20 text-warning' ?>">
<?= ucfirst($m['match_status'] ?? 'pending') ?>
</span>
</td>
<td class="py-3 text-right text-muted"><?= htmlspecialchars(substr($m['date'] ?? '', 0, 10)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<div class="text-center py-8 text-muted">
<span class="material-symbols-outlined text-3xl mb-2 block">history</span>
<p class="text-sm">No recent matches</p>
</div>
<?php endif; ?>
</div>
</section>
<footer class="mt-8 pb-8 text-center text-xs md:text-sm text-muted">
© 2026 FiveStats. All rights reserved.
</footer>
</div>
</div>
</div>

</div>
</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

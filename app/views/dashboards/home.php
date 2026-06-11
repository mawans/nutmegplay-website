<?php
$pageTitle = 'Dashboard';
$pageHeading = 'Dashboard Overview';
$pageDescription = 'Welcome back, ' . htmlspecialchars($account['fname'] ?? 'Player');
$currentPage = 'dashboard';
$userTotals = $userTotals ?? [];
$playerProgress = $playerProgress ?? [];
$recentMatches = $recentMatches ?? [];
$upcomingMatches = $upcomingMatches ?? [];
$announcements = $announcements ?? [];
$error = $error ?? '';
$success = $success ?? '';
$totalXpEarned = (int)($totalXpEarned ?? 0);
$winRate = (float)($userTotals['win_rate'] ?? 0);
$passAccuracy = (float)($userTotals['pass_accuracy'] ?? 0);
$dribbleAccuracy = (float)($userTotals['dribble_accuracy'] ?? 0);
$distanceKm = (float)($userTotals['distance_km'] ?? 0);
$canManageMatchmaking = \App\Core\Auth::can('matchmaking.manage');
$canManageVideo = \App\Core\Auth::can('video.manage');

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
<!-- Daily Statistics -->
<section>
<div class="flex items-center justify-between mb-4">
<h3 class="text-sm font-bold text-muted uppercase tracking-wider">Daily Statistics</h3>
<span class="text-xs text-muted">Last updated: Just now</span>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
<!-- Stat Card 1 -->
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 md:p-6 rounded-lg flex flex-col gap-1 transition-all duration-300 hover:shadow-lg hover:border-primary/30 hover:-translate-y-0.5 cursor-pointer group">
<div class="flex items-center gap-2 text-muted mb-1 group-hover:text-primary transition-colors">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform" style="font-size: 20px;">sports_soccer</span>
<span class="text-sm font-medium">Total Goals</span>
</div>
<p class="text-3xl md:text-4xl font-bold tracking-tight"><?= number_format($userTotals['goals'] ?? 0) ?></p>
<div class="flex items-center gap-1 text-xs text-primary mt-1">
<span class="material-symbols-outlined" style="font-size: 14px;">trending_up</span>
<span>Your career total</span>
</div>
</div>
<!-- Stat Card 2 -->
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 md:p-6 rounded-lg flex flex-col gap-1 transition-all duration-300 hover:shadow-lg hover:border-primary/30 hover:-translate-y-0.5 cursor-pointer group">
<div class="flex items-center gap-2 text-muted mb-1 group-hover:text-primary transition-colors">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform" style="font-size: 20px;">groups</span>
<span class="text-sm font-medium">Active Players</span>
</div>
<p class="text-3xl md:text-4xl font-bold tracking-tight"><?= number_format($totalPlayers ?? 0) ?></p>
<div class="flex items-center gap-1 text-xs text-muted mt-1">
<span>Registered players</span>
</div>
</div>
<!-- Stat Card 3 -->
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 md:p-6 rounded-lg flex flex-col gap-1 transition-all duration-300 hover:shadow-lg hover:border-primary/30 hover:-translate-y-0.5 cursor-pointer group">
<div class="flex items-center gap-2 text-muted mb-1 group-hover:text-primary transition-colors">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform" style="font-size: 20px;">shield</span>
<span class="text-sm font-medium">Total Clubs</span>
</div>
<p class="text-3xl md:text-4xl font-bold tracking-tight"><?= number_format($totalClubs ?? 0) ?></p>
<div class="flex items-center gap-1 text-xs text-muted mt-1">
<span>Active teams</span>
</div>
</div>
<!-- Stat Card 4 -->
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 md:p-6 rounded-lg flex flex-col gap-1 transition-all duration-300 hover:shadow-lg hover:border-primary/30 hover:-translate-y-0.5 cursor-pointer group">
<div class="flex items-center gap-2 text-muted mb-1 group-hover:text-primary transition-colors">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform" style="font-size: 20px;">emoji_events</span>
<span class="text-sm font-medium">OVR Rating</span>
</div>
<p class="text-3xl md:text-4xl font-bold tracking-tight"><?= (int)($playerProgress['ovr'] ?? 50) ?><span class="text-base font-normal text-muted ml-1">/ 99</span></p>
<div class="flex items-center gap-1 text-xs text-primary mt-1">
<span class="material-symbols-outlined" style="font-size: 14px;">check_circle</span>
<span>XP: <?= (int)($playerProgress['current_xp'] ?? 0) ?> / 100</span>
</div>
</div>
</div>
</section>

<!-- User Stats from production match_stats/player_progress/xp_history -->
<section class="mt-6">
<div class="flex items-center justify-between mb-4">
<h3 class="text-sm font-bold text-muted uppercase tracking-wider">Your Performance Stats</h3>
<span class="text-xs text-muted"><?= htmlspecialchars(strtoupper((string)($account['position'] ?? 'Player'))) ?> • <?= htmlspecialchars((string)($account['country'] ?? 'Unknown')) ?></span>
</div>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-4">
    <p class="text-xs text-muted uppercase">Matches</p>
    <p class="text-2xl font-bold"><?= (int)($userTotals['matches'] ?? 0) ?></p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-4">
    <p class="text-xs text-muted uppercase">Win Rate</p>
    <p class="text-2xl font-bold text-primary"><?= number_format($winRate, 1) ?>%</p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-4">
    <p class="text-xs text-muted uppercase">Pass Accuracy</p>
    <p class="text-2xl font-bold"><?= number_format($passAccuracy, 1) ?>%</p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-4">
    <p class="text-xs text-muted uppercase">Dribble Accuracy</p>
    <p class="text-2xl font-bold"><?= number_format($dribbleAccuracy, 1) ?>%</p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-4">
    <p class="text-xs text-muted uppercase">Distance</p>
    <p class="text-2xl font-bold"><?= number_format($distanceKm, 1) ?><span class="text-xs text-muted ml-1">km</span></p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-4">
    <p class="text-xs text-muted uppercase">Total XP Earned</p>
    <p class="text-2xl font-bold text-primary"><?= number_format($totalXpEarned) ?></p>
</div>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-3">
    <p class="text-xs text-muted uppercase">Minutes Played</p>
    <p class="text-lg font-bold"><?= number_format((int)($userTotals['minutes_played'] ?? 0)) ?></p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-3">
    <p class="text-xs text-muted uppercase">Interceptions</p>
    <p class="text-lg font-bold"><?= number_format((int)($userTotals['interceptions'] ?? 0)) ?></p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-3">
    <p class="text-xs text-muted uppercase">Duels Won</p>
    <p class="text-lg font-bold"><?= number_format((int)($userTotals['duels_won'] ?? 0)) ?></p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-3">
    <p class="text-xs text-muted uppercase">Clean Sheets</p>
    <p class="text-lg font-bold"><?= number_format((int)($userTotals['clean_sheets'] ?? 0)) ?></p>
</div>
</div>
</section>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
<!-- Live Pitch Activity (Left 2/3) -->
<section class="xl:col-span-2 flex flex-col gap-4">
<div class="flex items-center justify-between">
<h3 class="text-sm font-bold text-muted uppercase tracking-wider">Recent & Upcoming Matches</h3>
<a href="/match-history" class="text-primary text-sm font-medium hover:underline">View All</a>
</div>

<?php if (!empty($recentMatches) || !empty($upcomingMatches)):
    $displayMatches = array_merge($upcomingMatches, $recentMatches);
    $displayMatches = array_slice($displayMatches, 0, 4);
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
<?php foreach ($displayMatches as $dm): ?>
<?php
$displayScoreHome = $dm['score_home'] ?? ($dm['score_challanger'] ?? '–');
$displayScoreAway = $dm['score_away'] ?? ($dm['score_opponent'] ?? '–');
?>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg overflow-hidden flex flex-col">
<div class="relative h-32 bg-gradient-to-br from-[#122017] to-[#1b2e21] group">
<div class="absolute top-3 left-3 flex items-center gap-2 px-2 py-1 bg-black/60 backdrop-blur-sm rounded text-white text-xs font-mono border border-white/10">
<?php if (($dm['match_status'] ?? '') === 'completed'): ?>
<div class="size-2 rounded-full bg-muted"></div> Completed
<?php elseif (($dm['match_status'] ?? '') === 'pending'): ?>
<div class="size-2 rounded-full bg-warning animate-pulse"></div> Pending
<?php else: ?>
<div class="size-2 rounded-full bg-primary animate-pulse"></div> <?= ucfirst($dm['match_status'] ?? 'upcoming') ?>
<?php endif; ?>
</div>
<div class="absolute bottom-3 left-3 right-3 flex justify-between items-end">
<div>
<p class="text-xs font-bold text-white mb-0.5 shadow-sm"><?= htmlspecialchars($dm['location'] ?? 'TBD') ?></p>
<p class="text-xs text-white/80 font-mono">ID: #MATCH-<?= (int)($dm['id'] ?? 0) ?></p>
</div>
</div>
</div>
<div class="p-4 flex items-center justify-between bg-white dark:bg-card-dark">
<div class="flex items-center gap-4 flex-1">
<div class="text-center">
<p class="text-xs font-bold text-muted mb-1"><?= htmlspecialchars(mb_strimwidth($dm['challanger'] ?? 'TBD', 0, 12, '…')) ?></p>
<p class="text-2xl font-bold font-mono leading-none"><?= htmlspecialchars((string)$displayScoreHome) ?></p>
</div>
<div class="text-xl font-bold text-muted/50">:</div>
<div class="text-center">
<p class="text-xs font-bold text-muted mb-1"><?= htmlspecialchars(mb_strimwidth($dm['opponent'] ?? 'TBD', 0, 12, '…')) ?></p>
<p class="text-2xl font-bold font-mono leading-none"><?= htmlspecialchars((string)$displayScoreAway) ?></p>
</div>
</div>
<div class="flex flex-col items-end gap-2">
<div class="px-2 py-1 rounded bg-primary/10 text-primary text-xs font-bold font-mono">
<?= htmlspecialchars($dm['date'] ?? '') ?>
</div>
<span class="text-xs text-muted"><?= htmlspecialchars($dm['time'] ?? '') ?></span>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-8 text-center">
<span class="material-symbols-outlined text-5xl text-muted mb-2">sports_soccer</span>
<?php if ($canManageMatchmaking): ?>
<p class="text-muted">No matches yet. <a href="/matchmaking" class="text-primary hover:underline">Create your first match</a>.</p>
<?php else: ?>
<p class="text-muted">No matches are scheduled yet. Your instructor will create fixtures and publish them here.</p>
<?php endif; ?>
</div>
<?php endif; ?>
</section>
<!-- Quick Links & Announcements (Right Sidebar) -->
<section class="flex flex-col gap-4">
<div class="flex items-center justify-between">
<h3 class="text-sm font-bold text-muted uppercase tracking-wider">Quick Actions</h3>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg overflow-hidden flex-1 flex flex-col">
<div class="p-4 border-b border-slate-200 dark:border-[#264531] bg-slate-50 dark:bg-[#1f3429]">
<div class="flex justify-between items-center mb-2">
<span class="text-sm font-bold">Your Stats</span>
<span class="text-xs font-mono text-primary bg-primary/10 px-2 py-0.5 rounded">OVR <?= (int)($playerProgress['ovr'] ?? 50) ?></span>
</div>
<div class="grid grid-cols-3 gap-2 text-center text-xs">
<div class="bg-white dark:bg-[#122017] p-2 rounded">
    <p class="font-bold text-lg"><?= (int)($userTotals['wins'] ?? 0) ?></p>
    <p class="text-muted">Wins</p>
</div>
<div class="bg-white dark:bg-[#122017] p-2 rounded">
    <p class="font-bold text-lg"><?= (int)($userTotals['goals'] ?? 0) ?></p>
    <p class="text-muted">Goals</p>
</div>
<div class="bg-white dark:bg-[#122017] p-2 rounded">
    <p class="font-bold text-lg"><?= (int)($userTotals['assists'] ?? 0) ?></p>
    <p class="text-muted">Assists</p>
</div>
</div>
</div>
<div class="p-4 flex flex-col gap-3">
<?php if ($canManageMatchmaking): ?>
<a href="/matchmaking" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
    <span class="material-symbols-outlined text-primary">handshake</span>
    <div>
        <p class="text-sm font-medium">Create Match</p>
        <p class="text-xs text-muted">Challenge an opponent</p>
    </div>
</a>
<?php else: ?>
<a href="/fixtures" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
    <span class="material-symbols-outlined text-primary">event</span>
    <div>
        <p class="text-sm font-medium">View Fixtures</p>
        <p class="text-xs text-muted">Check upcoming matches</p>
    </div>
</a>
<?php endif; ?>
<a href="/players" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
    <span class="material-symbols-outlined text-primary">groups</span>
    <div>
        <p class="text-sm font-medium">Browse Players</p>
        <p class="text-xs text-muted"><?= number_format($totalPlayers ?? 0) ?> registered</p>
    </div>
</a>
<a href="/teams" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
    <span class="material-symbols-outlined text-primary">shield</span>
    <div>
        <p class="text-sm font-medium">Teams</p>
        <p class="text-xs text-muted"><?= number_format($totalClubs ?? 0) ?> active clubs</p>
    </div>
</a>
<?php if ($canManageVideo): ?>
<a href="/video-upload" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
    <span class="material-symbols-outlined text-primary">video_library</span>
    <div>
        <p class="text-sm font-medium">Upload Video</p>
        <p class="text-xs text-muted">Match highlights</p>
    </div>
</a>
<?php else: ?>
<a href="/match-history" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] hover:bg-primary/10 transition-colors">
    <span class="material-symbols-outlined text-primary">history</span>
    <div>
        <p class="text-sm font-medium">Match History</p>
        <p class="text-xs text-muted">Review your past games</p>
    </div>
</a>
<?php endif; ?>
</div>
<?php if (!empty($announcements)): ?>
<div class="p-4 border-t border-slate-200 dark:border-[#264531]">
<h4 class="text-xs font-bold text-muted uppercase mb-3">Announcements</h4>
<?php foreach ($announcements as $ann): ?>
<div class="flex items-start gap-2 mb-2 last:mb-0">
    <span class="material-symbols-outlined text-primary text-sm mt-0.5">campaign</span>
    <div>
        <p class="text-xs"><?= htmlspecialchars($ann['title'] ?? $ann['message'] ?? '') ?></p>
        <p class="text-[10px] text-muted"><?= htmlspecialchars(substr($ann['created_at'] ?? '', 0, 10)) ?></p>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</section>
</div>
</div>
<footer class="mt-8 text-center pb-4">
<p class="text-xs text-muted">NutmegPlay Dashboard v2.4.1 © 2024</p>
</footer>
</div>
</div>
</div>
</div>

</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

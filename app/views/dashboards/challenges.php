<?php
$pageTitle = 'Football Challenges';
$pageHeading = 'Challenges Dashboard';
$pageDescription = 'Complete challenges to earn XP and unlock badges';
$currentPage = 'challenges';
$pendingChallenges = $pendingChallenges ?? [];
$account = $account ?? [];
$playerProgress = $playerProgress ?? [];
$leaderboard = $leaderboard ?? [];
$userTotals = $userTotals ?? [];
$isInstructor = $isInstructor ?? false;
$error = $error ?? '';
$success = $success ?? '';
$csrfToken = \App\Core\Auth::csrfToken();

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

<!-- Pending Match Challenges from DB -->
<?php if (!empty($pendingChallenges)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">notifications_active</span> Pending Match Challenges</h3>
<div class="space-y-3">
<?php foreach ($pendingChallenges as $ch): ?>
<div class="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531]">
    <div>
        <p class="font-bold"><?= htmlspecialchars($ch['challanger'] ?? 'Unknown') ?> <span class="text-muted">vs</span> <?= htmlspecialchars($ch['opponent'] ?? 'You') ?></p>
        <p class="text-xs text-muted mt-1"><?= htmlspecialchars($ch['date'] ?? '') ?> <?= htmlspecialchars($ch['time'] ?? '') ?> · <?= htmlspecialchars($ch['location'] ?? 'TBD') ?></p>
    </div>
    <?php if ($isInstructor): ?>
    <form method="POST" action="/challenges" class="flex gap-2" hx-boost="false">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="match_id" value="<?= (int)($ch['id'] ?? 0) ?>">
        <button type="submit" name="action" value="accept" class="px-3 py-1.5 bg-primary text-white text-xs font-bold rounded-lg hover:bg-green-600 transition-colors">Accept</button>
        <button type="submit" name="action" value="decline" class="px-3 py-1.5 bg-accent-danger/10 text-accent-danger text-xs font-bold rounded-lg hover:bg-accent-danger hover:text-white transition-colors">Decline</button>
    </form>
    <?php else: ?>
    <span class="text-xs text-muted">Instructor will confirm or decline</span>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
<!-- LEFT COLUMN (Main Feed) -->
<div class="xl:col-span-2 flex flex-col gap-8">
<!-- 1. Your Challenge Stats Overview -->
<section class="relative overflow-hidden rounded-2xl bg-white dark:bg-surface-dark shadow-xl border border-slate-200 dark:border-[#264531]">
<div class="p-8">
<div class="flex items-center gap-2 mb-6">
<span class="material-symbols-outlined text-primary text-3xl">emoji_events</span>
<h2 class="text-2xl font-black">Your Challenge Overview</h2>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
<div class="bg-slate-50 dark:bg-[#16261d] p-4 rounded-xl text-center">
<p class="text-3xl font-black text-primary"><?= (int)($userTotals['matches'] ?? 0) ?></p>
<p class="text-xs text-muted mt-1">Matches Played</p>
</div>
<div class="bg-slate-50 dark:bg-[#16261d] p-4 rounded-xl text-center">
<p class="text-3xl font-black text-primary"><?= (int)($userTotals['wins'] ?? 0) ?></p>
<p class="text-xs text-muted mt-1">Wins</p>
</div>
<div class="bg-slate-50 dark:bg-[#16261d] p-4 rounded-xl text-center">
<p class="text-3xl font-black text-primary"><?= (int)($userTotals['goals'] ?? 0) ?></p>
<p class="text-xs text-muted mt-1">Goals</p>
</div>
<div class="bg-slate-50 dark:bg-[#16261d] p-4 rounded-xl text-center">
<p class="text-3xl font-black text-primary"><?= (int)($userTotals['assists'] ?? 0) ?></p>
<p class="text-xs text-muted mt-1">Assists</p>
</div>
</div>
</div>
</section>

<!-- 2. Personal Progress & OVR Card -->
<?php
$currentXp = (int)($playerProgress['current_xp'] ?? ($playerProgress['xp'] ?? 0));
$ovr = (int)($playerProgress['ovr'] ?? 50);
$currentLevel = (int)($playerProgress['level'] ?? max(1, $ovr - 49));
$xpForNext = $currentLevel * 200;
$xpPct = $xpForNext > 0 ? min(100, round(($currentXp / $xpForNext) * 100)) : 0;
?>
<section class="grid grid-cols-1 md:grid-cols-2 gap-6">
<!-- Progress Card -->
<div class="rounded-2xl bg-white dark:bg-surface-dark p-6 border border-slate-200 dark:border-surface-light shadow-lg">
<div class="flex justify-between items-start mb-6">
<div>
<h3 class="text-xl font-bold">Progression</h3>
<p class="text-sm text-muted">Season Progress</p>
</div>
<div class="bg-primary/10 text-primary p-2 rounded-lg">
<span class="material-symbols-outlined">military_tech</span>
</div>
</div>
<div class="flex flex-col gap-2 mb-6">
<div class="flex justify-between text-sm font-medium">
<span>Level <?= $currentLevel ?></span>
<span class="text-primary"><?= number_format($currentXp) ?> / <?= number_format($xpForNext) ?> XP</span>
</div>
<div class="h-3 w-full rounded-full bg-slate-200 dark:bg-surface-light overflow-hidden">
<div class="h-full rounded-full bg-primary shadow-[0_0_10px_rgba(29,185,84,0.5)]" style="width: <?= $xpPct ?>%"></div>
</div>
<p class="text-xs text-muted mt-1"><?= number_format(max(0, $xpForNext - $currentXp)) ?> XP needed for next level</p>
</div>
<div class="flex items-center gap-3 bg-slate-50 dark:bg-surface-light/30 p-3 rounded-xl border border-slate-200 dark:border-surface-light">
<span class="material-symbols-outlined text-primary">trending_up</span>
<p class="text-xs">Win Rate: <span class="font-bold text-primary"><?= (int)($userTotals['matches'] ?? 0) > 0 ? round(((int)($userTotals['wins'] ?? 0) / (int)($userTotals['matches'] ?? 1)) * 100) : 0 ?>%</span></p>
</div>
</div>
<!-- OVR Card -->
<div class="relative rounded-2xl bg-white dark:bg-surface-dark p-6 border border-slate-200 dark:border-surface-light shadow-lg flex items-center justify-center overflow-hidden group">
<div class="relative z-10 flex flex-col items-center gap-4 text-center">
<div class="relative w-28 h-40 bg-gradient-to-b from-primary/20 to-primary/5 rounded-lg border-2 border-primary/30 shadow-2xl flex flex-col items-center justify-center transform group-hover:scale-105 transition-transform duration-300">
<p class="text-4xl font-black text-primary"><?= $ovr ?></p>
<p class="text-xs text-muted uppercase font-bold">OVR</p>
</div>
<div>
<h4 class="font-bold"><?= htmlspecialchars(($account['fname'] ?? '') . ' ' . ($account['lname'] ?? '')) ?></h4>
<p class="text-xs text-muted">Level <?= $currentLevel ?> Player</p>
</div>
</div>
</div>
</section>
</div>
<!-- RIGHT COLUMN (Leaderboard) -->
<div class="flex flex-col gap-6">
<!-- Leaderboard Widget -->
<div class="bg-white dark:bg-surface-dark rounded-2xl p-6 shadow-xl border border-slate-200 dark:border-[#264531] h-full flex flex-col">
<div class="flex items-center justify-between mb-6">
<h3 class="text-lg font-bold">Leaderboard</h3>
<span class="text-xs text-muted uppercase tracking-wider">By Goals</span>
</div>
<!-- Leaderboard List -->
<div class="flex flex-col gap-2 overflow-y-auto flex-1 pr-2">
<?php if (!empty($leaderboard)): ?>
<?php foreach ($leaderboard as $idx => $entry): ?>
<?php
$rank = $idx + 1;
$rankColor = match($rank) {
    1 => 'text-[#FFD700]',
    2 => 'text-gray-400',
    3 => 'text-[#CD7F32]',
    default => 'text-muted'
};
$bgClass = $rank === 1 ? 'bg-gradient-to-r from-[#FFD700]/10 to-transparent border border-[#FFD700]/20' : 'hover:bg-slate-50 dark:hover:bg-surface-light transition-colors';
$initials = strtoupper(substr($entry['fname'] ?? 'U', 0, 1) . substr($entry['lname'] ?? '', 0, 1));
?>
<div class="flex items-center gap-3 p-3 rounded-xl <?= $bgClass ?> group cursor-pointer">
<div class="font-black <?= $rankColor ?> w-6 text-center"><?= $rank ?></div>
<div class="h-10 w-10 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold border border-slate-200 dark:border-surface-light">
<?= $initials ?>
</div>
<div class="flex-1">
<p class="text-sm font-bold"><?= htmlspecialchars(($entry['fname'] ?? '') . ' ' . substr($entry['lname'] ?? '', 0, 1) . '.') ?></p>
<p class="text-xs text-muted"><?= (int)($entry['total_goals'] ?? 0) ?> goals</p>
</div>
<div class="text-right">
<p class="text-sm font-bold text-primary"><?= (int)($entry['total_goals'] ?? 0) ?></p>
<p class="text-[10px] text-muted">goals</p>
</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="text-center py-8 text-muted">
<span class="material-symbols-outlined text-3xl mb-2 block">leaderboard</span>
<p class="text-sm">No leaderboard data yet</p>
<p class="text-xs mt-1">Play matches to appear here</p>
</div>
<?php endif; ?>
</div>
</div>
<!-- Mini Widget: Tips -->
<div class="bg-[#264531] rounded-2xl p-6 flex flex-col gap-3">
<div class="flex items-center gap-2 text-primary">
<span class="material-symbols-outlined">lightbulb</span>
<span class="font-bold text-sm uppercase">Pro Tip</span>
</div>
<p class="text-sm text-gray-200">Accept match challenges regularly to climb the leaderboard and earn XP faster.</p>
</div>
</div>
</div>
</div>
</div>
</div>

</div>
</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

<?php
$pageTitle = 'Matchmaking Lobby';
$pageHeading = 'Matchmaking Lobby';
$pageDescription = 'Find your next opponent and dominate the pitch';
$currentPage = 'matchmaking';
$clubs = $clubs ?? [];
$upcomingMatches = $upcomingMatches ?? [];
$pendingChallenges = $pendingChallenges ?? [];
$account = $account ?? [];
$announcements = $announcements ?? [];
$players = $players ?? [];
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

<!-- Create Match Form -->
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">add_circle</span> Create New Match</h3>
<?php if ($isInstructor): ?>
<form method="POST" action="/matchmaking" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4" hx-boost="false">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Select Challenger</label>
        <select name="challenger_id" required class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
            <option value="">Choose a player...</option>
            <?php foreach ($players as $p): ?>
            <option value="<?= htmlspecialchars($p['uid'] ?? '') ?>"><?= htmlspecialchars(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Select Opponent</label>
        <?php if (!empty($players)): ?>
        <select name="opponent_id" required class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
            <option value="">Choose a player...</option>
            <?php foreach ($players as $p):
            ?>
            <option value="<?= htmlspecialchars($p['uid'] ?? '') ?>"><?= htmlspecialchars(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?> <?= !empty($p['username']) ? '(@' . htmlspecialchars($p['username']) . ')' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="opponent" required class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm" placeholder="Opponent name">
        <?php endif; ?>
    </div>
    <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Location</label>
        <input type="text" name="location" class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm" placeholder="Pitch / venue">
    </div>
    <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Date & Time</label>
        <div class="flex gap-2">
            <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="flex-1 px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
            <input type="time" name="time" value="<?= date('H:i') ?>" class="w-24 px-2 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
        </div>
    </div>
    <div class="flex items-end">
        <button type="submit" class="w-full px-4 py-2 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors">
            <span class="material-symbols-outlined text-sm align-middle mr-1">send</span> Challenge
        </button>
    </div>
</form>
<?php else: ?>
<div class="rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
Only instructors can schedule matches and set match locations.
</div>
<?php endif; ?>
</div>

<!-- Upcoming Matches -->
<?php if (!empty($upcomingMatches)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">event</span> Upcoming Matches</h3>
<div class="space-y-3">
<?php foreach ($upcomingMatches as $match): ?>
<div class="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531]">
    <div class="flex items-center gap-4">
        <div class="text-center">
            <p class="text-sm font-bold"><?= htmlspecialchars($match['challanger'] ?? 'TBD') ?></p>
            <p class="text-xs text-muted">vs</p>
            <p class="text-sm font-bold"><?= htmlspecialchars($match['opponent'] ?? 'TBD') ?></p>
        </div>
    </div>
    <div class="text-right">
        <p class="text-sm font-mono"><?= htmlspecialchars($match['date'] ?? '') ?> <?= htmlspecialchars($match['time'] ?? '') ?></p>
        <p class="text-xs text-muted"><?= htmlspecialchars($match['location'] ?? 'TBD') ?></p>
        <span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-bold <?= ($match['challange_status'] ?? '') === 'accepted' ? 'bg-primary/20 text-primary' : 'bg-warning/20 text-warning' ?>">
            <?= ucfirst($match['challange_status'] ?? 'pending') ?>
        </span>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6 lg:gap-8 items-start">
<div class="xl:col-span-8 flex flex-col gap-6">

<!-- Pending Match Challenges -->
<?php if (!empty($pendingChallenges)): ?>
<?php foreach ($pendingChallenges as $challenge): ?>
<div class="bg-white dark:bg-card-dark rounded-xl overflow-hidden shadow-lg border border-slate-200 dark:border-[#264531]">
<div class="bg-[#122117] px-6 py-4 border-b border-[#264531] flex justify-between items-center">
<h2 class="text-white text-lg font-bold flex items-center gap-2">
<span class="material-symbols-outlined text-primary">handshake</span>
Match Proposal
</h2>
<span class="text-xs font-bold bg-warning/20 text-warning px-3 py-1 rounded-full uppercase tracking-widest">Pending</span>
</div>
<div class="p-6 md:p-8 relative">
<div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-10 flex flex-col items-center pointer-events-none">
<div class="size-14 bg-[#122017] border-4 border-primary rounded-full flex items-center justify-center shadow-[0_0_20px_rgba(29,185,84,0.4)]">
<span class="text-xl font-black italic text-white font-display">VS</span>
</div>
</div>
<div class="flex flex-col md:flex-row gap-8 md:gap-4 relative z-0">
<!-- Challenger -->
<div class="flex-1 flex flex-col items-center text-center p-4 md:p-6 rounded-xl bg-slate-50 dark:bg-[#16261d] border border-slate-200 dark:border-transparent">
<div class="size-20 rounded-full bg-primary/20 flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-primary text-4xl">person</span>
</div>
<h3 class="text-xl font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($challenge['challanger'] ?? 'Unknown') ?></h3>
<p class="text-sm text-slate-500 dark:text-text-muted font-medium mb-4">Challenger</p>
<div class="w-full grid grid-cols-2 gap-2 mt-auto">
<div class="bg-white dark:bg-[#1f3629] p-2 rounded-lg">
<p class="text-xs text-slate-500 dark:text-text-muted uppercase">Date</p>
<p class="text-sm font-bold text-primary"><?= htmlspecialchars($challenge['date'] ?? 'TBD') ?></p>
</div>
<div class="bg-white dark:bg-[#1f3629] p-2 rounded-lg">
<p class="text-xs text-slate-500 dark:text-text-muted uppercase">Time</p>
<p class="text-sm font-bold text-white"><?= htmlspecialchars($challenge['time'] ?? 'TBD') ?></p>
</div>
</div>
</div>
<!-- Opponent (You) -->
<div class="flex-1 flex flex-col items-center text-center p-4 md:p-6 rounded-xl bg-slate-50 dark:bg-[#16261d] border border-slate-200 dark:border-transparent">
<div class="size-20 rounded-full bg-accent-danger/20 flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-accent-danger text-4xl">shield</span>
</div>
<h3 class="text-xl font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($challenge['opponent'] ?? ($account['fname'] ?? 'You')) ?></h3>
<p class="text-sm text-slate-500 dark:text-text-muted font-medium mb-4">(You)</p>
<div class="w-full grid grid-cols-2 gap-2 mt-auto">
<div class="bg-white dark:bg-[#1f3629] p-2 rounded-lg">
<p class="text-xs text-slate-500 dark:text-text-muted uppercase">Location</p>
<p class="text-sm font-bold text-white"><?= htmlspecialchars($challenge['location'] ?? 'TBD') ?></p>
</div>
<div class="bg-white dark:bg-[#1f3629] p-2 rounded-lg">
<p class="text-xs text-slate-500 dark:text-text-muted uppercase">Status</p>
<p class="text-sm font-bold text-warning">Pending</p>
</div>
</div>
</div>
</div>
</div>
<div class="p-6 pt-2 pb-8 flex flex-col items-center gap-4 border-t border-slate-100 dark:border-[#264531] bg-slate-50 dark:bg-[#16261d]/50">
<?php if ($isInstructor): ?>
<form method="POST" action="/challenges" class="flex w-full max-w-md gap-4" hx-boost="false">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="match_id" value="<?= (int)($challenge['id'] ?? 0) ?>">
<button type="submit" name="action" value="decline" class="flex-1 py-4 px-6 rounded-xl bg-white dark:bg-transparent border-2 border-accent-danger text-accent-danger font-bold hover:bg-accent-danger hover:text-white transition-all duration-200">
Decline
</button>
<button type="submit" name="action" value="accept" class="flex-[2] py-4 px-6 rounded-xl bg-primary text-white font-bold shadow-[0_4px_14px_rgba(29,185,84,0.4)] hover:shadow-[0_6px_20px_rgba(29,185,84,0.6)] hover:scale-[1.02] transition-all duration-200 flex items-center justify-center gap-2">
Confirm Match
<span class="material-symbols-outlined">check_circle</span>
</button>
</form>
<?php else: ?>
<div class="text-sm text-muted">Waiting for instructor decision.</div>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-5 shadow-sm border-l-4 border-primary flex flex-col sm:flex-row items-center justify-between gap-4 relative overflow-hidden group">
<div class="absolute inset-0 bg-gradient-to-r from-primary/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
<div class="flex items-center gap-4 z-10">
<div class="relative">
<span class="material-symbols-outlined text-primary text-3xl">hourglass_empty</span>
</div>
<div>
<h3 class="text-lg font-bold leading-tight">No Pending Challenges</h3>
<p class="text-slate-500 dark:text-text-muted text-sm">Create a match above or wait for someone to challenge you.</p>
</div>
</div>
</div>
<?php endif; ?>
</div>
<div class="xl:col-span-4 flex flex-col gap-6">
<!-- Announcements -->
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] h-auto">
<div class="flex items-center justify-between mb-6">
<h3 class="text-lg font-bold text-slate-900 dark:text-white">Announcements</h3>
</div>
<?php if (!empty($announcements)): ?>
<div class="flex flex-col gap-4">
<?php foreach ($announcements as $ann): ?>
<div class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531] hover:border-primary/50 transition-colors group">
<div class="flex items-start gap-3">
<div class="size-10 rounded-full bg-primary/20 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-primary text-sm">campaign</span>
</div>
<div class="flex-1">
<div class="flex justify-between items-start">
<h4 class="text-sm font-bold text-slate-900 dark:text-white group-hover:text-primary transition-colors"><?= htmlspecialchars($ann['title'] ?? 'Announcement') ?></h4>
<span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars(substr($ann['created_at'] ?? '', 0, 10)) ?></span>
</div>
<p class="text-xs text-slate-500 dark:text-text-muted mt-1 leading-relaxed">
<?= htmlspecialchars($ann['message'] ?? '') ?>
</p>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="text-center py-6 text-muted">
<span class="material-symbols-outlined text-3xl mb-2 block">notifications_off</span>
<p class="text-sm">No announcements yet</p>
</div>
<?php endif; ?>
</div>

<!-- Match Stats Summary -->
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
<h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-text-muted mb-4">Match Overview</h3>
<div class="grid grid-cols-2 gap-3">
<div class="bg-slate-50 dark:bg-[#16261d] p-3 rounded-lg text-center">
<p class="text-lg font-bold text-primary"><?= count($upcomingMatches) ?></p>
<p class="text-xs text-muted">Upcoming</p>
</div>
<div class="bg-slate-50 dark:bg-[#16261d] p-3 rounded-lg text-center">
<p class="text-lg font-bold text-warning"><?= count($pendingChallenges) ?></p>
<p class="text-xs text-muted">Pending</p>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
</div>

</div>
</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

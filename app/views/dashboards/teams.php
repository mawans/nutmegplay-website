<?php
$pageTitle = 'Teams';
$pageHeading = 'Teams Dashboard';
$pageDescription = 'Manage your teams and squad lineups';
$currentPage = 'teams';
$clubs = $clubs ?? [];
$myClub = $myClub ?? null;
$teamMembers = $teamMembers ?? [];
$myInvites = $myInvites ?? [];
$account = $account ?? [];
$isInstructor = $isInstructor ?? false;
$players = $players ?? [];
$error = $error ?? '';
$success = $success ?? '';
$csrfToken = \App\Core\Auth::csrfToken();
$canManageTeams = \App\Core\Auth::can('teams.manage');
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

<!-- Create Club Form -->
<?php if (!$myClub && $canManageTeams): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">add_circle</span> Create Your Club</h3>
<form method="POST" action="/teams" class="flex gap-4 items-end" hx-boost="false">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <div class="flex-1">
        <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Club Name</label>
        <input type="text" name="name" required class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm" placeholder="Enter club name">
    </div>
    <button type="submit" class="px-6 py-2 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors text-sm">Create</button>
</form>
</div>
<?php elseif (!$myClub): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-primary">shield</span> No Team Yet</h3>
<p class="text-sm text-muted">You can view teams and invites here, but only instructors and admins can create or manage clubs.</p>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border-l-4 border-primary mb-6">
<div class="flex items-center justify-between">
    <div>
        <h3 class="text-lg font-bold flex items-center gap-2"><span class="material-symbols-outlined text-primary">shield</span> <?= htmlspecialchars($myClub['name'] ?? '') ?></h3>
        <p class="text-sm text-muted mt-1">Level <?= (int)($myClub['level'] ?? 1) ?> · Ranking #<?= (int)($myClub['ranking'] ?? 0) ?> · <?= (int)($myClub['number_player'] ?? 1) ?> Players</p>
    </div>
    <span class="px-3 py-1 rounded-full text-xs font-bold <?= ($myClub['team_active'] ?? false) ? 'bg-primary/20 text-primary' : 'bg-muted/20 text-muted' ?>">
        <?= ($myClub['team_active'] ?? false) ? 'Active' : 'Inactive' ?>
    </span>
</div>
</div>
<?php endif; ?>

<!-- My Team Roster -->
<?php if ($myClub && !empty($teamMembers)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">groups</span> Team Roster (<?= count($teamMembers) ?>)</h3>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
<?php foreach ($teamMembers as $member): ?>
<div class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531] hover:border-primary/50 transition-colors">
    <div class="flex items-center gap-3 mb-2">
        <div class="size-10 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm">
            <?= strtoupper(substr($member['fname'] ?? '?', 0, 1)) ?>
        </div>
        <div>
            <h4 class="font-bold text-sm"><?= htmlspecialchars($member['fname'] ?? 'Unknown') ?>
                <?php if (($member['uid'] ?? '') === ($account['uid'] ?? '')): ?>
                <span class="text-xs text-primary">(You)</span>
                <?php endif; ?>
            </h4>
            <p class="text-xs text-muted"><?= htmlspecialchars($member['position'] ?? 'No position') ?></p>
        </div>
    </div>
    <div class="flex gap-3 text-xs text-muted">
        <span>Level <?= (int)($member['level'] ?? 1) ?></span>
        <span><?= htmlspecialchars($member['country'] ?? '—') ?></span>
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide <?= strtolower((string)($member['role'] ?? 'player')) === 'instructor' ? 'bg-warning/20 text-warning' : 'bg-primary/20 text-primary' ?>">
            <?= htmlspecialchars((string)($member['role'] ?? 'player')) ?>
        </span>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php elseif ($myClub): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-8 text-center border border-slate-200 dark:border-[#264531] mb-6">
    <span class="material-symbols-outlined text-4xl text-muted mb-2 block">person_add</span>
    <h3 class="text-lg font-bold mb-1">No Team Members Yet</h3>
    <p class="text-muted text-sm"><?= $canInvitePlayers ? 'Invite players to join your team from the Players page.' : 'Ask an instructor to assign players to this team.' ?></p>
    <?php if ($canInvitePlayers): ?>
    <a href="/players" class="inline-block mt-3 px-5 py-2 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors text-sm">Browse Players</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isInstructor): ?>
<?php
$clubNamesById = [];
foreach ($clubs as $_club) {
    $clubNamesById[(string)($_club['id'] ?? '')] = (string)($_club['name'] ?? '');
}
?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">admin_panel_settings</span> Instructor Team Controls</h3>
<p class="text-sm text-muted mb-4">Choose a team for each player. The current team stays selected until you assign a new one.</p>

<?php if (!empty($players)): ?>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead>
<tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
    <th class="p-3">Player</th>
    <th class="p-3">Role</th>
    <th class="p-3">Team</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
<?php foreach ($players as $p): ?>
<?php
    $playerUid = (string)($p['uid'] ?? '');
    $clubAssign = trim((string)($p['club_assign'] ?? ''));
    $clubName = $clubNamesById[$clubAssign] ?? ($clubAssign !== '' ? $clubAssign : 'Unassigned');
?>
<tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
    <td class="p-3 font-medium">
        <?= htmlspecialchars($p['fname'] ?? 'Unknown') ?>
        <?php if (!empty($p['email'])): ?>
        <span class="text-xs text-muted block"><?= htmlspecialchars($p['email']) ?></span>
        <?php endif; ?>
    </td>
    <td class="p-3">
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide <?= strtolower((string)($p['role'] ?? 'player')) === 'instructor' ? 'bg-warning/20 text-warning' : 'bg-primary/20 text-primary' ?>">
            <?= htmlspecialchars((string)($p['role'] ?? 'player')) ?>
        </span>
    </td>
    <td class="p-3">
        <form method="POST" action="/teams/member/move" class="flex items-center gap-2" hx-boost="false" data-team-move-form data-move-action="/teams/member/move" data-remove-action="/teams/member/remove">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="player_uid" value="<?= htmlspecialchars($playerUid) ?>">
            <input type="hidden" value="<?= htmlspecialchars($clubAssign) ?>" data-current-club-id>
            <div class="relative min-w-[180px]" data-team-move-shell>
                <select name="target_club_id" class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark text-xs focus:border-primary outline-none transition-opacity" data-team-move-select>
                    <option value="" <?= $clubAssign === '' ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($clubs as $c): ?>
                    <?php $clubId = (string)($c['id'] ?? ''); ?>
                    <option value="<?= (int)($c['id'] ?? 0) ?>" <?= $clubId === $clubAssign ? 'selected' : '' ?>><?= htmlspecialchars($c['name'] ?? 'Unknown Team') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-0 hidden items-center justify-end rounded-lg bg-white/60 pr-3 dark:bg-background-dark/65" data-team-move-overlay aria-hidden="true">
                    <span class="h-4 w-4 rounded-full border-2 border-primary/25 border-t-primary animate-spin"></span>
                </div>
            </div>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p class="text-muted text-sm">No players found.</p>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- Pending Invites -->
<?php if (!empty($myInvites)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">mail</span> Your Invites</h3>
<div class="space-y-3">
<?php foreach ($myInvites as $inv): ?>
<div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531]">
    <div>
        <p class="text-sm font-medium">Club invite</p>
        <p class="text-xs text-muted">Status: <?= htmlspecialchars(ucfirst($inv['status'] ?? 'pending')) ?></p>
    </div>
    <span class="px-2 py-1 rounded text-xs font-bold <?= ($inv['status'] ?? '') === 'accepted' ? 'bg-primary/20 text-primary' : 'bg-warning/20 text-warning' ?>"><?= ucfirst($inv['status'] ?? 'pending') ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- Search and Actions -->
<div class="flex flex-col sm:flex-row gap-3 mb-6">
<!-- Search Bar -->
<div class="relative w-full sm:w-64 group">
<div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-[#96c5a6] group-focus-within:text-primary transition-colors">
<span class="material-symbols-outlined">search</span>
</div>
<input class="block w-full p-2.5 pl-10 text-sm text-white bg-card-dark border border-white/10 rounded-lg placeholder-[#96c5a6] focus:ring-2 focus:ring-primary focus:border-primary transition-all" placeholder="Search teams or players..." type="text"/>
</div>
<!-- Create Button -->
<?php if ($canManageTeams): ?>
<button class="flex flex-shrink-0 cursor-pointer items-center justify-center rounded-lg h-[42px] px-4 bg-primary hover:bg-primary/90 hover:shadow-lg transition-all duration-300 hover:scale-105 text-[#122117] gap-2 text-sm font-bold tracking-wide">
<span class="material-symbols-outlined text-[20px]">add</span>
<span>Create Team</span>
</button>
<?php endif; ?>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-white/10 rounded-xl p-6 flex flex-col gap-2 relative overflow-hidden group hover:border-primary/30 transition-all">
<div class="absolute right-0 top-0 p-4 opacity-10">
<span class="material-symbols-outlined text-6xl">shield</span>
</div>
<p class="text-muted text-sm font-medium">Your Club</p>
<p class="text-3xl font-bold"><?= $myClub ? htmlspecialchars($myClub['name'] ?? '—') : 'None' ?></p>
<p class="text-xs text-muted mt-1"><?= $myClub ? 'Level ' . (int)($myClub['level'] ?? 1) . ' · Rank #' . (int)($myClub['ranking'] ?? 0) : 'Create or join a club' ?></p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-white/10 rounded-xl p-6 flex flex-col gap-2 relative overflow-hidden group hover:border-primary/30 transition-all">
<div class="absolute right-0 top-0 p-4 opacity-10">
<span class="material-symbols-outlined text-6xl">groups</span>
</div>
<p class="text-muted text-sm font-medium">Team Members</p>
<p class="text-3xl font-bold"><?= count($teamMembers) ?></p>
<p class="text-xs text-muted mt-1">Players on your team</p>
</div>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-white/10 rounded-xl p-6 flex flex-col gap-2 relative overflow-hidden group hover:border-primary/30 transition-all">
<div class="absolute right-0 top-0 p-4 opacity-10">
<span class="material-symbols-outlined text-6xl">mail</span>
</div>
<p class="text-muted text-sm font-medium">Invites</p>
<p class="text-3xl font-bold"><?= count($myInvites) ?></p>
<p class="text-xs text-muted mt-1">Pending invites</p>
</div>
</div>

<!-- Team Members Table -->
<?php if ($myClub && !empty($teamMembers)): ?>
<div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-white/10 rounded-xl overflow-hidden mb-6">
<div class="p-6 border-b border-slate-200 dark:border-white/5">
<h3 class="text-lg font-bold"><?= htmlspecialchars($myClub['name'] ?? 'My Team') ?> — Squad</h3>
</div>
<div class="overflow-x-auto">
<table class="w-full text-left border-collapse">
<thead>
<tr class="border-b border-slate-200 dark:border-white/5 bg-slate-50 dark:bg-white/5">
<th class="p-4 text-xs font-semibold text-muted uppercase tracking-wider">Player</th>
<th class="p-4 text-xs font-semibold text-muted uppercase tracking-wider text-center">Position</th>
<th class="p-4 text-xs font-semibold text-muted uppercase tracking-wider text-center">Country</th>
<th class="p-4 text-xs font-semibold text-muted uppercase tracking-wider text-center">Level</th>
<th class="p-4 text-xs font-semibold text-muted uppercase tracking-wider text-center">Role</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-white/5">
<?php foreach ($teamMembers as $member): ?>
<tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors">
<td class="p-4">
<div class="flex items-center gap-3">
<div class="size-10 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-lg"><?= strtoupper(substr($member['fname'] ?? '?', 0, 1)) ?></div>
<div>
<p class="font-medium text-sm"><?= htmlspecialchars($member['fname'] ?? 'Unknown') ?>
<?php if (($member['uid'] ?? '') === ($account['uid'] ?? '')): ?>
<span class="text-xs text-primary font-bold">(You)</span>
<?php endif; ?>
</p>
<?php if (!empty($member['email'])): ?>
<p class="text-xs text-muted"><?= htmlspecialchars($member['email']) ?></p>
<?php endif; ?>
</div>
</div>
</td>
<td class="p-4 text-center text-sm"><?= htmlspecialchars($member['position'] ?? '—') ?></td>
<td class="p-4 text-center text-sm"><?= htmlspecialchars($member['country'] ?? '—') ?></td>
<td class="p-4 text-center"><span class="font-bold"><?= (int)($member['level'] ?? 1) ?></span></td>
<td class="p-4 text-center">
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= strtolower((string)($member['role'] ?? 'player')) === 'instructor' ? 'bg-warning/20 text-warning' : 'bg-primary/20 text-primary' ?>">
<?= ucfirst(htmlspecialchars((string)($member['role'] ?? 'player'))) ?>
</span>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php elseif (!$myClub): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-12 text-center border border-slate-200 dark:border-[#264531] mb-6">
<span class="material-symbols-outlined text-5xl text-muted mb-3 block">shield</span>
<h3 class="text-lg font-bold mb-2">No Team Yet</h3>
<p class="text-muted text-sm"><?= $canManageTeams ? 'Create a club above or assign players once it is ready.' : 'Ask an instructor to assign you to a team.' ?></p>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>

</div>
</div>
<script>
(function () {
    const moveForms = Array.from(document.querySelectorAll('[data-team-move-form]'));
    if (!moveForms.length) {
        return;
    }

    moveForms.forEach((form) => {
        const select = form.querySelector('[data-team-move-select]');
        const overlay = form.querySelector('[data-team-move-overlay]');
        const shell = form.querySelector('[data-team-move-shell]');
        const currentClubInput = form.querySelector('[data-current-club-id]');
        const moveAction = form.dataset.moveAction || '/teams/member/move';
        const removeAction = form.dataset.removeAction || '/teams/member/remove';

        if (!select) {
            return;
        }

        const resetState = () => {
            form.dataset.submitting = 'false';
            select.classList.remove('opacity-70');
            if (shell) {
                shell.classList.remove('pointer-events-none');
            }
            if (overlay) {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }
        };

        select.addEventListener('change', function () {
            if (form.dataset.submitting === 'true') {
                return;
            }

            const nextValue = String(select.value || '');
            const currentValue = String(currentClubInput?.value || '');

            if (nextValue === currentValue) {
                form.action = nextValue === '' ? removeAction : moveAction;
                resetState();
                return;
            }

            form.dataset.submitting = 'true';
            form.action = nextValue === '' ? removeAction : moveAction;
            select.classList.add('opacity-70');
            if (shell) {
                shell.classList.add('pointer-events-none');
            }
            if (overlay) {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }

            form.requestSubmit();
        });
    });
})();
</script>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

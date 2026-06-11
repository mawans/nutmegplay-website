<?php
$pageTitle = 'Admin Panel';
$pageHeading = 'Admin Panel';
$pageDescription = 'Manage users, matches, registrations, and website controls';
$currentPage = 'admin';
$allPlayers = $allPlayers ?? [];
$allChallenges = $allChallenges ?? [];
$totalPlayers = $totalPlayers ?? 0;
$totalMatches = $totalMatches ?? 0;
$totalClubs = $totalClubs ?? 0;
$completedCount = $completedCount ?? 0;
$instructors = $instructors ?? [];
$admins = $admins ?? [];
$allMatches = $allMatches ?? [];
$pendingMatches = $pendingMatches ?? [];
$upcomingMatches = $upcomingMatches ?? [];
$account = $account ?? [];
$error = $error ?? '';
$success = $success ?? '';
$csrfToken = \App\Core\Auth::csrfToken();

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
    <?php require_once BASE_PATH . '/includes/topnav.php'; ?>

    <div id="main-content"
        class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">
        <div class="p-4 md:p-6 lg:p-8 w-full flex-1">
            <div class="w-full">

                <!-- Flash Messages -->
                <?php if ($error): ?>
                <div
                    class="bg-accent-danger/10 border border-accent-danger/30 text-accent-danger rounded-xl p-4 mb-6 flex items-center gap-3">
                    <span class="material-symbols-outlined">error</span>
                    <p class="text-sm font-medium"><?= htmlspecialchars($error) ?></p>
                </div>
                <?php endif; ?>
                <?php if ($success): ?>
                <div
                    class="bg-primary/10 border border-primary/30 text-primary rounded-xl p-4 mb-6 flex items-center gap-3">
                    <span class="material-symbols-outlined">check_circle</span>
                    <p class="text-sm font-medium"><?= htmlspecialchars($success) ?></p>
                </div>
                <?php endif; ?>

                <!-- Overview Stats -->
                <section class="mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-bold text-muted uppercase tracking-wider">Platform Overview</h3>
                        <span
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span> Admin Access
                        </span>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div
                            class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 rounded-lg hover:border-primary/30 transition-all">
                            <div class="flex items-center gap-2 text-muted mb-1">
                                <span class="material-symbols-outlined text-primary"
                                    style="font-size:20px">groups</span>
                                <span class="text-xs font-medium">Players</span>
                            </div>
                            <p class="text-2xl font-bold"><?= $totalPlayers ?></p>
                        </div>
                        <div
                            class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 rounded-lg hover:border-primary/30 transition-all">
                            <div class="flex items-center gap-2 text-muted mb-1">
                                <span class="material-symbols-outlined text-primary"
                                    style="font-size:20px">school</span>
                                <span class="text-xs font-medium">Instructors</span>
                            </div>
                            <p class="text-2xl font-bold"><?= count($instructors) ?></p>
                        </div>
                        <div
                            class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 rounded-lg hover:border-primary/30 transition-all">
                            <div class="flex items-center gap-2 text-muted mb-1">
                                <span class="material-symbols-outlined text-primary"
                                    style="font-size:20px">admin_panel_settings</span>
                                <span class="text-xs font-medium">Admins</span>
                            </div>
                            <p class="text-2xl font-bold"><?= count($admins) ?></p>
                        </div>
                        <div
                            class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 rounded-lg hover:border-primary/30 transition-all">
                            <div class="flex items-center gap-2 text-muted mb-1">
                                <span class="material-symbols-outlined text-primary"
                                    style="font-size:20px">sports_soccer</span>
                                <span class="text-xs font-medium">Total Matches</span>
                            </div>
                            <p class="text-2xl font-bold"><?= $totalMatches ?></p>
                        </div>
                        <div
                            class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 rounded-lg hover:border-primary/30 transition-all">
                            <div class="flex items-center gap-2 text-muted mb-1">
                                <span class="material-symbols-outlined text-primary"
                                    style="font-size:20px">check_circle</span>
                                <span class="text-xs font-medium">Completed</span>
                            </div>
                            <p class="text-2xl font-bold"><?= $completedCount ?></p>
                        </div>
                        <div
                            class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] p-5 rounded-lg hover:border-primary/30 transition-all">
                            <div class="flex items-center gap-2 text-muted mb-1">
                                <span class="material-symbols-outlined text-primary"
                                    style="font-size:20px">shield</span>
                                <span class="text-xs font-medium">Teams</span>
                            </div>
                            <p class="text-2xl font-bold"><?= $totalClubs ?></p>
                        </div>
                    </div>
                </section>

                <!-- Tab Navigation -->
                <div class="mb-6 border-b border-slate-200 dark:border-[#264531]">
                    <nav class="flex flex-wrap gap-1 -mb-px" id="admin-tabs">
                        <button onclick="switchTab('users')" data-tab="users"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-bold border-b-2 border-primary text-primary transition-all">
                            <span class="material-symbols-outlined text-sm">manage_accounts</span> User Management
                        </button>
                        <button onclick="switchTab('matches')" data-tab="matches"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">sports_soccer</span> Match Approval
                        </button>
                        <button onclick="switchTab('registrations')" data-tab="registrations"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">person_add</span> Player Registrations
                        </button>
                        <button onclick="switchTab('matchmaking')" data-tab="matchmaking"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">handshake</span> Matchmaking
                        </button>
                        <button onclick="switchTab('videos')" data-tab="videos"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">video_library</span> Video Management
                        </button>
                        <button onclick="switchTab('locations')" data-tab="locations"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">location_on</span> Locations & Pitches
                        </button>
                        <button onclick="switchTab('announcements')" data-tab="announcements"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">campaign</span> Announcements
                        </button>
                        <button onclick="switchTab('challenges')" data-tab="challenges"
                            class="admin-tab-btn inline-flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-muted hover:text-white hover:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-sm">emoji_events</span> Challenges
                        </button>
                    </nav>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: User Management                                        -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-users" class="admin-tab-panel">

                    <!-- Create User Form -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">person_add</span> Create New User
                        </h3>
                        <form method="POST" action="/admin/user/create"
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" hx-boost="false">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">First
                                    Name *</label>
                                <input type="text" name="fname" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="First name">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Last
                                    Name</label>
                                <input type="text" name="lname"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="Last name">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Email
                                    *</label>
                                <input type="email" name="email" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="user@example.com">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Password
                                    *</label>
                                <div class="relative">
                                    <input id="admin-create-password" type="password" name="password" required
                                        minlength="6"
                                        class="w-full px-3 pr-11 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                        placeholder="Min 6 characters">
                                    <button type="button"
                                        class="password-toggle absolute right-3 top-1/2 -translate-y-1/2 text-muted hover:text-primary transition-colors"
                                        data-target="admin-create-password" aria-label="Show password"
                                        aria-pressed="false">
                                        <span
                                            class="material-symbols-outlined text-[20px] leading-none">visibility</span>
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Role
                                    *</label>
                                <select name="role" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                    <option value="player">Player</option>
                                    <option value="instructor">Instructor</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Position</label>
                                <select name="position"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                    <option value="">Select position</option>
                                    <option value="GK">Goalkeeper (GK)</option>
                                    <option value="CB">Centre Back (CB)</option>
                                    <option value="LB">Left Back (LB)</option>
                                    <option value="RB">Right Back (RB)</option>
                                    <option value="CDM">Defensive Midfielder (CDM)</option>
                                    <option value="CM">Central Midfielder (CM)</option>
                                    <option value="CAM">Attacking Midfielder (CAM)</option>
                                    <option value="LW">Left Wing (LW)</option>
                                    <option value="RW">Right Wing (RW)</option>
                                    <option value="ST">Striker (ST)</option>
                                    <option value="CF">Centre Forward (CF)</option>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Country</label>
                                <input type="text" name="country"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="e.g. United Kingdom">
                            </div>
                            <div class="flex items-end">
                                <button type="submit"
                                    class="w-full px-4 py-2.5 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-sm">person_add</span> Create User
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- All Users Table -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary">groups</span> All Users
                                (<?= count($allPlayers) ?>)
                            </h3>
                            <div class="flex items-center gap-2">
                                <input type="text" id="user-search" onkeyup="filterUsers()"
                                    placeholder="Search users..."
                                    class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-xs w-48">
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm" id="users-table">
                                <thead>
                                    <tr
                                        class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
                                        <th class="p-3">#</th>
                                        <th class="p-3">Name</th>
                                        <th class="p-3">Email</th>
                                        <th class="p-3">Role</th>
                                        <th class="p-3">Position</th>
                                        <th class="p-3">Country</th>
                                        <th class="p-3">Joined</th>
                                        <th class="p-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
                                    <?php foreach ($allPlayers as $i => $p): ?>
                                    <?php
    $pRole = strtolower($p['role'] ?? 'player');
    $roleBadge = match($pRole) {
        'admin'      => 'bg-accent-danger/20 text-accent-danger',
        'instructor' => 'bg-warning/20 text-warning',
        default      => 'bg-primary/20 text-primary',
    };
    $isCurrentUser = ((int)($p['id'] ?? 0)) === ((int)($account['id'] ?? 0));
?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors user-row">
                                        <td class="p-3 text-muted"><?= $i + 1 ?></td>
                                        <td class="p-3 font-medium">
                                            <div class="flex items-center gap-2">
                                                <div
                                                    class="size-8 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold shrink-0">
                                                    <?= strtoupper(substr($p['fname'] ?? '?', 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <span
                                                        class="user-name"><?= htmlspecialchars(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?></span>
                                                    <?php if ($isCurrentUser): ?>
                                                    <span class="text-[10px] text-primary ml-1">(You)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-3 text-xs font-mono user-email">
                                            <?= htmlspecialchars($p['email'] ?? '') ?></td>
                                        <td class="p-3">
                                            <span class="px-2 py-0.5 rounded text-xs font-bold <?= $roleBadge ?>">
                                                <?= ucfirst($pRole) ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-xs"><?= htmlspecialchars($p['position'] ?? '—') ?></td>
                                        <td class="p-3 text-xs"><?= htmlspecialchars($p['country'] ?? '—') ?></td>
                                        <td class="p-3 text-xs text-muted">
                                            <?= htmlspecialchars(substr($p['created_at'] ?? '', 0, 10)) ?></td>
                                        <td class="p-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <!-- Role Change -->
                                                <form method="POST" action="/admin/user/role"
                                                    class="inline-flex items-center gap-1" hx-boost="false">
                                                    <input type="hidden" name="_csrf"
                                                        value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="user_id"
                                                        value="<?= (int)($p['id'] ?? 0) ?>">
                                                    <select name="role"
                                                        class="px-2 py-1 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark text-xs outline-none"
                                                        <?= $isCurrentUser ? 'disabled' : '' ?>>
                                                        <option value="player"
                                                            <?= $pRole === 'player' ? 'selected' : '' ?>>Player</option>
                                                        <option value="instructor"
                                                            <?= $pRole === 'instructor' ? 'selected' : '' ?>>Instructor
                                                        </option>
                                                        <option value="admin"
                                                            <?= $pRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                    </select>
                                                    <?php if (!$isCurrentUser): ?>
                                                    <button type="submit"
                                                        class="px-2 py-1 bg-primary/10 text-primary hover:bg-primary hover:text-white text-xs font-bold rounded transition-colors"
                                                        title="Update role">
                                                        <span class="material-symbols-outlined"
                                                            style="font-size:14px">save</span>
                                                    </button>
                                                    <?php endif; ?>
                                                </form>
                                                <!-- Delete -->
                                                <?php if (!$isCurrentUser): ?>
                                                <form method="POST" action="/admin/user/delete" class="inline"
                                                    hx-boost="false"
                                                    onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                    <input type="hidden" name="_csrf"
                                                        value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="user_id"
                                                        value="<?= (int)($p['id'] ?? 0) ?>">
                                                    <button type="submit"
                                                        class="px-2 py-1 bg-accent-danger/10 text-accent-danger hover:bg-accent-danger hover:text-white text-xs font-bold rounded transition-colors"
                                                        title="Delete user">
                                                        <span class="material-symbols-outlined"
                                                            style="font-size:14px">delete</span>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Match Approval                                         -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-matches" class="admin-tab-panel hidden">

                    <!-- Pending Matches -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-warning">pending</span> Pending Match Approvals
                            (<?= count($pendingMatches) ?>)
                        </h3>
                        <?php if (!empty($pendingMatches)): ?>
                        <div class="space-y-4">
                            <?php foreach ($pendingMatches as $pm): ?>
                            <div
                                class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531] hover:border-warning/30 transition-colors">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <span class="material-symbols-outlined text-warning">sports_soccer</span>
                                            <h4 class="font-bold">
                                                <?= htmlspecialchars(($pm['challanger'] ?? 'TBD') . ' vs ' . ($pm['opponent'] ?? 'TBD')) ?>
                                            </h4>
                                            <span
                                                class="px-2 py-0.5 rounded text-[10px] font-bold bg-warning/20 text-warning uppercase">Pending</span>
                                        </div>
                                        <div class="flex flex-wrap gap-4 text-xs text-muted">
                                            <span class="flex items-center gap-1"><span
                                                    class="material-symbols-outlined"
                                                    style="font-size:14px">calendar_today</span>
                                                <?= htmlspecialchars($pm['date'] ?? 'TBD') ?></span>
                                            <span class="flex items-center gap-1"><span
                                                    class="material-symbols-outlined"
                                                    style="font-size:14px">schedule</span>
                                                <?= htmlspecialchars($pm['time'] ?? 'TBD') ?></span>
                                            <span class="flex items-center gap-1"><span
                                                    class="material-symbols-outlined"
                                                    style="font-size:14px">location_on</span>
                                                <?= htmlspecialchars($pm['location'] ?? 'Not set') ?></span>
                                        </div>
                                    </div>
                                    <form method="POST" action="/admin/match/approve" class="flex gap-2"
                                        hx-boost="false">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="match_id" value="<?= (int)($pm['id'] ?? 0) ?>">
                                        <button type="submit" name="action" value="decline"
                                            class="px-4 py-2 rounded-lg border-2 border-accent-danger text-accent-danger hover:bg-accent-danger hover:text-white font-bold text-xs transition-all">
                                            Decline
                                        </button>
                                        <button type="submit" name="action" value="accept"
                                            class="px-4 py-2 rounded-lg bg-primary text-white hover:bg-green-600 font-bold text-xs transition-all shadow-[0_2px_8px_rgba(29,185,84,0.3)]">
                                            Approve Match
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8 text-muted">
                            <span class="material-symbols-outlined text-4xl mb-2 block">check_circle</span>
                            <p class="text-sm">No pending match approvals</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- All Matches Table -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">list</span> All Matches
                            (<?= count($allMatches) ?>)
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr
                                        class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
                                        <th class="p-3">ID</th>
                                        <th class="p-3">Match</th>
                                        <th class="p-3">Date</th>
                                        <th class="p-3">Location</th>
                                        <th class="p-3">Challenge</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3">Video</th>
                                        <th class="p-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
                                    <?php foreach ($allMatches as $m): ?>
                                    <?php
    $challStatus = strtolower($m['challange_status'] ?? 'pending');
    $matchStatus = strtolower($m['match_status'] ?? 'pending');
    $challBadge = match($challStatus) {
        'accepted' => 'bg-primary/20 text-primary',
        'declined' => 'bg-accent-danger/20 text-accent-danger',
        default    => 'bg-warning/20 text-warning',
    };
    $matchBadge = match($matchStatus) {
        'completed' => 'bg-primary/20 text-primary',
        default     => 'bg-warning/20 text-warning',
    };
?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
                                        <td class="p-3 text-xs font-mono text-muted">#<?= (int)($m['id'] ?? 0) ?></td>
                                        <td class="p-3 font-medium text-xs">
                                            <?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?>
                                        </td>
                                        <td class="p-3 text-xs font-mono"><?= htmlspecialchars($m['date'] ?? '') ?></td>
                                        <td class="p-3 text-xs"><?= htmlspecialchars($m['location'] ?? '—') ?></td>
                                        <td class="p-3"><span
                                                class="px-2 py-0.5 rounded text-[10px] font-bold <?= $challBadge ?>"><?= ucfirst($challStatus) ?></span>
                                        </td>
                                        <td class="p-3"><span
                                                class="px-2 py-0.5 rounded text-[10px] font-bold <?= $matchBadge ?>"><?= ucfirst($matchStatus) ?></span>
                                        </td>
                                        <td class="p-3">
                                            <?php if (!empty($m['video_url'])): ?>
                                            <span class="text-primary text-xs flex items-center gap-1"><span
                                                    class="material-symbols-outlined"
                                                    style="font-size:14px">videocam</span> Yes</span>
                                            <?php else: ?>
                                            <span class="text-muted text-xs">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 text-right">
                                            <?php if ($challStatus === 'pending'): ?>
                                            <form method="POST" action="/admin/match/approve" class="inline-flex gap-1"
                                                hx-boost="false">
                                                <input type="hidden" name="_csrf"
                                                    value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="match_id"
                                                    value="<?= (int)($m['id'] ?? 0) ?>">
                                                <button type="submit" name="action" value="accept"
                                                    class="px-2 py-1 bg-primary/10 text-primary hover:bg-primary hover:text-white text-[10px] font-bold rounded transition-colors">Approve</button>
                                                <button type="submit" name="action" value="decline"
                                                    class="px-2 py-1 bg-accent-danger/10 text-accent-danger hover:bg-accent-danger hover:text-white text-[10px] font-bold rounded transition-colors">Decline</button>
                                            </form>
                                            <?php elseif ($matchStatus !== 'completed'): ?>
                                            <form method="POST" action="/admin/match/approve" class="inline"
                                                hx-boost="false">
                                                <input type="hidden" name="_csrf"
                                                    value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="match_id"
                                                    value="<?= (int)($m['id'] ?? 0) ?>">
                                                <button type="submit" name="action" value="complete"
                                                    class="px-2 py-1 bg-primary/10 text-primary hover:bg-primary hover:text-white text-[10px] font-bold rounded transition-colors">Complete</button>
                                            </form>
                                            <?php else: ?>
                                            <span class="text-xs text-muted">Done</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Player Registrations                                   -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-registrations" class="admin-tab-panel hidden">

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                        <!-- Role Distribution -->
                        <div
                            class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                            <h3 class="text-sm font-bold text-muted uppercase tracking-wider mb-4">Role Distribution
                            </h3>
                            <?php
        $playerCount = 0; $instrCount = 0; $adminCount = 0;
        foreach ($allPlayers as $p) {
            $r = strtolower($p['role'] ?? 'player');
            if ($r === 'instructor') $instrCount++;
            elseif ($r === 'admin') $adminCount++;
            else $playerCount++;
        }
        $totalUsers = max(1, count($allPlayers));
        ?>
                            <div class="space-y-4">
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="font-medium">Players</span>
                                        <span class="font-bold text-primary"><?= $playerCount ?>
                                            (<?= round($playerCount / $totalUsers * 100) ?>%)</span>
                                    </div>
                                    <div class="w-full bg-slate-200 dark:bg-white/10 rounded-full h-2">
                                        <div class="bg-primary h-2 rounded-full transition-all"
                                            style="width: <?= round($playerCount / $totalUsers * 100) ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="font-medium">Instructors</span>
                                        <span class="font-bold text-warning"><?= $instrCount ?>
                                            (<?= round($instrCount / $totalUsers * 100) ?>%)</span>
                                    </div>
                                    <div class="w-full bg-slate-200 dark:bg-white/10 rounded-full h-2">
                                        <div class="bg-warning h-2 rounded-full transition-all"
                                            style="width: <?= round($instrCount / $totalUsers * 100) ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="font-medium">Admins</span>
                                        <span class="font-bold text-accent-danger"><?= $adminCount ?>
                                            (<?= round($adminCount / $totalUsers * 100) ?>%)</span>
                                    </div>
                                    <div class="w-full bg-slate-200 dark:bg-white/10 rounded-full h-2">
                                        <div class="bg-accent-danger h-2 rounded-full transition-all"
                                            style="width: <?= round($adminCount / $totalUsers * 100) ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Position Distribution -->
                        <div
                            class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                            <h3 class="text-sm font-bold text-muted uppercase tracking-wider mb-4">Position Distribution
                            </h3>
                            <?php
        $positions = [];
        foreach ($allPlayers as $p) {
            $pos = strtoupper($p['position'] ?? 'N/A');
            $positions[$pos] = ($positions[$pos] ?? 0) + 1;
        }
        arsort($positions);
        ?>
                            <div class="space-y-2 max-h-48 overflow-y-auto sidebar-scroll">
                                <?php foreach (array_slice($positions, 0, 10) as $pos => $cnt): ?>
                                <div
                                    class="flex items-center justify-between p-2 rounded bg-slate-50 dark:bg-[#16261d]">
                                    <span class="text-xs font-bold"><?= htmlspecialchars($pos) ?></span>
                                    <span class="text-xs font-mono text-primary"><?= $cnt ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Country Distribution -->
                        <div
                            class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                            <h3 class="text-sm font-bold text-muted uppercase tracking-wider mb-4">Country Distribution
                            </h3>
                            <?php
        $countries = [];
        foreach ($allPlayers as $p) {
            $c = $p['country'] ?? 'Unknown';
            $countries[$c] = ($countries[$c] ?? 0) + 1;
        }
        arsort($countries);
        ?>
                            <div class="space-y-2 max-h-48 overflow-y-auto sidebar-scroll">
                                <?php foreach (array_slice($countries, 0, 10) as $c => $cnt): ?>
                                <div
                                    class="flex items-center justify-between p-2 rounded bg-slate-50 dark:bg-[#16261d]">
                                    <span class="text-xs font-medium"><?= htmlspecialchars($c) ?></span>
                                    <span class="text-xs font-mono text-primary"><?= $cnt ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Registered Players Full Table -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">how_to_reg</span> Registered Players
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr
                                        class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
                                        <th class="p-3">#</th>
                                        <th class="p-3">Player</th>
                                        <th class="p-3">Email</th>
                                        <th class="p-3">Position</th>
                                        <th class="p-3">Country</th>
                                        <th class="p-3">Level</th>
                                        <th class="p-3">Role</th>
                                        <th class="p-3">Registered</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
                                    <?php foreach ($allPlayers as $i => $p): ?>
                                    <?php
    $pRole = strtolower($p['role'] ?? 'player');
    $roleBadge = match($pRole) {
        'admin'      => 'bg-accent-danger/20 text-accent-danger',
        'instructor' => 'bg-warning/20 text-warning',
        default      => 'bg-primary/20 text-primary',
    };
?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
                                        <td class="p-3 text-muted text-xs"><?= $i + 1 ?></td>
                                        <td class="p-3 font-medium">
                                            <div class="flex items-center gap-2">
                                                <div
                                                    class="size-8 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold shrink-0">
                                                    <?= strtoupper(substr($p['fname'] ?? '?', 0, 1)) ?>
                                                </div>
                                                <?= htmlspecialchars(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?>
                                            </div>
                                        </td>
                                        <td class="p-3 text-xs font-mono"><?= htmlspecialchars($p['email'] ?? '') ?>
                                        </td>
                                        <td class="p-3 text-xs"><?= htmlspecialchars($p['position'] ?? '—') ?></td>
                                        <td class="p-3 text-xs"><?= htmlspecialchars($p['country'] ?? '—') ?></td>
                                        <td class="p-3"><span
                                                class="px-2 py-0.5 rounded bg-primary/10 text-primary text-xs font-bold">Lv
                                                <?= (int)($p['level'] ?? 1) ?></span></td>
                                        <td class="p-3"><span
                                                class="px-2 py-0.5 rounded text-xs font-bold <?= $roleBadge ?>"><?= ucfirst($pRole) ?></span>
                                        </td>
                                        <td class="p-3 text-xs text-muted">
                                            <?= htmlspecialchars(substr($p['created_at'] ?? '', 0, 10)) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Matchmaking                                            -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-matchmaking" class="admin-tab-panel hidden">

                    <!-- Create Match Form (Admin can set everything) -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">add_circle</span> Create New Match
                        </h3>
                        <form method="POST" action="/matchmaking"
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" hx-boost="false">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Challenger</label>
                                <select name="challenger_id" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                    <option value="">Select player...</option>
                                    <?php foreach ($allPlayers as $p): ?>
                                    <option value="<?= htmlspecialchars($p['uid'] ?? '') ?>">
                                        <?= htmlspecialchars(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Opponent</label>
                                <select name="opponent_id" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                    <option value="">Select player...</option>
                                    <?php foreach ($allPlayers as $p): ?>
                                    <option value="<?= htmlspecialchars($p['uid'] ?? '') ?>">
                                        <?= htmlspecialchars(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Location
                                    / Venue</label>
                                <input type="text" name="location"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="e.g. Etihad Campus – Pitch 3">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Date</label>
                                <input type="date" name="date" value="<?= date('Y-m-d') ?>"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Time</label>
                                <input type="time" name="time" value="<?= date('H:i') ?>"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                            </div>
                            <div class="flex items-end">
                                <button type="submit"
                                    class="w-full px-4 py-2.5 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-sm">send</span> Create Match
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Upcoming Matches -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">event</span> Upcoming Matches
                            (<?= count($upcomingMatches) ?>)
                        </h3>
                        <?php if (!empty($upcomingMatches)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            <?php foreach ($upcomingMatches as $um): ?>
                            <div
                                class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531]">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-mono text-muted">#<?= (int)($um['id'] ?? 0) ?></span>
                                    <span
                                        class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($um['challange_status'] ?? '') === 'accepted' ? 'bg-primary/20 text-primary' : 'bg-warning/20 text-warning' ?>">
                                        <?= ucfirst($um['challange_status'] ?? 'pending') ?>
                                    </span>
                                </div>
                                <h4 class="font-bold text-sm mb-2">
                                    <?= htmlspecialchars(($um['challanger'] ?? 'TBD') . ' vs ' . ($um['opponent'] ?? 'TBD')) ?>
                                </h4>
                                <div class="text-xs text-muted space-y-1">
                                    <p class="flex items-center gap-1"><span class="material-symbols-outlined"
                                            style="font-size:14px">calendar_today</span>
                                        <?= htmlspecialchars($um['date'] ?? 'TBD') ?>
                                        <?= htmlspecialchars($um['time'] ?? '') ?></p>
                                    <p class="flex items-center gap-1"><span class="material-symbols-outlined"
                                            style="font-size:14px">location_on</span>
                                        <?= htmlspecialchars($um['location'] ?? 'Not set') ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8 text-muted">
                            <span class="material-symbols-outlined text-4xl mb-2 block">event_busy</span>
                            <p class="text-sm">No upcoming matches scheduled</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Video Management                                       -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-videos" class="admin-tab-panel hidden">

                    <!-- Assign Video to Match -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">cloud_upload</span> Assign Video to
                            Match
                        </h3>
                        <form method="POST" action="/admin/match/video" class="grid grid-cols-1 md:grid-cols-3 gap-4"
                            hx-boost="false">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Select
                                    Match</label>
                                <select name="match_id" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                    <option value="">Choose match...</option>
                                    <?php foreach ($allMatches as $m): ?>
                                    <option value="<?= (int)($m['id'] ?? 0) ?>">
                                        #<?= (int)($m['id'] ?? 0) ?> –
                                        <?= htmlspecialchars(($m['challanger'] ?? '') . ' vs ' . ($m['opponent'] ?? '')) ?>
                                        (<?= htmlspecialchars($m['date'] ?? '') ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Video
                                    URL</label>
                                <input type="url" name="video_url" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="https://youtube.com/watch?v=...">
                            </div>
                            <div class="flex items-end">
                                <button type="submit"
                                    class="w-full px-4 py-2.5 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-sm">link</span> Assign Video
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Matches with Videos -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">video_library</span> Matches with
                            Videos
                        </h3>
                        <?php
$matchesWithVideo = array_filter($allMatches, fn($m) => !empty($m['video_url']));
$matchesWithoutVideo = array_filter($allMatches, fn($m) => empty($m['video_url']));
?>
                        <?php if (!empty($matchesWithVideo)): ?>
                        <div class="space-y-3 mb-6">
                            <?php foreach ($matchesWithVideo as $m): ?>
                            <div
                                class="p-4 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531] flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-sm">
                                        <?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?>
                                    </p>
                                    <p class="text-xs text-muted"><?= htmlspecialchars($m['date'] ?? '') ?> · <span
                                            class="text-primary"><?= htmlspecialchars($m['video_status'] ?? 'uploaded') ?></span>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary"
                                        style="font-size:18px">videocam</span>
                                    <?php
        $vUrl = $m['video_url'] ?? '';
        $isLocal = str_starts_with($vUrl, '/videos/') || str_starts_with($vUrl, '/public/videos/');
        ?>
                                    <?php if (!$isLocal): ?>
                                    <a href="<?= htmlspecialchars($vUrl) ?>" target="_blank" rel="noopener"
                                        class="text-xs text-primary hover:underline">Watch</a>
                                    <?php else: ?>
                                    <span class="text-xs text-muted">Local file</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($matchesWithoutVideo)): ?>
                        <h4 class="text-sm font-bold text-muted uppercase tracking-wider mb-3">Matches Without Video
                            (<?= count($matchesWithoutVideo) ?>)</h4>
                        <div class="space-y-2">
                            <?php foreach (array_slice($matchesWithoutVideo, 0, 10) as $m): ?>
                            <div
                                class="p-3 rounded-lg bg-slate-50 dark:bg-[#16261d] border border-slate-100 dark:border-[#264531] flex items-center justify-between">
                                <div class="text-sm">
                                    <span class="font-medium">#<?= (int)($m['id'] ?? 0) ?></span>
                                    <span class="text-muted mx-1">–</span>
                                    <?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?>
                                    <span
                                        class="text-muted ml-2 text-xs"><?= htmlspecialchars($m['date'] ?? '') ?></span>
                                </div>
                                <span class="material-symbols-outlined text-muted"
                                    style="font-size:16px">videocam_off</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Locations & Pitches                                    -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-locations" class="admin-tab-panel hidden">

                    <!-- Assign Location Form -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">edit_location</span> Assign Location &
                            Pitch to Match
                        </h3>
                        <form method="POST" action="/admin/match/location"
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" hx-boost="false">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Select
                                    Match</label>
                                <select name="match_id" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                    <option value="">Choose match...</option>
                                    <?php foreach ($allMatches as $m): ?>
                                    <option value="<?= (int)($m['id'] ?? 0) ?>">
                                        #<?= (int)($m['id'] ?? 0) ?> –
                                        <?= htmlspecialchars(($m['challanger'] ?? '') . ' vs ' . ($m['opponent'] ?? '')) ?>
                                        (<?= htmlspecialchars($m['date'] ?? '') ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Venue /
                                    Location</label>
                                <input type="text" name="location"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="e.g. Etihad Campus">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Pitch
                                    Number</label>
                                <input type="text" name="pitch"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="e.g. Pitch 3">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Update
                                    Date</label>
                                <input type="date" name="date"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Update
                                    Time</label>
                                <input type="time" name="time"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                            </div>
                            <div class="flex items-end">
                                <button type="submit"
                                    class="w-full px-4 py-2.5 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-sm">save</span> Update Match Details
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Current Match Locations -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531]">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">map</span> Current Match Locations
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr
                                        class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
                                        <th class="p-3">Match</th>
                                        <th class="p-3">Date / Time</th>
                                        <th class="p-3">Location</th>
                                        <th class="p-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
                                    <?php foreach ($allMatches as $m): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
                                        <td class="p-3 font-medium text-xs">
                                            <?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?>
                                        </td>
                                        <td class="p-3 text-xs font-mono">
                                            <?= htmlspecialchars(($m['date'] ?? '') . ' ' . ($m['time'] ?? '')) ?></td>
                                        <td class="p-3">
                                            <?php if (!empty($m['location'])): ?>
                                            <span class="flex items-center gap-1 text-xs">
                                                <span class="material-symbols-outlined text-primary"
                                                    style="font-size:14px">location_on</span>
                                                <?= htmlspecialchars($m['location']) ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-xs text-accent-danger flex items-center gap-1">
                                                <span class="material-symbols-outlined"
                                                    style="font-size:14px">location_off</span>
                                                Not assigned
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3"><span
                                                class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($m['match_status'] ?? '') === 'completed' ? 'bg-primary/20 text-primary' : 'bg-warning/20 text-warning' ?>"><?= ucfirst($m['match_status'] ?? 'pending') ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Announcements                                          -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-announcements" class="admin-tab-panel hidden">

                    <!-- Create Announcement -->
                    <div
                        class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">campaign</span> Create Announcement
                        </h3>
                        <form method="POST" action="/admin/announcement" class="flex flex-col gap-4" hx-boost="false">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Title
                                    *</label>
                                <input type="text" name="title" required
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                    placeholder="Announcement title">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Message
                                    *</label>
                                <textarea name="message" required rows="4"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm resize-y"
                                    placeholder="Write your announcement..."></textarea>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit"
                                    class="px-6 py-2.5 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm">send</span> Publish Announcement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- TAB: Challenges                                               -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div id="tab-challenges" class="admin-tab-panel hidden">

                    <!-- Create Challenge -->
                    <div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
                        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">emoji_events</span> Create Challenge
                        </h3>
                        <form method="POST" action="/admin/challenge/create" class="space-y-4" hx-boost="false">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Title *</label>
                                    <input type="text" name="title" required
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                                        placeholder="e.g. Score 5 Goals">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Type *</label>
                                    <select name="type" required
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Description</label>
                                <textarea name="description" rows="2"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm resize-y"
                                    placeholder="Describe the challenge..."></textarea>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">XP Reward *</label>
                                    <input type="number" name="xp_reward" required min="1" value="50"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Target Value</label>
                                    <input type="number" name="target_value" min="1" value="1" placeholder="e.g. 5"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Metric</label>
                                    <input type="text" name="metric" placeholder="e.g. goals, matches, wins"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Start Date</label>
                                    <input type="date" name="start_date"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">End Date</label>
                                    <input type="date" name="end_date"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm">
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit"
                                    class="px-6 py-2.5 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm">add</span> Create Challenge
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Existing Challenges -->
                    <div class="bg-white dark:bg-card-dark rounded-xl shadow-sm border border-slate-200 dark:border-[#264531] overflow-hidden">
                        <div class="p-4 border-b border-slate-200 dark:border-[#264531] flex items-center justify-between">
                            <h3 class="text-base font-bold flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-sm">list</span>
                                All Challenges (<?= count($allChallenges) ?>)
                            </h3>
                        </div>
                        <?php if (empty($allChallenges)): ?>
                        <div class="p-10 text-center">
                            <span class="material-symbols-outlined text-3xl text-muted mb-2 block">emoji_events</span>
                            <p class="text-sm text-muted">No challenges created yet.</p>
                        </div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-[#264531] bg-slate-50 dark:bg-[#122017]">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted">Title</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted">Type</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted">XP</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted">Target</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted">Status</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted">Dates</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-[#264531]">
                                    <?php foreach ($allChallenges as $ch): ?>
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-[#1a2c22] transition-colors">
                                        <td class="px-4 py-3">
                                            <p class="text-sm font-semibold"><?= htmlspecialchars($ch['title'] ?? '') ?></p>
                                            <?php if (!empty($ch['description'])): ?>
                                            <p class="text-xs text-muted truncate max-w-xs"><?= htmlspecialchars($ch['description']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php
                                            $typeBg = match($ch['type'] ?? '') {
                                                'daily' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
                                                'weekly' => 'bg-purple-500/10 text-purple-400 border-purple-500/20',
                                                'monthly' => 'bg-orange-500/10 text-orange-400 border-orange-500/20',
                                                default => 'bg-slate-500/10 text-slate-400 border-slate-500/20',
                                            };
                                            ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border <?= $typeBg ?> capitalize">
                                                <?= htmlspecialchars($ch['type'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="text-sm font-bold text-yellow-400"><?= intval($ch['xp_reward'] ?? 0) ?> XP</span>
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm">
                                            <?= intval($ch['target_value'] ?? 1) ?> <?= htmlspecialchars($ch['metric'] ?? '') ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if (!empty($ch['is_active']) && $ch['is_active'] !== 'false'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-primary"></span> Active
                                            </span>
                                            <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-500/10 text-slate-400 border border-slate-500/20">
                                                Inactive
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center text-xs text-muted">
                                            <?php if (!empty($ch['start_date'])): ?>
                                            <?= date('M j', strtotime($ch['start_date'])) ?>
                                            <?php if (!empty($ch['end_date'])): ?> – <?= date('M j', strtotime($ch['end_date'])) ?><?php endif; ?>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <form method="POST" action="/admin/challenge/toggle" hx-boost="false">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="challenge_id" value="<?= htmlspecialchars($ch['id'] ?? '') ?>">
                                                    <button type="submit" title="Toggle active" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-[#1a2c22] text-muted hover:text-primary transition-colors">
                                                        <span class="material-symbols-outlined text-sm"><?= (!empty($ch['is_active']) && $ch['is_active'] !== 'false') ? 'toggle_on' : 'toggle_off' ?></span>
                                                    </button>
                                                </form>
                                                <form method="POST" action="/admin/challenge/delete" hx-boost="false" onsubmit="return confirm('Delete this challenge?')">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="challenge_id" value="<?= htmlspecialchars($ch['id'] ?? '') ?>">
                                                    <button type="submit" title="Delete" class="p-1.5 rounded-lg hover:bg-accent-danger/10 text-muted hover:text-accent-danger transition-colors">
                                                        <span class="material-symbols-outlined text-sm">delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- end w-full -->
        </div><!-- end p-4 -->
    </div><!-- end main-content -->
</div><!-- end flex -->

<!-- Footer -->
<footer class="ml-0 md:ml-64 text-center py-4 border-t border-slate-200 dark:border-[#264531]">
    <p class="text-xs text-muted">Nutmeg Admin Panel v1.0 · © 2026 NutmegPlay</p>
</footer>

</div>
</div>

<script>
function switchTab(tabName) {
    // Hide all panels
    document.querySelectorAll('.admin-tab-panel').forEach(panel => {
        panel.classList.add('hidden');
    });
    // Show target panel
    const target = document.getElementById('tab-' + tabName);
    if (target) target.classList.remove('hidden');

    // Update tab buttons
    document.querySelectorAll('.admin-tab-btn').forEach(btn => {
        btn.classList.remove('border-primary', 'text-primary', 'font-bold');
        btn.classList.add('border-transparent', 'text-muted', 'font-medium');
    });
    const activeBtn = document.querySelector('[data-tab="' + tabName + '"]');
    if (activeBtn) {
        activeBtn.classList.remove('border-transparent', 'text-muted', 'font-medium');
        activeBtn.classList.add('border-primary', 'text-primary', 'font-bold');
    }

    // Save active tab
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '/admin#' + tabName);
    }
}

// User search filter
function filterUsers() {
    const query = document.getElementById('user-search').value.toLowerCase();
    document.querySelectorAll('.user-row').forEach(row => {
        const name = (row.querySelector('.user-name')?.textContent || '').toLowerCase();
        const email = (row.querySelector('.user-email')?.textContent || '').toLowerCase();
        row.style.display = (name.includes(query) || email.includes(query)) ? '' : 'none';
    });
}

// Restore tab from URL hash
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
        switchTab(hash);
    }
});
</script>

<?php require_once BASE_PATH . "/includes/footer.php"; ?>

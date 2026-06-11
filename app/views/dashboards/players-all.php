<?php
$pageTitle = 'All Players';
$pageHeading = 'All Players';
$pageDescription = 'Browse and search all registered players';
$currentPage = 'players';

$account = $account ?? [];
$players = $players ?? [];
$allPlayers = $allPlayers ?? [];
$query = $query ?? '';
$error = $error ?? '';
$success = $success ?? '';

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

<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-5">
        <div>
            <h3 class="text-lg font-bold flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">manage_search</span>
                All Registered Players
            </h3>
            <p class="text-xs text-muted mt-1">Use search to filter players. Dropdown suggestions appear as you type.</p>
        </div>
        <a href="/players" class="inline-flex items-center gap-1 px-3 py-1.5 bg-primary/10 text-primary hover:bg-primary hover:text-white text-xs font-bold rounded-lg transition-colors w-fit">
            <span class="material-symbols-outlined" style="font-size:16px">arrow_back</span>
            Back to players tab
        </a>
    </div>

    <form method="GET" action="/players/all" class="relative mb-5" hx-boost="false" autocomplete="off">
        <label for="player-search" class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Search players</label>
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <input
                    id="player-search"
                    name="q"
                    type="text"
                    value="<?= htmlspecialchars($query) ?>"
                    placeholder="Type player name or email"
                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary outline-none text-sm"
                >
                <div id="player-suggestions" class="absolute z-20 mt-1 w-full rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-card-dark shadow-lg hidden max-h-56 overflow-y-auto"></div>
            </div>
            <button type="submit" class="px-4 py-2 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors text-sm inline-flex items-center justify-center gap-1">
                <span class="material-symbols-outlined" style="font-size:18px">search</span>
                Search
            </button>
            <a href="/players/all" class="px-4 py-2 border border-slate-200 dark:border-[#264531] hover:border-primary text-sm rounded-lg inline-flex items-center justify-center gap-1 transition-colors">
                <span class="material-symbols-outlined" style="font-size:18px">restart_alt</span>
                Reset
            </a>
        </div>
    </form>

    <div class="text-xs text-muted mb-3">
        Showing <?= count($players) ?> of <?= count($allPlayers) ?> players
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" id="players-table">
            <thead>
                <tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
                    <th class="p-3">#</th>
                    <th class="p-3">Name</th>
                    <th class="p-3">Email</th>
                    <th class="p-3">Position</th>
                    <th class="p-3">Country</th>
                    <th class="p-3">Joined</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
                <?php foreach ($players as $i => $p): ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
                    <td class="p-3 text-muted"><?= $i + 1 ?></td>
                    <td class="p-3 font-medium">
                        <div class="flex items-center gap-2">
                            <div class="size-8 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold shrink-0">
                                <?= strtoupper(substr((string)($p['fname'] ?? '?'), 0, 1)) ?>
                            </div>
                            <span><?= htmlspecialchars(trim((string)($p['fname'] ?? '') . ' ' . (string)($p['lname'] ?? ''))) ?></span>
                        </div>
                    </td>
                    <td class="p-3 text-xs font-mono"><?= htmlspecialchars((string)($p['email'] ?? '—')) ?></td>
                    <td class="p-3"><?= htmlspecialchars((string)($p['position'] ?? '—')) ?></td>
                    <td class="p-3"><?= htmlspecialchars((string)($p['country'] ?? '—')) ?></td>
                    <td class="p-3 text-muted"><?= htmlspecialchars(substr((string)($p['created_at'] ?? ''), 0, 10) ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($players)): ?>
                <tr>
                    <td colspan="6" class="p-8 text-center text-muted">
                        <span class="material-symbols-outlined text-3xl mb-2 block">person_search</span>
                        No players matched your search.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<footer class="mt-8 pb-8 text-center text-xs md:text-sm text-muted">
© 2026 NutmegPlay. All rights reserved.
</footer>

</div>
</div>
</div>
</div>

<script>
(function () {
    const allPlayers = <?= json_encode(array_map(static function (array $player): array {
        return [
            'name' => trim((string)($player['fname'] ?? '') . ' ' . (string)($player['lname'] ?? '')),
            'email' => (string)($player['email'] ?? ''),
        ];
    }, $allPlayers), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    const input = document.getElementById('player-search');
    const dropdown = document.getElementById('player-suggestions');

    if (!input || !dropdown) {
        return;
    }

    function hideSuggestions() {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
    }

    function showSuggestions(items) {
        if (!items.length) {
            hideSuggestions();
            return;
        }

        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        dropdown.innerHTML = items.map((item) => {
            const label = item.name || item.email || 'Unknown player';
            const sub = item.email && item.email !== item.name ? `<span class="block text-[11px] text-muted">${escapeHtml(item.email)}</span>` : '';
            const value = item.name ? item.name : item.email;
            return `<button type="button" class="w-full text-left px-3 py-2 hover:bg-slate-100 dark:hover:bg-[#1f3629] text-sm" data-value="${escapeHtml(value)}">${escapeHtml(label)}${sub}</button>`;
        }).join('');

        dropdown.classList.remove('hidden');
    }

    input.addEventListener('input', function () {
        const term = input.value.trim().toLowerCase();
        if (term.length < 1) {
            hideSuggestions();
            return;
        }

        const results = allPlayers
            .filter((player) => {
                const name = (player.name || '').toLowerCase();
                const email = (player.email || '').toLowerCase();
                return name.includes(term) || email.includes(term);
            })
            .slice(0, 8);

        showSuggestions(results);
    });

    dropdown.addEventListener('click', function (event) {
        const button = event.target.closest('button[data-value]');
        if (!button) {
            return;
        }

        input.value = button.getAttribute('data-value') || '';
        hideSuggestions();
    });

    document.addEventListener('click', function (event) {
        if (!dropdown.contains(event.target) && event.target !== input) {
            hideSuggestions();
        }
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

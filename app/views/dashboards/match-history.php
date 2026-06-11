<?php
$pageTitle = 'Recent Matches';
$pageHeading = 'Recent Matches';
$isPlayer = strtolower((string)($account['role'] ?? 'player')) === 'player';
$pageDescription = $isPlayer
    ? 'Recent matches for your side'
    : 'Recent matches feed for all players';
$currentPage = 'match-history';
$userMatches = $userMatches ?? [];
$userTotals = $userTotals ?? [];
$account = $account ?? [];

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
<?php require_once BASE_PATH . '/includes/topnav.php'; ?>

<div id="main-content" class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">

            <div class="p-4 md:p-6 lg:p-8 w-full flex-1">
                <div class="w-full">

<!-- Dynamic Match History from DB -->
<?php if (!empty($userMatches)): ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-6 shadow-sm border border-slate-200 dark:border-[#264531] mb-6">
<h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-primary">history</span> Recent Matches (<?= count($userMatches) ?>)</h3>

<!-- Stats Summary -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
<div class="text-center p-3 rounded-lg bg-slate-50 dark:bg-[#16261d]">
    <p class="text-2xl font-bold text-primary"><?= (int)($userTotals['matches'] ?? 0) ?></p>
    <p class="text-xs text-muted">Matches</p>
</div>
<div class="text-center p-3 rounded-lg bg-slate-50 dark:bg-[#16261d]">
    <p class="text-2xl font-bold text-primary"><?= (int)($userTotals['wins'] ?? 0) ?></p>
    <p class="text-xs text-muted">Wins</p>
</div>
<div class="text-center p-3 rounded-lg bg-slate-50 dark:bg-[#16261d]">
    <p class="text-2xl font-bold"><?= (int)($userTotals['goals'] ?? 0) ?></p>
    <p class="text-xs text-muted">Goals</p>
</div>
<div class="text-center p-3 rounded-lg bg-slate-50 dark:bg-[#16261d]">
    <p class="text-2xl font-bold"><?= (int)($userTotals['assists'] ?? 0) ?></p>
    <p class="text-xs text-muted">Assists</p>
</div>
</div>

<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead>
<tr class="text-xs uppercase text-muted border-b border-slate-200 dark:border-[#264531]">
    <th class="p-3">Match</th>
    <th class="p-3">Date</th>
    <th class="p-3">Result</th>
    <th class="p-3">Status</th>
    <th class="p-3">Video</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100 dark:divide-[#264531]">
<?php foreach ($userMatches as $m): ?>
<?php
$videoUrl = (string)($m['video_url'] ?? '');
$videoParts = $videoUrl !== '' ? parse_url($videoUrl) : [];
$videoScheme = strtolower((string)($videoParts['scheme'] ?? ''));
$isSafeVideoUrl = str_starts_with($videoUrl, '/videos/')
    || str_starts_with($videoUrl, '/public/videos/')
    || in_array($videoScheme, ['http', 'https'], true);
?>
<tr class="hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors">
    <td class="p-3 font-medium"><?= htmlspecialchars($m['challanger'] ?? 'TBD') ?> <span class="text-muted">vs</span> <?= htmlspecialchars($m['opponent'] ?? 'TBD') ?></td>
    <td class="p-3 font-mono text-xs"><?= htmlspecialchars($m['date'] ?? '') ?></td>
    <td class="p-3"><?= htmlspecialchars($m['result'] ?? '—') ?></td>
    <td class="p-3">
        <span class="px-2 py-1 rounded text-[10px] font-bold
            <?php if (($m['match_status'] ?? '') === 'completed'): ?>bg-primary/20 text-primary
            <?php elseif (($m['match_status'] ?? '') === 'pending'): ?>bg-warning/20 text-warning
            <?php else: ?>bg-muted/20 text-muted<?php endif; ?>">
            <?= ucfirst($m['match_status'] ?? 'unknown') ?>
        </span>
    </td>
    <td class="p-3">
        <?php if ($videoUrl !== '' && $isSafeVideoUrl): ?>
        <a href="<?= htmlspecialchars($videoUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline text-xs">Watch</a>
        <?php else: ?>
        <span class="text-xs text-muted">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-8 text-center border border-slate-200 dark:border-[#264531] mb-6">
<span class="material-symbols-outlined text-5xl text-muted mb-2">sports_soccer</span>
<p class="text-muted">No match history yet. Play your first match!</p>
</div>
<?php endif; ?>

<!-- Recent Results Cards -->
<?php if (!empty($userMatches)): ?>
<div class="mt-6">
    <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">timeline</span> Recent Results
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <?php foreach (array_slice($userMatches, 0, 8) as $m):
            $status = $m['match_status'] ?? 'unknown';
            $isCompleted = $status === 'completed';
        ?>
        <div class="rounded-xl bg-white dark:bg-card-dark border border-slate-200 dark:border-white/10 p-5 hover:border-primary/30 transition-all">
            <div class="flex justify-between items-start mb-3">
                <span class="text-xs font-medium text-muted"><?= htmlspecialchars($m['date'] ?? '') ?></span>
                <span class="text-xs font-bold px-2 py-0.5 rounded uppercase tracking-wider
                    <?php if ($isCompleted): ?>bg-primary/20 text-primary
                    <?php elseif ($status === 'pending'): ?>bg-yellow-500/20 text-yellow-400
                    <?php else: ?>bg-slate-200 dark:bg-white/10 text-muted<?php endif; ?>">
                    <?= ucfirst($status) ?>
                </span>
            </div>
            <div class="mb-2">
                <p class="font-bold text-lg"><?= htmlspecialchars($m['result'] ?? '—') ?></p>
            </div>
            <p class="text-xs text-muted"><?= htmlspecialchars($m['challanger'] ?? '') ?> vs <?= htmlspecialchars($m['opponent'] ?? '') ?></p>
            <?php if (!empty($m['location'])): ?>
            <p class="text-xs text-muted mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">location_on</span> <?= htmlspecialchars($m['location']) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
                    <div class="h-20"></div>
                </div>
            </div>
        </div>

<?php require_once BASE_PATH . "/includes/footer.php"; ?>

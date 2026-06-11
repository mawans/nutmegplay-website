<?php
/**
 * Weekly Challenges Dashboard
 * Displays active challenges, leaderboards, and user progress
 */

$pageTitle = 'Weekly Challenges';
$pageHeading = 'Weekly Challenges';
$pageDescription = 'Compete with your teammates and earn rewards';
$currentPage = 'weekly-challenges';

// Ensure all variables are defined
$activeChallenges = $activeChallenges ?? [];
$userProgress = $userProgress ?? [];
$leaderboards = $leaderboards ?? [];
$overallLeaderboard = $overallLeaderboard ?? [];
$userHistory = $userHistory ?? [];
$isInstructor = $isInstructor ?? false;
$setupError = $setupError ?? '';
$csrfToken = \App\Core\Auth::csrfToken();

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
    <?php require_once BASE_PATH . '/includes/topnav.php'; ?>

    <div id="main-content" class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">
        <div class="p-4 md:p-6 lg:p-8 w-full flex-1">
            <div class="max-w-7xl mx-auto">
                <!-- Page Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold mb-1"><?= htmlspecialchars($pageHeading) ?></h1>
                    <p class="text-white/60"><?= htmlspecialchars($pageDescription) ?></p>
                </div>

                <?php if (!empty($setupError)): ?>
                    <div class="mb-8 rounded-xl border border-amber-500/30 bg-amber-500/10 px-6 py-4 flex items-start gap-4 shadow-lg shadow-amber-900/5">
                        <span class="material-symbols-outlined text-amber-400 mt-0.5">warning</span>
                        <div>
                            <div class="font-bold text-amber-200 mb-0.5 text-lg">Database Setup Required</div>
                            <p class="text-sm text-amber-100/70 leading-relaxed"><?= htmlspecialchars($setupError) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Create Challenge (Instructors Only) -->
                <?php if ($isInstructor): ?>
                    <div class="bg-surface-light dark:bg-[#163122] rounded-2xl p-6 mb-10 border border-[#264531] shadow-2xl">
                        <div class="flex items-center gap-3 mb-6">
                            <span class="material-symbols-outlined text-primary">add_circle</span>
                            <h2 class="text-xl font-bold">Create New Challenge</h2>
                        </div>
                        <form id="createChallengeForm" hx-post="/weekly-challenges/create" hx-swap="none" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1">Title</label>
                                <input type="text" name="title" placeholder="Challenge Title" 
                                    class="w-full bg-[#0d1f15] border border-[#264531] focus:border-primary focus:ring-1 focus:ring-primary/30 text-white px-4 py-3 rounded-lg outline-none transition-all placeholder:text-white/20" 
                                    style="background-color: #0d1f15 !important;" required>
                            </div>
                            
                            <div class="md:col-span-1">
                                <label class="block text-xs font-bold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1">Metric</label>
                                <div class="relative">
                                    <select name="metric" 
                                        class="w-full bg-[#0d1f15] border border-[#264531] focus:border-primary focus:ring-1 focus:ring-primary/30 text-white px-4 py-3 rounded-lg outline-none transition-all appearance-none cursor-pointer"
                                        style="background-color: #0d1f15 !important;" required>
                                        <option value="" disabled selected>Select Metric</option>
                                        <option value="goals">Goals Scored</option>
                                        <option value="assists">Assists</option>
                                        <option value="distance_meters">Distance Covered</option>
                                        <option value="sprints">Sprints</option>
                                        <option value="passes">Passes</option>
                                        <option value="matches_won">Matches Won</option>
                                        <option value="duels_won">Duels Won</option>
                                    </select>
                                    <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none text-xl">expand_more</span>
                                </div>
                            </div>

                            <div class="md:col-span-1">
                                <label class="block text-xs font-bold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1">Target Value</label>
                                <input type="number" name="target_value" placeholder="Target" 
                                    class="w-full bg-[#0d1f15] border border-[#264531] focus:border-primary focus:ring-1 focus:ring-primary/30 text-white px-4 py-3 rounded-lg outline-none transition-all placeholder:text-white/20" 
                                    style="background-color: #0d1f15 !important;" required>
                            </div>

                            <div class="md:col-span-1">
                                <label class="block text-xs font-bold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1">XP Reward</label>
                                <input type="number" name="xp_reward" placeholder="XP" value="100" 
                                    class="w-full bg-[#0d1f15] border border-[#264531] focus:border-primary focus:ring-1 focus:ring-primary/30 text-white px-4 py-3 rounded-lg outline-none transition-all placeholder:text-white/20" 
                                    style="background-color: #0d1f15 !important;" required>
                            </div>

                            <div class="md:col-span-1">
                                <label class="block text-xs font-bold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1">Difficulty</label>
                                <div class="relative">
                                    <select name="difficulty" 
                                        class="w-full bg-[#0d1f15] border border-[#264531] focus:border-primary focus:ring-1 focus:ring-primary/30 text-white px-4 py-3 rounded-lg outline-none transition-all appearance-none cursor-pointer"
                                        style="background-color: #0d1f15 !important;">
                                        <option value="easy">Easy</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                    <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none text-xl">expand_more</span>
                                </div>
                            </div>

                            <div class="md:col-span-2 flex flex-col">
                                <label class="block text-xs font-bold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1 opacity-0 pointer-events-none" aria-hidden="true">Spacer</label>
                                <div class="flex-1 flex items-center">
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <div class="relative w-6 h-6 flex items-center justify-center">
                                            <input type="checkbox" name="is_team_challenge" 
                                                class="peer appearance-none w-6 h-6 rounded-lg border border-[#264531] bg-[#0d1f15] checked:bg-primary checked:border-primary transition-all cursor-pointer"
                                                style="background-color: #0d1f15 !important;">
                                            <span class="material-symbols-outlined text-base text-black opacity-0 peer-checked:opacity-100 absolute pointer-events-none font-bold">check</span>
                                        </div>
                                        <span class="text-sm font-bold text-[#96c5a6] group-hover:text-white transition-colors">Team Challenge</span>
                                    </label>
                                </div>
                            </div>

                            <div class="md:col-span-3 lg:col-span-4">
                                <label class="block text-xs font-semibold text-[#96c5a6] uppercase tracking-wider mb-2 ml-1">Description</label>
                                <textarea name="description" placeholder="Challenge Description..." 
                                    class="w-full bg-[#0d1f15] border border-[#264531] focus:border-primary/50 text-white px-4 py-3 rounded-lg outline-none transition-all placeholder:text-[#315743] min-h-[100px]"
                                    style="background-color: #0d1f15 !important;"></textarea>
                            </div>

                            <div class="md:col-span-3 lg:col-span-4 flex justify-end mt-6">
                                <button type="submit" class="bg-primary hover:bg-[#22c55e] text-white px-4 py-3.5 rounded-lg transition-all shadow-lg shadow-primary/20 hover:shadow-primary/30 hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-1 border border-white/10 min-w-[160px]">
                                    <span class="">Create</span>
                                    <span class="material-symbols-outlined text-xl">add</span>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (is_array($activeChallenges) && !empty($activeChallenges)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                    <?php foreach ($activeChallenges as $challenge): ?>
                        <?php 
                            $userProg = $userProgress[$challenge['id']] ?? null;
                            $leaderboard = $leaderboards[$challenge['id']] ?? [];
                            $progressPct = ($userProg && (float)$userProg['target_value'] > 0) ? min(100, ($userProg['current_progress'] / $userProg['target_value']) * 100) : 0;
                            
                            $difficultyLabel = ucfirst($challenge['difficulty']);
                            $difficultyClass = match($challenge['difficulty']) {
                                'easy' => 'text-emerald-400 bg-emerald-400/10 border-emerald-400/20',
                                'hard' => 'text-rose-400 bg-rose-400/10 border-rose-400/20',
                                default => 'text-amber-400 bg-amber-400/10 border-amber-400/20'
                            };
                            $accentColor = match($challenge['difficulty']) {
                                'easy' => 'primary',
                                'hard' => 'accent-danger',
                                default => 'primary'
                            };
                        ?>
                        <div class="bg-surface-light dark:bg-[#163122] rounded-2xl overflow-hidden border border-[#264531] hover:border-primary/30 transition-all duration-300 group shadow-lg">
                            <div class="p-6">
                                <!-- Challenge Category & XP -->
                                <div class="flex justify-between items-center mb-4">
                                    <div class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest border <?= $difficultyClass ?>">
                                        <?= $difficultyLabel ?>
                                    </div>
                                    <div class="flex items-center gap-1 text-sm font-bold text-primary">
                                        <span class="material-symbols-outlined text-base">stars</span>
                                        <?= $challenge['xp_reward'] ?> XP
                                    </div>
                                </div>

                                <h3 class="text-xl font-bold mb-2 group-hover:text-primary transition-colors"><?= htmlspecialchars($challenge['title']) ?></h3>
                                <p class="text-xs text-[#96c5a6]/70 leading-relaxed mb-6 line-clamp-2"><?= htmlspecialchars($challenge['description'] ?? 'No description provided.') ?></p>

                                <!-- Progress Module -->
                                <div class="bg-[#0d1f15] rounded-xl p-4 mb-6 border border-white/5">
                                    <div class="flex justify-between items-end mb-2">
                                        <div class="text-[10px] font-bold text-[#315743] uppercase tracking-wider">Progress: <?= ucfirst(str_replace('_', ' ', $challenge['metric'])) ?></div>
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-lg font-bold text-white"><?= $userProg['current_progress'] ?? 0 ?></span>
                                            <span class="text-xs text-[#315743]">/ <?= $challenge['target_value'] ?></span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-white/5 h-1.5 rounded-full overflow-hidden">
                                        <div class="bg-primary h-full transition-all duration-500 rounded-full shadow-[0_0_8px_rgba(34,197,94,0.4)]" style="width: <?= $progressPct ?>%"></div>
                                    </div>
                                </div>

                                <!-- Action -->
                                <?php if (!$userProg): ?>
                                    <button class="enrollChallenge w-full bg-primary/10 hover:bg-primary text-primary hover:text-black border border-primary/20 px-4 py-2.5 rounded-xl text-sm font-bold transition-all" data-challenge-id="<?= $challenge['id'] ?>">
                                        Accept Challenge
                                    </button>
                                <?php elseif ($userProg['status'] === 'completed'): ?>
                                    <div class="w-full bg-emerald-400/10 border border-emerald-400/20 py-2.5 rounded-xl flex items-center justify-center gap-2 text-emerald-400 text-sm font-bold">
                                        <span class="material-symbols-outlined text-sm">check_circle</span>
                                        Completed
                                    </div>
                                <?php else: ?>
                                    <div class="w-full bg-primary/10 border border-primary/20 py-2.5 rounded-xl flex items-center justify-center gap-2 text-primary text-sm font-bold">
                                        <span class="flex h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                                        In Progress
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Mini Leaderboard Footer -->
                            <div class="bg-white/5 px-6 py-4 border-t border-white/5">
                                <div class="flex items-center gap-2 mb-3 text-[10px] font-bold text-white/30 tracking-widest uppercase">
                                    <span class="material-symbols-outlined text-xs">leaderboard</span>
                                    Top Performers
                                </div>
                                <div class="space-y-2">
                                    <?php if (!empty($leaderboard)): ?>
                                        <?php foreach (array_slice($leaderboard, 0, 3) as $idx => $entry): ?>
                                            <div class="flex justify-between items-center text-xs">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-white/20 w-3"><?= ($idx + 1) ?>.</span>
                                                    <span class="text-white/70"><?= htmlspecialchars(substr($entry['fname'], 0, 1) . '. ' . $entry['lname']) ?></span>
                                                </div>
                                                <span class="font-bold text-white/90"><?= $entry['current_progress'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-[10px] text-white/20 italic">No participants yet.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif (empty($setupError)): ?>
                    <div class="mb-12 rounded-3xl border border-dashed border-white/10 bg-black/5 px-6 py-16 text-center shadow-inner">
                        <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
                            <span class="material-symbols-outlined text-4xl text-white/20">emoji_events</span>
                        </div>
                        <h3 class="text-xl font-bold mb-1">No Active Challenges</h3>
                        <p class="text-white/40 max-w-sm mx-auto">New challenges are posted every Monday. Check back then or ask your instructor to create one!</p>
                    </div>
                <?php endif; ?>

                <!-- Overall Leaderboard -->
                <div class="bg-surface-light dark:bg-[#163122] rounded-2xl border border-[#264531] shadow-2xl overflow-hidden mb-12">
                    <div class="bg-gradient-to-r from-primary/10 to-transparent px-6 py-6 border-b border-[#264531] flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold">Global Leaderboard</h2>
                            <p class="text-xs text-[#96c5a6] uppercase tracking-widest mt-1">This Week's Top Performers</p>
                        </div>
                        <span class="material-symbols-outlined text-primary text-3xl">military_tech</span>
                    </div>

                    <div class="overflow-x-auto overflow-y-hidden">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-[#0d1f15] text-[10px] font-bold text-[#96c5a6] uppercase tracking-[0.2em] border-b border-[#264531]">
                                    <th class="px-6 py-4 text-left">Rank</th>
                                    <th class="px-6 py-4 text-left">Player</th>
                                    <th class="px-6 py-4 text-center">Achievements</th>
                                    <th class="px-6 py-4 text-right">Total XP</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#264531]">
                                <?php if (!empty($overallLeaderboard)): ?>
                                    <?php foreach ($overallLeaderboard as $idx => $player): ?>
                                        <tr class="hover:bg-white/[0.02] transition-colors group">
                                            <td class="px-6 py-4">
                                                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold <?= match($idx + 1) {
                                                    1 => 'bg-primary text-black shadow-[0_0_15px_rgba(34,197,94,0.3)]',
                                                    2 => 'bg-[#315743] text-white',
                                                    3 => 'bg-amber-700/30 text-amber-500',
                                                    default => 'text-[#315743]'
                                                } ?>">
                                                    <?= match($idx + 1) {
                                                        1 => '1',
                                                        2 => '2',
                                                        3 => '3',
                                                        default => $idx + 1
                                                    } ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center font-bold text-primary border border-primary/20 group-hover:scale-110 transition-transform">
                                                        <?= strtoupper(substr($player['fname'], 0, 1)) ?>
                                                    </div>
                                                    <div class="font-bold text-white/90 group-hover:text-white transition-colors">
                                                        <?= htmlspecialchars($player['fname'] . ' ' . $player['lname']) ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#0d1f15] text-[10px] font-bold text-[#96c5a6] border border-[#264531]">
                                                    <?= $player['challenges_completed'] ?? 0 ?> Challenges
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <div class="flex items-center justify-end gap-1.5 font-black text-primary italic">
                                                    <span class="text-xs">⭐</span>
                                                    <?= number_format($player['total_xp_earned'] ?? 0) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-12 text-center text-[#315743] italic text-sm">No leaderboard data yet. Start a challenge!</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- User Challenge History -->
                <?php if (!empty($userHistory) && is_array($userHistory)): ?>
                    <div class="bg-surface-light dark:bg-[#163122] rounded-2xl border border-[#264531] p-6 shadow-xl">
                        <div class="flex items-center gap-2 mb-6 text-[#96c5a6]">
                            <span class="material-symbols-outlined text-lg">history</span>
                            <h2 class="text-xl font-bold">Your Challenge History</h2>
                        </div>
                        <div class="space-y-3">
                            <?php foreach ($userHistory as $history): ?>
                                <div class="flex flex-col sm:flex-row justify-between sm:items-center bg-[#0d1f15] hover:bg-black/30 px-5 py-4 rounded-xl border border-[#264531] gap-3 transition-all duration-300">
                                    <div class="flex flex-col">
                                        <span class="font-bold text-white/90"><?= htmlspecialchars($history['challenge_title'] ?? 'Challenge #' . $history['challenge_id']) ?></span>
                                        <span class="text-xs text-[#315743] uppercase tracking-widest mt-0.5">Week of <?= $history['week_start_date'] ?></span>
                                    </div>
                                    <div class="flex gap-4 items-center">
                                        <div class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $history['status'] === 'completed' ? 'bg-emerald-400 text-black shadow-[0_0_10px_rgba(52,211,153,0.3)]' : 'bg-[#315743]/30 text-[#96c5a6]' ?>">
                                            <?= htmlspecialchars($history['status']) ?>
                                        </div>
                                        <?php if ($history['xp_awarded'] > 0): ?>
                                            <span class="text-primary font-black italic">+<?= $history['xp_awarded'] ?> XP</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

<script>
document.querySelectorAll('.enrollChallenge').forEach(btn => {
    btn.addEventListener('click', async function() {
        const challengeId = this.dataset.challengeId;
        const response = await fetch(`/weekly-challenges/${challengeId}/enroll`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `_csrf=${document.querySelector('input[name="_csrf"]').value}`
        });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});

// Handle Challenge Creation Success
document.body.addEventListener('htmx:afterRequest', function(evt) {
    if (evt.detail.target.id === 'createChallengeForm') {
        try {
            const response = JSON.parse(evt.detail.xhr.responseText);
            if (response.success) {
                location.reload(); // Refresh to show the new challenge in the list
            } else {
                alert('Error: ' + (response.error || 'Unknown error'));
            }
        } catch (e) {
            console.error('Failed to parse response', e);
        }
    }
});

// Auto-refresh leaderboard every 30 seconds
setInterval(() => {
    if (document.hidden) return;
    const activeChallenges = document.querySelectorAll('[data-challenge-id]');
    activeChallenges.forEach(async (el) => {
        const id = el.dataset.challengeId;
        const response = await fetch(`/api/weekly-challenges/leaderboard/${id}`);
        const data = await response.json();
        if (data.success) {
            // Update leaderboard in real-time
            console.log('Updated leaderboard for challenge', id);
        }
    });
}, 30000);
</script>

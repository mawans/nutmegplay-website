<?php
$pageTitle = 'Video Upload';
$pageHeading = 'Video Upload';
$pageDescription = 'Upload match video, assign jersey slots, and review AI stats by number';
$currentPage = 'video-upload';
$userMatches = $userMatches ?? [];
$players = $players ?? [];
$lineupsByMatch = $lineupsByMatch ?? [];
$statsByMatch = $statsByMatch ?? [];
$analysisByMatch = $analysisByMatch ?? [];
$account = $account ?? [];
$aiWorker = $aiWorker ?? [];
$aiWorkerSummaries = $aiWorkerSummaries ?? [];
$selectedInstanceKey = $selectedInstanceKey ?? '';
$activeInstanceKey = $activeInstanceKey ?? null;
$storedVideos = $storedVideos ?? [];
$error = $error ?? '';
$success = $success ?? '';
$requestedMatchId = isset($requestedMatchId) ? (int)$requestedMatchId : 0;
$focusPanel = $focusPanel ?? '';
$csrfToken = \App\Core\Auth::csrfToken();
$activeWorker = (is_string($activeInstanceKey) && isset($aiWorkerSummaries[$activeInstanceKey])) ? $aiWorkerSummaries[$activeInstanceKey] : null;

$playerLabel = static function (array $player): string {
    $name = trim((string)($player['fname'] ?? '') . ' ' . (string)($player['lname'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $username = trim((string)($player['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }

    return trim((string)($player['email'] ?? 'Player')) ?: 'Player';
};

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<style>
/* Nutmeg Premium Spinner */
.premium-spinner {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: conic-gradient(#0000 10%, #e5e7eb);
    -webkit-mask: radial-gradient(farthest-side, #0000 calc(100% - 2px), #000 0);
    animation: premium-spin 0.8s infinite linear;
}
.premium-spinner-large {
    width: 24px;
    height: 24px;
    -webkit-mask: radial-gradient(farthest-side, #0000 calc(100% - 3px), #000 0);
}
@keyframes premium-spin {
    to { transform: rotate(1turn); }
}

/* AI Component Overlay */
.ai-component-overlay {
    position: absolute;
    inset: -2px;
    z-index: 60;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background-color: rgba(0, 0, 0, 0.6);
    border-radius: 0.75rem;
    gap: 0.75rem;
    animation: ai-fade-in 0.3s ease-out;
    border: 1px solid rgba(34, 197, 94, 0.1);
}
@keyframes ai-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-icon-circle {
    aspect-ratio: 1 / 1;
    display: inline-grid;
    place-items: center;
}

.modal-icon-only {
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
    padding: 0 !important;
}

/* Force dark dropdowns everywhere on this page so the native option list
   doesn't render with OS-default white-on-black. color-scheme cues the UA
   to pick dark scrollbars/widgets too. */
.lineup-card select,
.lineup-card option,
select.lineup-select,
select.lineup-select option {
    background-color: #0d1f15;
    color: #ffffff;
    color-scheme: dark;
}

.lineup-row {
    display: flex;
    align-items: stretch;
    background-color: #0d1f15;
    border-radius: 0.5rem;
    /* overflow MUST stay visible so the searchable-select popover can
       drop below the row without being clipped. The rounded corners are
       restored on the badge and trigger so the visual still reads as a
       single rounded shell. */
    overflow: visible;
    position: relative;
}
.lineup-row .lineup-badge {
    flex: 0 0 auto;
    min-width: 3rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.875rem;
    color: #ffffff;
    letter-spacing: 0.02em;
    border-top-left-radius: 0.5rem;
    border-bottom-left-radius: 0.5rem;
}
.lineup-row.team-blue .lineup-badge { background-color: rgba(56, 189, 248, 0.18); color: #bae6fd; }
.lineup-row.team-red  .lineup-badge { background-color: rgba(244, 63, 94, 0.18); color: #fecdd3; }

.lineup-row .lineup-select {
    flex: 1 1 auto;
    min-width: 0;
    background-color: transparent;
    color: #ffffff;
    border: 0;
    outline: none;
    padding: 0.55rem 0.75rem;
    font-size: 0.875rem;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='%238fb9a0' viewBox='0 0 24 24'><path d='M7 10l5 5 5-5z'/></svg>");
    background-repeat: no-repeat;
    background-position: right 0.6rem center;
    background-size: 1rem;
    padding-right: 1.75rem;
}
.lineup-row .lineup-select:focus {
    box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.65);
}

/* Status pill chips for the match header */
.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.7rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.status-chip-done    { background-color: rgba(34, 197, 94, 0.15);  color: #86efac; }
.status-chip-busy    { background-color: rgba(245, 158, 11, 0.15); color: #fcd34d; }
.status-chip-waiting { background-color: rgba(56, 189, 248, 0.15); color: #7dd3fc; }
.status-chip-stopped { background-color: rgba(244, 63, 94, 0.15);  color: #fca5a5; }
.status-chip-idle    { background-color: rgba(148, 163, 184, 0.15); color: #cbd5e1; }
</style>

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

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start mb-6">
    <div class="rounded-xl nm-bg-card border nm-border p-6 shadow-sm">
        <h3 class="text-lg font-bold mb-4 flex items-center gap-2 text-white"><span class="material-symbols-outlined text-primary">cloud_upload</span> Upload Match Video</h3>
        <?php if (!empty($userMatches)): ?>
        <form method="POST" action="/video-upload" enctype="multipart/form-data" class="flex flex-col gap-4" hx-boost="false" id="video-upload-form">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Select Match</label>
                    <select name="match_id" id="match-id-select" class="w-full px-3 py-2 rounded-lg border nm-border nm-bg-recess text-white focus:border-primary outline-none text-sm" style="color-scheme: dark;">
                        <option value="">Choose a match</option>
                        <?php foreach ($userMatches as $m): ?>
                        <option value="<?= (int)($m['id'] ?? 0) ?>">
                            #<?= (int)($m['id'] ?? 0) ?> • <?= htmlspecialchars(($m['challanger'] ?? '') . ' vs ' . ($m['opponent'] ?? '')) ?> (<?= htmlspecialchars($m['date'] ?? '') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-muted mb-2">Choose Video</label>
                    <div class="relative" id="video-library-picker">
                        <input type="hidden" name="video_source_mode" id="video-source-mode" value="upload">
                        <input type="hidden" name="stored_video_url" id="stored-video-url-hidden" value="">
                        <input
                            type="text"
                            id="video-picker-search"
                            autocomplete="off"
                            class="w-full px-3 py-2 rounded-lg text-sm placeholder:text-[#96c5a6] outline-none appearance-none"
                            placeholder="Choose a saved clip or upload a video"
                            style="background-color:#163122;color:#ffffff;border:1px solid #315743;-webkit-appearance:none;appearance:none;box-shadow:none;"
                            value=""
                        >
                        <div id="video-picker-results" class="hidden absolute z-20 mt-2 w-full rounded-xl shadow-xl overflow-hidden max-h-64 overflow-y-auto" style="background-color:#163122;border:1px solid #315743;">
                            <button
                                type="button"
                                class="video-picker-option w-full text-left px-3 py-3 text-sm first:border-t-0 focus:outline-none transition-colors"
                                style="background-color:#163122;color:#ffffff;"
                                data-mode="upload"
                                data-value=""
                                data-search="upload a video upload new video new"
                                data-match-id=""
                            >
                                <span class="flex items-center gap-2 font-medium text-white">
                                    <span class="material-symbols-outlined text-base leading-none">add_circle</span>
                                    <span>Upload a Video</span>
                                </span>
                                <span class="block text-xs text-[#96c5a6]">Choose a file and start uploading right away</span>
                            </button>
                            <?php foreach ($storedVideos as $videoIndex => $video): ?>
                            <?php $sizeMb = max(0.1, round(((float)($video['size_bytes'] ?? 0)) / 1048576, 1)); ?>
                            <div class="flex items-stretch gap-2 px-3 py-3" data-video-row style="border-top:1px solid #264531;">
                                <button
                                    type="button"
                                    class="video-picker-option flex-1 text-left text-sm focus:outline-none transition-colors"
                                    style="background-color:#163122;color:#ffffff;"
                                    data-mode="stored"
                                    data-value="<?= htmlspecialchars((string)($video['url'] ?? '')) ?>"
                                    data-search="<?= htmlspecialchars((string)($video['search_text'] ?? '')) ?>"
                                    data-match-id="<?= htmlspecialchars((string)($video['match_id'] ?? '')) ?>"
                                >
                                    <span class="block font-medium text-white"><?= htmlspecialchars((string)($video['name'] ?? '')) ?></span>
                                    <span class="block text-xs text-[#96c5a6]"><?= htmlspecialchars((string)$sizeMb) ?> MB • Saved clip</span>
                                </button>
                                <button
                                    type="button"
                                    class="video-picker-delete modal-icon-only inline-flex h-10 w-10 flex-none items-center justify-center self-start rounded-lg"
                                    style="color:#ff6b6b;"
                                    data-video-url="<?= htmlspecialchars((string)($video['url'] ?? '')) ?>"
                                    data-video-name="<?= htmlspecialchars((string)($video['name'] ?? '')) ?>"
                                    data-match-id="<?= htmlspecialchars((string)($video['match_id'] ?? '')) ?>"
                                    title="Delete saved video"
                                    aria-label="Delete saved video"
                                >
                                    <span class="material-symbols-outlined text-base leading-none">delete</span>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-muted">
                        Pick a saved clip or choose Upload a Video.
                    </p>
                </div>
            </div>

            <input type="hidden" name="ai_instance" id="ai-instance-input" value="<?= htmlspecialchars((string)$selectedInstanceKey) ?>">
            <input type="hidden" name="run_ai" id="run-ai-input" value="1">
            <input type="file" name="video_file" id="video-file-input" accept="video/mp4,video/quicktime,video/webm,video/x-msvideo,video/x-matroska,.mp4,.mov,.webm,.avi,.mkv" class="hidden">

            <div id="stored-video-preview-panel" class="hidden rounded-xl border nm-border nm-bg-section p-4 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-sm">play_circle</span>
                    <p class="text-sm font-semibold text-white">Preview Selected Video</p>
                </div>
                <video id="stored-video-preview-player" controls preload="metadata" class="w-full rounded-lg max-h-[280px] bg-black">
                    <source id="stored-video-preview-source" src="" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>

            <div id="upload-video-preview-panel" class="hidden rounded-xl border nm-border nm-bg-section p-4 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-sm">play_circle</span>
                    <p class="text-sm font-semibold text-white">Selected Upload</p>
                </div>
                <p id="selected-upload-meta" class="text-xs nm-text-muted"></p>
                <video id="upload-video-preview-player" controls preload="metadata" class="w-full rounded-lg max-h-[280px] bg-black"></video>
            </div>

            <div id="upload-progress-panel" class="hidden rounded-xl nm-bg-section p-4 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <p id="upload-progress-label" class="text-sm font-semibold text-white">Uploading Video</p>
                    <span id="upload-progress-percent" class="text-xs font-bold text-primary tabular-nums">0%</span>
                </div>
                <div class="h-2 w-full rounded-full nm-bg-recess overflow-hidden">
                    <div id="upload-progress-bar" class="h-full bg-primary transition-all duration-200" style="width: 0%"></div>
                </div>
                <p id="upload-form-status" class="hidden text-sm nm-text-secondary"></p>
                <div class="flex items-center justify-between gap-2">
                    <p id="upload-stall-hint" class="hidden text-xs text-amber-300">Connection looks slow — still working...</p>
                    <button id="upload-cancel-button" type="button" class="hidden ml-auto rounded-lg px-3 py-1.5 text-xs font-bold bg-rose-500 text-white hover:bg-rose-600 transition-colors shadow-sm shadow-rose-900/40">
                        <span class="material-symbols-outlined align-middle text-[14px] mr-1">close</span>Cancel upload
                    </button>
                </div>
            </div>
            <button type="submit" id="upload-submit-button" class="hidden w-full md:w-auto self-end px-8 py-3 bg-primary hover:bg-green-600 text-white font-bold rounded-lg transition-colors text-sm flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-sm">smart_toy</span>
                <span id="upload-submit-label">Run AI</span>
            </button>
        </form>
        <?php else: ?>
        <p class="text-muted text-sm">No matches found. <a href="/matchmaking" class="text-primary hover:underline">Create a match</a> first.</p>
        <?php endif; ?>
    </div>

    <!-- Hidden AI Worker data for JavaScript - topnav only display -->
    <div id="ai-worker-shell" class="hidden" data-ai-worker-panel data-state="<?= htmlspecialchars((string)($aiWorker['state'] ?? 'unconfigured')) ?>" aria-hidden="true">
        <div id="ai-worker-loading-overlay" class="hidden"></div>
        <div id="ai-worker-start-mode" class="hidden">
            <p class="hidden" data-ai-worker-message></p>
            <p class="hidden" data-ai-worker-detail></p>
            <button type="button" class="hidden" data-ai-worker-start></button>
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl nm-bg-card border nm-border p-6">
            <h3 class="text-lg font-bold mb-3 flex items-center gap-2 text-white"><span class="material-symbols-outlined text-primary">tips_and_updates</span> Number Rules</h3>
            <ul class="space-y-2 text-sm nm-text-secondary">
                <li class="flex items-start gap-2"><span class="material-symbols-outlined text-primary text-sm mt-0.5">check_circle</span> Blue team uses fixed numbers <strong class="text-white">1-5</strong>.</li>
                <li class="flex items-start gap-2"><span class="material-symbols-outlined text-primary text-sm mt-0.5">check_circle</span> Red team uses fixed numbers <strong class="text-white">6-10</strong>.</li>
                <li class="flex items-start gap-2"><span class="material-symbols-outlined text-primary text-sm mt-0.5">check_circle</span> A swap means changing the player attached to the same jersey slot.</li>
                <li class="flex items-start gap-2"><span class="material-symbols-outlined text-primary text-sm mt-0.5">check_circle</span> AI stats are saved against the <strong class="text-white">number</strong>, not the human identity.</li>
                <li class="flex items-start gap-2"><span class="material-symbols-outlined text-primary text-sm mt-0.5">check_circle</span> Keep the AI worker on only while you need video processing.</li>
            </ul>
        </div>
    </div>
</div>

<?php if (!empty($userMatches)): ?>
<div class="space-y-6" id="match-cards">
    <div id="match-selection-empty" class="hidden rounded-xl border border-dashed nm-border nm-bg-card p-6 text-sm nm-text-muted">
        Select a match above to view its lineup, stats, and AI progress.
    </div>
    <?php foreach ($userMatches as $m): ?>
    <?php
    $matchId = (int)($m['id'] ?? 0);
    $lineup = $lineupsByMatch[$matchId] ?? ['blue' => [], 'red' => []];
    $stats = $statsByMatch[$matchId] ?? ['blue' => [], 'red' => []];
    $analysis = $analysisByMatch[$matchId] ?? [];
    $videoUrl = (string)($m['video_url'] ?? '');
    $analysisVideoUrl = trim((string)($analysis['video_url'] ?? ''));
    $analysisStatus = strtolower((string)($analysis['processing_status'] ?? ''));
    $displayVideoUrl = ($analysisStatus === 'processed' && $analysisVideoUrl !== '') ? $analysisVideoUrl : $videoUrl;
    $isLocal = str_starts_with($displayVideoUrl, '/videos/') || str_starts_with($displayVideoUrl, '/public/videos/');
    $processingStatus = strtolower((string)($analysis['processing_status'] ?? $m['video_status'] ?? 'pending'));
    $initialProgress = match ($processingStatus) {
        'processed' => 100,
        'processing' => 8,
        'queued' => 5,
        default => 0,
    };
    $initialMessage = match ($processingStatus) {
        'processed' => 'AI processing complete.',
        'processing' => 'AI worker is processing the video...',
        'queued' => 'Video uploaded. Waiting for AI worker to start...',
        'failed' => (string)($analysis['error_message'] ?? 'AI processing failed.'),
        default => 'No AI job is running for this match.',
    };
    $urlParts = $displayVideoUrl !== '' ? parse_url($displayVideoUrl) : [];
    $urlScheme = strtolower((string)($urlParts['scheme'] ?? ''));
    $isSafeExternal = in_array($urlScheme, ['http', 'https'], true);
    $scoreHome = $m['score_home'] ?? ($m['score_challanger'] ?? null);
    $scoreAway = $m['score_away'] ?? ($m['score_opponent'] ?? null);
    $hasScore = is_numeric($scoreHome) && is_numeric($scoreAway);
    $lineupByNumber = [];
    foreach (array_merge($lineup['blue'] ?? [], $lineup['red'] ?? []) as $slot) {
        $lineupByNumber[(int)($slot['jersey_number'] ?? 0)] = $slot;
    }

    // Map technical statuses onto user-friendly copy, icons, and a chip class.
    $statusMap = [
        'processed'  => ['key' => 'done',     'chip' => 'status-chip-done',    'label' => 'Done',      'icon' => 'check_circle',    'title' => 'Stats are ready',           'message' => 'AI stats are saved below.'],
        'processing' => ['key' => 'busy',     'chip' => 'status-chip-busy',    'label' => 'Analyzing', 'icon' => 'auto_awesome',    'title' => 'Analyzing the match',       'message' => 'The AI is watching the players...'],
        'queued'     => ['key' => 'waiting',  'chip' => 'status-chip-waiting', 'label' => 'Lined up',  'icon' => 'schedule',        'title' => 'Lined up',                  'message' => 'Waiting for the AI worker to start your job.'],
        'failed'     => ['key' => 'stopped',  'chip' => 'status-chip-stopped', 'label' => 'Stopped',   'icon' => 'error_outline',   'title' => "Last run didn't finish",    'message' => 'You can try again whenever you are ready.'],
        'pending'    => ['key' => 'idle',     'chip' => 'status-chip-idle',    'label' => 'Ready',     'icon' => 'play_circle',     'title' => 'Ready when you are',        'message' => 'Upload a clip and the AI will turn it into stats.'],
    ];
    $sm = $statusMap[$processingStatus] ?? $statusMap['pending'];

    // If the run failed, refine the message based on the underlying reason.
    if ($processingStatus === 'failed') {
        $rawError = strtolower(trim((string)($analysis['error_message'] ?? '')));
        if (str_contains($rawError, 'manually')) {
            $sm['title']   = 'You stopped the last run';
            $sm['message'] = 'The video is still saved. Click Try Again whenever you are ready.';
        } elseif ($rawError !== '') {
            $sm['title']   = "Last run didn't finish";
            $sm['message'] = 'Something interrupted the analysis. Click Try Again to re-run it.';
        }
    }

    // Decide if there's actually a video to preview. The file may have been
    // pruned by the cleanup cron or manually deleted; in that case the <video>
    // tag would render and the browser would throw a "no video with supported
    // MIME" error, which is what the user reported.
    $showVideoPreview = false;
    $videoMime = 'video/mp4';
    if ($displayVideoUrl !== '' && $isLocal) {
        $localPath = BASE_PATH . '/public' . $displayVideoUrl;
        if (is_file($localPath)) {
            $showVideoPreview = true;
            $ext = strtolower((string)pathinfo($displayVideoUrl, PATHINFO_EXTENSION));
            $videoMime = match ($ext) {
                'mp4'  => 'video/mp4',
                'webm' => 'video/webm',
                'mov'  => 'video/quicktime',
                'avi'  => 'video/x-msvideo',
                'mkv'  => 'video/x-matroska',
                default => 'video/mp4',
            };
        }
    }

    $hasAnyStats = !empty(($stats['blue'] ?? [])) || !empty(($stats['red'] ?? []));

    // Friendly "Last analyzed N ago" — only when there's a previous successful run.
    $lastProcessedAt = trim((string)($analysis['processed_at'] ?? ''));
    $lastProcessedHuman = '';
    if ($lastProcessedAt !== '') {
        $ts = strtotime($lastProcessedAt);
        if ($ts !== false) {
            $delta = max(0, time() - $ts);
            if ($delta < 60) {
                $lastProcessedHuman = 'just now';
            } elseif ($delta < 3600) {
                $mins = (int)floor($delta / 60);
                $lastProcessedHuman = $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
            } elseif ($delta < 86400) {
                $hrs = (int)floor($delta / 3600);
                $lastProcessedHuman = $hrs . ' hour' . ($hrs === 1 ? '' : 's') . ' ago';
            } else {
                $days = (int)floor($delta / 86400);
                $lastProcessedHuman = $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
            }
        }
    }
    ?>
    <div class="rounded-xl nm-bg-card border nm-border p-6 shadow-sm hidden" data-match-card data-match-id="<?= $matchId ?>">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-6">
            <div>
                <p class="text-xs uppercase tracking-[0.24em] text-muted font-semibold mb-2">Match #<?= $matchId ?></p>
                <h3 class="text-xl font-bold"><?= htmlspecialchars(($m['challanger'] ?? 'TBD') . ' vs ' . ($m['opponent'] ?? 'TBD')) ?></h3>
                <p class="text-sm text-muted mt-1"><?= htmlspecialchars($m['date'] ?? '') ?> • <?= htmlspecialchars($m['time'] ?? '') ?><?php if (!empty($m['location'])): ?> • <?= htmlspecialchars((string)$m['location']) ?><?php endif; ?></p>
                <p class="text-sm text-muted mt-1">
                    <span class="font-semibold text-foreground">Score:</span>
                    <?= $hasScore ? ((int)$scoreHome . ' - ' . (int)$scoreAway) : '–' ?>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="status-chip <?= htmlspecialchars($sm['chip']) ?>" data-match-chip>
                    <span class="material-symbols-outlined text-[14px]" data-match-chip-icon><?= htmlspecialchars($sm['icon']) ?></span>
                    <span data-match-chip-label><?= htmlspecialchars($sm['label']) ?></span>
                </span>
                <?php if ($displayVideoUrl !== '' && $isSafeExternal): ?>
                <a href="<?= htmlspecialchars($displayVideoUrl) ?>" target="_blank" rel="noopener noreferrer" class="px-3 py-1.5 rounded-lg nm-bg-section text-sm font-medium nm-text-secondary hover:bg-primary hover:text-white transition-colors">Watch Video</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
            <div>
                <form method="POST" action="/video-upload/lineup" hx-boost="false">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="match_id" value="<?= $matchId ?>">

                    <div class="lineup-card rounded-xl nm-bg-section border nm-border p-5">
                        <div class="mb-1">
                            <h4 class="font-bold text-sm uppercase tracking-wider text-white">Jersey Lineup</h4>
                        </div>
                        <p class="text-xs nm-text-muted mb-4">Stats are tied to the jersey number, so you can swap players later without re-running anything.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <?php foreach ([
                                'blue' => ['title' => 'Blue Team', 'numbers' => [1, 2, 3, 4, 5], 'team_class' => 'team-blue'],
                                'red'  => ['title' => 'Red Team',  'numbers' => [6, 7, 8, 9, 10], 'team_class' => 'team-red'],
                            ] as $team => $teamConfig): ?>
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h5 class="font-semibold text-sm text-white"><?= htmlspecialchars($teamConfig['title']) ?></h5>
                                    <span class="text-[10px] uppercase tracking-wider nm-text-muted font-semibold">Slots #<?= min($teamConfig['numbers']) ?>–#<?= max($teamConfig['numbers']) ?></span>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach ($teamConfig['numbers'] as $number): ?>
                                    <?php
                                        $slot = $lineupByNumber[$number] ?? [];
                                        $currentUid = (string)($slot['player_uid'] ?? '');
                                        $currentName = '';
                                        if ($currentUid !== '') {
                                            foreach ($players as $p) {
                                                if ((string)($p['uid'] ?? '') === $currentUid) {
                                                    $currentName = $playerLabel($p);
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <div class="lineup-row <?= $teamConfig['team_class'] ?>">
                                        <span class="lineup-badge px-3" aria-hidden="true">#<?= $number ?></span>
                                        <div class="nm-search-select-wrap" data-search-select data-jersey="<?= $number ?>" data-open="0">
                                            <input
                                                type="hidden"
                                                name="slots[<?= $number ?>][player_uid]"
                                                value="<?= htmlspecialchars($currentUid) ?>"
                                                data-search-value
                                            >
                                            <button
                                                type="button"
                                                class="nm-search-trigger <?= $currentUid === '' ? 'is-empty' : '' ?>"
                                                data-search-trigger
                                                aria-haspopup="listbox"
                                                aria-expanded="false"
                                                aria-label="Player for jersey #<?= $number ?>"
                                            >
                                                <span class="nm-search-trigger-label" data-search-label><?= $currentUid === '' ? '— Unassigned —' : htmlspecialchars($currentName) ?></span>
                                                <span class="nm-search-trigger-caret material-symbols-outlined">expand_more</span>
                                            </button>
                                            <div class="nm-search-popover" role="listbox" data-search-popover>
                                                <input
                                                    type="text"
                                                    class="nm-search-input"
                                                    data-search-input
                                                    placeholder="Type a player's name..."
                                                    aria-label="Search players for jersey #<?= $number ?>"
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                >
                                                <ul class="nm-search-list" data-search-list>
                                                    <li
                                                        data-uid=""
                                                        data-search-tokens=""
                                                        data-current="<?= $currentUid === '' ? '1' : '0' ?>"
                                                        data-active="0"
                                                    >
                                                        <span>— Unassigned —</span>
                                                    </li>
                                                    <?php foreach ($players as $player): ?>
                                                    <?php
                                                        $playerUid = (string)($player['uid'] ?? '');
                                                        if ($playerUid === '') continue;
                                                        $name  = $playerLabel($player);
                                                        $email = trim((string)($player['email'] ?? ''));
                                                        // Tokens we'll fuzzy-match against. Lowercased server-side
                                                        // so the JS can do simple .includes() instead of mb-aware
                                                        // case-folding on every keystroke.
                                                        $tokens = strtolower($name . ' ' . $email);
                                                    ?>
                                                    <li
                                                        data-uid="<?= htmlspecialchars($playerUid) ?>"
                                                        data-name="<?= htmlspecialchars($name) ?>"
                                                        data-search-tokens="<?= htmlspecialchars($tokens) ?>"
                                                        data-current="<?= $playerUid === $currentUid ? '1' : '0' ?>"
                                                        data-active="0"
                                                    >
                                                        <span><?= htmlspecialchars($name) ?></span>
                                                        <?php if ($email !== ''): ?>
                                                        <span class="nm-search-list-meta"><?= htmlspecialchars(mb_strimwidth($email, 0, 26, '…', 'UTF-8')) ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                                <p class="nm-search-empty hidden" data-search-empty>No players match that.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="mt-8 px-5 py-2.5 bg-primary hover:bg-green-600 text-white rounded-xl text-sm font-bold transition-colors inline-flex items-center gap-2" data-lineup-submit>
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="submit-text">Save Jersey Lineup</span>
                    </button>
                </form>
            </div>

            <div class="space-y-4">
                <div class="rounded-xl nm-bg-section border nm-border p-5 <?= ($processingStatus === 'pending' && $displayVideoUrl === '') ? 'hidden' : '' ?>"
                     data-ai-progress
                     data-match-id="<?= $matchId ?>"
                     data-status="<?= htmlspecialchars($processingStatus) ?>"
                     role="region"
                     aria-label="AI status for match #<?= $matchId ?>">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-10 w-10 flex-none items-center justify-center rounded-full <?= htmlspecialchars($sm['chip']) ?>" data-progress-icon-wrap>
                            <span class="material-symbols-outlined text-[20px]" data-progress-icon><?= htmlspecialchars($sm['icon']) ?></span>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-white" data-progress-stage><?= htmlspecialchars($sm['title']) ?></p>
                            <p class="text-sm nm-text-secondary mt-1" data-progress-message aria-live="polite"><?= htmlspecialchars($sm['message']) ?></p>
                            <div class="h-1.5 w-full rounded-full nm-bg-recess overflow-hidden mt-3 <?= in_array($processingStatus, ['queued', 'processing'], true) ? '' : 'hidden' ?>" data-progress-bar-wrap>
                                <div class="h-full transition-all duration-500 ease-out bg-primary" data-progress-bar style="width: <?= $initialProgress ?>%"></div>
                            </div>
                            <p class="text-[11px] nm-text-muted mt-2 tabular-nums" data-progress-detail></p>
                            <?php if ($lastProcessedHuman !== ''): ?>
                            <p class="text-[11px] nm-text-muted mt-1" data-progress-history>Last analyzed <span data-last-analyzed-human><?= htmlspecialchars($lastProcessedHuman) ?></span></p>
                            <?php else: ?>
                            <p class="text-[11px] nm-text-muted mt-1 hidden" data-progress-history>Last analyzed <span data-last-analyzed-human></span></p>
                            <?php endif; ?>
                        </div>
                        <span class="text-sm font-bold text-primary tabular-nums <?= in_array($processingStatus, ['queued', 'processing'], true) ? '' : 'hidden' ?>" data-progress-percent><?= $initialProgress ?>%</span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-xs font-bold transition-colors hidden bg-rose-500 text-white hover:bg-rose-600 shadow-sm shadow-rose-900/40"
                            data-cancel-analysis
                            data-match-id="<?= $matchId ?>"
                        >
                            <span class="material-symbols-outlined text-sm">stop_circle</span>
                            <span>Stop</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-xs font-bold transition-colors hidden bg-primary text-white hover:bg-emerald-600 shadow-sm shadow-emerald-900/40 disabled:nm-bg-section disabled:nm-text-muted disabled:cursor-not-allowed disabled:shadow-none"
                            data-retry-analysis
                            data-match-id="<?= $matchId ?>"
                        >
                            <span class="material-symbols-outlined text-sm">refresh</span>
                            <span data-retry-label>Try Again</span>
                        </button>
                        <span class="ml-auto text-[11px] font-semibold uppercase tracking-wider nm-text-muted hidden" data-progress-live>
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500 align-middle mr-1 animate-pulse"></span>Live
                        </span>
                    </div>
                </div>
                <?php if ($showVideoPreview): ?>
                <div class="rounded-xl border nm-border nm-bg-recess p-2">
                    <video controls class="w-full rounded-xl max-h-[260px] bg-black" preload="metadata">
                        <source src="<?= htmlspecialchars($displayVideoUrl) ?>" type="<?= htmlspecialchars($videoMime) ?>">
                        Your browser does not support the video tag.
                    </video>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($hasAnyStats): ?>
        <div class="mt-6 rounded-xl nm-bg-section border nm-border p-5">
            <div class="mb-4">
                <h4 class="text-base font-bold text-white">Match Stats</h4>
                <p class="text-xs nm-text-muted mt-1">Saved against jersey numbers, so swapping players never loses history.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ([
                    'blue' => ['title' => 'Blue Team', 'numColor' => 'nm-team-label-blue', 'numbers' => [1,2,3,4,5]],
                    'red'  => ['title' => 'Red Team',  'numColor' => 'nm-team-label-red',  'numbers' => [6,7,8,9,10]],
                ] as $teamKey => $tc): ?>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="font-semibold text-sm text-white"><?= htmlspecialchars($tc['title']) ?></h5>
                        <span class="text-[10px] uppercase tracking-wider nm-text-muted font-semibold">Slots #<?= min($tc['numbers']) ?>–#<?= max($tc['numbers']) ?></span>
                    </div>

                    <?php if (!empty($stats[$teamKey] ?? [])): ?>
                    <div class="rounded-xl nm-bg-recess overflow-x-auto">
                        <table class="w-full text-left text-xs sm:text-[13px]">
                            <thead class="text-[10px] uppercase tracking-wider nm-text-muted">
                                <tr>
                                    <th class="px-3 py-2 font-semibold">#</th>
                                    <th class="px-3 py-2 font-semibold">Player</th>
                                    <th class="px-2 py-2 font-semibold text-center">Pass</th>
                                    <th class="px-2 py-2 font-semibold text-center">Drib</th>
                                    <th class="px-2 py-2 font-semibold text-center">Shot</th>
                                    <th class="px-2 py-2 font-semibold text-center">Def</th>
                                    <th class="px-3 py-2 font-semibold text-right">Top</th>
                                    <th class="px-3 py-2 font-semibold text-right">Dist</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y nm-divide nm-text-secondary">
                                <?php foreach (($stats[$teamKey] ?? []) as $row): ?>
                                <?php $playerName = trim((string)($row['player_name'] ?? '')); ?>
                                <tr class="nm-row-hover transition-colors">
                                    <td class="px-3 py-2.5">
                                        <span class="text-[13px] font-bold tabular-nums <?= htmlspecialchars($tc['numColor']) ?>">#<?= (int)($row['jersey_number'] ?? 0) ?></span>
                                    </td>
                                    <td class="px-3 py-2.5 font-medium <?= $playerName === '' ? 'nm-text-muted italic' : 'text-white' ?>"><?= htmlspecialchars($playerName !== '' ? $playerName : 'Unassigned') ?></td>
                                    <td class="px-2 py-2.5 text-center tabular-nums"><?= (int)($row['successful_passes'] ?? 0) ?></td>
                                    <td class="px-2 py-2.5 text-center tabular-nums"><?= (int)($row['successful_dribbles'] ?? 0) ?></td>
                                    <td class="px-2 py-2.5 text-center tabular-nums"><?= (int)($row['shots'] ?? 0) ?></td>
                                    <td class="px-2 py-2.5 text-center tabular-nums"><?= (int)($row['interceptions'] ?? 0) ?></td>
                                    <td class="px-3 py-2.5 text-right tabular-nums"><?= number_format((float)($row['top_speed_kmh'] ?? 0), 1) ?> <span class="text-[10px] nm-text-muted">km/h</span></td>
                                    <td class="px-3 py-2.5 text-right tabular-nums"><?= (int)($row['distance_meters'] ?? 0) ?> <span class="text-[10px] nm-text-muted">m</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="px-4 py-6 text-xs nm-text-muted text-center">No stats for this team yet.</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($lastProcessedHuman !== ''): ?>
            <div class="mt-4 flex justify-end">
                <span class="text-[11px] uppercase tracking-wider nm-text-muted font-semibold">Last analyzed <?= htmlspecialchars($lastProcessedHuman) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</div>
</div>

</div>
</div>

<dialog id="ai-worker-confirm-dialog" class="backdrop:bg-black/70 rounded-3xl p-0 w-full max-w-lg bg-[#163122] text-white shadow-2xl" style="background-color:#163122;color:#ffffff;border:none;outline:none;">
    <form method="dialog" class="p-6 space-y-4">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 h-12 w-12 modal-icon-circle rounded-full bg-primary/20 text-primary flex items-center justify-center border border-primary/30" style="color:#22c55e;">
                <span class="material-symbols-outlined text-2xl" id="ai-worker-confirm-icon">power_settings_new</span>
            </div>
            <div class="space-y-1">
                <h3 class="text-lg font-bold text-white" id="ai-worker-confirm-title" style="color:#ffffff;">Confirm AI action</h3>
                <p class="text-sm text-slate-200" id="ai-worker-confirm-message" style="color:#d8e6dc;">Please confirm this AI worker action.</p>
            </div>
        </div>
        <div class="rounded-xl p-4" style="background-color:#102719;border:none;">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] nm-text-muted mb-2" style="color:#8fb9a0;">Heads Up</p>
            <p class="text-sm text-white" style="color:#ffffff;">Billing continues while a worker is running. Keep it on only while you are actively processing match videos.</p>
        </div>
        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
            <button type="button" id="ai-worker-confirm-cancel" class="px-4 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#102719]" style="color:#ffffff;background-color:#102719;border:none;">Cancel</button>
            <button type="button" id="ai-worker-confirm-submit" class="px-4 py-2.5 rounded-xl bg-primary hover:bg-green-600 text-white text-sm font-bold inline-flex items-center justify-center gap-2 min-w-[150px]" style="color:#ffffff;border:none;">
                <span class="material-symbols-outlined text-sm" id="ai-worker-confirm-submit-icon">play_circle</span>
                <span id="ai-worker-confirm-submit-label">Confirm</span>
            </button>
        </div>
    </form>
</dialog>

<script>
(function() {
    const input = document.getElementById('video-file-input');
    const uploadForm = document.getElementById('video-upload-form');
    const submitButton = document.getElementById('upload-submit-button');
    const uploadStatus = document.getElementById('upload-form-status');
    const uploadProgressPanel = document.getElementById('upload-progress-panel');
    const uploadProgressLabel = document.getElementById('upload-progress-label');
    const uploadProgressPercent = document.getElementById('upload-progress-percent');
    const uploadProgressBar = document.getElementById('upload-progress-bar');
    const uploadSubmitLabel = document.getElementById('upload-submit-label');
    const runAiInput = document.getElementById('run-ai-input');

    const aiWorkerPanel = document.querySelector('[data-ai-worker-panel]');
    const aiWorkerBadge = document.querySelector('[data-ai-worker-badge]');
    const aiWorkerMessage = document.querySelector('[data-ai-worker-message]');
    const aiWorkerDetail = document.querySelector('[data-ai-worker-detail]');
    const aiWorkerStart = document.querySelector('[data-ai-worker-start]');
    const aiInstanceInput = document.getElementById('ai-instance-input');
    const aiWorkerLoadingOverlay = document.getElementById('ai-worker-loading-overlay');
    const aiWorkerLoadingTitle = document.getElementById('ai-worker-loading-title');
    const aiWorkerLoadingMessage = document.getElementById('ai-worker-loading-message');

    const confirmDialog = document.getElementById('ai-worker-confirm-dialog');
    const confirmTitle = document.getElementById('ai-worker-confirm-title');
    const confirmMessage = document.getElementById('ai-worker-confirm-message');
    const confirmIcon = document.getElementById('ai-worker-confirm-icon');
    const confirmSubmit = document.getElementById('ai-worker-confirm-submit');
    const confirmSubmitIcon = document.getElementById('ai-worker-confirm-submit-icon');
    const confirmSubmitLabel = document.getElementById('ai-worker-confirm-submit-label');
    const confirmCancel = document.getElementById('ai-worker-confirm-cancel');

    const aiSelectedName = document.querySelector('[data-ai-selected-name]');
    const aiSelectedMeta = document.querySelector('[data-ai-selected-meta]');
    const aiSelectedBadge = document.querySelector('[data-ai-selected-badge]');
    const aiSelectedMessage = document.querySelector('[data-ai-selected-message]');
    const aiRunningIndicator = document.querySelector('[data-ai-running-indicator]');
    const aiRunningName = document.querySelector('[data-ai-running-name]');
    const aiRunningMeta = document.querySelector('[data-ai-running-meta]');
    const aiRunningBadge = document.querySelector('[data-ai-running-badge]');
    const aiRunningMessage = document.querySelector('[data-ai-running-message]');
    const publicAiWorkerName = 'AI Worker';

    const videoSourceMode = document.getElementById('video-source-mode');
    const storedVideoHidden = document.getElementById('stored-video-url-hidden');
    const storedVideoPreviewPanel = document.getElementById('stored-video-preview-panel');
    const storedVideoPreviewPlayer = document.getElementById('stored-video-preview-player');
    const storedVideoPreviewSource = document.getElementById('stored-video-preview-source');
    const uploadVideoPreviewPanel = document.getElementById('upload-video-preview-panel');
    const uploadVideoPreviewPlayer = document.getElementById('upload-video-preview-player');
    const selectedUploadMeta = document.getElementById('selected-upload-meta');
    const videoPickerSearch = document.getElementById('video-picker-search');
    const videoPickerResults = document.getElementById('video-picker-results');
    let videoPickerOptions = Array.from(document.querySelectorAll('.video-picker-option'));
    const matchSelect = document.getElementById('match-id-select');
    const topnavAiSlot = document.getElementById('page-topnav-status-slot');

    const csrfToken = '<?= addslashes($csrfToken ?? '') ?>';
    const initialAiWorkerData = <?= is_array($aiWorker) ? json_encode($aiWorker, JSON_UNESCAPED_SLASHES) : '{}' ?>;
    const requestedMatchId = '<?= (int)$requestedMatchId ?>';
    const focusPanel = '<?= addslashes((string)$focusPanel) ?>';

    let aiWorkerState = aiWorkerPanel ? String(aiWorkerPanel.dataset.state || 'unconfigured') : 'unconfigured';
    let confirmAction = null;
    let aiActionTimeout = null;
    let aiActionPending = false;
    let aiWorkerBusyState = false;
    let aiWorkerBusyLabel = 'Checking';
    let latestAiPayload = null;
    let uploadPreviewObjectUrl = null;
    let statusRequestSeq = 0;
    let aiStatusRequestInFlight = false;
    let selectedStoredMatchId = '';

    let matchCards = Array.from(document.querySelectorAll('[data-match-card]'));
    const matchEmptyState = document.getElementById('match-selection-empty');

    function selectedWorkerInstance() {
        return aiInstanceInput ? String(aiInstanceInput.value || '') : '';
    }

    function setSelectedWorkerInstance(value) {
        if (aiInstanceInput) {
            aiInstanceInput.value = value || '';
        }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function workerStateClasses(state) {
        const normalized = String(state || '').toLowerCase();
        if (normalized === 'ready') {
            return 'bg-emerald-500/15 text-emerald-200';
        }
        if (normalized === 'unavailable' || normalized === 'error') {
            return 'bg-rose-500/15 text-rose-200';
        }
        if (normalized === 'starting' || normalized === 'stopping') {
            return 'bg-amber-500/15 text-amber-100';
        }
        return 'nm-bg-section nm-text-secondary';
    }

    function currentTopnavActionButton() {
        return document.querySelector('[data-ai-worker-action-topnav]');
    }

    function setAiWorkerBusy(isBusy, title, message) {
        aiWorkerBusyState = isBusy;
        aiWorkerBusyLabel = title || 'Checking';

        if (aiWorkerLoadingOverlay) {
            aiWorkerLoadingOverlay.classList.toggle('hidden', !isBusy);
            if (aiWorkerLoadingTitle && title) {
                aiWorkerLoadingTitle.textContent = title;
            }
            if (aiWorkerLoadingMessage && message) {
                aiWorkerLoadingMessage.textContent = message;
            }
        }

        if (topnavAiSlot) {
            const existingOverlay = topnavAiSlot.querySelector('.ai-component-overlay');
            if (existingOverlay) {
                existingOverlay.remove();
            }

            if (isBusy) {
                const overlay = document.createElement('div');
                overlay.className = 'ai-component-overlay';
                overlay.innerHTML = '<div class="premium-spinner premium-spinner-large"></div>';
                topnavAiSlot.style.position = 'relative';
                topnavAiSlot.appendChild(overlay);
            }
        }

        const actionButton = currentTopnavActionButton();
        if (actionButton) {
            actionButton.disabled = isBusy;
            actionButton.classList.toggle('opacity-50', isBusy);
        }

        if (!isBusy && latestAiPayload) {
            setAiWorkerState(latestAiPayload);
        }
    }

    function setAiActionPending(isPending) {
        aiActionPending = isPending;
        const actionButton = currentTopnavActionButton();
        [aiWorkerStart, actionButton].forEach((button) => {
            if (!button) {
                return;
            }

            button.disabled = isPending || button.dataset.baseDisabled === 'true';
            button.classList.toggle('opacity-70', button.disabled);
            button.classList.toggle('cursor-not-allowed', button.disabled);
        });
    }

    function closeConfirmDialog() {
        confirmAction = null;
        if (confirmDialog && confirmDialog.open) {
            confirmDialog.close();
        }
    }

    function openConfirmDialog(config) {
        if (!confirmDialog || !confirmTitle || !confirmMessage || !confirmSubmit || !confirmSubmitLabel || !confirmIcon || !confirmSubmitIcon) {
            return;
        }

        confirmTitle.textContent = config.title;
        confirmMessage.textContent = config.message;
        confirmIcon.textContent = config.icon;
        confirmSubmitIcon.textContent = config.icon;
        confirmSubmitLabel.textContent = config.confirmLabel;
        confirmSubmit.className = 'px-4 py-2.5 rounded-xl text-white text-sm font-bold inline-flex items-center gap-2 ' + config.buttonClass;
        confirmAction = config.onConfirm;
        confirmDialog.showModal();
    }

    if (confirmSubmit) {
        confirmSubmit.addEventListener('click', function() {
            const action = confirmAction;
            closeConfirmDialog();
            if (typeof action === 'function') {
                action();
            }
        });
    }

    if (confirmCancel) {
        confirmCancel.addEventListener('click', closeConfirmDialog);
    }

    function ensureTopnavWorkerScaffold() {
        if (!topnavAiSlot) return null;
        let root = topnavAiSlot.querySelector('[data-ai-topnav-root]');
        if (root) return root;
        topnavAiSlot.classList.remove('hidden');
        topnavAiSlot.innerHTML = `
            <div data-ai-topnav-root class="inline-flex items-center gap-3" data-state="" data-rotating="0">
                <span data-ai-topnav-pill class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[10px] font-bold">
                    <span data-ai-topnav-dot class="h-2 w-2 rounded-full bg-current"></span>
                    <span data-ai-topnav-status class="whitespace-nowrap">…</span>
                </span>
                <span class="text-xs font-bold text-white" data-ai-topnav-name>AI Worker</span>
                <span class="text-xs font-semibold text-emerald-300 font-mono hidden" data-ai-topnav-uptime></span>
                <button
                    type="button"
                    data-ai-topnav-action data-ai-worker-action-topnav
                    data-action="start"
                    data-instance=""
                    data-base-disabled="false"
                    class="h-9 px-4 inline-flex items-center justify-center rounded-xl text-xs font-bold gap-2 shadow-lg"
                    style="border:none;"
                >
                    <span class="material-symbols-outlined text-lg" data-ai-topnav-icon>play_circle</span>
                    <span class="whitespace-nowrap" data-ai-topnav-action-label>Start</span>
                </button>
            </div>
        `;
        return topnavAiSlot.querySelector('[data-ai-topnav-root]');
    }

    function renderTopnavWorker(payload) {
        const root = ensureTopnavWorkerScaffold();
        if (!root) return;

        payload.instance_label = publicAiWorkerName;
        const state = String(payload.state || 'stopped').toLowerCase();
        const running = ['ready', 'starting', 'stopping'].includes(state);
        const workerLabel = publicAiWorkerName;
        const instanceKey = String(payload.instance_key || selectedWorkerInstance() || '');
        if (instanceKey !== '') setSelectedWorkerInstance(instanceKey);

        const statusLabel = aiWorkerBusyState
            ? aiWorkerBusyLabel
            : (running
                ? (state === 'ready' ? 'Running' : (state === 'stopping' ? 'Stopping' : 'Starting'))
                : (state === 'unavailable' ? 'No GPU Available' : (state === 'error' ? 'Error' : 'Stopped')));

        const actionType = running ? 'stop' : 'start';
        const actionLabel = actionType === 'stop' ? (payload.dynamic ? 'Terminate' : 'Stop') : 'Start';
        const actionIcon = actionType === 'stop' ? 'stop_circle' : 'play_circle';
        const actionClass = actionType === 'stop'
            ? 'bg-rose-500 hover:bg-rose-600 text-white'
            : 'bg-primary hover:bg-emerald-600 text-white';
        const actionDisabled = actionType === 'stop'
            ? (state === 'stopping' || payload.supports_stop === false)
            : ['starting', 'stopping', 'unconfigured'].includes(state);

        // Diff-update: only mutate what actually changed so CSS transitions
        // can animate from the previous values.
        const previousState = root.dataset.state || '';
        if (previousState !== state) {
            root.dataset.state = state;
            root.dataset.justChanged = '1';
            window.setTimeout(() => { root.removeAttribute('data-just-changed'); }, 360);
        }
        root.dataset.rotating = (state === 'starting' || state === 'stopping') ? '1' : '0';

        const pill   = root.querySelector('[data-ai-topnav-pill]');
        const status = root.querySelector('[data-ai-topnav-status]');
        const name   = root.querySelector('[data-ai-topnav-name]');
        const uptime = root.querySelector('[data-ai-topnav-uptime]');
        const action = root.querySelector('[data-ai-topnav-action]');
        const icon   = root.querySelector('[data-ai-topnav-icon]');
        const aLabel = root.querySelector('[data-ai-topnav-action-label]');

        if (status) status.textContent = statusLabel;
        if (name)   name.textContent = workerLabel;
        if (uptime) {
            if (running && payload.uptime_label) {
                uptime.textContent = 'Uptime: ' + String(payload.uptime_label);
                uptime.classList.remove('hidden');
            } else {
                uptime.classList.add('hidden');
            }
        }
        if (pill) {
            pill.className = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[10px] font-bold ' + workerStateClasses(state);
        }
        if (icon)   icon.textContent = actionIcon;
        if (aLabel) aLabel.textContent = actionLabel;
        if (action) {
            action.dataset.action = actionType;
            action.dataset.instance = instanceKey;
            action.dataset.baseDisabled = actionDisabled ? 'true' : 'false';
            action.className = 'h-9 px-4 inline-flex items-center justify-center rounded-xl text-xs font-bold gap-2 shadow-lg ' + actionClass;
            action.style.border = 'none';
        }

        const actionButton = currentTopnavActionButton();
        if (!actionButton) return;

        actionButton.disabled = aiWorkerBusyState || aiActionPending || actionButton.dataset.baseDisabled === 'true';
        actionButton.classList.toggle('opacity-70', actionButton.disabled);
        actionButton.classList.toggle('cursor-not-allowed', actionButton.disabled);

        // Bind the click handler once. The DOM node is now stable across
        // re-renders so we'd otherwise stack a fresh listener every poll.
        if (actionButton.dataset.clickBound !== '1') {
            actionButton.dataset.clickBound = '1';
            actionButton.addEventListener('click', function() {
                if (actionButton.disabled) return;
                const liveAction = String(actionButton.dataset.action || 'start');
                const key = String(actionButton.dataset.instance || selectedWorkerInstance() || '');
                const liveDynamic = !!(latestAiPayload && latestAiPayload.dynamic);
                const liveLabel = publicAiWorkerName;

                if (liveAction === 'stop') {
                    const stopMessage = liveDynamic
                        ? 'Stop ' + liveLabel + ' now? In dynamic mode this terminates the pod, so nothing is left running.'
                        : 'Stop ' + liveLabel + ' now? This only stops the pod (it is not destroyed).';
                    openConfirmDialog({
                        title: 'Stop AI worker?',
                        message: stopMessage,
                        icon: 'stop_circle',
                        confirmLabel: liveDynamic ? 'Terminate GPU' : 'Stop GPU',
                        buttonClass: 'bg-rose-500 hover:bg-rose-600',
                        onConfirm: function() {
                            mutateAiWorker('/video-upload/ai/stop', key, {
                                busyTitle: liveDynamic ? 'Terminating AI worker' : 'Stopping AI worker',
                                busyMessage: liveDynamic ? 'Sending terminate command to Runpod.' : 'Sending stop command to Runpod.'
                            });
                        }
                    });
                    return;
                }

                openConfirmDialog({
                    title: 'Start AI worker?',
                    message: 'Start ' + liveLabel + ' now? Billing begins while the GPU pod is running.',
                    icon: 'play_circle',
                    confirmLabel: 'Start GPU',
                    buttonClass: 'bg-primary hover:bg-green-600',
                    onConfirm: function() {
                        mutateAiWorker('/video-upload/ai/start', key, {
                            busyTitle: 'Starting AI worker',
                            busyMessage: 'Runpod is spinning up the GPU now.'
                        });
                    }
                });
            });
        }
    }

    function setAiWorkerState(payload) {
        if (!aiWorkerPanel || !payload || typeof payload !== 'object') {
            return;
        }

        payload.instance_label = publicAiWorkerName;
        payload.gpu_name = '';
        payload.cost_per_hr = null;
        payload.manual_hourly_cost = null;
        latestAiPayload = payload;
        const state = String(payload.state || 'unknown').toLowerCase();
        aiWorkerState = state;
        aiWorkerPanel.dataset.state = state;

        const running = ['ready', 'starting', 'stopping'].includes(state);
        const labelText = publicAiWorkerName;

        if (payload.instance_key) {
            setSelectedWorkerInstance(String(payload.instance_key));
        }

        if (aiWorkerBadge) {
            aiWorkerBadge.textContent = running
                ? (state === 'ready' ? 'Running' : (state === 'stopping' ? 'Stopping' : 'Starting'))
                : (state === 'unavailable' ? 'No GPU Available' : (state === 'error' ? 'Error' : 'Stopped'));
            aiWorkerBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border';
            aiWorkerBadge.classList.add(...workerStateClasses(state).split(' '));
        }

        if (aiWorkerMessage) {
            aiWorkerMessage.textContent = String(payload.message || '');
        }

        if (aiWorkerDetail) {
            if (state === 'unavailable') {
                aiWorkerDetail.textContent = 'No free GPU was available for this worker. You can migrate to the lowest-cost available worker.';
            } else if (running) {
                aiWorkerDetail.textContent = 'AI worker is active. You can upload video and run AI now.';
            } else {
                aiWorkerDetail.textContent = 'AI worker is stopped. Start it before running AI on uploads.';
            }
        }

        if (aiSelectedName) {
            aiSelectedName.textContent = labelText;
        }
        if (aiSelectedMeta) {
            const gpuName = String(payload.gpu_name || '').trim();
            const cost = payload.cost_per_hr;
            const parts = [];
            if (gpuName !== '') {
                parts.push(gpuName);
            }
            if (cost !== null && cost !== undefined) {
                parts.push('$' + Number(cost).toFixed(2) + '/hr');
            }
            aiSelectedMeta.textContent = parts.join(' • ');
        }
        if (aiSelectedBadge) {
            aiSelectedBadge.textContent = running ? 'Running' : 'Stopped';
            aiSelectedBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border';
            aiSelectedBadge.classList.add(...workerStateClasses(state).split(' '));
        }
        if (aiSelectedMessage) {
            aiSelectedMessage.textContent = String(payload.message || '');
        }

        if (aiRunningIndicator) {
            aiRunningIndicator.classList.toggle('hidden', !running);
        }
        if (aiRunningName) {
            aiRunningName.textContent = running ? labelText : 'No AI worker running';
        }
        if (aiRunningMeta) {
            aiRunningMeta.textContent = running ? ('Uptime ' + String(payload.uptime_label || '...')) : '';
        }
        if (aiRunningBadge) {
            aiRunningBadge.textContent = running ? 'Running' : 'Stopped';
            aiRunningBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border';
            aiRunningBadge.classList.add(...workerStateClasses(state).split(' '));
        }
        if (aiRunningMessage) {
            aiRunningMessage.textContent = running
                ? 'Worker is active and can process uploads.'
                : 'Start the worker to process uploads.';
        }

        renderTopnavWorker(payload);
    }

    async function refreshAiWorkerStatus(instanceKey = '') {
        if (!aiWorkerPanel) {
            return null;
        }

        if (document.hidden || aiStatusRequestInFlight) {
            return null;
        }

        aiStatusRequestInFlight = true;
        const mySeq = ++statusRequestSeq;

        try {
            const key = instanceKey || selectedWorkerInstance();
            const params = key ? ('?ai_instance=' + encodeURIComponent(key)) : '';
            const response = await fetch('/video-upload/ai/status' + params, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) {
                return null;
            }

            const payload = await response.json();
            if (mySeq !== statusRequestSeq) {
                return null;
            }

            setAiWorkerState(payload);
            return payload;
        } catch (error) {
            return null;
        } finally {
            aiStatusRequestInFlight = false;
        }
    }

    async function mutateAiWorker(endpoint, instanceKey, config = {}) {
        if (!aiWorkerPanel) {
            return;
        }

        try {
            setAiWorkerBusy(true, config.busyTitle || 'Working on AI worker', config.busyMessage || 'Please wait...');
            setAiActionPending(true);

            if (aiActionTimeout) {
                clearTimeout(aiActionTimeout);
            }

            const timeoutMs = endpoint.includes('/video-upload/ai/migrate') ? 150000 : 45000;
            aiActionTimeout = window.setTimeout(function() {
                setAiActionPending(false);
                setAiWorkerBusy(false);
                if (uploadStatus) {
                    uploadStatus.classList.remove('hidden');
                    uploadStatus.textContent = 'The AI worker request is taking longer than expected. Please wait a moment.';
                }
            }, timeoutMs);

            const body = new URLSearchParams({ _csrf: csrfToken });
            if (instanceKey) {
                body.set('ai_instance', instanceKey);
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString(),
                credentials: 'same-origin'
            });

            const payload = await response.json().catch(() => null);

            setAiActionPending(false);
            setAiWorkerBusy(false);
            if (aiActionTimeout) {
                clearTimeout(aiActionTimeout);
                aiActionTimeout = null;
            }

            if (!response.ok || !payload || !payload.ok) {
                const error = payload && payload.error
                    ? String(payload.error)
                    : 'The AI worker request could not be completed right now.';

                if (payload && payload.status) {
                    setAiWorkerState(payload.status);
                }

                if (uploadStatus) {
                    uploadStatus.classList.remove('hidden');
                    uploadStatus.textContent = error;
                }

                const noGpu = /no gpu is available|not enough free gpus/i.test(error);
                if (noGpu && endpoint.includes('/video-upload/ai/start')) {
                    openConfirmDialog({
                        title: 'No GPU Available',
                        message: 'No GPU is free right now. Migrate to the lowest-cost available worker? This usually takes around 1-3 minutes.',
                        icon: 'swap_horiz',
                        confirmLabel: 'Migrate Worker',
                        buttonClass: 'bg-amber-600 hover:bg-amber-500',
                        onConfirm: function() {
                            mutateAiWorker('/video-upload/ai/migrate', '', {
                                busyTitle: 'Migrating AI worker',
                                busyMessage: 'Looking for the lowest-cost available GPU. Average migration time is 1-3 minutes.'
                            });
                        }
                    });
                }

                return;
            }

            if (payload.status) {
                setAiWorkerState(payload.status);
            }

            if (uploadStatus && payload.message) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = String(payload.message);
            }

            window.setTimeout(refreshAiWorkerStatus, 1200);
        } catch (error) {
            if (aiActionTimeout) {
                clearTimeout(aiActionTimeout);
                aiActionTimeout = null;
            }
            setAiActionPending(false);
            setAiWorkerBusy(false);
            if (uploadStatus) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = 'The AI worker could not be reached right now.';
            }
        }
    }

    if (aiWorkerStart) {
        aiWorkerStart.addEventListener('click', function() {
            const instanceKey = selectedWorkerInstance();
            openConfirmDialog({
                title: 'Start AI worker?',
                message: 'Start the AI worker now? Billing begins while the GPU pod is running.',
                icon: 'play_circle',
                confirmLabel: 'Start GPU',
                buttonClass: 'bg-primary hover:bg-green-600',
                onConfirm: function() {
                    mutateAiWorker('/video-upload/ai/start', instanceKey, {
                        busyTitle: 'Starting AI worker',
                        busyMessage: 'Runpod is spinning up the GPU now.'
                    });
                }
            });
        });
    }

    function applyVideoChoice(mode, value, labelText, matchId = '') {
        if (videoSourceMode) {
            videoSourceMode.value = mode;
        }
        selectedStoredMatchId = mode === 'stored' ? String(matchId || '') : '';
        if (storedVideoHidden) {
            storedVideoHidden.value = mode === 'stored' ? String(value || '') : '';
        }
        if (videoPickerSearch) {
            videoPickerSearch.value = mode === 'stored' ? labelText : '';
        }
        if (storedVideoPreviewPanel) {
            storedVideoPreviewPanel.classList.toggle('hidden', mode !== 'stored' || !value);
        }
        if (storedVideoPreviewPlayer && storedVideoPreviewSource) {
            if (mode === 'stored' && value) {
                storedVideoPreviewSource.src = String(value);
                storedVideoPreviewPlayer.load();
            } else {
                storedVideoPreviewPlayer.pause();
                storedVideoPreviewSource.src = '';
                storedVideoPreviewPlayer.load();
            }
        }
        if (mode !== 'stored' && input) {
            input.value = '';
            if (selectedUploadMeta) {
                selectedUploadMeta.textContent = '';
            }
            if (uploadVideoPreviewPlayer && uploadVideoPreviewPanel) {
                uploadVideoPreviewPlayer.pause();
                uploadVideoPreviewPlayer.removeAttribute('src');
                uploadVideoPreviewPlayer.load();
                uploadVideoPreviewPanel.classList.add('hidden');
            }
        }

        // Auto-sync the match dropdown when a stored clip is picked, so the
        // user does not have to mirror their pick in two different controls.
        // Without this you could pick "match_42_clip.mp4" from the library and
        // still get "no match found" because the dropdown was untouched.
        if (mode === 'stored' && matchId && matchSelect && String(matchSelect.value || '') !== String(matchId)) {
            const optionExists = Array.from(matchSelect.options).some((o) => String(o.value) === String(matchId));
            if (optionExists) {
                matchSelect.value = String(matchId);
                matchSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        updateSubmitButtonState();
    }

    function updateSubmitButtonState() {
        if (!submitButton) {
            return;
        }

        const selectedMode = videoSourceMode ? String(videoSourceMode.value || 'upload') : 'upload';
        const hasStoredVideo = selectedMode === 'stored' && storedVideoHidden && storedVideoHidden.value.trim() !== '';
        const hasFile = !!(input && input.files && input.files.length > 0);
        // Show the "Run AI" button whenever we have a clip queued (stored or
        // freshly picked). Don't auto-submit — let the user click it.
        submitButton.classList.toggle('hidden', !(hasStoredVideo || hasFile));
    }

    function refreshVideoPickerOptions() {
        videoPickerOptions = Array.from(document.querySelectorAll('.video-picker-option'));
    }

    function openUploadPicker() {
        if (!input) {
            return;
        }

        applyVideoChoice('upload', '', '');
        input.click();
    }

    function startUploadSubmission() {
        if (!uploadForm || !submitButton || !window.XMLHttpRequest) {
            return;
        }

        const selectedFile = input && input.files && input.files.length > 0 ? input.files[0] : null;
        const selectedMode = videoSourceMode ? videoSourceMode.value : 'upload';
        const wantsAi = runAiInput ? runAiInput.value === '1' : true;
        let selectedMatchId = matchSelect ? String(matchSelect.value || '') : '';

        if (!selectedMatchId) {
            setUploadProgress(0, 'Pick a match from the dropdown above before uploading.', 'Pick a match first');
            if (matchSelect) {
                matchSelect.focus();
                matchSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        if (wantsAi) {
            const selectedCard = document.querySelector('[data-match-card][data-match-id="' + selectedMatchId + '"]');
            if (selectedCard) {
                const lineupInputs = Array.from(selectedCard.querySelectorAll('input[name^="slots["][name$="[player_uid]"]'));
                if (lineupInputs.length > 0) {
                    const hasAssignedPlayer = lineupInputs.some((el) => String(el.value || '').trim() !== '');
                    if (!hasAssignedPlayer) {
                        setUploadProgress(0, 'Assign at least one player to a jersey below, then click Run AI again.', 'Lineup Required');
                        selectedCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        const firstTrigger = selectedCard.querySelector('[data-search-trigger]');
                        if (firstTrigger) firstTrigger.focus();
                        return;
                    }
                }
            }
        }

        if (!selectedFile) {
            if (selectedMode === 'stored' && storedVideoHidden && storedVideoHidden.value.trim() && wantsAi && aiWorkerPanel && aiWorkerState !== 'ready') {
                setUploadProgress(0, 'Start the AI worker and wait for Running/Ready before running AI.', 'AI Worker Required');
                return;
            }
            if (selectedMode === 'upload') {
                setUploadProgress(0, 'Choose a saved video or use Upload a Video first.', 'Video Required');
                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('opacity-70', 'cursor-not-allowed');
            if (uploadSubmitLabel) {
                uploadSubmitLabel.textContent = wantsAi ? 'Running AI...' : 'Saving...';
            }
        } else {
            if (wantsAi && aiWorkerPanel && aiWorkerState !== 'ready') {
                setUploadProgress(0, 'Start the AI worker and wait for Running/Ready before uploading.', 'AI Worker Required');
                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('opacity-70', 'cursor-not-allowed');
            if (uploadSubmitLabel) {
                uploadSubmitLabel.textContent = wantsAi ? 'Uploading + Running AI...' : 'Uploading Video...';
            }
            setUploadProgress(0, 'Preparing the upload...', 'Uploading Video');
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', uploadForm.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');

        const stallHint   = document.getElementById('upload-stall-hint');
        const cancelBtn   = document.getElementById('upload-cancel-button');
        const STALL_MS    = 20000;
        let stallTimer    = null;
        let lastLoaded    = 0;

        function clearStallWatch() {
            if (stallTimer) { window.clearTimeout(stallTimer); stallTimer = null; }
            if (stallHint)  stallHint.classList.add('hidden');
        }

        function armStallWatch() {
            if (stallTimer) window.clearTimeout(stallTimer);
            stallTimer = window.setTimeout(function() {
                if (stallHint) stallHint.classList.remove('hidden');
            }, STALL_MS);
        }

        function showCancelButton() {
            if (!cancelBtn) return;
            cancelBtn.classList.remove('hidden');
            cancelBtn.onclick = function() {
                if (cancelBtn.dataset.cancelled === '1') return;
                cancelBtn.dataset.cancelled = '1';
                try { xhr.abort(); } catch (_e) {}
            };
        }

        function hideCancelButton() {
            if (cancelBtn) cancelBtn.classList.add('hidden');
        }

        showCancelButton();
        armStallWatch();

        // Two-stage flow:
        //  Stage 1 (run_ai=0): pick a file -> auto-save it on the server
        //                       so we have a stored clip and a URL.
        //  Stage 2 (run_ai=1): user clicks Run AI on the saved clip -> the
        //                       analysis is queued and the page reloads to
        //                       the live progress view.
        const isStageOne = wantsAi === false;

        // Stage 2 has no real upload (tiny POST body), so the stall watcher
        // would fire after 20 s of "waiting for the server" even though
        // nothing is wrong. Skip the watcher and the upload-progress bar
        // entirely for Stage 2; show a calm "Queueing your analysis..."
        // message instead.
        if (!isStageOne) {
            setUploadProgress(0, 'Queueing your analysis on the AI worker...', 'Queueing');
            const queueBar = document.getElementById('upload-progress-bar');
            if (queueBar) queueBar.style.width = '100%';
            const queuePct = document.getElementById('upload-progress-percent');
            if (queuePct) queuePct.textContent = '';
            clearStallWatch();
        }

        xhr.upload.addEventListener('progress', function(event) {
            if (!isStageOne) return; // already handled above
            if (!event.lengthComputable) {
                setUploadProgress(5, 'Saving your video...', 'Saving');
                armStallWatch();
                return;
            }

            if (event.loaded !== lastLoaded) {
                lastLoaded = event.loaded;
                armStallWatch();
                if (stallHint) stallHint.classList.add('hidden');
            }

            const percent = Math.max(1, Math.round((event.loaded / event.total) * 100));
            const message = percent < 100
                ? 'Saving your video to the server...'
                : 'Saved — wrapping up...';
            setUploadProgress(percent, message, 'Saving');
        });

        xhr.addEventListener('load', function() {
            clearStallWatch();
            hideCancelButton();
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText || '{}');
            } catch (_e) {}

            if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                // Stage 1 success → swap the picker into "stored" mode so the
                // user just hits Run AI next. No page reload, smooth UX.
                if (isStageOne && payload.stored_video_url) {
                    setUploadProgress(100, 'Video saved. Click Run AI when you’re ready.', 'Saved');
                    if (input) input.value = '';
                    if (uploadVideoPreviewPanel) uploadVideoPreviewPanel.classList.add('hidden');
                    const fname = (payload.stored_video_url || '').split('/').pop() || 'Saved clip';
                    applyVideoChoice('stored', payload.stored_video_url, fname, payload.match_id || '');
                    if (runAiInput) runAiInput.value = '1';
                    resetUploadButton();
                    if (submitButton) {
                        submitButton.classList.remove('hidden');
                        submitButton.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    return;
                }

                // Stage 2 success → AI queued, jump to the live progress view.
                setUploadProgress(100, 'Analysis queued. Opening live progress...', 'Queued');
                window.setTimeout(function() {
                    window.location.href = payload.redirect_url || '/video-upload';
                }, 350);
                return;
            }

            // Map common server statuses to plain-English copy. Don't echo the
            // raw server error back to the user.
            let errorMessage = (payload && payload.error) ? String(payload.error) : '';
            if (!errorMessage) {
                if (xhr.status === 413) {
                    errorMessage = 'That video is too large for the server. Try a smaller file or compress it.';
                } else if (xhr.status === 419) {
                    errorMessage = 'Your session expired. Refresh the page and try again.';
                } else if (xhr.status === 0) {
                    errorMessage = 'The upload was interrupted before it finished. Check your connection and try again.';
                } else if (xhr.status >= 500) {
                    errorMessage = 'Something went wrong on the server. Try again in a moment.';
                } else {
                    errorMessage = 'Could not save the video right now. Please try again.';
                }
            }
            setUploadProgress(0, errorMessage, isStageOne ? 'Upload didn’t finish' : 'Could not start analysis');
            resetUploadButton();
        });

        xhr.addEventListener('error', function() {
            clearStallWatch();
            hideCancelButton();
            // Most common reason on shared hosting: the file was bigger than
            // the host's hard upload cap and Apache dropped the connection.
            // Phrasing avoids exposing that — just nudges the user to try
            // again or use a smaller file.
            const message = (xhr.upload && lastLoaded > 0)
                ? 'The upload was cut off before it finished. Check your connection — or try a smaller file — and try again.'
                : 'Something stopped the upload before it could start. Refresh the page and try again.';
            setUploadProgress(0, message, 'Upload didn’t finish');
            resetUploadButton();
        });

        xhr.addEventListener('abort', function() {
            clearStallWatch();
            hideCancelButton();
            setUploadProgress(0, 'Upload cancelled.', 'Cancelled');
            resetUploadButton();
        });

        xhr.send(new FormData(uploadForm));
    }

    function filterVideoPickerOptions() {
        if (!videoPickerSearch) {
            return;
        }
        const query = videoPickerSearch.value.trim().toLowerCase();
        let visibleCount = 0;

        videoPickerOptions.forEach((option) => {
            const haystack = String(option.dataset.search || option.textContent || '').toLowerCase();
            const show = query === '' || haystack.includes(query);
            const row = option.closest('[data-video-row]');
            if (row) {
                row.classList.toggle('hidden', !show);
            } else {
                option.classList.toggle('hidden', !show);
            }
            if (show) {
                visibleCount += 1;
            }
        });

        if (videoPickerResults) {
            videoPickerResults.classList.toggle('hidden', visibleCount === 0);
        }
    }

    async function postForm(endpoint, fields) {
        const body = new URLSearchParams({ _csrf: csrfToken });
        Object.entries(fields || {}).forEach(([key, value]) => {
            body.set(key, String(value ?? ''));
        });

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString(),
            credentials: 'same-origin'
        });
        const payload = await response.json().catch(() => ({}));
        return { response, payload };
    }

    async function deleteStoredVideo(matchId, videoUrl, videoName, deleteButton) {
        try {
            const { response, payload } = await postForm('/video-upload/video/delete', {
                match_id: matchId,
                video_url: videoUrl
            });

            if (!response.ok || !payload || payload.ok !== true) {
                throw new Error(payload && payload.error ? String(payload.error) : 'Could not delete stored video.');
            }

            const row = deleteButton ? deleteButton.closest('[data-video-row]') : null;
            if (row) {
                row.remove();
            }

            if (storedVideoHidden && String(storedVideoHidden.value || '') === String(videoUrl || '')) {
                applyVideoChoice('upload', '', '');
            }

            refreshVideoPickerOptions();
            filterVideoPickerOptions();
            if (uploadStatus) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = payload.message || ('Deleted ' + videoName + '.');
            }

            document.dispatchEvent(new CustomEvent('match-video-state-changed', {
                detail: { matchId: String(matchId || '') }
            }));
        } catch (error) {
            if (uploadStatus) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = error instanceof Error ? error.message : 'Could not delete the stored video.';
            }
        }
    }

    async function cancelAnalysis(matchId) {
        try {
            const { response, payload } = await postForm('/video-upload/ai/cancel', {
                match_id: matchId
            });

            if (!response.ok || !payload || payload.ok !== true) {
                throw new Error(payload && payload.error ? String(payload.error) : 'Could not stop AI processing.');
            }

            if (uploadStatus) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = payload.message || 'AI processing was stopped.';
            }

            document.dispatchEvent(new CustomEvent('match-video-state-changed', {
                detail: { matchId: String(matchId || '') }
            }));
        } catch (error) {
            if (uploadStatus) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = error instanceof Error ? error.message : 'Could not stop AI processing.';
            }
        }
    }

    if (videoPickerSearch && videoPickerResults) {
        videoPickerSearch.addEventListener('focus', function() {
            videoPickerResults.classList.remove('hidden');
            filterVideoPickerOptions();
        });

        videoPickerSearch.addEventListener('click', function() {
            videoPickerResults.classList.remove('hidden');
            filterVideoPickerOptions();
        });

        videoPickerSearch.addEventListener('input', function() {
            videoPickerResults.classList.remove('hidden');
            filterVideoPickerOptions();
        });

        document.addEventListener('click', function(event) {
            const pickerRoot = document.getElementById('video-library-picker');
            if (pickerRoot && !pickerRoot.contains(event.target)) {
                videoPickerResults.classList.add('hidden');
            }
        });

    }

    document.addEventListener('click', function(event) {
        const deleteButton = event.target instanceof Element ? event.target.closest('.video-picker-delete') : null;
        if (deleteButton) {
            event.preventDefault();
            event.stopPropagation();
            const matchId = String(deleteButton.getAttribute('data-match-id') || '');
            const videoUrl = String(deleteButton.getAttribute('data-video-url') || '');
            const videoName = String(deleteButton.getAttribute('data-video-name') || 'saved video');
            openConfirmDialog({
                title: 'Delete saved video?',
                message: 'Delete "' + videoName + '" from storage? This cannot be undone.',
                icon: 'delete',
                confirmLabel: 'Delete Video',
                buttonClass: 'bg-red-600 hover:bg-red-500',
                onConfirm: function() {
                    deleteStoredVideo(matchId, videoUrl, videoName, deleteButton);
                }
            });
            return;
        }

        const cancelButton = event.target instanceof Element ? event.target.closest('[data-cancel-analysis]') : null;
        if (cancelButton) {
            event.preventDefault();
            const matchId = String(cancelButton.getAttribute('data-match-id') || '');
            openConfirmDialog({
                title: 'Stop AI processing?',
                message: 'Stop AI processing for this match now? Current progress will be discarded.',
                icon: 'stop_circle',
                confirmLabel: 'Stop AI',
                buttonClass: 'bg-red-600 hover:bg-red-500',
                onConfirm: function() {
                    cancelAnalysis(matchId);
                }
            });
            return;
        }

        const option = event.target instanceof Element ? event.target.closest('.video-picker-option') : null;
        if (option && videoPickerResults && videoPickerResults.contains(option)) {
            const mode = String(option.dataset.mode || 'stored');
            const value = String(option.dataset.value || '');
            const optionMatchId = String(option.dataset.matchId || '');
            const labelText = option.querySelector('.font-medium')?.textContent?.trim() || option.textContent.trim();
            if (mode === 'upload') {
                openUploadPicker();
            } else {
                applyVideoChoice(mode, value, labelText, optionMatchId);
            }
            videoPickerResults.classList.add('hidden');
        }
    });

    function updateMatchCards() {
        if (!matchCards.length) {
            return;
        }

        const selected = matchSelect ? String(matchSelect.value || '') : '';
        let shown = 0;
        matchCards.forEach((card) => {
            const cardMatchId = String(card.dataset.matchId || '');
            const show = selected !== '' && cardMatchId === selected;
            card.classList.toggle('hidden', !show);
            if (show) {
                shown += 1;
            }
        });

        if (matchEmptyState) {
            matchEmptyState.classList.toggle('hidden', shown > 0);
        }
    }

    if (matchSelect) {
        matchSelect.addEventListener('change', function() {
            filterVideoPickerOptions();
            updateMatchCards();
            updateSubmitButtonState();
        });
        if (requestedMatchId !== '' && requestedMatchId !== '0') {
            matchSelect.value = requestedMatchId;
        } else {
            // If there's only one real match, pre-select it so the user
            // never sees "no match found" after picking a video.
            const realOptions = Array.from(matchSelect.options).filter((o) => String(o.value || '').trim() !== '');
            if (realOptions.length === 1) {
                matchSelect.value = realOptions[0].value;
            }
        }
        updateMatchCards();
    }

    function setUploadProgress(percent, message, labelText) {
        if (uploadProgressPanel) {
            uploadProgressPanel.classList.remove('hidden');
        }
        if (uploadProgressLabel && labelText) {
            uploadProgressLabel.textContent = labelText;
        }
        if (uploadProgressPercent) {
            uploadProgressPercent.textContent = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (uploadProgressBar) {
            uploadProgressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (uploadStatus) {
            uploadStatus.classList.remove('hidden');
            uploadStatus.textContent = message;
        }
    }

    function resetUploadButton() {
        if (!submitButton) {
            return;
        }
        submitButton.disabled = false;
        submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
        if (uploadSubmitLabel) {
            uploadSubmitLabel.textContent = 'Run AI';
        }
        updateSubmitButtonState();
    }

    if (input) {
        input.addEventListener('change', function() {
            if (this.files.length <= 0) {
                return;
            }

            // Stage 1: must have a match selected. Without one we can't even
            // store the file, so block before opening any network calls.
            const selectedMatchId = matchSelect ? String(matchSelect.value || '') : '';
            if (!selectedMatchId) {
                setUploadProgress(0, 'Pick a match from the dropdown first, then choose your video.', 'Pick a match');
                if (matchSelect) {
                    matchSelect.focus();
                    matchSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                this.value = '';
                return;
            }

            const f = this.files[0];
            if (selectedUploadMeta) {
                selectedUploadMeta.textContent = f.name + ' • ' + (f.size / (1024 * 1024)).toFixed(1) + ' MB';
            }

            if (uploadPreviewObjectUrl) {
                URL.revokeObjectURL(uploadPreviewObjectUrl);
            }
            uploadPreviewObjectUrl = URL.createObjectURL(f);
            if (uploadVideoPreviewPlayer && uploadVideoPreviewPanel) {
                uploadVideoPreviewPlayer.src = uploadPreviewObjectUrl;
                uploadVideoPreviewPanel.classList.remove('hidden');
            }

            if (videoPickerSearch) {
                videoPickerSearch.value = f.name;
            }
            if (videoSourceMode) {
                videoSourceMode.value = 'upload';
            }

            // Stage 1: just save the file on the server. No AI yet — that's
            // what the Run AI button is for once the upload finishes.
            if (runAiInput) runAiInput.value = '0';
            startUploadSubmission();
        });
    }

    if (uploadForm && submitButton && window.XMLHttpRequest) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            startUploadSubmission();
        });
    }

    updateSubmitButtonState();

    if (aiWorkerPanel) {
        const initial = (initialAiWorkerData && typeof initialAiWorkerData === 'object') ? initialAiWorkerData : {
            configured: true,
            instance_key: '',
            state: 'stopped',
            label: 'Stopped',
            message: 'Checking worker status...',
            supports_stop: true,
            ai_ready: false
        };
        setAiWorkerBusy(true, 'Checking', 'Checking AI worker status...');
        setAiWorkerState(initial);
        refreshAiWorkerStatus().finally(function() {
            setAiWorkerBusy(false);
        });

        // Adaptive poll: tight cadence only while the worker is in a transient
        // state (starting / stopping). Stable states (ready / stopped /
        // unavailable / error) drop to a long interval so we are not hammering
        // /video-upload/ai/status every 5 s on an idle dashboard.
        let aiPollTimer = null;
        function scheduleAiPoll() {
            if (aiPollTimer) clearTimeout(aiPollTimer);
            const transient = aiWorkerState === 'starting' || aiWorkerState === 'stopping';
            const next = transient ? 4000 : (document.hidden ? 60000 : 20000);
            aiPollTimer = window.setTimeout(async function() {
                if (['starting', 'stopping', 'ready', 'unavailable', 'stopped', 'error'].includes(aiWorkerState)) {
                    await refreshAiWorkerStatus();
                }
                scheduleAiPoll();
            }, next);
        }
        scheduleAiPoll();
        document.addEventListener('visibilitychange', scheduleAiPoll);
    }

    // Handle AJAX lineup saving
    document.addEventListener('submit', async function(e) {
        const form = e.target.closest('form');
        if (!form || !form.action.includes('/video-upload/lineup')) return;

        e.preventDefault();
        const btn = form.querySelector('[data-lineup-submit]');
        const submitText = btn ? btn.querySelector('.submit-text') : null;
        const originalText = submitText ? submitText.textContent : 'Save Jersey Lineup';
        
        if (btn) btn.disabled = true;
        if (submitText) submitText.textContent = 'Saving...';

        try {
            const formData = new FormData(form);
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (response.ok) {
                if (btn) btn.classList.add('bg-emerald-600');
                if (submitText) submitText.textContent = 'Saved!';
                setTimeout(() => {
                    if (btn) btn.classList.remove('bg-emerald-600');
                    if (submitText) submitText.textContent = originalText;
                    if (btn) btn.disabled = false;
                }, 2000);
            } else {
                const text = await response.text();
                alert('Could not save lineup: ' + (text || 'Unknown error'));
                if (btn) btn.disabled = false;
                if (submitText) submitText.textContent = originalText;
            }
        } catch (error) {
            console.error('Lineup save error:', error);
            alert('A network error occurred.');
            if (btn) btn.disabled = false;
            if (submitText) submitText.textContent = originalText;
        }
    });
})();

(function() {
    let panels = Array.from(document.querySelectorAll('[data-ai-progress]'));
    const matchSelect = document.getElementById('match-id-select');
    const requestedMatchId = '<?= (int)$requestedMatchId ?>';
    const focusPanel = '<?= addslashes((string)$focusPanel) ?>';
    const csrfToken = '<?= addslashes($csrfToken ?? '') ?>';
    if (!panels.length) {
        return;
    }

    // Friendly, non-technical copy. Keys cover the canonical statuses plus
    // mid-processing stages emitted by the AI worker.
    const statusCopy = {
        pending:    { title: 'Ready when you are',    message: 'Upload a clip and the AI will turn it into stats.',  icon: 'play_circle',     chip: 'idle',    label: 'Ready' },
        queued:     { title: 'Lined up',              message: 'Waiting for the AI worker to start your job.',       icon: 'schedule',        chip: 'waiting', label: 'Lined up' },
        processing: { title: 'Analyzing the match',   message: 'The AI is watching the players...',                  icon: 'auto_awesome',    chip: 'busy',    label: 'Analyzing' },
        processed:  { title: 'Stats are ready',       message: 'AI stats are saved below.',                          icon: 'check_circle',    chip: 'done',    label: 'Done' },
        failed:     { title: "Last run didn't finish",message: 'You can try again whenever you are ready.',          icon: 'error_outline',   chip: 'stopped', label: 'Stopped' }
    };

    // Mid-processing stage overrides — only the message changes.
    const stageMessage = {
        startup:     'Spinning up the AI worker...',
        downloading: 'Fetching your video...',
        analyzing:   'Watching the match...',
        finalizing:  'Saving the stats...'
    };

    const activeStreams = new Map();   // matchId -> { source, fallbackTimer }
    const FALLBACK_POLL_MS = 4000;
    const ETA_WINDOW_MS = 90000;

    function panelIsVisible(panel) {
        if (!panel || panel.classList.contains('hidden')) {
            return false;
        }
        const matchCard = panel.closest('[data-match-card]');
        return !matchCard || !matchCard.classList.contains('hidden');
    }

    function selectedPanel() {
        const selectedMatchId = String(matchSelect?.value || '').trim();
        if (selectedMatchId !== '') {
            return panels.find((panel) => String(panel.dataset.matchId || '') === selectedMatchId) || null;
        }
        return panels.find((panel) => panelIsVisible(panel)) || null;
    }

    function refreshMatchCollections() {
        panels = Array.from(document.querySelectorAll('[data-ai-progress]'));
    }

    function syncVisibleMatchCard() {
        const selectedMatchId = String(matchSelect?.value || '').trim();
        const cards = Array.from(document.querySelectorAll('[data-match-card]'));
        let visibleCount = 0;
        cards.forEach((card) => {
            const cardMatchId = String(card.dataset.matchId || '');
            const show = selectedMatchId !== '' && cardMatchId === selectedMatchId;
            card.classList.toggle('hidden', !show);
            if (show) visibleCount += 1;
        });
        const emptyState = document.getElementById('match-selection-empty');
        if (emptyState) emptyState.classList.toggle('hidden', visibleCount > 0);
    }

    async function refreshMatchCard(matchId) {
        if (!matchId) return;
        try {
            const response = await fetch(window.location.href, {
                headers: { 'Accept': 'text/html' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) return;
            const html = await response.text();
            const parser = new DOMParser();
            const freshDocument = parser.parseFromString(html, 'text/html');
            const freshCard = Array.from(freshDocument.querySelectorAll('[data-match-card]')).find((card) => String(card.dataset.matchId || '') === String(matchId));
            const currentCard = Array.from(document.querySelectorAll('[data-match-card]')).find((card) => String(card.dataset.matchId || '') === String(matchId));
            if (!freshCard || !currentCard) return;

            currentCard.outerHTML = freshCard.outerHTML;
            refreshMatchCollections();
            syncVisibleMatchCard();
            const restoredPanel = panels.find((panel) => String(panel.dataset.matchId || '') === String(matchId));
            if (restoredPanel) attachPanelStreams(restoredPanel);
        } catch (error) {
            // Keep existing markup if refetch fails.
        }
    }

    const chipClassByKey = {
        idle:    'status-chip-idle',
        waiting: 'status-chip-waiting',
        busy:    'status-chip-busy',
        done:    'status-chip-done',
        stopped: 'status-chip-stopped'
    };

    function humanizeAgo(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) return '';
        if (seconds < 60) return 'just now';
        if (seconds < 3600) {
            const m = Math.floor(seconds / 60);
            return m + ' minute' + (m === 1 ? '' : 's') + ' ago';
        }
        if (seconds < 86400) {
            const h = Math.floor(seconds / 3600);
            return h + ' hour' + (h === 1 ? '' : 's') + ' ago';
        }
        const d = Math.floor(seconds / 86400);
        return d + ' day' + (d === 1 ? '' : 's') + ' ago';
    }

    function refineCopy(copy, status, payload) {
        const error = String(payload.error_message || payload.message || '').toLowerCase();
        if (status === 'failed' && error.includes('manually')) {
            return { ...copy, title: 'You stopped the last run', message: 'The video is still saved. Click Try Again whenever you are ready.' };
        }
        if (status === 'failed' && error !== '') {
            return { ...copy, title: "Last run didn't finish", message: 'Something interrupted the analysis. Click Try Again to re-run it.' };
        }
        return copy;
    }

    function setPanelState(panel, payload) {
        const previousStatus = String(panel.dataset.status || '').toLowerCase();
        const status = String(payload.status || 'pending').toLowerCase();
        const stage = String(payload.stage || status).toLowerCase();
        const percent = Math.max(0, Math.min(100, Number(payload.percent || 0)));

        const stageNode      = panel.querySelector('[data-progress-stage]');
        const percentNode    = panel.querySelector('[data-progress-percent]');
        const barNode        = panel.querySelector('[data-progress-bar]');
        const barWrapNode    = panel.querySelector('[data-progress-bar-wrap]');
        const messageNode    = panel.querySelector('[data-progress-message]');
        const detailNode     = panel.querySelector('[data-progress-detail]');
        const historyNode    = panel.querySelector('[data-progress-history]');
        const historyHumanNode = panel.querySelector('[data-last-analyzed-human]');
        const iconNode       = panel.querySelector('[data-progress-icon]');
        const iconWrap       = panel.querySelector('[data-progress-icon-wrap]');
        const cancelBtn      = panel.querySelector('[data-cancel-analysis]');
        const retryBtn       = panel.querySelector('[data-retry-analysis]');
        const liveTag        = panel.querySelector('[data-progress-live]');

        const baseCopy = statusCopy[status] || statusCopy.pending;
        const copy     = refineCopy(baseCopy, status, payload);
        const chipKey  = chipClassByKey[copy.chip] || 'status-chip-idle';
        const isBusy   = status === 'queued' || status === 'processing';
        const showProgressBar = isBusy;

        panel.classList.remove('hidden');
        panel.dataset.status = status;

        if (stageNode) stageNode.textContent = copy.title;
        if (iconNode)  iconNode.textContent  = copy.icon;
        if (iconWrap)  iconWrap.className    = 'inline-flex h-10 w-10 flex-none items-center justify-center rounded-full ' + chipKey;
        if (messageNode) {
            const mid = stageMessage[stage];
            messageNode.textContent = isBusy && mid
                ? mid
                : (status === 'failed' && payload.message ? payload.message : copy.message);
        }

        if (barWrapNode) barWrapNode.classList.toggle('hidden', !showProgressBar);
        if (percentNode) {
            percentNode.classList.toggle('hidden', !showProgressBar);
            percentNode.textContent = percent + '%';
        }
        if (barNode) barNode.style.width = percent + '%';

        if (detailNode) {
            const currentFrame = Number(payload.current_frame || 0);
            const totalFrames  = Number(payload.total_frames || 0);
            const parts = [];
            if (isBusy && currentFrame > 0 && totalFrames > 0) {
                parts.push(currentFrame.toLocaleString() + ' / ' + totalFrames.toLocaleString() + ' frames');
            }
            if (isBusy) {
                const eta = computeEta(panel, percent);
                if (eta !== null) parts.push('~' + formatSeconds(eta) + ' left');
            }
            detailNode.textContent = parts.join(' • ');
        }

        // Match-card chip in the title row, kept in sync with this panel.
        const matchCard = panel.closest('[data-match-card]');
        if (matchCard) {
            const chip = matchCard.querySelector('[data-match-chip]');
            const chipLabel = matchCard.querySelector('[data-match-chip-label]');
            const chipIcon = matchCard.querySelector('[data-match-chip-icon]');
            if (chip) chip.className = 'status-chip ' + chipKey;
            if (chipLabel) chipLabel.textContent = copy.label;
            if (chipIcon) chipIcon.textContent = copy.icon;
        }

        // Last-analyzed footer (only when there's a successful run).
        if (historyNode && historyHumanNode) {
            const ts = payload.processed_at ? Date.parse(payload.processed_at) : NaN;
            if (Number.isFinite(ts)) {
                historyHumanNode.textContent = humanizeAgo(Math.round((Date.now() - ts) / 1000));
                historyNode.classList.remove('hidden');
            }
        }

        if (cancelBtn) cancelBtn.classList.toggle('hidden', !isBusy);
        if (retryBtn) {
            const showRetry = status === 'failed';
            retryBtn.classList.toggle('hidden', !showRetry);
            if (showRetry) syncRetryButtonToWorkerState(retryBtn);
        }
        if (liveTag) liveTag.classList.toggle('hidden', !isBusy);

        if (status === 'processed' && previousStatus !== 'processed' && !panel.dataset.reloadScheduled) {
            panel.dataset.reloadScheduled = '1';
            window.setTimeout(() => refreshMatchCard(panel.dataset.matchId), 600);
        }
    }

    function readAiWorkerState() {
        const panel = document.querySelector('[data-ai-worker-panel]');
        return String(panel?.dataset.state || '').toLowerCase();
    }

    function syncRetryButtonToWorkerState(retryBtn) {
        const labelNode = retryBtn.querySelector('[data-retry-label]') || retryBtn.querySelector('span:last-child');
        const workerState = readAiWorkerState();
        const usable = workerState === 'ready' || workerState === 'starting' || workerState === '';
        retryBtn.disabled = !usable;
        if (usable) {
            retryBtn.removeAttribute('aria-disabled');
            retryBtn.title = 'Re-queue AI processing for this match';
            if (labelNode) labelNode.textContent = 'Try Again';
            return;
        }
        retryBtn.setAttribute('aria-disabled', 'true');
        if (workerState === 'unavailable') {
            retryBtn.title = 'No GPU is available right now. Wait a moment or migrate the worker, then try again.';
            if (labelNode) labelNode.textContent = 'No GPU';
        } else {
            retryBtn.title = 'The AI worker is off. Start it from the top bar, then come back and try again.';
            if (labelNode) labelNode.textContent = 'Worker off';
        }
    }

    function refreshAllRetryButtons() {
        document.querySelectorAll('[data-retry-analysis]').forEach((btn) => {
            if (!btn.classList.contains('hidden')) syncRetryButtonToWorkerState(btn);
        });
    }

    // The other IIFE updates [data-ai-worker-panel]'s dataset.state every few
    // seconds; observe it so the retry button reflects worker status live.
    (function watchWorkerState() {
        const target = document.querySelector('[data-ai-worker-panel]');
        if (!target || typeof MutationObserver === 'undefined') return;
        const observer = new MutationObserver(refreshAllRetryButtons);
        observer.observe(target, { attributes: true, attributeFilter: ['data-state'] });
    })();

    function computeEta(panel, percent) {
        if (percent <= 5 || percent >= 100) {
            panel.dataset.etaAnchor = '';
            return null;
        }
        const now = Date.now();
        if (!panel.dataset.etaAnchor) {
            panel.dataset.etaAnchor = JSON.stringify({ at: now, percent });
            return null;
        }
        try {
            const anchor = JSON.parse(panel.dataset.etaAnchor);
            const elapsed = now - Number(anchor.at || now);
            const delta = percent - Number(anchor.percent || percent);
            if (elapsed < 4000 || delta <= 0) return null;
            if (elapsed > ETA_WINDOW_MS) {
                panel.dataset.etaAnchor = JSON.stringify({ at: now, percent });
                return null;
            }
            const remaining = (100 - percent) * (elapsed / delta) / 1000;
            if (!Number.isFinite(remaining) || remaining <= 0) return null;
            return Math.min(60 * 60, Math.round(remaining));
        } catch (_e) {
            return null;
        }
    }

    function formatSeconds(seconds) {
        if (seconds < 60) return seconds + 's';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return s ? m + 'm ' + s + 's' : m + 'm';
    }

    async function pollPanelOnce(panel) {
        const matchId = panel.dataset.matchId;
        if (!matchId) return;
        try {
            const response = await fetch('/video-upload/progress?match_id=' + encodeURIComponent(matchId), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) return;
            const payload = await response.json();
            setPanelState(panel, payload);
        } catch (_e) {
            // ignore - keep last state
        }
    }

    function detachPanelStream(panel) {
        const matchId = panel.dataset.matchId;
        if (!matchId) return;
        const entry = activeStreams.get(matchId);
        if (!entry) return;
        if (entry.source) {
            try { entry.source.close(); } catch (_e) {}
        }
        if (entry.fallbackTimer) clearInterval(entry.fallbackTimer);
        if (entry.kickTimer) clearInterval(entry.kickTimer);
        activeStreams.delete(matchId);
    }

    /**
     * Self-healing kick: if a match is stuck in "queued" or "processing"
     * because the host doesn't have a working cron (or exec() is disabled),
     * poke the queue from the user's open tab. The endpoint accepts the
     * request, returns 200 fast, and runs processMatchVideo in the
     * background via fastcgi_finish_request.
     */
    function pingKick(matchId) {
        const fd = new FormData();
        fd.set('_csrf', csrfToken);
        fd.set('match_id', String(matchId));
        return fetch('/video-analysis/kick', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            keepalive: true
        }).catch(() => null);
    }

    function attachPanelStreams(panel) {
        if (!panel) return;
        const matchId = panel.dataset.matchId;
        if (!matchId) return;
        if (activeStreams.has(matchId)) return;

        let source = null;
        let fallbackTimer = null;
        let kickTimer = null;

        if (typeof EventSource !== 'undefined') {
            try {
                source = new EventSource('/video-analysis/stream?match_id=' + encodeURIComponent(matchId));
                source.addEventListener('progress', (ev) => {
                    try { setPanelState(panel, JSON.parse(ev.data)); } catch (_e) {}
                });
                source.addEventListener('done', (ev) => {
                    try {
                        const data = JSON.parse(ev.data);
                        if (data?.status) panel.dataset.status = String(data.status).toLowerCase();
                    } catch (_e) {}
                    detachPanelStream(panel);
                    pollPanelOnce(panel);
                });
                source.addEventListener('error', () => {
                    // EventSource auto-reconnects; only fall back to polling if connection genuinely closed.
                    if (source.readyState === EventSource.CLOSED && !fallbackTimer) {
                        fallbackTimer = window.setInterval(() => pollPanelOnce(panel), FALLBACK_POLL_MS);
                        const entry = activeStreams.get(matchId);
                        if (entry) entry.fallbackTimer = fallbackTimer;
                    }
                });
            } catch (_e) {
                source = null;
            }
        }

        if (!source) {
            fallbackTimer = window.setInterval(() => pollPanelOnce(panel), FALLBACK_POLL_MS);
        }

        // Very gentle kick while the job is queued — only nudges the queue
        // (does NOT run analysis in-process, which would block FPM workers).
        // 90-second cadence keeps load minimal even with many tabs open. The
        // cron in scripts/process-queued-videos.php is the real driver.
        let kicksFired = 0;
        kickTimer = window.setInterval(() => {
            const status = String(panel.dataset.status || '').toLowerCase();
            if (status !== 'queued') return;
            if (kicksFired++ > 8) return; // ~12 minutes max, then stop nagging
            pingKick(matchId);
        }, 90000);

        activeStreams.set(matchId, { source, fallbackTimer, kickTimer });
        pollPanelOnce(panel); // prime initial state
        if (String(panel.dataset.status || '').toLowerCase() === 'queued') {
            pingKick(matchId);
        }
    }

    async function postCsrfJson(url, formData) {
        formData = formData || new FormData();
        formData.set('_csrf', csrfToken);
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        let payload = null;
        try { payload = await response.json(); } catch (_e) {}
        return { ok: response.ok, payload };
    }

    document.addEventListener('click', async function(e) {
        const retryBtn = e.target.closest('[data-retry-analysis]');
        if (!retryBtn) return;
        e.preventDefault();
        if (retryBtn.disabled || retryBtn.getAttribute('aria-disabled') === 'true') return;
        const matchId = String(retryBtn.dataset.matchId || '');
        if (!matchId) return;

        retryBtn.disabled = true;
        const formData = new FormData();
        formData.set('match_id', matchId);
        const { ok, payload } = await postCsrfJson('/video-upload/ai/retry', formData);
        // Re-sync against current worker state instead of just re-enabling.
        syncRetryButtonToWorkerState(retryBtn);

        if (!ok) {
            alert((payload && payload.error) || 'AI processing could not be re-queued right now.');
            return;
        }

        const panel = panels.find((p) => String(p.dataset.matchId || '') === matchId);
        if (panel) {
            detachPanelStream(panel);
            panel.dataset.status = 'queued';
            panel.dataset.etaAnchor = '';
            attachPanelStreams(panel);
            pollPanelOnce(panel);
        }
    });

    function ensureSelectedStream() {
        const panel = selectedPanel();
        panels.forEach((other) => {
            if (other !== panel) detachPanelStream(other);
        });
        if (panel) attachPanelStreams(panel);
    }

    if (requestedMatchId !== '' && requestedMatchId !== '0' && matchSelect) {
        matchSelect.value = requestedMatchId;
    }

    if (matchSelect) {
        matchSelect.addEventListener('change', ensureSelectedStream);
    }

    document.addEventListener('match-video-state-changed', function(event) {
        const matchId = String(event?.detail?.matchId || '').trim();
        if (matchId !== '') {
            refreshMatchCard(matchId).finally(ensureSelectedStream);
            return;
        }
        ensureSelectedStream();
    });

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            panels.forEach(detachPanelStream);
        } else {
            ensureSelectedStream();
        }
    });

    window.addEventListener('beforeunload', function() {
        panels.forEach(detachPanelStream);
    });

    ensureSelectedStream();

    if (focusPanel === 'progress') {
        window.setTimeout(function() {
            const panel = selectedPanel();
            if (!panel) return;
            panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (window.history && typeof window.history.replaceState === 'function') {
                const url = new URL(window.location.href);
                url.searchParams.delete('focus');
                window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
            }
        }, 250);
    }
})();

/* ─── Searchable lineup dropdown ───────────────────────────────────────
   Replaces the native <select> on each jersey slot. Shared event handlers
   (single click + keydown listener on document) so adding rows is cheap.

   Fuzzy scoring (per match):
     score = 100  exact name prefix
           +  60  any word in name starts with query
           +  30  name contains query (substring)
           +  15  every char of query appears in order in name (subseq)
   Tied scores keep the original DOM order, which mirrors the server's
   accounts.fname ascending sort. */
(function() {
    if (!document.querySelector('[data-search-select]')) return;

    function closeAll(except) {
        document.querySelectorAll('[data-search-select][data-open="1"]').forEach((el) => {
            if (el === except) return;
            el.dataset.open = '0';
            const trig = el.querySelector('[data-search-trigger]');
            if (trig) trig.setAttribute('aria-expanded', 'false');
        });
    }

    function score(query, target) {
        if (!query) return 1; // empty query keeps everything visible
        const q = query.toLowerCase().trim();
        if (q === '') return 1;
        const t = String(target || '').toLowerCase();
        if (!t) return 0;
        if (t.startsWith(q)) return 100;
        // word-prefix scan
        const words = t.split(/[\s,;()|/-]+/).filter(Boolean);
        for (const w of words) if (w.startsWith(q)) return 60;
        if (t.includes(q)) return 30;
        // subsequence: every char of q appears in t in order
        let i = 0;
        for (let k = 0; k < t.length && i < q.length; k++) {
            if (t.charAt(k) === q.charAt(i)) i++;
        }
        if (i === q.length) return 15;
        return 0;
    }

    function applyFilter(root) {
        const list = root.querySelector('[data-search-list]');
        const empty = root.querySelector('[data-search-empty]');
        const input = root.querySelector('[data-search-input]');
        if (!list) return;
        const q = input ? input.value : '';
        let visibleCount = 0;
        let firstVisible = null;
        Array.from(list.children).forEach((li) => {
            li.dataset.active = '0';
            const tokens = li.dataset.searchTokens || '';
            const s = score(q, tokens);
            if (s > 0) {
                li.classList.remove('is-hidden');
                li.dataset.score = String(s);
                visibleCount++;
                if (!firstVisible) firstVisible = li;
            } else {
                li.classList.add('is-hidden');
                li.dataset.score = '0';
            }
        });
        // Reorder: highest score first (stable for tied scores).
        if (q && q.trim() !== '') {
            const visible = Array.from(list.children).filter((li) => !li.classList.contains('is-hidden'));
            visible.sort((a, b) => Number(b.dataset.score || 0) - Number(a.dataset.score || 0));
            visible.forEach((li) => list.appendChild(li));
            if (firstVisible) firstVisible = visible[0];
        }
        if (empty) empty.classList.toggle('hidden', visibleCount > 0);
        if (firstVisible) firstVisible.dataset.active = '1';
    }

    function openSelect(root) {
        closeAll(root);
        root.dataset.open = '1';
        const trig = root.querySelector('[data-search-trigger]');
        if (trig) trig.setAttribute('aria-expanded', 'true');
        const input = root.querySelector('[data-search-input]');
        if (input) {
            input.value = '';
            applyFilter(root);
            // Defer so the popover transition has started; otherwise focus
            // happens before the element is interactive on some browsers.
            window.setTimeout(() => input.focus(), 30);
        }
    }

    function closeSelect(root) {
        root.dataset.open = '0';
        const trig = root.querySelector('[data-search-trigger]');
        if (trig) trig.setAttribute('aria-expanded', 'false');
    }

    function pickItem(root, li) {
        if (!li) return;
        const uid = li.dataset.uid || '';
        const name = li.dataset.name || (uid === '' ? '— Unassigned —' : uid);
        const hidden = root.querySelector('[data-search-value]');
        const trigger = root.querySelector('[data-search-trigger]');
        const label = root.querySelector('[data-search-label]');
        if (hidden) hidden.value = uid;
        if (trigger) trigger.classList.toggle('is-empty', uid === '');
        if (label) label.textContent = uid === '' ? '— Unassigned —' : name;
        // Update which row is "current" within this popover so reopening
        // shows the chosen one highlighted.
        Array.from(root.querySelectorAll('[data-search-list] li')).forEach((row) => {
            row.dataset.current = (row.dataset.uid === uid) ? '1' : '0';
        });
        closeSelect(root);
    }

    function visibleItems(root) {
        const list = root.querySelector('[data-search-list]');
        if (!list) return [];
        return Array.from(list.children).filter((li) => !li.classList.contains('is-hidden'));
    }

    function moveActive(root, direction) {
        const items = visibleItems(root);
        if (items.length === 0) return;
        let idx = items.findIndex((li) => li.dataset.active === '1');
        if (idx < 0) idx = direction > 0 ? -1 : items.length;
        let next = (idx + direction + items.length) % items.length;
        items.forEach((li) => li.dataset.active = '0');
        items[next].dataset.active = '1';
        // Make sure it is in view inside the scroll container.
        const list = root.querySelector('[data-search-list]');
        const r = items[next].getBoundingClientRect();
        const lr = list.getBoundingClientRect();
        if (r.top < lr.top) list.scrollTop -= (lr.top - r.top);
        else if (r.bottom > lr.bottom) list.scrollTop += (r.bottom - lr.bottom);
    }

    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('[data-search-trigger]');
        if (trigger) {
            e.preventDefault();
            const root = trigger.closest('[data-search-select]');
            if (!root) return;
            if (root.dataset.open === '1') closeSelect(root);
            else openSelect(root);
            return;
        }

        const li = e.target.closest('[data-search-list] li');
        if (li) {
            const root = li.closest('[data-search-select]');
            if (root) pickItem(root, li);
            return;
        }

        // Click outside any popover → close all.
        if (!e.target.closest('[data-search-select]')) closeAll(null);
    });

    // Single delegated input handler so adding/removing rows doesn't leak.
    document.addEventListener('input', function(e) {
        const input = e.target.closest('[data-search-input]');
        if (!input) return;
        const root = input.closest('[data-search-select]');
        if (root) applyFilter(root);
    });

    document.addEventListener('keydown', function(e) {
        // Escape always closes any open popover, even when focus is elsewhere.
        if (e.key === 'Escape') {
            const open = document.querySelector('[data-search-select][data-open="1"]');
            if (open) {
                e.preventDefault();
                closeSelect(open);
                const trig = open.querySelector('[data-search-trigger]');
                if (trig) trig.focus();
            }
            return;
        }
        const root = e.target.closest('[data-search-select][data-open="1"]');
        if (!root) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(root, 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(root, -1); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            const active = visibleItems(root).find((li) => li.dataset.active === '1') || visibleItems(root)[0];
            if (active) pickItem(root, active);
        }
    });
})();
</script>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>

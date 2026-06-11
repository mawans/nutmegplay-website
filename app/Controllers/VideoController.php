<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\AccountService;
use App\Services\MatchJerseySlotService;
use App\Services\MatchJerseyStatService;
use App\Services\MatchService;
use App\Services\MatchVideoAnalysisService;
use App\Services\NotificationService;
use App\Services\RunpodPodService;
use App\Services\VideoAnalysisService;
use RuntimeException;

class VideoController extends Controller
{
    private const ALLOWED_TYPES = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska'];
    private const MAX_SIZE = 500 * 1024 * 1024; // 500 MB
    private const PUBLIC_AI_WORKER_LABEL = 'AI Worker';

    /** GET /video-upload */
    public function upload(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');

        $matches = new MatchService();
        $uid = Auth::uid();
        $supabaseError = null;

        try {
            $userMatches = Auth::isInstructor()
                ? $matches->list(100)
                : $matches->listByUser($uid, 20);
        } catch (\Throwable $e) {
            $this->reportException('video-upload-list-matches', $e);
            $userMatches = [];
            $supabaseError = 'Match list could not be loaded right now. Please refresh in a minute.';
        }
        $account = Auth::account();
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');
        $requestedMatchId = (int)($_GET['match_id'] ?? 0);
        $focusPanel = trim((string)($_GET['focus'] ?? ''));
        $selectedInstanceKey = $this->requestAiInstanceKey();
        $aiWorkerSummaries = [];
        $activeInstanceKey = null;
        try {
            $aiWorker = $this->buildAiWorkerPayload($selectedInstanceKey, true);
            $activeKey = trim((string)($aiWorker['active_instance_key'] ?? ''));
            $activeInstanceKey = $activeKey !== '' ? $activeKey : null;
            if ($activeInstanceKey !== null) {
                $selectedInstanceKey = $activeInstanceKey;
            } elseif (trim((string)($aiWorker['instance_key'] ?? '')) !== '') {
                $selectedInstanceKey = (string)$aiWorker['instance_key'];
            }
        } catch (\Throwable $e) {
            $this->reportException('runpod-status-page', $e);
            $activeInstanceKey = null;
            $selectedInstanceKey = $selectedInstanceKey !== '' ? $selectedInstanceKey : RunpodPodService::preferredInstanceKey();
            $instances = RunpodPodService::configuredInstances();
            $aiWorker = [
                'configured' => true,
                'instance_key' => $selectedInstanceKey,
                'instance_label' => self::PUBLIC_AI_WORKER_LABEL,
                'state' => 'error',
                'label' => 'Unavailable',
                'message' => 'The AI worker status could not be loaded right now.',
                'supports_stop' => false,
                'ai_ready' => false,
            ];
        }

        $aiWorker['all_workers'] = [];
        $aiWorker['active_instance_key'] = $activeInstanceKey;
        $aiWorker['active_worker'] = in_array(strtolower((string)($aiWorker['state'] ?? '')), ['ready', 'starting', 'stopping'], true)
            ? $aiWorker
            : null;

        $players = [];
        try {
            $accounts = new AccountService();
            $players = array_values(array_filter(
                $accounts->list(null, 300),
                static fn(array $player): bool => in_array(
                    strtolower((string)($player['role'] ?? 'player')),
                    ['player', 'instructor'],
                    true
                )
            ));
        } catch (\Throwable $e) {
            $this->reportException('video-upload-list-accounts', $e);
            $supabaseError = $supabaseError ?? 'Player list could not be loaded right now. Please refresh in a minute.';
        }

        $slotService = new MatchJerseySlotService();
        $jerseyStats = new MatchJerseyStatService();
        $analysisService = new MatchVideoAnalysisService();
        $lineupsByMatch = [];
        $statsByMatch = [];
        $analysisByMatch = [];
        $matchIds = [];

        foreach ($userMatches as $match) {
            $matchId = (int)($match['id'] ?? 0);
            if ($matchId > 0) {
                $matchIds[] = $matchId;
            }
        }

        try {
            $lineupsByMatch = $slotService->groupedByMatchIds($matchIds);
            $statsByMatch = $jerseyStats->groupedByMatchIds($matchIds);
            $analysisByMatch = $analysisService->listByMatchIds($matchIds);
        } catch (\Throwable $e) {
            $this->reportException('video-upload-fanout', $e);
            $supabaseError = $supabaseError ?? 'Lineups / stats could not be loaded right now. Please refresh in a minute.';
        }

        foreach ($userMatches as $match) {
            $matchId = (int)($match['id'] ?? 0);
            if ($matchId <= 0) {
                continue;
            }

            $lineupsByMatch[$matchId] = $lineupsByMatch[$matchId] ?? ['blue' => [], 'red' => []];
            $statsByMatch[$matchId] = $statsByMatch[$matchId] ?? ['blue' => [], 'red' => []];
            $analysisByMatch[$matchId] = $analysisByMatch[$matchId] ?? null;
        }

        if ($requestedMatchId <= 0) {
            $activeAnalyses = array_filter(
                $analysisByMatch,
                static fn(mixed $analysis): bool => is_array($analysis)
                    && in_array(
                        strtolower(trim((string)($analysis['processing_status'] ?? ''))),
                        ['queued', 'processing'],
                        true
                    )
            );
            uasort($activeAnalyses, static function (array $left, array $right): int {
                $leftTime = (string)($left['updated_at'] ?? $left['started_at'] ?? $left['queued_at'] ?? '');
                $rightTime = (string)($right['updated_at'] ?? $right['started_at'] ?? $right['queued_at'] ?? '');
                return strcmp($rightTime, $leftTime);
            });

            $activeMatchId = array_key_first($activeAnalyses);
            if ($activeMatchId !== null) {
                $requestedMatchId = (int)$activeMatchId;
                $focusPanel = $focusPanel !== '' ? $focusPanel : 'progress';
            }
        }

        $storedVideos = $this->listStoredVideos($userMatches, $analysisByMatch);

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/video-upload.php', [
            'account' => $account,
            'userMatches' => $userMatches,
            'players' => $players,
            'lineupsByMatch' => $lineupsByMatch,
            'statsByMatch' => $statsByMatch,
            'analysisByMatch' => $analysisByMatch,
            'aiWorker' => $aiWorker,
            'aiWorkerSummaries' => $aiWorkerSummaries ?? [],
            'selectedInstanceKey' => $selectedInstanceKey,
            'activeInstanceKey' => $activeInstanceKey ?? null,
            'storedVideos' => $storedVideos,
            'error' => $error ?: $supabaseError,
            'success' => $success,
            'requestedMatchId' => $requestedMatchId > 0 ? $requestedMatchId : null,
            'focusPanel' => $focusPanel !== '' ? $focusPanel : null,
        ]);
    }

    /** GET /video-upload/progress */
    public function progress(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');

        $matchId = (int)($_GET['match_id'] ?? 0);
        if ($matchId <= 0) {
            $this->json(['error' => 'Missing match_id.'], 400);
            return;
        }

        $match = (new MatchService())->getById($matchId);
        if (!$match) {
            $this->json(['error' => 'Forbidden.'], 403);
            return;
        }

        $progress = (new VideoAnalysisService())->getLiveProgress($matchId);
        $this->json($progress);
    }

    /** GET /video-upload/ai/status */
    public function aiStatus(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        try {
            $instanceKey = $this->requestAiInstanceKey();
            $this->json($this->buildAiWorkerPayload($instanceKey, true));
        } catch (\Throwable $e) {
            $this->reportException('runpod-status', $e);
            $instanceKey = $this->requestAiInstanceKey();
            $instances = RunpodPodService::configuredInstances();
            $this->json([
                'configured' => true,
                'instance_key' => $instanceKey,
                'instance_label' => self::PUBLIC_AI_WORKER_LABEL,
                'state' => 'error',
                'label' => 'Unavailable',
                'message' => 'The AI worker status could not be loaded right now.',
                'supports_stop' => false,
                'ai_ready' => false,
            ], 503);
        }
    }

    /** POST /video-upload/ai/start */
    public function startAi(): void
    {
        set_time_limit(60);
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json([
                'ok' => false,
                'error' => 'Invalid request. Please refresh and try again.',
            ], 419);
            return;
        }

        try {
            $instanceKey = $this->requestAiInstanceKey();
            (new RunpodPodService($instanceKey))->startPod();
            $status = $this->confirmAiStartState($instanceKey);
            if (strtolower((string)($status['state'] ?? '')) === 'unavailable') {
                $this->json([
                    'ok' => false,
                    'error' => (string)($status['message'] ?? 'No GPU is available for that AI worker right now. Choose another worker or try again later.'),
                    'status' => $status,
                ], 409);
                return;
            }
            $this->json([
                'ok' => true,
                'message' => 'The AI worker is starting now. Wait for Ready before uploading.',
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            $this->reportException('runpod-start', $e);
            $instanceKey = $instanceKey ?? $this->requestAiInstanceKey();
            $rawMessage = trim($e->getMessage());
            $noGpu = stripos($rawMessage, 'not enough free gpus') !== false
                || stripos($rawMessage, 'no gpu is available') !== false;
            $message = $noGpu
                ? 'No GPU is available for that AI worker right now. Choose another worker or try again later.'
                : 'The AI worker could not be started right now.';
            try {
                $status = $this->buildAiWorkerPayload($instanceKey, false);
            } catch (\Throwable) {
                $status = [
                    'configured' => true,
                    'instance_key' => $instanceKey,
                    'state' => $noGpu ? 'unavailable' : 'error',
                    'label' => $noGpu ? 'No GPU Available' : 'Unavailable',
                    'message' => $message,
                    'supports_stop' => false,
                    'ai_ready' => false,
                ];
            }
            if ($noGpu) {
                $status['state'] = 'unavailable';
                $status['label'] = 'No GPU Available';
                $status['message'] = $message;
                $status['supports_stop'] = false;
                $status['ai_ready'] = false;
            }
            $this->json([
                'ok' => false,
                'error' => $message,
                'status' => $status,
            ], $noGpu ? 409 : 500);
        }
    }

    /** POST /video-upload/ai/stop */
    public function stopAi(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json([
                'ok' => false,
                'error' => 'Invalid request. Please refresh and try again.',
            ], 419);
            return;
        }

        try {
            $instanceKey = RunpodPodService::activeInstanceKey(true) ?: $this->requestAiInstanceKey();
            $status = (new RunpodPodService($instanceKey))->stopPod();
            $this->json([
                'ok' => true,
                'message' => !empty($status['dynamic']) && strtolower((string)($status['state'] ?? '')) === 'stopped'
                    ? 'The AI worker has been terminated.'
                    : 'The AI worker is stopping now.',
                'status' => $this->buildAiWorkerPayload($instanceKey, true),
            ]);
        } catch (\Throwable $e) {
            $this->reportException('runpod-stop', $e);
            $message = $this->safeExceptionMessage($e, 'The AI worker could not be stopped right now.', [
                'This Runpod pod cannot be stopped while a network volume is attached.',
            ]);
            $this->json([
                'ok' => false,
                'error' => $message,
            ], 500);
        }
    }

    /** POST /video-upload/ai/cancel */
    public function cancelAi(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json([
                'ok' => false,
                'error' => 'Invalid request. Please refresh and try again.',
            ], 419);
            return;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        if ($matchId <= 0) {
            $this->json([
                'ok' => false,
                'error' => 'Missing match_id.',
            ], 400);
            return;
        }

        $this->requireManageableMatch($matchId);
        $analysisService = new MatchVideoAnalysisService();
        $analysis = $analysisService->getByMatchId($matchId);
        if (!$analysis) {
            $this->json([
                'ok' => false,
                'error' => 'No AI job is active for this match.',
            ], 404);
            return;
        }

        $status = strtolower(trim((string)($analysis['processing_status'] ?? '')));
        if (!in_array($status, ['queued', 'processing'], true)) {
            $this->json([
                'ok' => false,
                'error' => 'Only queued or processing jobs can be stopped.',
            ], 409);
            return;
        }

        $manualStopMessage = 'AI processing was stopped manually.';
        $analysisService->updateByMatchId($matchId, [
            'processing_status' => 'failed',
            'error_message' => $manualStopMessage,
            'updated_at' => gmdate('c'),
        ]);
        (new MatchService())->update($matchId, [
            'video_status' => 'failed',
        ]);
        (new \App\Services\AiProgressService())->failed($matchId, $manualStopMessage);

        $this->json([
            'ok' => true,
            'message' => $manualStopMessage,
        ]);
    }

    /** POST /video-upload/video/delete */
    public function deleteStoredVideo(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json([
                'ok' => false,
                'error' => 'Invalid request. Please refresh and try again.',
            ], 419);
            return;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        $videoUrl = trim((string)($_POST['video_url'] ?? ''));
        if ($matchId <= 0 || $videoUrl === '') {
            $this->json([
                'ok' => false,
                'error' => 'Missing match_id or video_url.',
            ], 400);
            return;
        }

        $this->requireManageableMatch($matchId);
        if ($this->hasActiveAiJob($matchId)) {
            $this->json([
                'ok' => false,
                'error' => 'Stop the active AI job before deleting this video.',
            ], 409);
            return;
        }

        $normalized = $this->normalizeStoredVideoUrl($videoUrl);
        if ($normalized === null) {
            $this->json([
                'ok' => false,
                'error' => 'Invalid stored video URL.',
            ], 400);
            return;
        }

        $filename = basename($normalized);
        if (preg_match('/^match_(\d+)_/i', $filename, $parts) !== 1 || (int)$parts[1] !== $matchId) {
            $this->json([
                'ok' => false,
                'error' => 'You can only delete videos that belong to the selected match.',
            ], 403);
            return;
        }

        $path = BASE_PATH . '/public' . $normalized;
        if (!is_file($path)) {
            $this->json([
                'ok' => true,
                'message' => 'Video already removed.',
            ]);
            return;
        }

        if (!@unlink($path)) {
            $this->json([
                'ok' => false,
                'error' => 'The video file could not be deleted right now.',
            ], 500);
            return;
        }

        $matches = new MatchService();
        $match = $matches->getById($matchId);
        if (is_array($match) && trim((string)($match['video_url'] ?? '')) === $normalized) {
            $matches->update($matchId, [
                'video_url' => null,
                'video_status' => null,
            ]);
            (new MatchVideoAnalysisService())->updateByMatchId($matchId, [
                'video_url' => null,
                'video_source_type' => 'local_upload',
                'processing_status' => 'pending',
                'error_message' => null,
                'updated_at' => gmdate('c'),
            ]);
        }

        $this->json([
            'ok' => true,
            'message' => 'Stored video deleted successfully.',
        ]);
    }

    /** POST /video-upload/ai/migrate */
    public function migrateAi(): void
    {
        set_time_limit(120);
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json([
                'ok' => false,
                'error' => 'Invalid request. Please refresh and try again.',
            ], 419);
            return;
        }

        $sourceKey = RunpodPodService::activeInstanceKey(true) ?: $this->requestAiInstanceKey();

        try {
            $migrated = RunpodPodService::startLeastCostAvailable($sourceKey);
            $targetKey = (string)($migrated['instance_key'] ?? RunpodPodService::preferredInstanceKey());
            $status = $this->confirmAiStartState($targetKey);
            $state = strtolower((string)($status['state'] ?? ''));

            if (strtolower((string)($status['state'] ?? '')) === 'unavailable') {
                $this->json([
                    'ok' => false,
                    'error' => (string)($status['message'] ?? 'No GPU is available for any configured AI worker right now. Please try again in a few minutes.'),
                    'status' => $status,
                ], 409);
                return;
            }

            $message = $state === 'ready'
                ? 'Migration complete. The AI worker migrated successfully to the lowest-cost available GPU.'
                : 'Migration started. The AI worker is moving to the lowest-cost available GPU. This usually takes around 1-3 minutes.';

            $this->json([
                'ok' => true,
                'message' => $message,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            $this->reportException('runpod-migrate', $e);
            $noGpu = $this->isNoGpuMessage($e->getMessage());
            $message = $noGpu
                ? 'No GPU is available for any configured AI worker right now. Please try again in a few minutes.'
                : 'Migration could not be completed right now.';

            $status = [
                'configured' => true,
                'instance_key' => $sourceKey,
                'state' => $noGpu ? 'unavailable' : 'error',
                'label' => $noGpu ? 'No GPU Available' : 'Unavailable',
                'message' => $message,
                'supports_stop' => false,
                'ai_ready' => false,
                'all_workers' => [],
                'active_instance_key' => null,
                'active_worker' => null,
            ];

            $this->json([
                'ok' => false,
                'error' => $message,
                'status' => $status,
            ], $noGpu ? 409 : 500);
        }
    }

    /** POST /video-upload/ai/redeploy — migrate pod to a different physical host machine */
    public function redeployAi(): void
    {
        set_time_limit(60);
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'Invalid request. Please refresh and try again.'], 419);
            return;
        }

        $instanceKey = $this->requestAiInstanceKey();

        try {
            $status = (new RunpodPodService($instanceKey))->redeployToNewMachine();
            $state  = strtolower((string)($status['state'] ?? ''));

            if ($state === 'unavailable') {
                $this->json([
                    'ok'     => false,
                    'error'  => (string)($status['message'] ?? 'No machine is available for this pod right now. Try again in a few minutes.'),
                    'status' => $status,
                ], 409);
                return;
            }

            $this->json([
                'ok'      => true,
                'message' => 'Pod migration started. Runpod is finding a new machine — the worker will start shortly.',
                'status'  => $status,
            ]);
        } catch (\Throwable $e) {
            $this->reportException('runpod-redeploy', $e);
            $this->json([
                'ok'     => false,
                'error'  => 'Could not redeploy the pod: ' . $e->getMessage(),
                'status' => $this->buildAiWorkerPayload($instanceKey, false),
            ], 500);
        }
    }

    /** POST /video-upload/lineup */
    public function saveLineup(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /video-upload');
            exit;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        $this->requireManageableMatch($matchId);

        try {
            $assignments = $this->extractSubmittedAssignments($_POST['slots'] ?? []);
            (new MatchJerseySlotService())->replaceAssignments($matchId, $assignments);
            Auth::flash('success', 'Jersey lineup saved successfully.');
        } catch (\Throwable $e) {
            $this->reportException('video-save-lineup', $e);
            Auth::flash('error', $this->safeExceptionMessage($e, 'The lineup could not be saved right now.', [
                'A player cannot be assigned to more than one jersey slot.',
                'One or more selected players could not be found.',
            ]));
        }

        header('Location: /video-upload');
        exit;
    }

    public function handleUpload(): void
    {
        // Explicit gate at the public entry point so an audit grep sees it
        // immediately. The private handleUploadRequest() also calls
        // requirePermission for defense-in-depth.
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        try {
            $this->handleUploadRequest();
        } catch (\Throwable $e) {
            $this->reportException('video-upload', $e);
            $this->respondUploadError('The upload could not be completed right now. Please try again.', 500);
        }
    }

    /** POST /video-upload */
    private function handleUploadRequest(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if ($this->requestLikelyExceededPostLimit()) {
            $this->respondUploadError('Uploaded file exceeds the current PHP upload limit. The server has been updated; please refresh the page and try again.');
            return;
        }

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->respondUploadError('Invalid request. Please refresh and try again.', 419);
            return;
        }

        $runAi = (string)($_POST['run_ai'] ?? '0') === '1';

        $matchId = (int)($_POST['match_id'] ?? 0);
        $uid = Auth::uid();
        $matches = new MatchService();

        if ($matchId <= 0) {
            $this->respondUploadError('Please select a match.');
            return;
        }

        $match = $this->requireManageableMatch($matchId);
        $analysis = new VideoAnalysisService();
        $analysis->expireStaleAnalysisIfNeeded($matchId);

        $this->ensureNoActiveAiJob($matchId);

        /* Lineup requirement removed per user request */

        if (isset($_POST['slots']) && is_array($_POST['slots'])) {
            try {
                $assignments = $this->extractSubmittedAssignments($_POST['slots']);
                (new MatchJerseySlotService())->replaceAssignments($matchId, $assignments);
            } catch (\Throwable $e) {
                $this->reportException('video-inline-lineup', $e);
                $this->respondUploadError($this->safeExceptionMessage($e, 'The lineup could not be saved right now.', [
                    'A player cannot be assigned to more than one jersey slot.',
                    'One or more selected players could not be found.',
                ]));
                return;
            }
        }

        if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $storedVideoUrl = trim((string)($_POST['stored_video_url'] ?? ''));
            if ($storedVideoUrl !== '') {
                $this->handleExistingStoredVideo($matchId, $uid, $matches, $analysis, $storedVideoUrl, $runAi);
                return;
            }

            $videoUrl = trim($_POST['video_url'] ?? '');
            if ($videoUrl !== '') {
                $parts = parse_url($videoUrl);
                $scheme = strtolower((string)($parts['scheme'] ?? ''));
                if (!filter_var($videoUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                    $this->respondUploadError('Please provide a valid http(s) video URL.');
                    return;
                }

                $matches->update($matchId, [
                    'video_url' => $videoUrl,
                    'video_status' => 'external_url',
                ]);

                try {
                    $m = $matches->getById($matchId);
                    $ns = new NotificationService();
                    foreach (array_filter([(string)($m['challenger_id'] ?? ''), (string)($m['opponent_id'] ?? '')]) as $pid) {
                        $ns->send($pid, NotificationService::TYPE_VIDEO_UPLOADED, 'Video Link Added', 'A video link has been added to your match.', 'videocam', '/video-upload');
                    }
                } catch (\Throwable $e) {
                    // ignore
                }

                $this->respondUploadSuccess('Video URL attached successfully. AI processing only runs for website-hosted or AI-hosted videos.', $matchId);
                return;
            }

            $this->respondUploadError('Please upload a video file or provide a URL.');
            return;
        }

        $file = $_FILES['video_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server maximum upload size.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory configured.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
            ];
            $msg = $errorMessages[$file['error']] ?? 'Unknown upload error.';
            $this->respondUploadError($msg);
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_TYPES, true)) {
            $this->respondUploadError('Invalid file type. Allowed: MP4, MOV, AVI, WebM, MKV.');
            return;
        }

        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            $this->respondUploadError('File too large. Maximum size is 500MB.');
            return;
        }

        // Worker readiness is informational only now — the queued-video cron
        // and processMatchVideo() will defer cleanly while the pod is booting.
        $workerReady = false;
        $workerState = '';
        if ($runAi) {
            try {
                $instanceKey = $this->requestAiInstanceKey();
                $runpod = new RunpodPodService($instanceKey);
                if ($runpod->isConfigured()) {
                    $worker = $runpod->getStatus(false);
                    $workerState = strtolower((string)($worker['state'] ?? ''));
                    $workerReady = $workerState === 'ready';
                } else {
                    $workerReady = true;
                }
            } catch (\Throwable $e) {
                $this->reportException('runpod-status-upload', $e);
                // Treat lookup failures as "boot in progress" — still queue.
                $workerReady = false;
                $workerState = 'unknown';
            }
        }

        $originalFilename = trim((string)($file['name'] ?? ''));
        if ($originalFilename === '') {
            $originalFilename = 'match-' . $matchId . '.mp4';
        }
        $storedVideoUrl = '';
        $backgroundProcessingRequested = $runAi;
        $backgroundProcessingStarted = false;
        try {
            RunpodPodService::rememberMatchInstance($matchId, $instanceKey ?? null);
            if ($runAi) {
                VideoAnalysisService::rememberMatchWebsiteBaseUrl($matchId, $this->currentRequestBaseUrl());
            }
            $storedVideo = $this->storeWebsiteHostedVideo($file, $matchId);
            $videoUrl = trim((string)($storedVideo['video_url'] ?? ''));
            if ($videoUrl === '') {
                throw new RuntimeException('Website storage did not return a video URL.');
            }
            $storedVideoUrl = $videoUrl;

            if ($runAi) {
                $analysis->useRunpodInstance($instanceKey ?? null)->queueAnalysis($matchId, $videoUrl, $uid, 'local_upload');
                if ($backgroundProcessingRequested) {
                    $backgroundProcessingStarted = $this->startQueuedAiBackgroundProcessing($analysis, $matchId, 'video-upload-background');
                }
            } else {
                $matches->update($matchId, [
                    'video_url' => $videoUrl,
                    'video_status' => 'uploaded',
                ]);
            }
        } catch (\Throwable $e) {
            $this->reportException('video-upload-process', $e);
            try {
                $analysis->failAnalysis($matchId, 'AI upload or processing request failed.');
            } catch (\Throwable $inner) {
                // ignore follow-up failures
            }

            if ($runAi && $storedVideoUrl !== '') {
                try {
                    $matches->update($matchId, [
                        'video_url' => $storedVideoUrl,
                        'video_status' => 'uploaded',
                    ]);

                    $this->respondUploadSuccess(
                        'Video uploaded successfully, but AI processing could not be started right now. You can retry AI from the saved website video.',
                        $matchId
                    );
                    return;
                } catch (\Throwable $recoveryError) {
                    $this->reportException('video-upload-recovery', $recoveryError);
                }
            }

            $this->respondUploadError('Video could not be saved or queued for AI processing right now. Please try again later.', 500);
            return;
        }

        // Notifications fan out asynchronously after the response is sent so
        // the upload XHR is not held open by 2+ Supabase writes.
        $deferredMatch = $matches->getById($matchId) ?: $match;

        if ($runAi) {
            if ($backgroundProcessingRequested && $backgroundProcessingStarted) {
                $message = $workerReady
                    ? 'Video uploaded. Analysis is running in the background — watch the live progress card.'
                    : 'Video uploaded. The AI worker is still warming up; analysis will start automatically once it is ready.';
            } elseif ($backgroundProcessingRequested) {
                $message = 'Video uploaded. Analysis will start on the next worker cycle.';
            } else {
                $message = 'Video uploaded. Analysis has been queued.';
            }
        } else {
            $message = 'Video saved. Click Run AI to start analysis.';
        }
        $this->respondUploadSuccess($message, $matchId, [
            'stored_video_url' => $storedVideoUrl,
            'run_ai_started'   => (bool)$runAi,
        ]);

        // Fan out notifications after the response is on the wire so a slow
        // Supabase round-trip can't hold the upload XHR open and trigger the
        // "connection looks slow" stall hint in the browser.
        $this->detachResponse();
        try {
            $ns = new NotificationService();
            foreach (array_filter([
                (string)($deferredMatch['challenger_id'] ?? ''),
                (string)($deferredMatch['opponent_id'] ?? ''),
            ]) as $pid) {
                $ns->send(
                    $pid,
                    NotificationService::TYPE_VIDEO_UPLOADED,
                    'Video Uploaded',
                    'A match video has been uploaded and processed for AI stats.',
                    'videocam',
                    '/video-upload'
                );
            }
        } catch (\Throwable $e) {
            error_log('[video-upload-notify] ' . $e->getMessage());
        }
        return;
    }

    private function storeWebsiteHostedVideo(array $file, int $matchId): array
    {
        $uploadDir = BASE_PATH . '/public/videos';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('The website upload directory could not be created.');
        }

        $originalName = trim((string)($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['mp4', 'mov', 'avi', 'webm', 'mkv'];
        if (!in_array($extension, $allowedExtensions, true)) {
            $extension = 'mp4';
        }

        $storedName = sprintf('match_%d_%s.%s', $matchId, bin2hex(random_bytes(8)), $extension);
        $destination = $uploadDir . '/' . $storedName;
        $tmpName = (string)($file['tmp_name'] ?? '');

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $destination)
            : rename($tmpName, $destination);

        if (!$moved) {
            throw new RuntimeException('The uploaded video could not be moved into website storage.');
        }

        $this->pruneOlderMatchStoredVideos($matchId, $storedName);

        return [
            'stored_path' => $destination,
            'video_url' => '/videos/' . $storedName,
        ];
    }

    private function hasActiveAiJob(int $matchId): bool
    {
        $analysisService = new VideoAnalysisService();
        $analysisService->expireStaleAnalysisIfNeeded($matchId);

        $analysis = (new MatchVideoAnalysisService())->getByMatchId($matchId);
        if (!$analysis) {
            return false;
        }

        $status = strtolower(trim((string)($analysis['processing_status'] ?? '')));
        return in_array($status, ['queued', 'processing'], true);
    }

    private function ensureNoActiveAiJob(int $matchId): void
    {
        if (!$this->hasActiveAiJob($matchId)) {
            return;
        }

        $this->respondUploadError('An AI job is already queued or processing for this match. Stop it first, then upload/select a different video.', 409);
    }

    private function normalizeStoredVideoUrl(string $videoUrl): ?string
    {
        $normalized = trim($videoUrl);
        if (preg_match('#^/videos/[A-Za-z0-9._-]+$#', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function pruneOlderMatchStoredVideos(int $matchId, string $keepFileName): void
    {
        $dir = BASE_PATH . '/public/videos';
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === $keepFileName) {
                continue;
            }

            if (preg_match('/^match_' . preg_quote((string)$matchId, '/') . '_/i', $file) !== 1) {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function requireManageableMatch(int $matchId): array
    {
        $match = (new MatchService())->getById($matchId);
        if (!$match) {
            if ($this->wantsJsonResponse()) {
                $this->json([
                    'ok' => false,
                    'error' => 'That match could not be found.',
                    'redirect_url' => '/video-upload',
                ], 404);
                exit;
            }
            Auth::flash('error', 'That match could not be found.');
            header('Location: /video-upload');
            exit;
        }

        return $match;
    }

    private function requestAiInstanceKey(): string
    {
        $raw = trim((string)($_REQUEST['ai_instance'] ?? ''));
        if ($raw === '') {
            return RunpodPodService::preferredInstanceKey();
        }

        $instances = RunpodPodService::configuredInstances();
        if ($instances === []) {
            return RunpodPodService::preferredInstanceKey();
        }

        if (isset($instances[$raw])) {
            return $raw;
        }

        $rawLower = strtolower($raw);
        foreach (array_keys($instances) as $key) {
            if (strtolower((string)$key) === $rawLower) {
                return (string)$key;
            }
        }

        return RunpodPodService::preferredInstanceKey();
    }

    private function listStoredVideos(array $visibleMatches, array $analysisByMatch = []): array
    {
        $dir = BASE_PATH . '/public/videos';
        if (!is_dir($dir)) {
            return [];
        }

        $visibleMatchIds = [];
        $allowedVideoUrls = [];
        foreach ($visibleMatches as $match) {
            $matchId = (int)($match['id'] ?? 0);
            if ($matchId > 0) {
                $visibleMatchIds[$matchId] = true;
            }

            foreach ([
                (string)($match['video_url'] ?? ''),
                (string)($analysisByMatch[$matchId]['video_url'] ?? ''),
            ] as $videoUrl) {
                $videoUrl = trim($videoUrl);
                if (preg_match('#^/videos/[A-Za-z0-9._-]+$#', $videoUrl) === 1) {
                    $allowedVideoUrls[$videoUrl] = true;
                }
            }
        }

        if ($visibleMatchIds === [] && $allowedVideoUrls === []) {
            return [];
        }

        $videos = [];
        $files = scandir($dir, SCANDIR_SORT_DESCENDING);
        if (!is_array($files)) {
            return [];
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            if (str_starts_with($file, '.')) {
                continue;
            }

            $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($extension, ['mp4', 'mov', 'avi', 'webm', 'mkv'], true)) {
                continue;
            }

            $matchId = null;
            if (preg_match('/^match_(\d+)_/i', $file, $matches) === 1) {
                $matchId = (int)$matches[1];
            }

            $videoUrl = '/videos/' . $file;
            $isVisible = ($matchId !== null && isset($visibleMatchIds[$matchId]))
                || isset($allowedVideoUrls[$videoUrl]);
            if (!$isVisible) {
                continue;
            }

            $videos[] = [
                'name' => $file,
                'url' => $videoUrl,
                'size_bytes' => filesize($path) ?: 0,
                'modified_at' => filemtime($path) ?: 0,
                'match_id' => $matchId,
                'search_text' => strtolower(trim($file . ' ' . ($matchId !== null ? ('match ' . $matchId) : ''))),
            ];
        }

        usort($videos, static fn(array $a, array $b): int => (int)$b['modified_at'] <=> (int)$a['modified_at']);
        return array_slice($videos, 0, 250);
    }

    private function handleExistingStoredVideo(int $matchId, string $uid, MatchService $matches, VideoAnalysisService $analysis, string $storedVideoUrl, bool $runAi): void
    {
        $normalized = trim($storedVideoUrl);
        if (!preg_match('#^/videos/[A-Za-z0-9._-]+$#', $normalized)) {
            $this->respondUploadError('Please choose a valid stored website video.');
            return;
        }

        $path = BASE_PATH . '/public' . $normalized;
        if (!is_file($path)) {
            $this->respondUploadError('That stored video could not be found anymore.');
            return;
        }

        $instanceKey = $this->requestAiInstanceKey();
        $backgroundProcessingRequested = $runAi;
        $backgroundProcessingStarted = false;
        if ($runAi) {
            if (!$this->hasAssignedLineupPlayers($matchId)) {
                $this->respondUploadError('Assign at least one player in the jersey lineup before running AI.', 422);
                return;
            }

            // Critical work: queue the row so the dashboard reflects the new
            // job immediately. Anything that can be deferred (worker kick,
            // notification fan-out) runs *after* we flush the response.
            try {
                VideoAnalysisService::rememberMatchWebsiteBaseUrl($matchId, $this->currentRequestBaseUrl());
                RunpodPodService::rememberMatchInstance($matchId, $instanceKey);
                $analysis->useRunpodInstance($instanceKey)->queueAnalysis($matchId, $normalized, $uid, 'local_upload');
            } catch (\Throwable $e) {
                $this->reportException('video-existing-queue', $e);
                try {
                    $analysis->failAnalysis($matchId, 'AI processing request for a stored website video failed.');
                } catch (\Throwable) {
                }
                $this->respondUploadError('Could not queue the saved clip for analysis right now. Please try again.', 500);
                return;
            }

            $message = 'Saved clip queued for analysis. Watch the live progress card.';
            $this->respondUploadSuccess($message, $matchId);

            // Anything below runs after the user already saw a 200. Use
            // fastcgi_finish_request when available so the connection drops
            // immediately; otherwise we still try (worst case it shows up
            // in the response time but the JSON is already on the wire).
            $this->detachResponse();
            try {
                $this->startQueuedAiBackgroundProcessing($analysis, $matchId, 'video-existing-background');
            } catch (\Throwable $e) {
                error_log('[video-existing-kick] ' . $e->getMessage());
            }
            return;
        }

        $matches->update($matchId, [
            'video_url' => $normalized,
            'video_status' => 'uploaded',
        ]);

        $this->respondUploadSuccess('Stored website video selected successfully. Run AI when you are ready.', $matchId);
    }

    private function extractSubmittedAssignments(mixed $rawSlots): array
    {
        $slots = is_array($rawSlots) ? $rawSlots : [];
        $accounts = new AccountService();
        $players = $accounts->list(null, 500);
        $playersByUid = [];
        foreach ($players as $player) {
            $role = strtolower((string)($player['role'] ?? 'player'));
            if (!in_array($role, ['player', 'instructor'], true)) {
                continue;
            }
            $uid = trim((string)($player['uid'] ?? ''));
            if ($uid !== '') {
                $playersByUid[$uid] = $player;
            }
        }

        $assignments = [];
        $seenPlayers = [];
        foreach (range(1, 10) as $jerseyNumber) {
            $slot = is_array($slots[$jerseyNumber] ?? null) ? $slots[$jerseyNumber] : [];
            $playerUid = trim((string)($slot['player_uid'] ?? ''));
            if ($playerUid === '') {
                $assignments[$jerseyNumber] = [
                    'player_uid' => null,
                    'player_name' => null,
                ];
                continue;
            }

            if (isset($seenPlayers[$playerUid])) {
                throw new RuntimeException('A player cannot be assigned to more than one jersey slot.');
            }

            $player = $playersByUid[$playerUid] ?? null;
            if (!$player) {
                throw new RuntimeException('One or more selected players could not be found.');
            }

            $seenPlayers[$playerUid] = true;
            $assignments[$jerseyNumber] = [
                'player_uid' => $playerUid,
                'player_name' => $this->playerLabel($player),
            ];
        }

        return $assignments;
    }

    private function playerLabel(array $player): string
    {
        $name = trim((string)($player['fname'] ?? '') . ' ' . (string)($player['lname'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string)($player['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        return trim((string)($player['email'] ?? 'Player')) ?: 'Player';
    }

    /**
     * Flush the response to the client so any after-the-fact work (worker
     * kicks, notification fan-out) doesn't keep the user waiting.
     */
    private function detachResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        @ignore_user_abort(true);
        @flush();
    }

    private function startQueuedAiBackgroundProcessing(VideoAnalysisService $analysis, int $matchId, string $context): bool
    {
        try {
            $analysis->startBackgroundProcessing($matchId);
            return true;
        } catch (\Throwable $e) {
            $this->reportException($context, $e);
            return false;
        }
    }

    private function requestLikelyExceededPostLimit(): bool
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength <= 0) {
            return false;
        }

        if (!empty($_POST) || !empty($_FILES)) {
            return false;
        }

        $postMax = $this->iniSizeToBytes((string)ini_get('post_max_size'));
        return $postMax > 0 && $contentLength > $postMax;
    }

    private function hasAssignedLineupPlayers(int $matchId): bool
    {
        if ($matchId <= 0) {
            return false;
        }

        $slots = (new MatchJerseySlotService())->ensureDefaults($matchId);
        foreach ($slots as $slot) {
            $playerUid = trim((string)($slot['player_uid'] ?? ''));
            if ($playerUid !== '') {
                return true;
            }
        }

        return false;
    }

    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        return match ($unit) {
            'g' => (int)round($number * 1024 * 1024 * 1024),
            'm' => (int)round($number * 1024 * 1024),
            'k' => (int)round($number * 1024),
            default => (int)round($number),
        };
    }

    private function respondUploadSuccess(string $message, int $matchId, array $extra = []): void
    {
        $redirectUrl = $this->buildUploadRedirectUrl($matchId, 'progress');
        if ($this->wantsJsonResponse()) {
            $payload = array_merge([
                'ok' => true,
                'message' => $message,
                'match_id' => $matchId,
                'redirect_url' => $redirectUrl,
            ], $extra);
            $this->json($payload);
            return;
        }

        Auth::flash('success', $message);
        header('Location: ' . $redirectUrl);
        exit;
    }

    private function respondUploadError(string $message, int $status = 400): void
    {
        if ($this->wantsJsonResponse()) {
            $this->json([
                'ok' => false,
                'error' => $message,
                'redirect_url' => '/video-upload',
            ], $status);
            return;
        }

        Auth::flash('error', $message);
        header('Location: /video-upload');
        exit;
    }

    private function wantsJsonResponse(): bool
    {
        $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    private function buildUploadRedirectUrl(int $matchId, string $focus = 'progress'): string
    {
        $params = ['match_id' => $matchId];
        if ($focus !== '') {
            $params['focus'] = $focus;
        }

        return '/video-upload?' . http_build_query($params);
    }

    private function currentRequestBaseUrl(): ?string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }

        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = trim((string)($_SERVER['HTTPS'] ?? ''));
        $scheme = strtolower($forwardedProto) === 'https' || ($https !== '' && strtolower($https) !== 'off')
            ? 'https'
            : 'http';

        return $scheme . '://' . $host;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function buildAiWorkerPayload(string $instanceKey, bool $useCache): array
    {
        $resolvedKey = trim($instanceKey) !== '' ? $instanceKey : RunpodPodService::preferredInstanceKey();

        $current = (new RunpodPodService($resolvedKey))->getStatus($useCache);
        if (!is_array($current)) {
            $current = [];
        }
        $current['instance_key'] = (string)($current['instance_key'] ?? $resolvedKey);
        $current['instance_label'] = self::PUBLIC_AI_WORKER_LABEL;

        $currentState = strtolower((string)($current['state'] ?? ''));
        $activeInstanceKey = null;
        $activeWorker = null;

        if (in_array($currentState, ['ready', 'starting', 'stopping'], true)) {
            $activeInstanceKey = (string)$current['instance_key'];
            $activeWorker = $current;
        } else {
            $activeInstanceKey = RunpodPodService::activeInstanceKey($useCache);
            if ($activeInstanceKey !== null) {
                try {
                    $activeWorker = (new RunpodPodService($activeInstanceKey))->getStatus($useCache);
                } catch (\Throwable) {
                    $activeWorker = null;
                }
            }
        }

        $payload = is_array($activeWorker) ? $activeWorker : $current;
        if (!is_array($payload)) {
            $payload = $current;
        }
        $payload['instance_key'] = (string)($payload['instance_key'] ?? ($activeInstanceKey ?: $resolvedKey));
        $payload['instance_label'] = self::PUBLIC_AI_WORKER_LABEL;

        $payload['all_workers'] = [];
        $current['active_instance_key'] = $activeInstanceKey;
        $payload['active_instance_key'] = $activeInstanceKey;
        $payload['active_worker'] = is_array($activeWorker) ? $this->sanitizeAiWorkerPayload($activeWorker) : null;
        return $this->sanitizeAiWorkerPayload($payload);
    }

    private function confirmAiStartState(string $instanceKey): array
    {
        $status = (new RunpodPodService($instanceKey))->getStatus(false);
        $state = strtolower((string)($status['state'] ?? ''));

        if ($state !== 'starting') {
            return $this->decorateSingleStatusPayload($status, $instanceKey);
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            usleep(750000);
            $status = (new RunpodPodService($instanceKey))->getStatus(false);
            $state = strtolower((string)($status['state'] ?? ''));
            if ($state !== 'starting') {
                break;
            }
        }

        return $this->decorateSingleStatusPayload($status, $instanceKey);
    }

    private function decorateSingleStatusPayload(array $status, string $defaultInstanceKey): array
    {
        $status['instance_key'] = (string)($status['instance_key'] ?? $defaultInstanceKey);
        $status['instance_label'] = self::PUBLIC_AI_WORKER_LABEL;

        $state = strtolower((string)($status['state'] ?? ''));
        $isRunning = in_array($state, ['ready', 'starting', 'stopping'], true);

        $status['all_workers'] = [];
        $status['active_instance_key'] = $isRunning ? (string)$status['instance_key'] : null;
        $status['active_worker'] = $isRunning ? $status : null;
        return $this->sanitizeAiWorkerPayload($status);
    }

    private function sanitizeAiWorkerPayload(array $payload): array
    {
        $payload['instance_label'] = self::PUBLIC_AI_WORKER_LABEL;
        unset($payload['gpu_name'], $payload['cost_per_hr'], $payload['manual_hourly_cost']);

        if (isset($payload['active_worker']) && is_array($payload['active_worker'])) {
            $activeWorker = $payload['active_worker'];
            unset($activeWorker['active_worker']);
            $payload['active_worker'] = $this->sanitizeAiWorkerPayload($activeWorker);
        }

        return $payload;
    }

    private function activeInstanceKey(array $summaries): ?string
    {
        foreach ($summaries as $key => $summary) {
            if (in_array(strtolower((string)($summary['state'] ?? '')), ['ready', 'starting', 'stopping'], true)) {
                return (string)$key;
            }
        }

        return null;
    }

    private function isNoGpuMessage(string $message): bool
    {
        $raw = strtolower(trim($message));
        if ($raw === '') {
            return false;
        }

        return str_contains($raw, 'not enough free gpus')
            || str_contains($raw, 'no gpu is available');
    }
}

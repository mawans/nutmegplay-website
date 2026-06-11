<?php
namespace App\Services;

use App\Core\AiSecurity;
use App\Core\Cache;
use RuntimeException;

/**
 * Orchestrates match video AI processing and persistence.
 */
class VideoAnalysisService
{
    private const MATCH_WEBSITE_BASE_TTL = 604800;
    private const DEFAULT_STALE_JOB_TIMEOUT_SECONDS = 7200;

    private MatchService $matchs;
    private MatchStatsService $matchStats;
    private MatchVideoAnalysisService $analysis;
    private AiProgressService $progressState;
    private MatchJerseySlotService $jerseySlots;
    private MatchJerseyStatService $jerseyStats;
    private PlayerProgressService $progress;
    private XpHistoryService $xpHistory;
    private ?string $runpodInstanceKey = null;

    public function __construct()
    {
        $this->matchs = new MatchService();
        $this->matchStats = new MatchStatsService();
        $this->analysis = new MatchVideoAnalysisService();
        $this->progressState = new AiProgressService();
        $this->jerseySlots = new MatchJerseySlotService();
        $this->jerseyStats = new MatchJerseyStatService();
        $this->progress = new PlayerProgressService();
        $this->xpHistory = new XpHistoryService();
    }

    public function useRunpodInstance(?string $instanceKey): self
    {
        $this->runpodInstanceKey = is_string($instanceKey) && trim($instanceKey) !== ''
            ? trim($instanceKey)
            : null;
        return $this;
    }

    public static function rememberMatchWebsiteBaseUrl(int $matchId, ?string $baseUrl): void
    {
        if ($matchId <= 0) {
            return;
        }

        $normalized = self::normalizeWebsiteBaseUrl($baseUrl);
        if ($normalized === null) {
            return;
        }

        Cache::put(self::matchWebsiteBaseCacheKey($matchId), $normalized, self::MATCH_WEBSITE_BASE_TTL);
    }

    public function queueAnalysis(int $matchId, string $videoUrl, string $uploadedBy, string $sourceType = 'upload'): void
    {
        $this->requireMatch($matchId);
        $this->jerseySlots->ensureDefaults($matchId);
        RunpodPodService::touchActivity($this->runpodInstanceKey);

        $this->analysis->upsertByMatchId($matchId, [
            'video_url' => $videoUrl,
            'video_source_type' => $sourceType,
            'processing_status' => 'queued',
            'uploaded_by' => $uploadedBy,
            'error_message' => null,
            'queued_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);

        $this->matchs->update($matchId, [
            'video_url' => $videoUrl,
            'video_status' => 'queued',
        ]);
        $this->progressState->queued($matchId);
    }

    public function storeUploadedVideo(string $tempPath, string $originalFilename, ?string $mimeType, int $matchId): array
    {
        if (!is_file($tempPath)) {
            throw new RuntimeException('Uploaded video temp file was not found.');
        }

        if (!class_exists(\CURLFile::class)) {
            throw new RuntimeException('The PHP cURL extension does not support multipart file uploads.');
        }

        $resolvedName = trim($originalFilename) !== '' ? trim($originalFilename) : basename($tempPath);
        $resolvedMime = trim((string)$mimeType);
        if ($resolvedMime === '') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $resolvedMime = $finfo->file($tempPath) ?: 'application/octet-stream';
        }

        $payload = [
            'match_id' => (string)$matchId,
            'video_file' => new \CURLFile($tempPath, $resolvedMime, $resolvedName),
        ];

        $decoded = $this->postMultipartToFastApi($this->resolveRemoteFastApiStoreUrl(), $payload);
        $videoUrl = trim((string)($decoded['video_url'] ?? ''));
        if ($videoUrl === '') {
            throw new RuntimeException('AI storage response did not include a video URL.');
        }

        $decoded['video_url'] = $videoUrl;
        $decoded['video_id'] = trim((string)($decoded['video_id'] ?? ''));
        $decoded['expires_at'] = trim((string)($decoded['expires_at'] ?? ''));

        return $decoded;
    }

    public function processMatchVideo(int $matchId): array
    {
        if ($this->runpodInstanceKey === null) {
            $this->runpodInstanceKey = RunpodPodService::instanceForMatch($matchId);
        }
        RunpodPodService::touchActivity($this->runpodInstanceKey);

        // Idempotency / pod-availability gate: if the worker is still starting,
        // leave the row at "queued" and let the next cron tick try again.
        // Failing the job here would force the user to manually retry.
        try {
            $podCheck = new RunpodPodService($this->runpodInstanceKey);
            if ($podCheck->isConfigured()) {
                $podStatus = $podCheck->getStatus(true);
                $podState = strtolower((string)($podStatus['state'] ?? ''));
                if (in_array($podState, ['stopped', 'unconfigured'], true)) {
                    $this->progressState->processingStage(
                        $matchId,
                        'queued',
                        5,
                        'Selecting an available GPU for your match...'
                    );
                    $podCheck->startPod(true);
                    $this->progressState->processingStage(
                        $matchId,
                        'queued',
                        7,
                        'GPU selected. The NutmegPlay AI worker is starting...'
                    );
                    return [];
                }

                if (!in_array($podState, ['ready', ''], true)) {
                    $this->progressState->processingStage(
                        $matchId,
                        'queued',
                        7,
                        $podState === 'starting'
                            ? 'AI worker is booting up — your job will start as soon as it is ready.'
                            : 'Waiting for an available AI worker before starting analysis...'
                    );
                    return [];
                }
            }
        } catch (\Throwable $e) {
            // Surface as queued, not failed — a transient Runpod API blip
            // shouldn't drop the user's submission.
            error_log('[ai-pod-check] ' . $e->getMessage());
            $message = str_contains(strtolower($e->getMessage()), 'no gpu')
                ? 'Runpod has no available GPU right now. Your job is queued and will retry automatically.'
                : 'Checking AI worker status...';
            $this->progressState->processingStage(
                $matchId,
                'queued',
                6,
                $message
            );
            return [];
        }

        $match = $this->requireMatch($matchId);
        $slots = $this->jerseySlots->ensureDefaults($matchId);
        $videoUrl = trim((string)($match['video_url'] ?? ''));
        if ($videoUrl === '') {
            throw new RuntimeException('Match does not have a video URL.');
        }

        $analysisRecord = $this->analysis->getByMatchId($matchId) ?? [];
        $sourceType = $this->inferVideoSourceType($videoUrl, $analysisRecord);
        if (!in_array($sourceType, ['local_upload', 'remote_upload', 'external_url'], true)) {
            throw new RuntimeException('AI processing only supports downloadable video URLs.');
        }

        $this->analysis->upsertByMatchId($matchId, [
            'video_url' => $videoUrl,
            'video_source_type' => $sourceType,
            'processing_status' => 'processing',
            'error_message' => null,
            'processed_at' => null,
            'updated_at' => gmdate('c'),
        ]);
        $this->matchs->update($matchId, ['video_status' => 'processing']);
        $this->progressState->processing($matchId);

        // The website-hosted-video path uses a single synchronous /analyze-url
        // call to the AI worker, which holds curl open for the entire run.
        // PHP cannot update progress during that call, so we set a clear
        // expectations message *before* it starts and refresh the DB row's
        // updated_at so the stale-job sweep doesn't mark a real run failed.
        $this->progressState->processingStage(
            $matchId,
            'analyzing',
            30,
            'Watching the match... this usually takes 5–20 minutes for a short clip. The bar will jump to "Done" when the AI finishes.'
        );
        $this->analysis->updateByMatchId($matchId, ['updated_at' => gmdate('c')]);
        $aiOutput = $this->runAiAnalysisForQueuedSource($videoUrl, $sourceType, $matchId, $slots);
        $this->ensureAnalysisNotManuallyStopped($matchId);
        
        // Re-read latest slots in case the user updated the lineup while AI was running
        $latestSlots = $this->jerseySlots->ensureDefaults($matchId);
        $normalized = $this->normalizeAiOutput($match, $latestSlots, $aiOutput);

        $this->progressState->processingStage(
            $matchId,
            'finalizing',
            72,
            'AI analysis is complete. Saving match stats...'
        );
        $this->ensureAnalysisNotManuallyStopped($matchId);
        $this->replacePersistedStats((string)$matchId, $normalized['jersey_stats']);

        $this->analysis->upsertByMatchId($matchId, [
            'video_url' => $videoUrl,
            'video_source_type' => $sourceType,
            'processing_status' => 'processed',
            'ai_output' => $aiOutput,
            'team_stats' => $normalized['team_stats'],
            'player_stats' => $normalized['player_stats'],
            'normalized_stats' => $normalized['jersey_stats'],
            'error_message' => null,
            'processed_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);
        $this->matchs->update($matchId, ['video_status' => 'processed']);
        $this->persistMatchScores($matchId, $match, $normalized['team_scores'] ?? []);
        $this->progressState->processed($matchId);
        RunpodPodService::touchActivity($this->runpodInstanceKey);

        // Update weekly challenge progress for all players in this match
        try {
            $this->updateWeeklyChallengesForMatch($matchId);
        } catch (\Throwable $e) {
            error_log('Failed to update weekly challenges: ' . $e->getMessage());
        }

        return $normalized;
    }

    public function failAnalysis(int $matchId, string $message): void
    {
        $rawMessage = trim($message);
        $normalizedMessage = strtolower($rawMessage);
        $publicMessage = $this->mapFailureToPublicMessage($normalizedMessage, $rawMessage);

        // Always log the underlying error to the host log so an admin running
        // tail on storage/logs/ai/queue.log (or the queue cron log) can see
        // exactly what the worker reported.
        error_log(sprintf('[ai-processing-failed][match:%d] %s', $matchId, $rawMessage));

        // Persist the friendly message for the dashboard AND capture a short
        // sanitized hint of the raw cause inside ai_output.error_detail so
        // the diagnose-ai script (and a future admin view) can surface it
        // without leaking the full stack trace.
        $this->analysis->upsertByMatchId($matchId, [
            'processing_status' => 'failed',
            'error_message' => $publicMessage,
            'ai_output' => $this->buildFailurePayload($rawMessage),
            'updated_at' => gmdate('c'),
        ]);
        $this->matchs->update($matchId, ['video_status' => 'failed']);
        $this->progressState->failed($matchId, $publicMessage);
    }

    private function mapFailureToPublicMessage(string $normalized, string $raw): string
    {
        if (str_contains($normalized, 'stopped manually')) {
            return 'You stopped the analysis manually. Click Try Again whenever you are ready.';
        }
        if (str_contains($normalized, 'http 524') || str_contains($normalized, 'timed out while waiting')) {
            return 'The AI worker took too long to respond. Try a shorter clip, or retry — a fresh pod will be provisioned automatically.';
        }
        if (str_contains($normalized, 'cuda out of memory') || str_contains($normalized, 'out of memory')) {
            return 'The GPU ran out of memory on this clip. Retrying will pick a fresh pod, or try a shorter / lower-resolution video.';
        }
        if (str_contains($normalized, 'no players') || str_contains($normalized, 'no detections')) {
            return 'The AI could not see any players in the video. Check the camera angle, lighting, and that jersey numbers are visible.';
        }
        if (str_contains($normalized, 'connection refused') || str_contains($normalized, 'curl error') || str_contains($normalized, 'could not be reached')) {
            return 'Lost connection to the AI worker mid-analysis. Click Try Again — a fresh pod will be provisioned.';
        }
        if (str_contains($normalized, 'invalid video') || str_contains($normalized, 'cannot open video') || str_contains($normalized, 'unsupported format')) {
            return 'The AI worker could not read the video. Try re-encoding it as MP4 (H.264) and uploading again.';
        }
        if (str_contains($normalized, '404') || str_contains($normalized, 'not found')) {
            return 'The saved clip on the AI worker was lost (the pod likely restarted mid-job). Click Try Again to start over.';
        }
        if (str_contains($normalized, 'permission') || str_contains($normalized, 'denied')) {
            return 'The AI worker could not access the video file. Click Try Again — a fresh pod will be provisioned.';
        }
        if (str_contains($normalized, '503') || str_contains($normalized, '502') || str_contains($normalized, 'bad gateway')) {
            return 'The AI worker briefly went offline. Click Try Again — a fresh pod will be provisioned.';
        }
        return 'AI processing could not complete right now. Please try again, and if it still fails, share match ID with admin.';
    }

    private function buildFailurePayload(string $rawMessage): array
    {
        // Cap length, strip ANSI / control chars, collapse whitespace. Keep
        // the snippet small but useful enough that diagnose-ai.php can show
        // the underlying cause without dragging in stack traces.
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $rawMessage);
        $clean = preg_replace('/\s+/', ' ', (string)$clean);
        $clean = trim((string)$clean);
        if (strlen($clean) > 800) {
            $clean = substr($clean, 0, 800) . '…';
        }
        return [
            'error_detail'  => $clean,
            'failed_at'     => gmdate('c'),
        ];
    }

    public function expireStaleAnalysisIfNeeded(int $matchId): bool
    {
        $record = $this->analysis->getByMatchId($matchId);
        if (!is_array($record)) {
            return false;
        }

        $status = strtolower(trim((string)($record['processing_status'] ?? '')));
        if (!in_array($status, ['queued', 'processing'], true)) {
            return false;
        }

        $thresholdSeconds = $this->staleJobTimeoutSeconds();
        $referenceAt = $this->analysisTimestamp($record['updated_at'] ?? null)
            ?? $this->analysisTimestamp($record['queued_at'] ?? null);
        if ($referenceAt === null) {
            return false;
        }

        if ((time() - $referenceAt) < $thresholdSeconds) {
            return false;
        }

        $this->failAnalysis($matchId, 'AI processing expired because the worker stopped or was terminated. Re-upload the video and run AI again.');
        @unlink($this->progressState->pathForMatch($matchId));
        return true;
    }

    private function ensureAnalysisNotManuallyStopped(int $matchId): void
    {
        $record = $this->analysis->getByMatchId($matchId);
        if (!is_array($record)) {
            return;
        }

        $status = strtolower(trim((string)($record['processing_status'] ?? '')));
        $error = strtolower(trim((string)($record['error_message'] ?? '')));
        if ($status === 'failed' && str_contains($error, 'stopped manually')) {
            throw new RuntimeException('AI processing was stopped manually.');
        }
    }

    /**
     * Best-effort kick that swallows failures. The minute-level cron in
     * scripts/process-queued-videos.php is the source of truth — this just
     * shortens the latency on hosts that allow exec()/proc_open().
     */
    public function kickBackgroundProcessing(int $matchId): bool
    {
        try {
            $this->startBackgroundProcessing($matchId);
            return true;
        } catch (\Throwable $e) {
            error_log(sprintf('[ai-kick][match:%d] %s', $matchId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Re-queue a previously failed (or otherwise idle) analysis without making
     * the user re-upload the video.
     */
    public function retryAnalysis(int $matchId, string $videoUrl, string $uploadedBy): void
    {
        $videoUrl = trim($videoUrl);
        if ($videoUrl === '') {
            throw new RuntimeException('Cannot retry without a video URL.');
        }

        $existing = $this->analysis->getByMatchId($matchId);
        if (is_array($existing)) {
            $status = strtolower(trim((string)($existing['processing_status'] ?? '')));
            if (in_array($status, ['queued', 'processing'], true)) {
                throw new RuntimeException('AI processing is already running for this match.');
            }
        }

        $sourceType = $this->inferVideoSourceType($videoUrl, $existing ?? []);
        if (!in_array($sourceType, ['local_upload', 'remote_upload'], true)) {
            throw new RuntimeException('Retry is only available for website-hosted or AI-hosted videos.');
        }

        $this->queueAnalysis($matchId, $videoUrl, $uploadedBy, $sourceType);
    }

    /**
     * Apply an AI -> PHP webhook payload. Updates progress (and on terminal
     * status, the analysis row) without re-running the analysis pipeline.
     */
    public function applyCallback(int $matchId, array $payload): void
    {
        $status = strtolower(trim((string)($payload['status'] ?? 'processing')));
        $stage = strtolower(trim((string)($payload['stage'] ?? $status)));
        $message = trim((string)($payload['message'] ?? ''));
        $percent = (int)($payload['percent'] ?? 0);

        // Drop callbacks for matches we are not actively tracking — refuses to
        // create phantom rows from late or replayed payloads.
        if ($this->analysis->getByMatchId($matchId) === null) {
            error_log(sprintf('[ai-callback][match:%d] discarding callback for unknown match', $matchId));
            return;
        }

        if (in_array($status, ['queued', 'pending'], true)) {
            $this->progressState->queued($matchId);
            return;
        }

        if (in_array($status, ['processing', 'started'], true)) {
            $this->progressState->processingStage(
                $matchId,
                $stage !== '' ? $stage : 'analyzing',
                max(8, min(95, $percent)),
                $message !== '' ? $message : 'AI worker is analyzing the video...'
            );
            $this->analysis->updateByMatchId($matchId, [
                'processing_status' => 'processing',
                'updated_at' => gmdate('c'),
            ]);
            return;
        }

        if ($status === 'failed') {
            $errorMessage = $message !== '' ? $message : (string)($payload['error'] ?? 'AI analysis failed.');
            $this->failAnalysis($matchId, $errorMessage);
            return;
        }

        if ($status === 'processed' || $status === 'completed') {
            // Completion callbacks are advisory — actual stats persistence
            // happens when processMatchVideo returns. Just nudge UI.
            $this->progressState->processingStage(
                $matchId,
                'finalizing',
                max(90, min(99, $percent ?: 95)),
                $message !== '' ? $message : 'AI analysis is complete. Saving match stats...'
            );
        }
    }

    public function signedVideoUrlForAi(string $videoPath, ?int $matchId = null): ?string
    {
        $resolved = $this->resolveWebsiteHostedVideoPublicUrl($videoPath, $matchId);
        if ($resolved === null) {
            return null;
        }

        if (!preg_match('#/videos/(match_\d+_[A-Za-z0-9]+\.[A-Za-z0-9]+)$#', $resolved, $matches)) {
            return $resolved;
        }

        $base = preg_replace('#/videos/[^/]+$#', '', $resolved);
        if (!is_string($base) || $base === '') {
            return $resolved;
        }

        try {
            $signedPath = AiSecurity::signVideoUrl('/v/' . $matches[1]);
        } catch (\Throwable $e) {
            error_log('[signed-video-url] ' . $e->getMessage());
            return $resolved;
        }

        return rtrim($base, '/') . $signedPath;
    }

    public function callbackUrlForAi(): ?string
    {
        $base = $this->reachableWebsiteBaseUrl();
        if ($base === '') {
            return null;
        }
        if (self::isLoopbackOrPrivateBaseUrl($base)) {
            // AI worker on Runpod cannot reach loopback / RFC1918 hosts. Caller
            // should fall back to the existing polled flow.
            return null;
        }
        return rtrim($base, '/') . '/video-analysis/callback';
    }

    public function startBackgroundProcessing(int $matchId): void
    {
        $script = BASE_PATH . '/scripts/process-match-video.php';
        if (!is_file($script)) {
            throw new RuntimeException('AI processing script entrypoint was not found.');
        }

        $logDir = BASE_PATH . '/storage/logs/ai';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $stdout = $logDir . '/match_' . $matchId . '.stdout.log';
        $stderr = $logDir . '/match_' . $matchId . '.stderr.log';
        $php = $this->resolvePhpBinary();

        if (PHP_OS_FAMILY === 'Windows') {
            if (!$this->isPhpFunctionAvailable('proc_open')) {
                throw new RuntimeException('Background AI processing is not available on this PHP host.');
            }

            $command = $this->buildWindowsBackgroundCommand($php, $script, $matchId, $stdout, $stderr);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start background AI processing.');
            }

            $launcherStdout = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
            $launcherStderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $message = trim($launcherStderr !== '' ? $launcherStderr : $launcherStdout);
                throw new RuntimeException($message !== '' ? $message : 'Could not start background AI processing.');
            }
            return;
        }

        if (!$this->isPhpFunctionAvailable('exec')) {
            throw new RuntimeException('Background AI processing is not available on this PHP host.');
        }

        $command = sprintf(
            'nohup %s %s --match-id=%d > %s 2> %s &',
            escapeshellarg($php),
            escapeshellarg($script),
            $matchId,
            escapeshellarg($stdout),
            escapeshellarg($stderr)
        );
        exec($command);
    }

    private function isPhpFunctionAvailable(string $functionName): bool
    {
        if (!function_exists($functionName)) {
            return false;
        }

        $disabled = array_filter(array_map(
            static fn(string $value): string => trim($value),
            explode(',', (string)ini_get('disable_functions'))
        ));

        return !in_array($functionName, $disabled, true);
    }

    public function getLiveProgress(int $matchId): array
    {
        $this->expireStaleAnalysisIfNeeded($matchId);
        $progress = $this->progressState->read($matchId) ?? [];
        $analysis = $this->analysis->getByMatchId($matchId) ?? [];
        $status = strtolower((string)($analysis['processing_status'] ?? $progress['status'] ?? 'pending'));
        $dbProgress = array_filter([
            'stage' => $analysis['progress_stage'] ?? null,
            'percent' => isset($analysis['progress_percent']) ? (int)$analysis['progress_percent'] : null,
            'message' => $analysis['progress_message'] ?? null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
        $progress = array_merge($dbProgress, $progress);

        if ($status === 'processed') {
            $progress = array_merge($progress, [
                'status' => 'processed',
                'stage' => 'complete',
                'percent' => 100,
                'message' => $progress['message'] ?? 'AI processing complete.',
                'processed_at' => $analysis['processed_at'] ?? null,
            ]);
        } elseif ($status === 'failed') {
            $progress = array_merge($progress, [
                'status' => 'failed',
                'stage' => 'failed',
                'message' => (string)($analysis['error_message'] ?? $progress['message'] ?? 'AI processing failed.'),
                'error_message' => $analysis['error_message'] ?? null,
            ]);
        } elseif ($status === 'processing') {
            $progress = array_merge([
                'status' => 'processing',
                'stage' => 'startup',
                'percent' => 8,
                'message' => 'AI worker is processing the video...',
            ], $progress);
        } elseif ($status === 'queued') {
            $progress = array_merge([
                'status' => 'queued',
                'stage' => 'queued',
                'percent' => 5,
                'message' => 'Video uploaded. Waiting for AI worker to start...',
            ], $progress);
        } else {
            $progress = array_merge([
                'status' => 'pending',
                'stage' => 'idle',
                'percent' => 0,
                'message' => 'No AI job is running for this match.',
            ], $progress);
        }

        $progress['match_id'] = $matchId;
        return $progress;
    }

    private function requireMatch(int $matchId): array
    {
        $match = $this->matchs->getById($matchId);
        if (!$match) {
            throw new RuntimeException('Match not found.');
        }

        return $match;
    }

    private function resolveLocalVideoPath(string $videoUrl): ?string
    {
        if (str_starts_with($videoUrl, '/videos/')) {
            $path = BASE_PATH . '/public' . $videoUrl;
            return is_file($path) ? $path : null;
        }

        if (str_starts_with($videoUrl, '/public/videos/')) {
            $path = BASE_PATH . $videoUrl;
            return is_file($path) ? $path : null;
        }

        return null;
    }

    private function staleJobTimeoutSeconds(): int
    {
        $value = (int)(getenv('NUTMEG_AI_STALE_JOB_TIMEOUT_SECONDS') ?: 0);
        return $value > 0 ? $value : self::DEFAULT_STALE_JOB_TIMEOUT_SECONDS;
    }

    private function analysisTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }

    private function resolvePhpBinary(): string
    {
        $env = trim((string)(getenv('NUTMEG_PHP_BIN') ?: ''));
        if ($env !== '') {
            return $env;
        }

        $binary = (string)(PHP_BINARY ?: '');

        if (PHP_OS_FAMILY !== 'Windows') {
            $basename = strtolower(basename($binary));
            if ($basename === '' || str_starts_with($basename, 'php-fpm')) {
                $cliCandidate = rtrim((string)(PHP_BINDIR ?: ''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';
                if (is_file($cliCandidate) && is_executable($cliCandidate)) {
                    return $cliCandidate;
                }

                if (is_file('/usr/bin/php') && is_executable('/usr/bin/php')) {
                    return '/usr/bin/php';
                }

                return 'php';
            }
        }

        return $binary !== '' ? $binary : 'php';
    }

    private function resolvePythonBinary(): string
    {
        $env = trim((string)(getenv('NUTMEG_AI_PYTHON_BIN') ?: ''));
        if ($env !== '') {
            return $env;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    private function resolveAiAppPath(): string
    {
        $configured = trim((string)(getenv('NUTMEG_AI_APP_PATH') ?: ''));
        $candidates = array_filter([
            $configured,
            dirname(BASE_PATH) . '/NutmegPlay AI/app.py',
            dirname(BASE_PATH) . '/NutmegPlay/app.py',
            dirname(BASE_PATH) . '/NetmegPlay/app.py',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('AI processing script was not found.');
    }

    private function resolveProcessingVideoPath(string $videoUrl): ?string
    {
        $override = trim((string)(getenv('NUTMEG_VIDEO_OVERRIDE_PATH') ?: ''));
        if ($override !== '' && is_file($override)) {
            return $override;
        }

        return $this->resolveLocalVideoPath($videoUrl);
    }

    private function runAiAnalysisForQueuedSource(string $videoUrl, string $sourceType, int $matchId, array $slots): array
    {
        $outputDir = BASE_PATH . '/storage/ai-output';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Could not create AI output directory.');
        }

        $jsonPath = $outputDir . '/match_' . $matchId . '_analysis.json';
        $progressPath = $this->progressState->pathForMatch($matchId);

        if ($sourceType === 'remote_upload') {
            return $this->runAiAnalysisWithStoredRemoteVideo($videoUrl, $jsonPath, $matchId, $slots);
        }

        if ($sourceType === 'local_upload') {
            return $this->runAiAnalysisWithWebsiteHostedVideo($videoUrl, $jsonPath, $matchId, $slots);
        }

        if ($sourceType === 'external_url') {
            return $this->runAiAnalysisWithExternalVideoUrl($videoUrl, $jsonPath, $matchId, $slots);
        }

        throw new RuntimeException('AI processing could not resolve the video source.');
    }

    private function runAiAnalysisWithExternalVideoUrl(string $videoUrl, string $jsonPath, int $matchId, array $slots): array
    {
        $payload = $this->withCallbackPayload([
            'match_id' => $matchId,
            'video_url' => $videoUrl,
            'headless' => true,
            'lineup' => $this->groupSlotsForApi($slots),
        ], $matchId);

        $analysis = $this->runAnalyzeUrlAsync($payload, $matchId, $videoUrl);
        file_put_contents(
            $jsonPath,
            json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        return $analysis;
    }

    private function runAiAnalysisWithWebsiteHostedVideo(string $videoUrl, string $jsonPath, int $matchId, array $slots): array
    {
        $signedVideoUrl = $this->signedVideoUrlForAi($videoUrl, $matchId);
        $publicVideoUrl = $signedVideoUrl ?? $this->resolveWebsiteHostedVideoPublicUrl($videoUrl, $matchId);
        $localVideoPath = $this->resolveLocalVideoPath($videoUrl);

        // Local development often stores uploads behind a loopback URL that the
        // remote RunPod worker cannot reach. In that case, upload the file
        // directly to the worker instead of sending an unreachable website URL.
        if ($publicVideoUrl !== null && !$this->isLoopbackOrPrivateVideoUrl($publicVideoUrl)) {
            $payload = $this->withCallbackPayload([
                'match_id' => $matchId,
                'video_url' => $publicVideoUrl,
                'headless' => true,
                'lineup' => $this->groupSlotsForApi($slots),
            ], $matchId);

            try {
                // Prefer the async endpoint: it returns a job_id quickly so we
                // can poll for progress instead of holding a single curl open
                // for the full 5–20 minute analysis. Falls back to the sync
                // /analyze-url if the worker bundle is older and 404s.
                $analysis = $this->runAnalyzeUrlAsync($payload, $matchId, $videoUrl);
                file_put_contents(
                    $jsonPath,
                    json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
                return $analysis;
            } catch (\Throwable $e) {
                if ($localVideoPath !== null) {
                    error_log('Remote website-hosted video fetch failed, retrying with direct upload: ' . $e->getMessage());
                    return $this->runAiAnalysisWithStoredRemoteVideoUpload($localVideoPath, $jsonPath, $matchId, $slots);
                }

                throw $e;
            }
        }

        if ($localVideoPath !== null) {
            return $this->runAiAnalysisWithStoredRemoteVideoUpload($localVideoPath, $jsonPath, $matchId, $slots);
        }

        if ($publicVideoUrl !== null) {
            throw new RuntimeException('The uploaded website video is only reachable on a private URL. Use a public website URL or upload directly to the AI worker.');
        }

        throw new RuntimeException('Website-hosted video URL could not be resolved for AI processing.');
    }

    private function runAiAnalysisUsingSelectedMode(string $videoPath, string $jsonPath, string $progressPath, int $matchId, array $slots): array
    {
        // Supported deployment path: PHP/shared hosting website + remote AI endpoint.
        $mode = 'remote_fastapi_upload';

        return match ($mode) {
            'local_fastapi' => $this->runAiAnalysisWithLocalFastApi($videoPath, $jsonPath, $progressPath, $matchId, $slots),
            'remote_fastapi_upload' => $this->runAiAnalysisWithRemoteFastApiUpload($videoPath, $jsonPath, $matchId, $slots),
            'local_cli' => $this->runAiAnalysisWithLocalCli($videoPath, $jsonPath, $progressPath),
            default => throw new RuntimeException('Unknown AI mode selected in VideoAnalysisService.'),
        };
    }

    private function runAiAnalysisWithLocalFastApi(string $videoPath, string $jsonPath, string $progressPath, int $matchId, array $slots): array
    {
        $endpoint = $this->resolveLocalFastApiAnalyzeUrl();
        $payload = [
            'match_id' => $matchId,
            'video_path' => $videoPath,
            'output_json_path' => $jsonPath,
            'progress_json_path' => $progressPath,
            'headless' => true,
            'lineup' => $this->groupSlotsForApi($slots),
        ];

        $decoded = $this->postJsonToFastApi($endpoint, $payload);
        return $this->extractAnalysisFromFastApiResponse($decoded);
    }

    private function runAiAnalysisWithRemoteFastApiUpload(string $videoPath, string $jsonPath, int $matchId, array $slots): array
    {
        if (!is_file($videoPath)) {
            throw new RuntimeException('The uploaded video file was not found on the website host.');
        }

        if (!class_exists(\CURLFile::class)) {
            throw new RuntimeException('The PHP cURL extension does not support multipart file uploads.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($videoPath) ?: 'application/octet-stream';
        $endpoint = $this->resolveRemoteFastApiUploadUrl();
        $payload = [
            'match_id' => (string)$matchId,
            'headless' => '1',
            'lineup_json' => json_encode($this->groupSlotsForApi($slots), JSON_UNESCAPED_SLASHES),
            'video_file' => new \CURLFile($videoPath, $mimeType, basename($videoPath)),
        ];

        $decoded = $this->postMultipartToFastApi($endpoint, $payload);
        $analysis = $this->extractAnalysisFromFastApiResponse($decoded);
        file_put_contents(
            $jsonPath,
            json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $analysis;
    }

    private function runAiAnalysisWithStoredRemoteVideo(string $videoUrl, string $jsonPath, int $matchId, array $slots): array
    {
        $videoId = $this->extractRemoteVideoId($videoUrl);
        if ($videoId === null) {
            throw new RuntimeException('Could not determine the stored AI video ID from the video URL.');
        }

        return $this->runAiAnalysisWithStoredRemoteVideoId($videoId, $jsonPath, $matchId, $slots);
    }

    private function runAiAnalysisWithStoredRemoteVideoUpload(string $videoPath, string $jsonPath, int $matchId, array $slots): array
    {
        $stored = $this->storeUploadedVideo($videoPath, basename($videoPath), null, $matchId);
        $videoId = trim((string)($stored['video_id'] ?? ''));
        if ($videoId === '') {
            throw new RuntimeException('AI storage response did not include a stored video ID.');
        }

        return $this->runAiAnalysisWithStoredRemoteVideoId($videoId, $jsonPath, $matchId, $slots);
    }

    private function runAiAnalysisWithStoredRemoteVideoId(string $videoId, string $jsonPath, int $matchId, array $slots): array
    {
        $payload = $this->withCallbackPayload([
            'match_id' => $matchId,
            'video_id' => $videoId,
            'headless' => true,
            'lineup' => $this->groupSlotsForApi($slots),
        ], $matchId);

        try {
            $decoded = $this->postJsonToFastApi($this->resolveRemoteFastApiAnalyzeStoredAsyncUrl(), $payload);
            $jobId = trim((string)($decoded['job_id'] ?? ''));
            if ($jobId === '') {
                throw new RuntimeException('Async AI job response did not include a job ID.');
            }

            $analysis = $this->waitForAsyncStoredAnalysis($jobId, $matchId);
        } catch (\Throwable $e) {
            $message = strtolower(trim($e->getMessage()));
            $asyncUnsupported = str_contains($message, '404')
                || str_contains($message, 'not found')
                || str_contains($message, '405');

            if (!$asyncUnsupported) {
                throw $e;
            }

            $decoded = $this->postJsonToFastApi($this->resolveRemoteFastApiAnalyzeStoredUrl(), $payload);
            $analysis = $this->extractAnalysisFromFastApiResponse($decoded);
        }

        file_put_contents(
            $jsonPath,
            json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $analysis;
    }

    private function waitForAsyncStoredAnalysis(string $jobId, int $matchId): array
    {
        return $this->waitForAsyncJob($jobId, $matchId);
    }

    /**
     * Generic poll loop for any async AI job (analyze-stored-async or
     * analyze-url-async). Updates the on-disk progress JSON AND refreshes
     * match_video_analysis.updated_at on every poll so the stale-job sweep
     * never marks a live analysis as failed.
     */
    private function waitForAsyncJob(string $jobId, int $matchId): array
    {
        $deadline = time() + $this->fastApiTimeoutSeconds();
        $pollCount = 0;

        while (time() <= $deadline) {
            $pollCount++;
            $current = $this->analysis->getByMatchId($matchId);
            $currentStatus = strtolower(trim((string)($current['processing_status'] ?? '')));
            if ($currentStatus === 'failed'
                && str_contains(strtolower((string)($current['error_message'] ?? '')), 'stopped manually')) {
                throw new RuntimeException('AI processing was stopped manually.');
            }
            $payload = $this->getJsonFromFastApi($this->resolveRemoteFastApiJobUrl($jobId));
            $status = strtolower((string)($payload['status'] ?? 'processing'));
            $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
            $message = trim((string)($progress['message'] ?? ''));
            $percent = (int)($progress['percent'] ?? 0);

            if ($status === 'completed') {
                return $this->extractAnalysisFromFastApiResponse($payload);
            }
            if ($status === 'failed') {
                $error = trim((string)($payload['error'] ?? 'AI analysis failed.'));
                throw new RuntimeException($error !== '' ? $error : 'AI analysis failed.');
            }

            $reportedPercent = max(25, min(95, $percent > 0 ? $percent : (25 + min(60, $pollCount * 2))));
            $this->progressState->processingStage(
                $matchId,
                'analyzing',
                $reportedPercent,
                $message !== '' ? $message : 'AI is watching the match...'
            );
            // Keep the DB row's heartbeat alive — the stale-job sweep reads
            // updated_at to decide if it should give up on a row, and the
            // progress JSON file alone does not refresh that timestamp.
            try {
                $this->analysis->updateByMatchId($matchId, ['updated_at' => gmdate('c')]);
            } catch (\Throwable $e) {
                error_log('[ai-poll-heartbeat] ' . $e->getMessage());
            }

            sleep(5);
        }

        throw new RuntimeException('Timed out while waiting for the AI worker to finish analyzing the video.');
    }

    /**
     * POST to /analyze-url-async on the worker; on success poll for the job's
     * progress + final result. If the worker is older and doesn't expose
     * the async endpoint (404 / 405), fall back to the legacy synchronous
     * /analyze-url so users are not blocked on a stale bundle.
     */
    private function runAnalyzeUrlAsync(array $payload, int $matchId, string $videoUrl): array
    {
        $asyncUrl = $this->resolveRemoteFastApiBaseUrl() . '/analyze-url-async';

        try {
            $decoded = $this->postJsonToFastApi($asyncUrl, $payload);
            $jobId = trim((string)($decoded['job_id'] ?? ''));
            if ($jobId === '') {
                throw new RuntimeException('Async AI job response did not include a job ID.');
            }
            return $this->waitForAsyncJob($jobId, $matchId);
        } catch (\Throwable $e) {
            $msg = strtolower(trim($e->getMessage()));
            $missingEndpoint = str_contains($msg, '404')
                || str_contains($msg, '405')
                || str_contains($msg, 'not found')
                || str_contains($msg, 'method not allowed');
            if (!$missingEndpoint) {
                throw $e;
            }
            error_log('[analyze-url-async] worker bundle is older — falling back to sync /analyze-url');
            $decoded = $this->postJsonToFastApi($this->resolveRemoteFastApiAnalyzeUrl(), $payload);
            return $this->extractAnalysisFromFastApiResponse($decoded);
        }
    }

    private function runAiAnalysisWithLocalCli(string $videoPath, string $jsonPath, string $progressPath): array
    {
        $python = $this->resolvePythonBinary();
        $appPath = $this->resolveAiAppPath();
        $command = [$python, $appPath, '--video', $videoPath, '--output-json', $jsonPath, '--progress-json', $progressPath, '--headless'];
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname($appPath));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start AI processing.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException($message !== '' ? $message : 'AI processing failed.');
        }

        if (!is_file($jsonPath)) {
            throw new RuntimeException('AI processing did not produce an output JSON file.');
        }

        $decoded = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI output JSON is invalid.');
        }

        return $decoded;
    }

    private function replacePersistedStats(string $matchId, array $statsRows): void
    {
        $existingXp = $this->xpHistory->getByMatch($matchId);
        $existingMatchStats = $this->matchStats->getByMatch($matchId);
        $userIds = [];

        foreach ([$existingXp, $existingMatchStats] as $set) {
            foreach ($set as $entry) {
                $userId = (string)($entry['user_id'] ?? '');
                if ($userId !== '') {
                    $userIds[$userId] = true;
                }
            }
        }

        foreach ($statsRows as $row) {
            $playerUid = trim((string)($row['player_uid'] ?? ''));
            if ($playerUid !== '') {
                $userIds[$playerUid] = true;
            }
        }

        $this->jerseyStats->deleteByMatch($matchId);
        $this->matchStats->deleteByMatch($matchId);
        $this->xpHistory->deleteByMatch($matchId);

        $this->progressState->processingStage(
            (int)$matchId,
            'finalizing',
            76,
            'Clearing older saved stats for this match...'
        );

        $jerseyRowsToInsert = [];
        $candidateRowsByUid = [];

        foreach ($statsRows as $row) {
            $jerseyRowsToInsert[] = $row;

            $playerUid = trim((string)($row['player_uid'] ?? ''));
            if ($playerUid === '' || !$this->isValidUuid($playerUid)) {
                continue;
            }
            $candidateRowsByUid[$playerUid] = $row;
        }

        // ⚠ Validate every player UID against accounts before we touch the
        // user-keyed tables. The lineup can reference a player who has since
        // been deleted; without this check the FK on match_stats.user_id
        // (or xp_history.user_id) raises 409 and we throw away stats that
        // already analyzed cleanly. Filter out the ghosts and keep going.
        $existingUids = $this->filterExistingPlayerUids(array_keys($candidateRowsByUid));
        $matchStatsRowsToInsert = [];
        $xpRowsToInsert = [];

        foreach ($candidateRowsByUid as $uid => $row) {
            if (!isset($existingUids[$uid])) {
                error_log(sprintf(
                    '[ai-stats][match:%s] dropping rows for player_uid %s — no longer in accounts',
                    $matchId,
                    $uid
                ));
                continue;
            }

            $userStats = $this->buildUserStatsFromJerseyRow($row);
            $matchStatsRowsToInsert[] = $userStats;

            $xp = $this->calculateXp($userStats);
            if ($xp > 0) {
                $xpRowsToInsert[] = [
                    'user_id'   => $uid,
                    'match_id'  => (int)$matchId,
                    'xp_gained' => $xp,
                    'reason'    => 'AI video analysis',
                ];
            }
        }

        if ($jerseyRowsToInsert !== []) {
            $this->progressState->processingStage(
                (int)$matchId,
                'finalizing',
                82,
                'Saving jersey-based AI stats...'
            );

            if ($this->jerseyStats->createMany($jerseyRowsToInsert) === []) {
                throw new RuntimeException('Failed to persist jersey-based match stats.');
            }
        }

        // Per-user inserts go ONE AT A TIME. The FK on match_stats.user_id
        // targets auth.users (Supabase Auth) — different from accounts.uid
        // — so a UID that exists in accounts can still fail FK validation
        // here. With a batch insert, one bad row kills all 10. With per-row
        // we keep every row that does pass.
        if ($matchStatsRowsToInsert !== []) {
            $this->progressState->processingStage(
                (int)$matchId,
                'finalizing',
                88,
                'Saving player match stats...'
            );
            $saved = 0;
            foreach ($matchStatsRowsToInsert as $row) {
                try {
                    $r = $this->matchStats->createMany([$row]);
                    if (is_array($r) && $r !== []) {
                        $saved++;
                    } else {
                        error_log(sprintf(
                            '[ai-stats][match:%s] match_stats row skipped (likely missing in auth.users): user_id=%s',
                            $matchId,
                            (string)($row['user_id'] ?? '?')
                        ));
                    }
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '[ai-stats][match:%s] match_stats row exception user_id=%s: %s',
                        $matchId,
                        (string)($row['user_id'] ?? '?'),
                        $e->getMessage()
                    ));
                }
            }
            error_log(sprintf('[ai-stats][match:%s] match_stats saved %d/%d', $matchId, $saved, count($matchStatsRowsToInsert)));
        }

        if ($xpRowsToInsert !== []) {
            $this->progressState->processingStage(
                (int)$matchId,
                'finalizing',
                93,
                'Saving XP history from AI stats...'
            );
            $saved = 0;
            foreach ($xpRowsToInsert as $row) {
                try {
                    $r = $this->xpHistory->createMany([$row]);
                    if (is_array($r) && $r !== []) $saved++;
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '[ai-stats][match:%s] xp_history row exception user_id=%s: %s',
                        $matchId,
                        (string)($row['user_id'] ?? '?'),
                        $e->getMessage()
                    ));
                }
            }
            error_log(sprintf('[ai-stats][match:%s] xp_history saved %d/%d', $matchId, $saved, count($xpRowsToInsert)));
        }

        $userIdList = array_values(array_keys($userIds));
        $totalUsers = count($userIdList);
        foreach ($userIdList as $index => $userId) {
            $percent = 96;
            if ($totalUsers > 0) {
                $percent += (int)floor((($index + 1) / $totalUsers) * 3);
            }
            $this->progressState->processingStage(
                (int)$matchId,
                'finalizing',
                $percent,
                sprintf('Refreshing player ratings (%d/%d)...', $index + 1, max(1, $totalUsers))
            );
            $this->syncProgressFromHistory($userId);
        }
    }

    private function buildUserStatsFromJerseyRow(array $row): array
    {
        return [
            'match_id' => $row['match_id'],
            'user_id' => $row['player_uid'],
            'goals' => (int)($row['goals'] ?? 0),
            'assists' => (int)($row['assists'] ?? 0),
            'distance_meters' => (int)($row['distance_meters'] ?? 0),
            'sprints' => (int)($row['sprints'] ?? 0),
            'successful_passes' => (int)($row['successful_passes'] ?? 0),
            'passes_attempted' => (int)($row['passes_attempted'] ?? 0),
            'successful_dribbles' => (int)($row['successful_dribbles'] ?? 0),
            'dribbles_attempted' => (int)($row['dribbles_attempted'] ?? 0),
            'interceptions' => (int)($row['interceptions'] ?? 0),
            'duels_won' => (int)($row['duels_won'] ?? 0),
            'minutes_played' => (int)($row['minutes_played'] ?? 0),
            'clean_sheet' => !empty($row['clean_sheet']),
            'match_winning_goal' => !empty($row['match_winning_goal']),
            'team_win_streak' => (int)($row['team_win_streak'] ?? 0),
            'result' => (string)($row['result'] ?? 'draw'),
        ];
    }

    private function syncProgressFromHistory(string $userId): void
    {
        try {
            $totalXp = $this->xpHistory->totalXp($userId);
            $this->progress->syncFromTotalXp($userId, $totalXp);
        } catch (\Throwable $e) {
            // player_progress.user_id has the same auth.users FK problem —
            // log and move on instead of nuking the run.
            error_log(sprintf('[ai-stats] progress sync skipped for %s: %s', $userId, $e->getMessage()));
        }
    }

    private function calculateXp(array $stats): int
    {
        $xp = 25;
        $xp += ((int)($stats['goals'] ?? 0)) * 20;
        $xp += ((int)($stats['assists'] ?? 0)) * 10;
        $xp += ((int)($stats['successful_passes'] ?? 0));
        $xp += ((int)($stats['successful_dribbles'] ?? 0)) * 3;
        $xp += ((int)($stats['interceptions'] ?? 0)) * 5;
        $xp += ((int)($stats['duels_won'] ?? 0)) * 3;

        if (!empty($stats['clean_sheet'])) {
            $xp += 15;
        }
        if (!empty($stats['match_winning_goal'])) {
            $xp += 25;
        }

        $result = strtolower((string)($stats['result'] ?? 'draw'));
        if ($result === 'win') {
            $xp += 30;
        } elseif ($result === 'draw') {
            $xp += 10;
        }

        return $xp;
    }

    private function normalizeAiOutput(array $match, array $slots, array $aiOutput): array
    {
        $teamStats = is_array($aiOutput['team_statistics'] ?? null) ? $aiOutput['team_statistics'] : [];
        $playerStats = is_array($aiOutput['player_statistics'] ?? null) ? $aiOutput['player_statistics'] : [];
        $teamScores = $this->extractTeamScores($teamStats, $playerStats);
        $minutesPlayed = $this->extractMinutesPlayed($aiOutput);
        $aggregatedBySlot = $this->aggregatePlayerStatsBySlot($playerStats, $slots);

        $normalizedRows = [];
        foreach ($slots as $slot) {
            $team = strtolower((string)($slot['team_color'] ?? ''));
            $jerseyNumber = (int)($slot['jersey_number'] ?? 0);
            if (!$this->isValidTeamNumber($team, $jerseyNumber)) {
                continue;
            }

            $aggregated = $aggregatedBySlot[$team][$jerseyNumber] ?? $this->emptyAggregatedPlayerStats();
            $successfulPasses = (int)($aggregated['passes'] ?? 0);
            $passesAttempted = (int)($aggregated['passes_attempted'] ?? 0);
            if ($passesAttempted > 0) {
                $passesAttempted = max($passesAttempted, $successfulPasses);
            }

            $successfulDribbles = (int)($aggregated['dribbles'] ?? 0);
            $dribblesAttempted = (int)($aggregated['dribbles_attempted'] ?? 0);
            if ($dribblesAttempted > 0) {
                $dribblesAttempted = max($dribblesAttempted, $successfulDribbles);
            }

            $normalizedRows[] = [
                'match_id' => (string)($match['id'] ?? ''),
                'team_color' => $team,
                'jersey_number' => $jerseyNumber,
                'player_uid' => $this->nullableString($slot['player_uid'] ?? null),
                'player_name' => $this->nullableString($slot['player_name'] ?? null),
                'goals' => (int)($aggregated['goals'] ?? 0),
                'assists' => (int)($aggregated['assists'] ?? 0),
                'distance_meters' => (int)round((float)($aggregated['distance_m'] ?? 0)),
                'sprints' => (int)($aggregated['speed'] ?? 0),
                'successful_passes' => $successfulPasses,
                'passes_attempted' => $passesAttempted,
                'successful_dribbles' => $successfulDribbles,
                'dribbles_attempted' => $dribblesAttempted,
                'interceptions' => (int)($aggregated['defense'] ?? 0),
                'duels_won' => (int)($aggregated['physical'] ?? 0),
                'shots' => (int)($aggregated['shots'] ?? 0),
                'top_speed_kmh' => round((float)($aggregated['top_speed_kmh'] ?? 0), 2),
                'minutes_played' => $minutesPlayed,
                'clean_sheet' => false,
                'match_winning_goal' => false,
                'team_win_streak' => 0,
                'result' => $this->inferResultForTeamColor($match, $team),
                'raw_player_key' => $aggregated['raw_player_key'] ?? null,
                'updated_at' => gmdate('c'),
            ];
        }

        return [
            'team_stats' => $teamStats,
            'player_stats' => $playerStats,
            'team_scores' => $teamScores,
            'jersey_stats' => $normalizedRows,
            'lineup' => $this->groupSlotsForApi($slots),
        ];
    }

    private function extractTeamScores(array $teamStats, array $playerStats): array
    {
        $scores = ['blue' => null, 'red' => null];
        $hasExplicitGoalSignal = false;

        foreach (['blue', 'red'] as $team) {
            $teamRow = is_array($teamStats[$team] ?? null) ? $teamStats[$team] : [];
            $teamScore = $this->extractIntByCandidateKeys($teamRow, ['goals', 'score', 'goals_scored', 'team_score']);
            if ($teamScore !== null) {
                $scores[$team] = $teamScore;
                $hasExplicitGoalSignal = true;
            }
        }

        foreach ($playerStats as $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $team = strtolower(trim((string)($stats['team'] ?? '')));
            if (!isset($scores[$team])) {
                continue;
            }

            if (array_key_exists('goals', $stats)) {
                $hasExplicitGoalSignal = true;
                if ($scores[$team] === null) {
                    $scores[$team] = 0;
                }
                $scores[$team] += (int)($stats['goals'] ?? 0);
            }
        }

        if (!$hasExplicitGoalSignal) {
            return [];
        }

        if ($scores['blue'] === null || $scores['red'] === null) {
            return [];
        }

        return [
            'blue' => (int)$scores['blue'],
            'red' => (int)$scores['red'],
        ];
    }

    private function persistMatchScores(int $matchId, array $match, array $teamScores): void
    {
        if (!isset($teamScores['blue'], $teamScores['red'])) {
            return;
        }

        $blueScore = (int)$teamScores['blue'];
        $redScore = (int)$teamScores['red'];
        $challengerColor = strtolower(trim((string)(getenv('NUTMEG_CHALLENGER_TEAM_COLOR') ?: 'blue')));
        $challengerScore = $challengerColor === 'red' ? $redScore : $blueScore;
        $opponentScore = $challengerColor === 'red' ? $blueScore : $redScore;

        $challengerName = trim((string)($match['challanger'] ?? ''));
        $opponentName = trim((string)($match['opponent'] ?? ''));
        $result = 'draw';
        if ($challengerScore > $opponentScore) {
            $result = $challengerName !== '' ? $challengerName : 'challenger';
        } elseif ($opponentScore > $challengerScore) {
            $result = $opponentName !== '' ? $opponentName : 'opponent';
        }

        $payloads = [
            [
                'score_home' => $challengerScore,
                'score_away' => $opponentScore,
                'score_challanger' => $challengerScore,
                'score_opponent' => $opponentScore,
                'result' => $result,
            ],
            [
                'score_home' => $challengerScore,
                'score_away' => $opponentScore,
                'result' => $result,
            ],
            [
                'score_challanger' => $challengerScore,
                'score_opponent' => $opponentScore,
                'result' => $result,
            ],
        ];

        foreach ($payloads as $payload) {
            $updated = $this->matchs->update($matchId, $payload);
            if ($updated !== null) {
                return;
            }
        }
    }

    private function extractIntByCandidateKeys(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (int)$row[$key];
            }
        }

        return null;
    }

    private function aggregatePlayerStatsBySlot(array $playerStats, array $slots): array
    {
        $grouped = ['blue' => [], 'red' => []];
        $unassigned = ['blue' => [], 'red' => []];

        foreach ($playerStats as $rawKey => $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $team = strtolower(trim((string)($stats['team'] ?? '')));
            if (!isset($grouped[$team])) {
                continue;
            }

            $aggregate = $this->buildAggregatedPlayerStats($rawKey, $stats);
            $jerseyNumber = (int)($stats['jersey_number'] ?? 0);
            if (!$this->isValidTeamNumber($team, $jerseyNumber)) {
                $unassigned[$team][] = $aggregate;
                continue;
            }

            if (!isset($grouped[$team][$jerseyNumber])) {
                $grouped[$team][$jerseyNumber] = $this->emptyAggregatedPlayerStats();
                $grouped[$team][$jerseyNumber]['raw_keys'] = [];
            }

            $grouped[$team][$jerseyNumber] = $this->mergeAggregatedPlayerStats($grouped[$team][$jerseyNumber], $aggregate);
        }

        $slotsByTeam = ['blue' => [], 'red' => []];
        foreach ($slots as $slot) {
            $team = strtolower((string)($slot['team_color'] ?? ''));
            $jerseyNumber = (int)($slot['jersey_number'] ?? 0);
            if ($this->isValidTeamNumber($team, $jerseyNumber)) {
                $slotsByTeam[$team][] = $jerseyNumber;
            }
        }

        foreach ($slotsByTeam as $team => $numbers) {
            sort($numbers);
            $missingNumbers = array_values(array_filter(
                $numbers,
                fn (int $number): bool => !isset($grouped[$team][$number])
            ));

            if ($missingNumbers === [] || $unassigned[$team] === []) {
                continue;
            }

            usort($unassigned[$team], function (array $a, array $b): int {
                $scoreA = $this->aggregatedPlayerScore($a);
                $scoreB = $this->aggregatedPlayerScore($b);
                if (abs($scoreA - $scoreB) > 0.0001) {
                    return $scoreB <=> $scoreA;
                }

                return strcmp((string)($a['raw_player_key'] ?? ''), (string)($b['raw_player_key'] ?? ''));
            });

            foreach ($missingNumbers as $index => $jerseyNumber) {
                if (!isset($unassigned[$team][$index])) {
                    break;
                }
                $grouped[$team][$jerseyNumber] = $unassigned[$team][$index];
            }
        }

        foreach ($grouped as $team => $rows) {
            foreach ($rows as $number => $stats) {
                $grouped[$team][$number]['raw_player_key'] = implode(',', $stats['raw_keys']);
                unset($grouped[$team][$number]['raw_keys']);
            }
        }

        return $grouped;
    }

    private function extractMinutesPlayed(array $aiOutput): int
    {
        $videoInfo = is_array($aiOutput['video_info'] ?? null) ? $aiOutput['video_info'] : [];
        $frames = (float)($videoInfo['total_frames'] ?? 0);
        $fps = (float)($videoInfo['frame_rate'] ?? 0);
        if ($frames <= 0 || $fps <= 0) {
            return 0;
        }

        return (int)max(1, round(($frames / $fps) / 60));
    }

    private function inferResultForTeamColor(array $match, string $teamColor): string
    {
        $challengerColor = strtolower(trim((string)(getenv('NUTMEG_CHALLENGER_TEAM_COLOR') ?: 'blue')));
        $side = $teamColor === $challengerColor ? 'challenger' : 'opponent';
        return $this->inferResultForSide($match, $side);
    }

    private function inferResultForSide(array $match, string $side): string
    {
        $raw = strtolower(trim((string)($match['result'] ?? '')));
        if ($raw === '') {
            return 'draw';
        }

        if (in_array($raw, ['win', 'draw', 'loss'], true)) {
            return $raw;
        }

        $challengerName = strtolower(trim((string)($match['challanger'] ?? '')));
        $opponentName = strtolower(trim((string)($match['opponent'] ?? '')));

        if ($challengerName !== '' && str_contains($raw, $challengerName)) {
            return $side === 'challenger' ? 'win' : 'loss';
        }
        if ($opponentName !== '' && str_contains($raw, $opponentName)) {
            return $side === 'opponent' ? 'win' : 'loss';
        }

        return 'draw';
    }

    private function resolveLocalFastApiAnalyzeUrl(): string
    {
        $base = 'http://127.0.0.1:8001';
        return rtrim($base, '/') . '/analyze';
    }

    private function resolveRemoteFastApiAnalyzeUrl(): string
    {
        return $this->resolveRemoteFastApiBaseUrl() . '/analyze-url';
    }

    private function resolveRemoteFastApiBaseUrl(): string
    {
        $base = $this->configuredRemoteFastApiBaseUrl();
        if ($base === null) {
            throw new RuntimeException('Configure the Runpod pod proxy or set NUTMEG_AI_FASTAPI_URL to the Runpod proxy URL before using the remote FastAPI modes.');
        }

        return $base;
    }

    private function configuredRemoteFastApiBaseUrl(): ?string
    {
        $runpod = $this->resolveRunpodBackedFastApiBaseUrl();
        if (($runpod['configured'] ?? false) === true) {
            return $runpod['base'] ?? null;
        }

        $base = trim((string)(getenv('NUTMEG_AI_FASTAPI_URL') ?: ''));
        if ($base === '') {
            return null;
        }

        $normalized = preg_replace('#/(analyze|analyze-url|analyze-upload|upload-video|analyze-stored|health)/?$#', '', rtrim($base, '/'));
        $normalized = is_string($normalized) ? $normalized : rtrim($base, '/');
        $normalized = rtrim($normalized, '/');
        if (!preg_match('#^https://[A-Za-z0-9.-]+\.proxy\.runpod\.net(?:/.*)?$#i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function resolveRunpodBackedFastApiBaseUrl(): array
    {
        try {
            $runpod = new RunpodPodService($this->runpodInstanceKey);
            if (!$runpod->isConfigured()) {
                return [
                    'configured' => false,
                    'base' => null,
                ];
            }

            $resolved = $runpod->resolveAiBaseUrl();
            return [
                'configured' => true,
                'base' => is_string($resolved) && trim($resolved) !== ''
                    ? rtrim(trim($resolved), '/')
                    : null,
            ];
        } catch (\Throwable) {
            return [
                'configured' => false,
                'base' => null,
            ];
        }
    }

    private function resolveRemoteFastApiStoreUrl(): string
    {
        return $this->resolveRemoteFastApiBaseUrl() . '/upload-video';
    }

    private function resolveRemoteFastApiUploadUrl(): string
    {
        return $this->resolveRemoteFastApiBaseUrl() . '/analyze-upload';
    }

    private function resolveRemoteFastApiAnalyzeStoredUrl(): string
    {
        return $this->resolveRemoteFastApiBaseUrl() . '/analyze-stored';
    }

    private function resolveRemoteFastApiAnalyzeStoredAsyncUrl(): string
    {
        return $this->resolveRemoteFastApiBaseUrl() . '/analyze-stored-async';
    }

    private function resolveRemoteFastApiJobUrl(string $jobId): string
    {
        return $this->resolveRemoteFastApiBaseUrl() . '/jobs/' . rawurlencode($jobId);
    }

    private function inferVideoSourceType(string $videoUrl, array $analysisRecord = []): string
    {
        $explicit = strtolower(trim((string)($analysisRecord['video_source_type'] ?? '')));
        if (in_array($explicit, ['remote_upload', 'local_upload', 'external_url'], true)) {
            return $explicit;
        }

        if ($this->isWebsiteHostedVideoUrl($videoUrl)) {
            return 'local_upload';
        }

        if ($this->isAiHostedVideoUrl($videoUrl)) {
            return 'remote_upload';
        }

        return 'external_url';
    }

    private function isAiHostedVideoUrl(string $videoUrl): bool
    {
        $base = $this->configuredRemoteFastApiBaseUrl();
        if ($base === null) {
            return false;
        }

        $normalizedVideoUrl = rtrim(trim($videoUrl), '/');
        return $normalizedVideoUrl !== '' && str_starts_with($normalizedVideoUrl, $base . '/videos/');
    }

    private function isWebsiteHostedVideoUrl(string $videoUrl): bool
    {
        $normalized = rtrim(trim($videoUrl), '/');
        if ($normalized === '') {
            return false;
        }

        if (str_starts_with($normalized, '/videos/') || str_starts_with($normalized, '/public/videos/')) {
            return true;
        }

        $base = trim((string)(getenv('NUTMEG_WEBSITE_URL') ?: ''));
        if ($base === '') {
            return false;
        }

        $normalizedBase = rtrim($base, '/');
        return str_starts_with($normalized, $normalizedBase . '/videos/')
            || str_starts_with($normalized, $normalizedBase . '/public/videos/');
    }

    private function resolveWebsiteHostedVideoPublicUrl(string $videoUrl, ?int $matchId = null): ?string
    {
        $normalized = trim($videoUrl);
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            $path = (string)(parse_url($normalized, PHP_URL_PATH) ?: '');
            if (str_starts_with($path, '/videos/') || str_starts_with($path, '/public/videos/')) {
                return $normalized;
            }

            return $this->isWebsiteHostedVideoUrl($normalized) ? $normalized : null;
        }

        if (!$this->isWebsiteHostedVideoUrl($normalized)) {
            return null;
        }

        $base = $this->reachableWebsiteBaseUrl($matchId);
        if ($base === '') {
            throw new RuntimeException('Set NUTMEG_WEBSITE_URL before using website-hosted AI video processing.');
        }

        return $base . '/' . ltrim($normalized, '/');
    }

    private function reachableWebsiteBaseUrl(?int $matchId = null): string
    {
        $candidates = [];

        if ($matchId !== null && $matchId > 0) {
            $cached = Cache::get(self::matchWebsiteBaseCacheKey($matchId));
            if (is_string($cached) && trim($cached) !== '') {
                $candidates[] = trim($cached);
            }
        }

        $requestBase = self::normalizeWebsiteBaseUrl($this->currentRequestBaseUrl());
        if ($requestBase !== null) {
            $candidates[] = $requestBase;
        }

        $configured = self::normalizeWebsiteBaseUrl((string)(getenv('NUTMEG_WEBSITE_URL') ?: ''));
        if ($configured !== null) {
            $candidates[] = $configured;
        }

        foreach ($candidates as $candidate) {
            if (!self::isLoopbackOrPrivateBaseUrl($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
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

    private static function matchWebsiteBaseCacheKey(int $matchId): string
    {
        return 'nutmeg:match:' . $matchId . ':website-base-url';
    }

    private static function normalizeWebsiteBaseUrl(?string $baseUrl): ?string
    {
        $normalized = trim((string)$baseUrl);
        if ($normalized === '') {
            return null;
        }

        if (!str_starts_with($normalized, 'http://') && !str_starts_with($normalized, 'https://')) {
            return null;
        }

        return rtrim($normalized, '/');
    }

    private static function isLoopbackOrPrivateBaseUrl(string $baseUrl): bool
    {
        $host = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function isLoopbackOrPrivateVideoUrl(string $videoUrl): bool
    {
        $host = strtolower((string)(parse_url($videoUrl, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return true;
        }

        $base = 'https://' . $host;
        if (str_starts_with($videoUrl, 'http://')) {
            $base = 'http://' . $host;
        }

        return self::isLoopbackOrPrivateBaseUrl($base);
    }

    private function extractRemoteVideoId(string $videoUrl): ?string
    {
        $path = parse_url($videoUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        if (!preg_match('#/videos/([^/]+)$#', rtrim($path, '/'), $matches)) {
            return null;
        }

        $videoId = trim((string)($matches[1] ?? ''));
        return $videoId === '' ? null : $videoId;
    }

    private function postJsonToFastApi(string $endpoint, array $payload): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->fastApiCurlOptions($endpoint, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->fastApiTimeoutSeconds(),
        ]));

        return $this->decodeFastApiResponse($ch);
    }

    private function postMultipartToFastApi(string $endpoint, array $payload): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->fastApiCurlOptions($endpoint, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->fastApiTimeoutSeconds(),
        ]));

        return $this->decodeFastApiResponse($ch);
    }

    private function getJsonFromFastApi(string $endpoint): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->fastApiCurlOptions($endpoint, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => min(30, $this->fastApiTimeoutSeconds()),
        ]));

        return $this->decodeFastApiResponse($ch);
    }

    private function fastApiCurlOptions(string $endpoint, array $options): array
    {
        if ($this->isHttpsEndpoint($endpoint) && !$this->shouldVerifyFastApiTls()) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        return $options;
    }

    private function shouldVerifyFastApiTls(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_AI_FASTAPI_VERIFY_SSL') ?: '1')));
        return !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }

    private function isHttpsEndpoint(string $endpoint): bool
    {
        return str_starts_with(strtolower($endpoint), 'https://');
    }

    private function decodeFastApiResponse(\CurlHandle $ch): array
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: '';
        $curlInfo = curl_getinfo($ch);
        // PHP 8.5 deprecates curl_close(); the handle is released automatically.
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($error !== '') {
            error_log(sprintf(
                '[post-fastapi] curl error when requesting %s: %s; http_code=%d; info=%s; response_snippet=%s',
                $effectiveUrl,
                $error,
                $httpCode,
                json_encode($curlInfo),
                is_string($response) ? substr($response, 0, 1000) : ''
            ));

            throw new RuntimeException($error);
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($httpCode >= 400) {
            $message = is_array($decoded) ? ($decoded['detail'] ?? $decoded['message'] ?? null) : null;
            error_log(sprintf(
                '[post-fastapi] FastAPI returned HTTP %d for %s; parsed_message=%s; response_snippet=%s; info=%s',
                $httpCode,
                $effectiveUrl,
                is_string($message) ? $message : json_encode($message),
                is_string($response) ? substr($response, 0, 2000) : '',
                json_encode($curlInfo)
            ));
            if ((!is_string($message) || $message === '') && is_string($response) && trim($response) !== '') {
                $message = trim($response);
            }
            if ($httpCode === 524) {
                $message = 'RunPod proxy timed out while waiting for AI analysis (HTTP 524).';
            }
            throw new RuntimeException(is_string($message) && $message !== '' ? $message : 'FastAPI analysis request failed.');
        }

        if (!is_array($decoded)) {
            error_log(sprintf(
                '[post-fastapi] FastAPI returned invalid JSON for %s; http_code=%d; response_snippet=%s; info=%s',
                $effectiveUrl,
                $httpCode,
                is_string($response) ? substr($response, 0, 2000) : '',
                json_encode($curlInfo)
            ));

            throw new RuntimeException('FastAPI returned an invalid JSON response.');
        }

        return $decoded;
    }

    private function extractAnalysisFromFastApiResponse(array $decoded): array
    {
        $analysis = $decoded['analysis'] ?? $decoded;
        if (!is_array($analysis)) {
            throw new RuntimeException('FastAPI response does not contain analysis data.');
        }

        return $analysis;
    }

    private function buildWindowsBackgroundCommand(string $php, string $script, int $matchId, string $stdout, string $stderr): string
    {
        $ps = sprintf(
            "Start-Process -FilePath %s -ArgumentList @(%s,%s) -WorkingDirectory %s -RedirectStandardOutput %s -RedirectStandardError %s -WindowStyle Hidden",
            $this->powershellQuote($php),
            $this->powershellQuote($script),
            $this->powershellQuote('--match-id=' . $matchId),
            $this->powershellQuote(BASE_PATH),
            $this->powershellQuote($stdout),
            $this->powershellQuote($stderr)
        );

        return 'powershell -NoProfile -Command ' . escapeshellarg($ps);
    }

    private function powershellQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function fastApiTimeoutSeconds(): int
    {
        $value = (int)(getenv('NUTMEG_AI_FASTAPI_TIMEOUT') ?: 0);
        return $value > 0 ? $value : 1800;
    }

    /**
     * Attach callback metadata so the AI worker can push progress updates back
     * to PHP. If we cannot expose a publicly reachable callback URL (typical
     * during local dev) the analyzer falls back to PHP-side polling cleanly.
     */
    private function withCallbackPayload(array $payload, int $matchId): array
    {
        $callbackUrl = $this->callbackUrlForAi();
        if ($callbackUrl === null) {
            return $payload;
        }

        try {
            $secret = AiSecurity::callbackSecret();
        } catch (\Throwable $e) {
            return $payload;
        }

        $payload['callback_url'] = $callbackUrl;
        $payload['callback_secret'] = $secret;
        $payload['callback_match_id'] = $matchId;
        return $payload;
    }

    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /**
     * Given a list of candidate player UIDs (already UUID-shaped), returns
     * the subset that actually exist in the accounts table, keyed by UID.
     * Used as a guard before inserting into user-keyed tables so a
     * deleted-but-still-assigned player can never trigger a FK violation
     * that loses the entire AI run.
     */
    private function filterExistingPlayerUids(array $uids): array
    {
        $clean = array_values(array_unique(array_filter($uids, fn(string $u): bool => $u !== '')));
        if ($clean === []) {
            return [];
        }

        try {
            $db = \App\Core\SupabaseClient::getInstance();
            $rows = $db->from('accounts')
                ->select('uid')
                ->filter('uid', 'in', '(' . implode(',', $clean) . ')')
                ->execute();
            if (!is_array($rows) || !empty($rows['error'])) {
                error_log('[ai-stats] filterExistingPlayerUids: lookup failed, falling back to "all valid"');
                return array_fill_keys($clean, true);
            }
            $found = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $u = (string)($row['uid'] ?? '');
                    if ($u !== '') $found[$u] = true;
                }
            }
            return $found;
        } catch (\Throwable $e) {
            error_log('[ai-stats] filterExistingPlayerUids exception: ' . $e->getMessage());
            // Fail-open: treat all UIDs as valid so a Supabase blip can't
            // turn a successful analysis into a failed one.
            return array_fill_keys($clean, true);
        }
    }

    private function isValidTeamNumber(string $team, int $jerseyNumber): bool
    {
        if ($team === 'blue') {
            return $jerseyNumber >= 1 && $jerseyNumber <= 5;
        }

        if ($team === 'red') {
            return $jerseyNumber >= 6 && $jerseyNumber <= 10;
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function emptyAggregatedPlayerStats(): array
    {
        return [
            'goals' => 0,
            'assists' => 0,
            'passes' => 0,
            'passes_attempted' => 0,
            'defense' => 0,
            'speed' => 0,
            'physical' => 0,
            'dribbles' => 0,
            'dribbles_attempted' => 0,
            'shots' => 0,
            'distance_m' => 0.0,
            'top_speed_kmh' => 0.0,
            'raw_player_key' => null,
        ];
    }

    private function buildAggregatedPlayerStats(string|int $rawKey, array $stats): array
    {
        $aggregate = $this->emptyAggregatedPlayerStats();
        $aggregate['goals'] = (int)($stats['goals'] ?? 0);
        $aggregate['assists'] = (int)($stats['assists'] ?? 0);
        $aggregate['passes'] = (int)($stats['passes'] ?? 0);
        $aggregate['passes_attempted'] = (int)($stats['passes_attempted'] ?? 0);
        $aggregate['defense'] = (int)($stats['defense'] ?? 0);
        $aggregate['speed'] = (int)($stats['speed'] ?? 0);
        $aggregate['physical'] = (int)($stats['physical'] ?? 0);
        $aggregate['dribbles'] = (int)($stats['dribbles'] ?? 0);
        $aggregate['dribbles_attempted'] = (int)($stats['dribbles_attempted'] ?? 0);
        $aggregate['shots'] = (int)($stats['shots'] ?? 0);
        $aggregate['distance_m'] = (float)($stats['distance_m'] ?? 0);
        $aggregate['top_speed_kmh'] = (float)($stats['top_speed_kmh'] ?? 0);
        $aggregate['raw_player_key'] = (string)$rawKey;
        $aggregate['raw_keys'] = [(string)$rawKey];

        return $aggregate;
    }

    private function mergeAggregatedPlayerStats(array $current, array $incoming): array
    {
        $current['goals'] += (int)($incoming['goals'] ?? 0);
        $current['assists'] += (int)($incoming['assists'] ?? 0);
        $current['passes'] += (int)($incoming['passes'] ?? 0);
        $current['passes_attempted'] += (int)($incoming['passes_attempted'] ?? 0);
        $current['defense'] += (int)($incoming['defense'] ?? 0);
        $current['speed'] += (int)($incoming['speed'] ?? 0);
        $current['physical'] += (int)($incoming['physical'] ?? 0);
        $current['dribbles'] += (int)($incoming['dribbles'] ?? 0);
        $current['dribbles_attempted'] += (int)($incoming['dribbles_attempted'] ?? 0);
        $current['shots'] += (int)($incoming['shots'] ?? 0);
        $current['distance_m'] += (float)($incoming['distance_m'] ?? 0);
        $current['top_speed_kmh'] = max(
            (float)($current['top_speed_kmh'] ?? 0),
            (float)($incoming['top_speed_kmh'] ?? 0)
        );

        $currentKeys = is_array($current['raw_keys'] ?? null) ? $current['raw_keys'] : [];
        $incomingKeys = is_array($incoming['raw_keys'] ?? null) ? $incoming['raw_keys'] : [];
        $current['raw_keys'] = array_values(array_unique(array_merge($currentKeys, $incomingKeys)));
        $current['raw_player_key'] = implode(',', $current['raw_keys']);

        return $current;
    }

    private function aggregatedPlayerScore(array $stats): float
    {
        return ((float)($stats['distance_m'] ?? 0) * 10)
            + ((float)($stats['top_speed_kmh'] ?? 0) * 2)
            + ((float)($stats['passes'] ?? 0) * 5)
            + ((float)($stats['dribbles'] ?? 0) * 7)
            + ((float)($stats['shots'] ?? 0) * 9)
            + ((float)($stats['defense'] ?? 0) * 6)
            + ((float)($stats['physical'] ?? 0) * 1.5)
            + ((float)($stats['speed'] ?? 0) * 8);
    }

    private function groupSlotsForApi(array $slots): array
    {
        $grouped = ['blue' => [], 'red' => []];
        foreach ($slots as $slot) {
            $team = strtolower((string)($slot['team_color'] ?? ''));
            if (!isset($grouped[$team])) {
                continue;
            }

            $grouped[$team][] = [
                'jersey_number' => (int)($slot['jersey_number'] ?? 0),
                'player_uid' => $this->nullableString($slot['player_uid'] ?? null),
                'player_name' => $this->nullableString($slot['player_name'] ?? null),
            ];
        }

        return $grouped;
    }

    /**
     * Update weekly challenge progress for all players who participated in this match
     */
    private function updateWeeklyChallengesForMatch(int $matchId): void
    {
        try {
            // Get all players who played in this match
            $jerseyStats = $this->jerseyStats->getByMatchId($matchId);
            $challenges = new WeeklyChallengesService();

            foreach ($jerseyStats as $stat) {
                $userId = $stat['player_uid'] ?? null;
                if (!$userId) {
                    continue;
                }

                // Update weekly challenge progress for this player
                $challenges->updatePlayerProgress($userId, $matchId);
            }
        } catch (\Throwable $e) {
            error_log('Error updating weekly challenges: ' . $e->getMessage());
        }
    }
}

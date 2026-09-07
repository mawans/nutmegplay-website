<?php
namespace App\Services;

use App\Core\AiSecurity;
use App\Core\Cache;
use App\Core\SupabaseClient;
use App\Exceptions\AiProcessingDeferredException;
use RuntimeException;

/**
 * Orchestrates match video AI processing and persistence.
 */
class VideoAnalysisService
{
    private const MATCH_WEBSITE_BASE_TTL = 604800;
    private const ANALYSIS_CLIP_REFERENCE_TTL = 604800;
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

    public function queueAnalysis(
        int $matchId,
        string $videoUrl,
        string $uploadedBy,
        string $sourceType = 'upload',
        array $clipMetadata = []
    ): void {
        $this->requireMatch($matchId);
        $videoUrl = trim($videoUrl);
        if ($videoUrl === '') {
            throw new RuntimeException('The selected video clip reference was not found.');
        }
        if (!in_array($sourceType, ['local_upload', 'remote_upload', 'external_url', 'b2_storage'], true)) {
            $sourceType = $this->inferVideoSourceType($videoUrl);
        }

        $storageLock = VideoStorageProtectionService::acquireSharedLock();
        try {
            if ($sourceType === 'b2_storage') {
                $this->assertB2SourceAvailable($videoUrl);
            }

            $existing = $this->analysis->getByMatchId($matchId);
            if (
                is_array($existing)
                && strtolower(trim((string)($existing['processing_status'] ?? ''))) === 'processed'
                && trim((string)($existing['video_url'] ?? '')) === trim($videoUrl)
            ) {
                $this->logAiProcessing('queue:already_processed', ['match_id' => $matchId]);
                return;
            }
            $this->jerseySlots->ensureDefaults($matchId);
            RunpodPodService::touchActivity($this->runpodInstanceKey);
            $this->logAiProcessing('queue', [
                'match_id' => $matchId,
                'uploaded_by' => $uploadedBy,
                'source_type' => $sourceType,
                'video_host' => parse_url($videoUrl, PHP_URL_HOST),
            ]);

            $clipReference = [
                'video_url' => $videoUrl,
                'video_source_type' => $sourceType,
                'uploaded_by' => $uploadedBy,
                'queued_at' => gmdate('c'),
            ];
            foreach (['recording_id', 'file_name', 'folder_name', 'folder_path', 'video_part_number', 'video_group_key', 'camera_number', 'storage_version', 'ai_video_url'] as $key) {
                $value = trim((string)($clipMetadata[$key] ?? ''));
                if ($value !== '') {
                    $clipReference[$key] = $value;
                }
            }
            if (
                empty($clipReference['ai_video_url'])
                && $sourceType === 'b2_storage'
                && B2VideoStorageService::isConfigured()
            ) {
                try {
                    $optimizedUrl = (new B2VideoStorageService())->aiOptimizedUrlForVideoUrl($videoUrl);
                    if (is_string($optimizedUrl) && trim($optimizedUrl) !== '') {
                        $clipReference['ai_video_url'] = trim($optimizedUrl);
                    }
                } catch (\Throwable $e) {
                    $this->logAiProcessing('queue:ai_optimized_lookup_failed', [
                        'match_id' => $matchId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
            $analysisAttemptId = bin2hex(random_bytes(16));
            $existingOutput = [
                'selected_clip' => $clipReference,
                'analysis_attempt_id' => $analysisAttemptId,
                'queued_at' => gmdate('c'),
            ];

            $queued = $this->analysis->upsertByMatchId($matchId, [
                'video_url' => $videoUrl,
                'video_source_type' => $sourceType,
                'processing_status' => 'queued',
                'uploaded_by' => $uploadedBy,
                'ai_output' => $existingOutput,
                'error_message' => null,
                'queued_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ]);
            if (!is_array($queued)) {
                throw new RuntimeException('The AI queue could not lock the saved video in the database.');
            }
            $this->rememberAnalysisClipReference($matchId, $clipReference);

            $this->matchs->update($matchId, [
                'video_url' => $videoUrl,
                'video_status' => 'queued',
            ]);
            $this->progressState->queued($matchId);
        } finally {
            VideoStorageProtectionService::releaseLock($storageLock);
        }
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
        $this->logAiProcessing('process:start', ['match_id' => $matchId]);
        $existing = $this->analysis->getByMatchId($matchId);
        $existingStatus = strtolower(trim((string)($existing['processing_status'] ?? '')));
        if ($existingStatus === 'processed') {
            $this->logAiProcessing('process:already_processed', ['match_id' => $matchId]);
            return [
                'jersey_stats' => is_array($existing['normalized_stats'] ?? null)
                    ? $existing['normalized_stats']
                    : [],
                'player_stats' => is_array($existing['player_stats'] ?? null)
                    ? $existing['player_stats']
                    : [],
                'team_stats' => is_array($existing['team_stats'] ?? null)
                    ? $existing['team_stats']
                    : [],
            ];
        }
        if ($existingStatus === 'processing') {
            try {
                if ($this->finalizeCompletedRunpodJob($matchId)) {
                    $completed = $this->analysis->getByMatchId($matchId) ?? [];
                    if (strtolower(trim((string)($completed['processing_status'] ?? ''))) === 'processed') {
                        return [
                            'jersey_stats' => is_array($completed['normalized_stats'] ?? null)
                                ? $completed['normalized_stats']
                                : [],
                            'player_stats' => is_array($completed['player_stats'] ?? null)
                                ? $completed['player_stats']
                                : [],
                            'team_stats' => is_array($completed['team_stats'] ?? null)
                                ? $completed['team_stats']
                                : [],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logAiProcessing('process:active_job_check_failed', [
                    'match_id' => $matchId,
                    'message' => $e->getMessage(),
                ]);
            }
            throw new AiProcessingDeferredException('AI processing is already running for this match.');
        }
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
                    $this->logAiProcessing('process:pod_starting', [
                        'match_id' => $matchId,
                        'pod_state' => $podState,
                    ]);
                    $this->progressState->queuedStage(
                        $matchId,
                        'queued',
                        5,
                        'Selecting an available GPU for your match...'
                    );
                    $podCheck->startPod(true);
                    $this->progressState->queuedStage(
                        $matchId,
                        'queued',
                        7,
                        'GPU selected. The FiveStats AI worker is starting...'
                    );
                    throw new AiProcessingDeferredException('AI worker is starting; queued job will retry on the next cron run.');
                }

                if (!in_array($podState, ['ready', ''], true)) {
                    $this->logAiProcessing('process:pod_waiting', [
                        'match_id' => $matchId,
                        'pod_state' => $podState,
                    ]);
                    $this->progressState->queuedStage(
                        $matchId,
                        'queued',
                        7,
                        $podState === 'starting'
                            ? 'AI worker is booting up — your job will start as soon as it is ready.'
                            : 'Waiting for an available AI worker before starting analysis...'
                    );
                    throw new AiProcessingDeferredException('AI worker is not ready yet; queued job will retry on the next cron run.');
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof AiProcessingDeferredException) {
                throw $e;
            }

            // Surface as queued, not failed — a transient Runpod API blip
            // shouldn't drop the user's submission.
            $this->logAiProcessing('process:pod_check_exception', [
                'match_id' => $matchId,
                'message' => $e->getMessage(),
            ]);
            $message = str_contains(strtolower($e->getMessage()), 'no gpu')
                ? 'Runpod has no available GPU right now. Your job is queued and will retry automatically.'
                : 'Checking AI worker status...';
            if (str_contains(strtolower($e->getMessage()), 'no gpu')) {
                throw new RuntimeException('Runpod has no available GPU right now. Your credit has been refunded. Please start AI again later.');
            }
            $this->progressState->queuedStage(
                $matchId,
                'queued',
                6,
                $message
            );
            throw new AiProcessingDeferredException($message);
        }

        $match = $this->requireMatch($matchId);
        $slots = $this->jerseySlots->ensureDefaults($matchId);
        $analysisRecord = $this->analysis->getByMatchId($matchId) ?? [];
        $clipReference = $this->analysisClipReference($matchId, $analysisRecord);
        $videoUrl = trim((string)($clipReference['video_url'] ?? ''));
        if ($videoUrl === '') {
            $videoUrl = trim((string)($analysisRecord['video_url'] ?? ''));
        }
        if ($videoUrl === '') {
            $videoUrl = trim((string)($match['video_url'] ?? ''));
        }
        if ($videoUrl === '') {
            throw new RuntimeException('The selected video clip reference was not found.');
        }
        $analysisVideoUrl = trim((string)($clipReference['ai_video_url'] ?? ''));
        if ($analysisVideoUrl === '') {
            $analysisVideoUrl = $videoUrl;
        }

        $sourceType = trim((string)($clipReference['video_source_type'] ?? ''));
        if ($sourceType === '') {
            $sourceType = $this->inferVideoSourceType($videoUrl, $analysisRecord);
        }
        if (!in_array($sourceType, ['local_upload', 'remote_upload', 'external_url', 'b2_storage'], true)) {
            throw new RuntimeException('AI processing only supports downloadable video URLs.');
        }
        if ($sourceType === 'b2_storage') {
            $analysisVideoUrl = $this->resolveAvailableB2AnalysisUrl($videoUrl, $analysisVideoUrl);
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
        $this->rememberAnalysisClipReference($matchId, [
            'video_url' => $videoUrl,
            'video_source_type' => $sourceType,
            'started_at' => gmdate('c'),
        ]);
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
        $matchClips = $this->analysisClipsForMatch($matchId, $videoUrl, $sourceType);
        if (count($matchClips) > 1) {
            $this->progressState->processingStage(
                $matchId,
                'starting_parallel_jobs',
                22,
                'Starting AI analysis for all match recordings...'
            );
        }
        $aiOutput = count($matchClips) > 1
            ? $this->runAiAnalysisForMatchClips($matchClips, $matchId, $slots)
            : $this->runAiAnalysisForQueuedSource($analysisVideoUrl, $sourceType, $matchId, $slots);
        return $this->persistCompletedAnalysis($matchId, $match, $videoUrl, $sourceType, $aiOutput);
    }

    private function analysisClipsForMatch(int $matchId, string $selectedVideoUrl, string $sourceType): array
    {
        $selectedVideoUrl = trim($selectedVideoUrl);
        $clips = [];

        if ($sourceType === 'b2_storage' && B2VideoStorageService::isConfigured()) {
            try {
                $storage = new B2VideoStorageService();
                $prefixes = ['matches/' . $matchId . '/'];
                $selectedObjectName = $storage->objectNameFromUrl($selectedVideoUrl);
                if (is_string($selectedObjectName) && trim($selectedObjectName) !== '') {
                    $selectedDirectory = trim((string)pathinfo($selectedObjectName, PATHINFO_DIRNAME), '.');
                    if ($selectedDirectory !== '') {
                        $prefixes[] = rtrim($selectedDirectory, '/') . '/';
                    }
                }

                foreach (array_values(array_unique($prefixes)) as $prefix) {
                    foreach ($storage->listVideos(1000, $prefix) as $object) {
                    $url = trim((string)($object['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $modifiedAt = (int)($object['modified_at'] ?? 0);
                    if ($modifiedAt > 0 && !str_contains($url, '?')) {
                        $url .= '?v=' . $modifiedAt;
                    }
                    $clip = [
                        'video_url' => $url,
                        'video_source_type' => 'b2_storage',
                        'recording_id' => (string)($object['file_id'] ?? ''),
                        'file_name' => (string)($object['file_name'] ?? $object['name'] ?? basename((string)($object['object_name'] ?? ''))),
                        'object_name' => (string)($object['object_name'] ?? ''),
                    ];
                    foreach (['video_part_number', 'video_group_key', 'camera_number'] as $key) {
                        if (isset($object[$key]) && trim((string)$object[$key]) !== '') {
                            $clip[$key] = $object[$key];
                        }
                    }
                    try {
                        $optimizedUrl = $storage->aiOptimizedUrlForVideoUrl($url);
                        if (is_string($optimizedUrl) && trim($optimizedUrl) !== '') {
                            $clip['ai_video_url'] = trim($optimizedUrl);
                        }
                    } catch (\Throwable) {
                    }
                    $clips[$this->clipKey($url)] = $clip;
                }
                }
            } catch (\Throwable $e) {
                $this->logAiProcessing('clips:list_failed', [
                    'match_id' => $matchId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($selectedVideoUrl !== '' && !isset($clips[$this->clipKey($selectedVideoUrl)])) {
            $clips[$this->clipKey($selectedVideoUrl)] = [
                'video_url' => $selectedVideoUrl,
                'video_source_type' => $sourceType,
                'recording_id' => '',
                'file_name' => basename(parse_url($selectedVideoUrl, PHP_URL_PATH) ?: 'selected-video'),
                'object_name' => '',
            ];
        }

        $clipList = array_values($clips);
        usort($clipList, static function (array $left, array $right): int {
            return strcmp(
                (string)($left['object_name'] ?: $left['file_name'] ?: $left['video_url']),
                (string)($right['object_name'] ?: $right['file_name'] ?: $right['video_url'])
            );
        });

        return $clipList;
    }

    private function clipKey(string $videoUrl): string
    {
        $parts = parse_url(trim($videoUrl));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = rawurldecode((string)($parts['path'] ?? trim($videoUrl)));
        return $host . '/' . ltrim($path, '/');
    }

    private function analysisClipReference(int $matchId, array $analysisRecord): array
    {
        $fromRecord = [];
        $aiOutput = $analysisRecord['ai_output'] ?? null;
        if (is_array($aiOutput) && is_array($aiOutput['selected_clip'] ?? null)) {
            $fromRecord = $aiOutput['selected_clip'];
        }

        $fromCache = Cache::get($this->analysisClipReferenceCacheKey($matchId), []);
        if (is_array($fromCache) && trim((string)($fromCache['video_url'] ?? '')) !== '') {
            return $fromCache;
        }

        return is_array($fromRecord) ? $fromRecord : [];
    }

    private function rememberAnalysisClipReference(int $matchId, array $reference): void
    {
        $videoUrl = trim((string)($reference['video_url'] ?? ''));
        if ($matchId <= 0 || $videoUrl === '') {
            return;
        }

        Cache::put(
            $this->analysisClipReferenceCacheKey($matchId),
            [
                'video_url' => $videoUrl,
                'video_source_type' => trim((string)($reference['video_source_type'] ?? '')),
                'uploaded_by' => trim((string)($reference['uploaded_by'] ?? '')),
                'queued_at' => trim((string)($reference['queued_at'] ?? '')),
                'started_at' => trim((string)($reference['started_at'] ?? '')),
                'recording_id' => trim((string)($reference['recording_id'] ?? '')),
                'file_name' => trim((string)($reference['file_name'] ?? '')),
                'video_part_number' => trim((string)($reference['video_part_number'] ?? '')),
                'video_group_key' => trim((string)($reference['video_group_key'] ?? '')),
                'storage_version' => trim((string)($reference['storage_version'] ?? '')),
                'ai_video_url' => trim((string)($reference['ai_video_url'] ?? '')),
            ],
            self::ANALYSIS_CLIP_REFERENCE_TTL
        );
    }

    private function analysisClipReferenceCacheKey(int $matchId): string
    {
        return 'video-analysis:selected-clip:' . $matchId;
    }

    private function persistCompletedAnalysis(int $matchId, array $match, string $videoUrl, string $sourceType, array $aiOutput): array
    {
        $this->logAiProcessing('process:ai_output_received', [
            'match_id' => $matchId,
            'top_level_keys' => array_keys($aiOutput),
            'player_statistics_count' => is_array($aiOutput['player_statistics'] ?? null)
                ? count($aiOutput['player_statistics'])
                : null,
            'players_count' => is_array($aiOutput['players'] ?? null)
                ? count($aiOutput['players'])
                : null,
        ]);
        $this->ensureAnalysisCanFinalize($matchId);

        // Re-read latest slots in case the user updated the lineup while AI was running
        $latestSlots = $this->jerseySlots->ensureDefaults($matchId);
        $normalized = $this->normalizeAiOutput($match, $latestSlots, $aiOutput);
        $this->logAiProcessing('process:normalized', [
            'match_id' => $matchId,
            'jersey_rows' => count($normalized['jersey_stats'] ?? []),
            'player_stats_count' => is_array($normalized['player_stats'] ?? null)
                ? count($normalized['player_stats'])
                : null,
            'team_stats_keys' => is_array($normalized['team_stats'] ?? null)
                ? array_keys($normalized['team_stats'])
                : [],
        ]);

        $this->progressState->processingStage(
            $matchId,
            'finalizing',
            72,
            'AI analysis is complete. Saving match stats...'
        );
        $this->ensureAnalysisCanFinalize($matchId);
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
        $this->notifyRequesterAnalysisComplete($matchId);
        $this->logAiProcessing('process:processed', [
            'match_id' => $matchId,
            'jersey_rows' => count($normalized['jersey_stats'] ?? []),
        ]);
        RunpodPodService::touchActivity($this->runpodInstanceKey);

        // Update weekly challenge progress for all players in this match
        try {
            $this->updateWeeklyChallengesForMatch($matchId);
        } catch (\Throwable $e) {
            error_log('Failed to update weekly challenges: ' . $e->getMessage());
        }

        return $normalized;
    }

    public function finalizeCompletedRunpodJob(int $matchId): bool
    {
        $record = $this->analysis->getByMatchId($matchId);
        if (!is_array($record)) {
            return false;
        }

        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        $jobId = trim((string)($metadata['runpod_job_id'] ?? ''));
        if ($jobId === '') {
            return false;
        }

        $payload = $this->getJsonFromFastApi($this->resolveRemoteFastApiJobUrl($jobId));
        $status = strtolower(trim((string)($payload['status'] ?? 'processing')));
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];

        if (in_array($status, ['processing', 'started', 'queued', 'pending'], true)) {
            $this->analysis->updateByMatchId($matchId, [
                'processing_status' => 'processing',
                'progress_stage' => (string)($payload['stage'] ?? $progress['stage'] ?? 'analyzing'),
                'progress_percent' => max(8, min(95, (int)($progress['percent'] ?? 0))),
                'progress_message' => $this->stringifyProgressValue($progress['message'] ?? 'AI worker is analyzing the video...'),
                'error_message' => null,
                'updated_at' => gmdate('c'),
            ]);
            return false;
        }

        if ($status === 'failed') {
            $this->failAnalysis($matchId, (string)($payload['error'] ?? 'AI analysis failed.'));
            return true;
        }

        if ($status !== 'completed' && $status !== 'processed') {
            return false;
        }

        $aiOutput = $this->extractAnalysisFromFastApiResponse($payload);
        $match = $this->requireMatch($matchId);
        $videoUrl = trim((string)($match['video_url'] ?? $record['video_url'] ?? ''));
        $sourceType = $this->inferVideoSourceType($videoUrl, $record);
        $this->persistCompletedAnalysis($matchId, $match, $videoUrl, $sourceType, $aiOutput);
        return true;
    }

    private function notifyRequesterAnalysisComplete(int $matchId): void
    {
        try {
            $record = $this->analysis->getByMatchId($matchId) ?? [];
            $userIds = [];
            $uploadedBy = trim((string)($record['uploaded_by'] ?? ''));
            if ($uploadedBy !== '') {
                $userIds[$uploadedBy] = true;
            }

            $unlockRows = SupabaseClient::getInstance()
                ->from('stat_unlocks')
                ->select('user_id')
                ->eq('match_id', (string)$matchId)
                ->execute();
            if (is_array($unlockRows) && empty($unlockRows['error'])) {
                foreach ($unlockRows as $unlockRow) {
                    if (!is_array($unlockRow)) {
                        continue;
                    }
                    $unlockUserId = trim((string)($unlockRow['user_id'] ?? ''));
                    if ($unlockUserId !== '') {
                        $userIds[$unlockUserId] = true;
                    }
                }
            }

            if ($userIds === []) {
                return;
            }

            $title = 'Your match has been analyzed.';
            $body = 'Your match stats are ready to view.';
            $notificationService = new NotificationService();

            foreach (array_keys($userIds) as $userId) {
                $notificationService->send(
                    $userId,
                    NotificationService::TYPE_VIDEO_PROCESSED,
                    $title,
                    $body,
                    'analytics',
                    '/profile'
                );

                $account = SupabaseClient::getInstance()
                    ->from('accounts')
                    ->select('push_token')
                    ->eq('uid', $userId)
                    ->single()
                    ->execute();

                $token = trim((string)($account['push_token'] ?? ''));
                if (!$this->isExpoPushToken($token)) {
                    continue;
                }

                $this->sendExpoPushNotification($token, $title, $body, [
                    'type' => NotificationService::TYPE_VIDEO_PROCESSED,
                    'screen' => 'Profile',
                    'match_id' => $matchId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logAiProcessing('process:completion_notification_failed', [
                'match_id' => $matchId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function isExpoPushToken(string $token): bool
    {
        return str_starts_with($token, 'ExponentPushToken[')
            || str_starts_with($token, 'ExpoPushToken[');
    }

    private function sendExpoPushNotification(string $token, string $title, string $body, array $data = []): void
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $payload = json_encode([
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        if (!$ch) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($error !== '' || $status >= 400) {
            $this->logAiProcessing('process:completion_push_failed', [
                'http_status' => $status,
                'curl_error' => $error,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
            ]);
        }
    }

    public function failAnalysis(int $matchId, string $message): void
    {
        $rawMessage = trim($message);
        $normalizedMessage = strtolower($rawMessage);
        $publicMessage = $this->mapFailureToPublicMessage($normalizedMessage, $rawMessage);

        // Always log the underlying error to the host log so an admin running
        // tail on storage/logs/ai/queue.log (or the queue cron log) can see
        // exactly what the worker reported.
        $this->logAiProcessing('process:failed', [
            'match_id' => $matchId,
            'raw_message' => $rawMessage,
            'public_message' => $publicMessage,
        ]);

        // Persist the friendly message for the dashboard AND capture a short
        // sanitized hint of the raw cause inside ai_output.error_detail so
        // the diagnose-ai script (and a future admin view) can surface it
        // without leaking the full stack trace.
        $record = $this->analysis->getByMatchId($matchId) ?? [];
        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        $metadata = array_merge($metadata, $this->buildFailurePayload($rawMessage));
        $this->analysis->upsertByMatchId($matchId, [
            'processing_status' => 'failed',
            'error_message' => $publicMessage,
            // Keep selected_clip and worker job metadata so Retry can always
            // recover the durable source after a pod or network failure.
            'ai_output' => $metadata,
            'updated_at' => gmdate('c'),
        ]);
        $this->matchs->update($matchId, ['video_status' => 'failed']);
        $this->progressState->failed($matchId, $publicMessage);
        try {
            (new AiCreditRefundService())->refundFailedAnalysis($matchId, $publicMessage);
        } catch (\Throwable $refundError) {
            $this->logAiProcessing('process:refund_failed', [
                'match_id' => $matchId,
                'message' => $refundError->getMessage(),
            ]);
        }
    }

    /**
     * Repair jobs that completed and saved stats before a web-invoked cron
     * runner failed while writing to the CLI-only STDOUT/STDERR constants.
     */
    public function recoverCompletedAnalysisFromPersistedStats(int $matchId): ?array
    {
        $record = $this->analysis->getByMatchId($matchId);
        if (!is_array($record) || strtolower(trim((string)($record['processing_status'] ?? ''))) !== 'failed') {
            return null;
        }

        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        $failureText = strtolower(trim(
            (string)($record['error_message'] ?? '') . ' ' . (string)($metadata['error_detail'] ?? '')
        ));
        if (!str_contains($failureText, 'undefined constant "stdout"')
            && !str_contains($failureText, "undefined constant 'stdout'")
            && !str_contains($failureText, 'undefined constant "stderr"')
            && !str_contains($failureText, "undefined constant 'stderr'")) {
            return null;
        }

        $stats = $this->jerseyStats->getByMatch($matchId);
        if ($stats === []) {
            return null;
        }

        $processedAt = trim((string)($record['processed_at'] ?? ''));
        if ($processedAt === '') {
            $processedAt = trim((string)($record['updated_at'] ?? '')) ?: gmdate('c');
        }

        $metadata['recovered_from_persisted_stats'] = true;
        $metadata['recovered_at'] = gmdate('c');
        $updated = [
            'processing_status' => 'processed',
            'normalized_stats' => $stats,
            'ai_output' => $metadata,
            'error_message' => null,
            'processed_at' => $processedAt,
            'progress_stage' => 'complete',
            'progress_percent' => 100,
            'progress_message' => 'AI processing complete.',
            'updated_at' => gmdate('c'),
        ];

        $this->analysis->updateByMatchId($matchId, $updated);
        $this->matchs->update($matchId, ['video_status' => 'processed']);
        $this->progressState->processed($matchId);
        $this->logAiProcessing('process:recovered_false_failure', [
            'match_id' => $matchId,
            'jersey_rows' => count($stats),
        ]);

        return array_merge($record, $updated);
    }

    private function mapFailureToPublicMessage(string $normalized, string $raw): string
    {
        if (str_contains($normalized, 'stopped manually')) {
            return 'You stopped the analysis manually. Click Try Again whenever you are ready.';
        }
        if (str_contains($normalized, 'http 524') || str_contains($normalized, 'timed out while waiting')) {
            return 'The AI worker took too long to respond. Try a shorter clip, or retry — a fresh pod will be provisioned automatically.';
        }
        if (str_contains($normalized, 'no gpu') || str_contains($normalized, 'gpu is available')) {
            return 'No GPU is available right now. Your credit has been refunded. Please start AI again later.';
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
        if (str_contains($normalized, 'invalid video')
            || str_contains($normalized, 'cannot open video')
            || str_contains($normalized, 'could not be opened')
            || str_contains($normalized, 'video file was not found')
            || str_contains($normalized, 'unsupported format')) {
            return 'The AI worker could not read the video. Try re-encoding it as MP4 (H.264) and uploading again.';
        }
        if (str_contains($normalized, '404') || str_contains($normalized, 'not found')) {
            return 'The AI worker restarted. The original clip is safe in storage; tap Try Again to resume from it.';
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

        $staleMessage = 'AI processing expired because the worker stopped or was terminated.';
        if ($this->requeueAfterInfrastructureFailure($matchId, $staleMessage)) {
            return false;
        }

        $this->failAnalysis($matchId, $staleMessage . ' The saved clip is protected; retry the analysis.');
        @unlink($this->progressState->pathForMatch($matchId));
        return true;
    }

    public function requeueAfterInfrastructureFailure(int $matchId, string $message): bool
    {
        if (!$this->isRetryableInfrastructureFailure($message)) {
            return false;
        }

        $record = $this->analysis->getByMatchId($matchId);
        if (!is_array($record)) {
            return false;
        }

        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        $retryCount = (int)($metadata['automatic_retry_count'] ?? 0);
        if ($retryCount >= 3) {
            return false;
        }

        $selected = is_array($metadata['selected_clip'] ?? null) ? $metadata['selected_clip'] : [];
        $videoUrl = trim((string)($selected['video_url'] ?? $record['video_url'] ?? ''));
        $sourceType = trim((string)($selected['video_source_type'] ?? $record['video_source_type'] ?? ''));
        if ($videoUrl === '' || !in_array($sourceType, ['b2_storage', 'local_upload'], true)) {
            return false;
        }

        $storageLock = VideoStorageProtectionService::acquireSharedLock();
        try {
            if ($sourceType === 'b2_storage') {
                $this->assertB2SourceAvailable($videoUrl);
            } elseif ($this->resolveLocalVideoPath($videoUrl) === null) {
                return false;
            }

            $history = is_array($metadata['automatic_retry_history'] ?? null)
                ? $metadata['automatic_retry_history']
                : [];
            $history[] = [
                'attempt' => $retryCount + 1,
                'reason' => substr(trim($message), 0, 500),
                'queued_at' => gmdate('c'),
                'previous_job_id' => (string)($metadata['runpod_job_id'] ?? ''),
            ];
            $metadata['automatic_retry_count'] = $retryCount + 1;
            $metadata['automatic_retry_history'] = array_slice($history, -3);
            $metadata['last_recovery_reason'] = substr(trim($message), 0, 500);
            $metadata['last_recovery_at'] = gmdate('c');
            unset(
                $metadata['runpod_job_id'],
                $metadata['runpod_endpoint'],
                $metadata['runpod_jobs'],
                $metadata['runpod_jobs_started_at']
            );

            $now = gmdate('c');
            $requeued = $this->analysis->updateByMatchId($matchId, [
                'processing_status' => 'queued',
                'video_url' => $videoUrl,
                'video_source_type' => $sourceType,
                'ai_output' => $metadata,
                'error_message' => null,
                'progress_stage' => 'recovering',
                'progress_percent' => 7,
                'progress_message' => 'The AI worker disconnected. Retrying automatically from the saved clip...',
                'queued_at' => $now,
                'updated_at' => $now,
            ]);
            if (!is_array($requeued)) {
                throw new RuntimeException('The analysis could not be requeued in the database.');
            }
            $this->matchs->update($matchId, ['video_status' => 'queued']);
            $this->progressState->queuedStage(
                $matchId,
                'recovering',
                7,
                'The AI worker disconnected. Retrying automatically from the saved clip...'
            );
            $this->logAiProcessing('process:automatically_requeued', [
                'match_id' => $matchId,
                'retry_count' => $retryCount + 1,
                'reason' => $message,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logAiProcessing('process:automatic_requeue_failed', [
                'match_id' => $matchId,
                'message' => $e->getMessage(),
            ]);
            return false;
        } finally {
            VideoStorageProtectionService::releaseLock($storageLock);
        }
    }

    private function isRetryableInfrastructureFailure(string $message): bool
    {
        $normalized = strtolower(trim($message));
        foreach ([
            'timed out',
            'timeout',
            'worker stopped',
            'worker was terminated',
            'worker restarted',
            'job was not found',
            'job not found',
            'unknown job',
            'connection reset',
            'connection refused',
            'could not resolve host',
            'temporary failure in name resolution',
            'http 502',
            'http 503',
            'http 504',
            'proxy error',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
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

    private function ensureAnalysisCanFinalize(int $matchId): void
    {
        $record = $this->analysis->getByMatchId($matchId);
        if (!is_array($record)) {
            throw new AiProcessingDeferredException('AI processing was superseded before finalizing.');
        }

        $status = strtolower(trim((string)($record['processing_status'] ?? '')));
        $error = strtolower(trim((string)($record['error_message'] ?? '')));
        if ($status === 'failed' && str_contains($error, 'stopped manually')) {
            throw new RuntimeException('AI processing was stopped manually.');
        }

        if ($status !== 'processing') {
            throw new AiProcessingDeferredException('AI processing was superseded before finalizing.');
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
        if (!in_array($sourceType, ['local_upload', 'remote_upload', 'b2_storage'], true)) {
            throw new RuntimeException('Retry is only available for FiveStats-hosted videos.');
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
        $message = $this->stringifyProgressValue($payload['message'] ?? '');
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
                'error_message' => null,
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
            try {
                $this->finalizeCompletedRunpodJob($matchId);
            } catch (\Throwable $e) {
                error_log(sprintf('[ai-callback-finalize][match:%d] %s', $matchId, $e->getMessage()));
            }
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
            'nohup %s %s --match-id=%d > %s 2> %s < /dev/null & echo $!',
            escapeshellarg($php),
            escapeshellarg($script),
            $matchId,
            escapeshellarg($stdout),
            escapeshellarg($stderr)
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $pid = trim((string)($output[count($output) - 1] ?? ''));
        if ($exitCode !== 0 || $pid === '' || !ctype_digit($pid)) {
            throw new RuntimeException('The PHP host could not launch the background AI queue worker.');
        }
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
        $this->recoverCompletedAnalysisFromPersistedStats($matchId);
        $this->expireStaleAnalysisIfNeeded($matchId);
        try {
            $record = $this->analysis->getByMatchId($matchId);
            $status = strtolower(trim((string)($record['processing_status'] ?? '')));
            if (in_array($status, ['queued', 'processing'], true)) {
                $this->finalizeCompletedRunpodJob($matchId);
            }
        } catch (\Throwable $e) {
            error_log(sprintf('[ai-progress-finalize][match:%d] %s', $matchId, $e->getMessage()));
        }
        $progress = $this->progressState->read($matchId) ?? [];
        $analysis = $this->analysis->getByMatchId($matchId) ?? [];
        $status = strtolower((string)($analysis['processing_status'] ?? $progress['status'] ?? 'pending'));
        $aiOutput = is_array($analysis['ai_output'] ?? null) ? $analysis['ai_output'] : [];
        $errorDetail = trim((string)($aiOutput['error_detail'] ?? ''));
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
            $failedPercent = isset($progress['percent']) ? (int)$progress['percent'] : 0;
            if (isset($analysis['progress_percent']) && is_numeric($analysis['progress_percent'])) {
                $failedPercent = max($failedPercent, (int)$analysis['progress_percent']);
            }
            $progress = array_merge($progress, [
                'status' => 'failed',
                'stage' => 'failed',
                'percent' => max(1, min(99, $failedPercent)),
                'message' => (string)($analysis['error_message'] ?? $progress['message'] ?? 'AI processing failed.'),
                'error_message' => $analysis['error_message'] ?? null,
                'error_detail' => $errorDetail !== '' ? $errorDetail : null,
            ]);
        } elseif ($status === 'processing') {
            $progress = array_merge([
                'status' => 'processing',
                'stage' => 'startup',
                'percent' => 8,
                'message' => 'AI worker is processing the video...',
            ], $progress, ['status' => 'processing']);
        } elseif ($status === 'queued') {
            $progress = array_merge([
                'status' => 'queued',
                'stage' => 'queued',
                'percent' => 5,
                'message' => 'Video uploaded. Waiting for AI worker to start...',
            ], $progress, ['status' => 'queued']);
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

        if ($sourceType === 'b2_storage') {
            return $this->runAiAnalysisWithExternalVideoUrl($videoUrl, $jsonPath, $matchId, $slots);
        }

        throw new RuntimeException('AI processing could not resolve the video source.');
    }

    /**
     * Start every match clip before polling. This keeps multi-part/camera
     * matches from being processed one after another.
     *
     * @param array<int, array<string, mixed>> $clips
     */
    private function runAiAnalysisForMatchClips(array $clips, int $matchId, array $slots): array
    {
        $jobs = [];
        $asyncUrl = $this->resolveRemoteFastApiBaseUrl() . '/analyze-url-async';
        foreach ($clips as $index => $clip) {
            $originalVideoUrl = trim((string)($clip['video_url'] ?? ''));
            $videoUrl = trim((string)($clip['ai_video_url'] ?? ''));
            if ($videoUrl === '') {
                $videoUrl = $originalVideoUrl;
            }
            if ($videoUrl === '') {
                continue;
            }

            $payload = $this->withCallbackPayload([
                'match_id' => $matchId,
                'video_url' => $videoUrl,
                'headless' => true,
                'lineup' => $this->groupSlotsForApi($slots),
            ], $matchId);

            try {
                $decoded = $this->postJsonToFastApi($asyncUrl, $payload);
                $jobId = trim((string)($decoded['job_id'] ?? ''));
                if ($jobId === '') {
                    throw new RuntimeException('Async AI job response did not include a job ID.');
                }

                $jobs[] = [
                    'job_id' => $jobId,
                    'endpoint' => $asyncUrl,
                    'video_url' => $videoUrl,
                    'original_video_url' => $originalVideoUrl,
                    'clip_index' => $index + 1,
                    'clip' => $clip,
                    'status' => 'processing',
                    'progress' => [],
                ];
            } catch (\Throwable $e) {
                $message = strtolower(trim($e->getMessage()));
                $missingEndpoint = str_contains($message, '405')
                    || str_contains($message, 'method not allowed')
                    || ((str_contains($message, '404') || str_contains($message, 'not found'))
                        && !$this->isLostAsyncJobMessage($message));

                if ($missingEndpoint) {
                    $this->logAiProcessing('clips:async_unsupported', [
                        'match_id' => $matchId,
                        'message' => $e->getMessage(),
                    ]);
                    $outputs = [];
                    foreach ($clips as $fallbackClip) {
                        $fallbackUrl = trim((string)($fallbackClip['ai_video_url'] ?? ''));
                        if ($fallbackUrl === '') {
                            $fallbackUrl = trim((string)($fallbackClip['video_url'] ?? ''));
                        }
                        if ($fallbackUrl === '') {
                            continue;
                        }
                        $outputs[] = [
                            'clip' => $fallbackClip,
                            'analysis' => $this->runAiAnalysisForQueuedSource(
                                $fallbackUrl,
                                trim((string)($fallbackClip['video_source_type'] ?? 'b2_storage')) ?: 'b2_storage',
                                $matchId,
                                $slots
                            ),
                        ];
                    }
                    return $this->mergeClipAiOutputs($outputs);
                }

                throw $e;
            }
        }

        if ($jobs === []) {
            throw new RuntimeException('No match recordings were available for AI analysis.');
        }

        $this->rememberRunpodJobs($matchId, $jobs);
        $outputs = $this->waitForAsyncClipJobs($jobs, $matchId);
        $outputDir = BASE_PATH . '/storage/ai-output';
        if (is_dir($outputDir)) {
            file_put_contents(
                $outputDir . '/match_' . $matchId . '_analysis.json',
                json_encode($outputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        return $this->mergeClipAiOutputs($outputs);
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

            $this->rememberRunpodJob($matchId, $jobId, $this->resolveRemoteFastApiAnalyzeStoredAsyncUrl());
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
            if (!$this->recordOwnsRunpodJob($current, $jobId)) {
                throw new AiProcessingDeferredException('AI processing was superseded by a newer retry.');
            }
            $currentStatus = strtolower(trim((string)($current['processing_status'] ?? '')));
            if ($currentStatus === 'failed'
                && str_contains(strtolower((string)($current['error_message'] ?? '')), 'stopped manually')) {
                throw new RuntimeException('AI processing was stopped manually.');
            }
            $payload = $this->getJsonFromFastApi($this->resolveRemoteFastApiJobUrl($jobId));
            $status = strtolower((string)($payload['status'] ?? 'processing'));
            $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
            $message = $this->stringifyProgressValue($progress['message'] ?? '');
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
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array{clip: array<string, mixed>, analysis: array<string, mixed>}>
     */
    private function waitForAsyncClipJobs(array $jobs, int $matchId): array
    {
        $deadline = time() + max($this->fastApiTimeoutSeconds(), $this->fastApiTimeoutSeconds() * count($jobs));
        $completed = [];
        $progressByJob = [];
        $pollCount = 0;

        while (time() <= $deadline) {
            $pollCount++;
            $current = $this->analysis->getByMatchId($matchId);
            $currentStatus = strtolower(trim((string)($current['processing_status'] ?? '')));
            if ($currentStatus === 'failed'
                && str_contains(strtolower((string)($current['error_message'] ?? '')), 'stopped manually')) {
                throw new RuntimeException('AI processing was stopped manually.');
            }

            foreach ($jobs as $job) {
                $jobId = (string)($job['job_id'] ?? '');
                if ($jobId === '' || isset($completed[$jobId])) {
                    continue;
                }
                if (!$this->recordOwnsRunpodJob($current, $jobId)) {
                    throw new AiProcessingDeferredException('AI processing was superseded by a newer retry.');
                }

                $payload = $this->getJsonFromFastApi($this->resolveRemoteFastApiJobUrl($jobId));
                $status = strtolower((string)($payload['status'] ?? 'processing'));
                $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
                $progressByJob[$jobId] = [
                    'status' => $status,
                    'percent' => (int)($progress['percent'] ?? 0),
                    'message' => $this->stringifyProgressValue($progress['message'] ?? ''),
                ];

                if ($status === 'completed') {
                    $completed[$jobId] = [
                        'clip' => is_array($job['clip'] ?? null) ? $job['clip'] : [],
                        'analysis' => $this->extractAnalysisFromFastApiResponse($payload),
                    ];
                    continue;
                }

                if ($status === 'failed') {
                    $error = trim((string)($payload['error'] ?? 'AI analysis failed.'));
                    $clipName = trim((string)($job['clip']['file_name'] ?? 'match recording'));
                    throw new RuntimeException(($clipName !== '' ? $clipName . ': ' : '') . ($error !== '' ? $error : 'AI analysis failed.'));
                }
            }

            $jobCount = max(1, count($jobs));
            if (count($completed) >= $jobCount) {
                return array_values($completed);
            }

            $percentTotal = 0;
            foreach ($jobs as $job) {
                $jobId = (string)($job['job_id'] ?? '');
                if (isset($completed[$jobId])) {
                    $percentTotal += 100;
                    continue;
                }
                $percentTotal += max(10, min(95, (int)($progressByJob[$jobId]['percent'] ?? 10)));
            }
            $average = (int)floor($percentTotal / $jobCount);
            $reportedPercent = max(25, min(95, $average > 0 ? $average : (25 + min(60, $pollCount * 2))));

            $this->progressState->processingStage(
                $matchId,
                'analyzing_all_recordings',
                $reportedPercent,
                sprintf(
                    'AI is analyzing all match recordings (%d of %d complete)...',
                    count($completed),
                    $jobCount
                )
            );

            try {
                $this->analysis->updateByMatchId($matchId, [
                    'updated_at' => gmdate('c'),
                ]);
            } catch (\Throwable $e) {
                error_log('[ai-poll-heartbeat] ' . $e->getMessage());
            }

            sleep(5);
        }

        throw new RuntimeException('Timed out while waiting for all AI workers to finish analyzing the match recordings.');
    }

    private function recordOwnsRunpodJob(?array $record, string $jobId): bool
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return false;
        }

        if (!is_array($record)) {
            return false;
        }

        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        if (trim((string)($metadata['runpod_job_id'] ?? '')) === $jobId) {
            return true;
        }

        $jobs = is_array($metadata['runpod_jobs'] ?? null) ? $metadata['runpod_jobs'] : [];
        foreach ($jobs as $job) {
            if (is_array($job) && trim((string)($job['job_id'] ?? '')) === $jobId) {
                return true;
            }
        }

        return false;
    }

    private function isLostAsyncJobMessage(string $message): bool
    {
        $message = strtolower(trim($message));
        return str_contains($message, 'analysis job was not found')
            || str_contains($message, '/jobs/')
            || str_contains($message, 'stored video was not found')
            || str_contains($message, 'saved clip');
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

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $decoded = $this->postJsonToFastApi($asyncUrl, $payload);
                $jobId = trim((string)($decoded['job_id'] ?? ''));
                if ($jobId === '') {
                    throw new RuntimeException('Async AI job response did not include a job ID.');
                }
                $this->rememberRunpodJob($matchId, $jobId, $asyncUrl);
                return $this->waitForAsyncJob($jobId, $matchId);
            } catch (\Throwable $e) {
                $msg = strtolower(trim($e->getMessage()));
                $missingEndpoint = str_contains($msg, '405')
                    || str_contains($msg, 'method not allowed')
                    || ((str_contains($msg, '404') || str_contains($msg, 'not found'))
                        && !$this->isLostAsyncJobMessage($msg));

                if ($missingEndpoint) {
                    error_log('[analyze-url-async] worker bundle is older — falling back to sync /analyze-url');
                    $decoded = $this->postJsonToFastApi($this->resolveRemoteFastApiAnalyzeUrl(), $payload);
                    return $this->extractAnalysisFromFastApiResponse($decoded);
                }

                if ($attempt === 0 && $this->isLostAsyncJobMessage($msg)) {
                    $this->logAiProcessing('process:async_job_lost_retrying', [
                        'match_id' => $matchId,
                        'message' => $e->getMessage(),
                    ]);
                    $this->progressState->processingStage(
                        $matchId,
                        'recovering',
                        35,
                        'AI worker restarted mid-analysis. Recovering the video and restarting automatically...'
                    );
                    $this->analysis->updateByMatchId($matchId, ['updated_at' => gmdate('c')]);
                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException('AI analysis failed after recovering the worker job.');
    }

    private function rememberRunpodJob(int $matchId, string $jobId, string $endpoint): void
    {
        $record = $this->analysis->getByMatchId($matchId) ?? [];
        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        $metadata['runpod_job_id'] = $jobId;
        $metadata['runpod_endpoint'] = $endpoint;
        $metadata['runpod_base_url'] = $this->resolveRemoteFastApiBaseUrl();
        $metadata['runpod_job_started_at'] = gmdate('c');

        $this->analysis->updateByMatchId($matchId, [
            'ai_output' => $metadata,
            'error_message' => null,
            'updated_at' => gmdate('c'),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    private function rememberRunpodJobs(int $matchId, array $jobs): void
    {
        $record = $this->analysis->getByMatchId($matchId) ?? [];
        $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
        $metadata['runpod_jobs'] = array_map(static function (array $job): array {
            return [
                'job_id' => (string)($job['job_id'] ?? ''),
                'endpoint' => (string)($job['endpoint'] ?? ''),
                'video_url' => (string)($job['video_url'] ?? ''),
                'original_video_url' => (string)($job['original_video_url'] ?? ''),
                'clip_index' => (int)($job['clip_index'] ?? 0),
                'file_name' => (string)($job['clip']['file_name'] ?? ''),
                'recording_id' => (string)($job['clip']['recording_id'] ?? ''),
            ];
        }, $jobs);
        $metadata['runpod_base_url'] = $this->resolveRemoteFastApiBaseUrl();
        $metadata['runpod_jobs_started_at'] = gmdate('c');

        $this->analysis->updateByMatchId($matchId, [
            'ai_output' => $metadata,
            'error_message' => null,
            'updated_at' => gmdate('c'),
        ]);
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

            $this->logAiProcessing('persist:jersey_rows_attempt', [
                'match_id' => $matchId,
                'rows' => count($jerseyRowsToInsert),
                'columns' => array_keys($jerseyRowsToInsert[0] ?? []),
            ]);
            if ($this->jerseyStats->createMany($jerseyRowsToInsert) === []) {
                $this->logAiProcessing('persist:jersey_rows_failed', [
                    'match_id' => $matchId,
                    'rows' => count($jerseyRowsToInsert),
                ]);
                throw new RuntimeException('Failed to persist jersey-based match stats.');
            }
            $this->logAiProcessing('persist:jersey_rows_saved', [
                'match_id' => $matchId,
                'rows' => count($jerseyRowsToInsert),
            ]);
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

    private function logAiProcessing(string $event, array $context = []): void
    {
        $context['event'] = $event;
        $context['time'] = gmdate('c');
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('[ai-processing] ' . (is_string($encoded) ? $encoded : $event));
    }

    private function normalizeAiOutput(array $match, array $slots, array $aiOutput): array
    {
        $teamStats = $this->normalizeTeamStatsPayload($aiOutput);
        $playerStats = $this->normalizePlayerStatsPayload($aiOutput);
        if ($playerStats === []) {
            throw new RuntimeException('AI response did not include player statistics.');
        }
        $teamScores = $this->extractTeamScores($teamStats, $playerStats);
        $minutesPlayed = $this->extractMinutesPlayed($aiOutput);
        $aggregatedBySlot = $this->aggregatePlayerStatsBySlot($playerStats, $slots);
        if (!$this->hasMappedPlayerStats($aggregatedBySlot)) {
            throw new RuntimeException('AI could not map detected players to team colors or jersey numbers. Check that shirts are visible and try again.');
        }

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

    /**
     * @param array<int, array{clip: array<string, mixed>, analysis: array<string, mixed>}> $outputs
     */
    private function mergeClipAiOutputs(array $outputs): array
    {
        if (count($outputs) === 1 && is_array($outputs[0]['analysis'] ?? null)) {
            return $outputs[0]['analysis'];
        }

        $players = [];
        $teams = [];
        $totalSeconds = 0.0;
        $preferredFps = 30.0;
        $clipSummaries = [];

        foreach ($outputs as $index => $entry) {
            $clip = is_array($entry['clip'] ?? null) ? $entry['clip'] : [];
            $analysis = is_array($entry['analysis'] ?? null) ? $entry['analysis'] : [];
            $clipIndex = $index + 1;
            $clipSummaries[] = [
                'clip_index' => $clipIndex,
                'video_url' => (string)($clip['video_url'] ?? ''),
                'file_name' => (string)($clip['file_name'] ?? ''),
                'recording_id' => (string)($clip['recording_id'] ?? ''),
            ];

            foreach ($this->normalizePlayerStatsPayload($analysis) as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $players['clip_' . $clipIndex . '_' . $key] = array_merge($row, [
                    'source_clip_index' => $clipIndex,
                    'source_file_name' => (string)($clip['file_name'] ?? ''),
                ]);
            }

            foreach ($this->normalizeTeamStatsPayload($analysis) as $team => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $teams[$team] = isset($teams[$team])
                    ? $this->mergeNumericStatRows($teams[$team], $row)
                    : $row;
            }

            $videoInfo = is_array($analysis['video_info'] ?? null) ? $analysis['video_info'] : [];
            $frames = (float)($videoInfo['total_frames'] ?? 0);
            $fps = (float)($videoInfo['frame_rate'] ?? $videoInfo['fps'] ?? 0);
            if ($fps > 0) {
                $preferredFps = max($preferredFps, $fps);
            }
            if ($frames > 0 && $fps > 0) {
                $totalSeconds += $frames / $fps;
            }
        }

        return [
            'player_statistics' => $players,
            'team_statistics' => $teams,
            'video_info' => [
                'total_frames' => (int)round($totalSeconds * $preferredFps),
                'frame_rate' => $preferredFps,
                'clip_count' => count($outputs),
            ],
            'clip_analyses' => $clipSummaries,
            'analysis_mode' => 'all_match_recordings',
        ];
    }

    private function mergeNumericStatRows(array $current, array $incoming): array
    {
        $merged = $current;
        foreach ($incoming as $key => $value) {
            if (is_numeric($value) && is_numeric($merged[$key] ?? null)) {
                $merged[$key] = (float)$merged[$key] + (float)$value;
                if ((string)(int)$merged[$key] === (string)$merged[$key]) {
                    $merged[$key] = (int)$merged[$key];
                }
                continue;
            }

            if (!array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
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

    private function normalizeTeamStatsPayload(array $aiOutput): array
    {
        $source = $aiOutput['team_statistics']
            ?? $aiOutput['team_stats']
            ?? $aiOutput['teams']
            ?? [];

        if (!is_array($source)) {
            return [];
        }

        $normalized = [];
        foreach ($source as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $team = $this->normalizeTeamColor($row['team'] ?? $row['team_color'] ?? $key);
            if ($team === null) {
                continue;
            }
            $normalized[$team] = $row;
        }

        return $normalized;
    }

    private function normalizePlayerStatsPayload(array $aiOutput): array
    {
        $source = $aiOutput['player_statistics']
            ?? $aiOutput['player_stats']
            ?? $aiOutput['players']
            ?? $aiOutput['stats']
            ?? [];

        if (!is_array($source)) {
            return [];
        }

        $normalized = [];
        foreach ($source as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $jerseyNumber = (int)$this->firstNumericValue($row, [
                'jersey_number',
                'shirt_number',
                'number',
                'kit_number',
                'player_number',
            ]);
            $team = $this->normalizeTeamColor($row['team'] ?? $row['team_color'] ?? $row['side'] ?? null)
                ?? $this->teamColorForJerseyNumber($jerseyNumber);

            $normalized[$key] = array_merge($row, [
                'team' => $team,
                'jersey_number' => $jerseyNumber > 0 ? $jerseyNumber : null,
            ]);
        }

        return $normalized;
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

    private function hasMappedPlayerStats(array $aggregatedBySlot): bool
    {
        foreach (['blue', 'red'] as $team) {
            foreach (($aggregatedBySlot[$team] ?? []) as $stats) {
                if (is_array($stats)) {
                    return true;
                }
            }
        }

        return false;
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
        $base = trim((string)(getenv('NUTMEG_AI_LOCAL_FASTAPI_URL') ?: ''));
        if ($base === '') {
            throw new RuntimeException('Local FastAPI mode requires NUTMEG_AI_LOCAL_FASTAPI_URL.');
        }
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
            throw new AiProcessingDeferredException('AI worker is still preparing its RunPod proxy URL; queued job will retry on the next cron run.');
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
        if (in_array($explicit, ['remote_upload', 'local_upload', 'external_url', 'b2_storage'], true)) {
            return $explicit;
        }

        if (B2VideoStorageService::isConfigured() && (new B2VideoStorageService())->isManagedUrl($videoUrl)) {
            return 'b2_storage';
        }

        if ($this->isWebsiteHostedVideoUrl($videoUrl)) {
            return 'local_upload';
        }

        if ($this->isAiHostedVideoUrl($videoUrl)) {
            return 'remote_upload';
        }

        return 'external_url';
    }

    private function assertB2SourceAvailable(string $videoUrl): void
    {
        if (!B2VideoStorageService::isConfigured()) {
            throw new RuntimeException('Durable video storage is not configured.');
        }

        $storage = new B2VideoStorageService();
        $objectName = $storage->objectNameFromUrl($videoUrl);
        if ($objectName === null || !$storage->objectExists($objectName)) {
            throw new RuntimeException('The saved source clip is missing from durable storage. Upload it again before starting AI.');
        }
    }

    private function resolveAvailableB2AnalysisUrl(string $sourceUrl, string $preferredUrl): string
    {
        $this->assertB2SourceAvailable($sourceUrl);
        $preferredUrl = trim($preferredUrl);
        if ($preferredUrl === '' || $this->clipKey($preferredUrl) === $this->clipKey($sourceUrl)) {
            return $sourceUrl;
        }

        try {
            $storage = new B2VideoStorageService();
            $preferredObject = $storage->objectNameFromUrl($preferredUrl);
            if ($preferredObject !== null && $storage->objectExists($preferredObject)) {
                return $preferredUrl;
            }
        } catch (\Throwable $e) {
            $this->logAiProcessing('process:optimized_source_check_failed', [
                'message' => $e->getMessage(),
            ]);
        }

        // The smaller AI copy is an optimization only. The original B2 clip
        // remains the durable source of truth and is always a valid fallback.
        return $sourceUrl;
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
            if (!is_string($message) || trim($message) === '') {
                $snippet = is_string($response) ? trim(substr(preg_replace('/\s+/', ' ', $response), 0, 500)) : '';
                $message = 'FastAPI analysis request failed with HTTP ' . $httpCode
                    . ($snippet !== '' ? ': ' . $snippet : '.');
            }
            throw new RuntimeException($message);
        }

        if (!is_array($decoded)) {
            $snippet = is_string($response) ? trim(substr(preg_replace('/\s+/', ' ', $response), 0, 500)) : '';
            error_log(sprintf(
                '[post-fastapi] FastAPI returned invalid JSON for %s; http_code=%d; response_snippet=%s; info=%s',
                $effectiveUrl,
                $httpCode,
                is_string($response) ? substr($response, 0, 2000) : '',
                json_encode($curlInfo)
            ));

            throw new RuntimeException(
                'FastAPI returned an invalid JSON response'
                . ($snippet !== '' ? ': ' . $snippet : '.')
            );
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
        $aggregate['goals'] = (int)$this->firstNumericValue($stats, ['goals', 'goals_scored']);
        $aggregate['assists'] = (int)$this->firstNumericValue($stats, ['assists']);
        $aggregate['passes'] = (int)$this->firstNumericValue($stats, ['passes', 'successful_passes', 'completed_passes']);
        $aggregate['passes_attempted'] = (int)$this->firstNumericValue($stats, ['passes_attempted', 'attempted_passes', 'total_passes']);
        $aggregate['defense'] = (int)$this->firstNumericValue($stats, ['defense', 'interceptions']);
        $aggregate['speed'] = (int)$this->firstNumericValue($stats, ['speed', 'sprints', 'total_sprints']);
        $aggregate['physical'] = (int)$this->firstNumericValue($stats, ['physical', 'duels_won']);
        $aggregate['dribbles'] = (int)$this->firstNumericValue($stats, ['dribbles', 'successful_dribbles']);
        $aggregate['dribbles_attempted'] = (int)$this->firstNumericValue($stats, ['dribbles_attempted', 'attempted_dribbles', 'total_dribbles']);
        $aggregate['shots'] = (int)$this->firstNumericValue($stats, ['shots', 'total_shots']);
        $aggregate['distance_m'] = (float)$this->firstNumericValue($stats, ['distance_m', 'distance_meters', 'distance_covered']);
        $aggregate['top_speed_kmh'] = (float)$this->firstNumericValue($stats, ['top_speed_kmh', 'maximum_speed', 'max_speed_kmh']);
        $aggregate['raw_player_key'] = (string)$rawKey;
        $aggregate['raw_keys'] = [(string)$rawKey];

        return $aggregate;
    }

    private function firstNumericValue(array $row, array $keys, float|int $fallback = 0): float|int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return $row[$key] + 0;
            }
        }

        return $fallback;
    }

    private function normalizeTeamColor(mixed $value): ?string
    {
        $raw = strtolower(trim((string)$value));
        if ($raw === '') {
            return null;
        }

        return match ($raw) {
            'blue', 'home', 'team_blue', 'blue_team' => 'blue',
            'red', 'away', 'team_red', 'red_team' => 'red',
            default => null,
        };
    }

    private function teamColorForJerseyNumber(int $jerseyNumber): ?string
    {
        if ($jerseyNumber >= 1 && $jerseyNumber <= 5) {
            return 'blue';
        }
        if ($jerseyNumber >= 6 && $jerseyNumber <= 10) {
            return 'red';
        }

        return null;
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

    private function stringifyProgressValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? trim($encoded) : '';
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

<?php
namespace App\Services;

use App\Core\Cache;
use RuntimeException;

class RunpodPodService
{
    private const DEFAULT_API_BASE = 'https://rest.runpod.io/v1';
    private const DEFAULT_INSTANCE_KEY = 'primary';
    private const UNAVAILABLE_TTL = 300;
    private const MATCH_INSTANCE_TTL = 86400;
    private const ACTIVITY_TTL = 604800;
    private const START_ATTEMPT_TTL = 180;
    private const INSTANCE_START_ATTEMPT_TTL = 240;
    private const ACTIVE_JOB_COUNT_TTL = 15;
    private const RECENT_DYNAMIC_STATUS_TTL = 45;
    private const WARMUP_WARNING_SECONDS = 900;
    private const WORKING_AI_BASE_URL_TTL = 86400;

    public function __construct(private ?string $instanceKey = null)
    {
    }

    public static function configuredInstances(): array
    {
        $instances = [];
        $dynamicOnly = self::dynamicOnlyModeEnabled();

        if (!$dynamicOnly) {
            $raw = trim((string)(getenv('NUTMEG_RUNPOD_INSTANCES_JSON') ?: getenv('NUTMEG_RUNPOD_INSTANCES') ?: ''));
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $config) {
                        if (!is_array($config)) {
                            continue;
                        }

                        $normalizedKey = self::normalizeInstanceKey((string)$key);
                        $instances[$normalizedKey] = self::normalizeInstanceConfig($normalizedKey, $config);
                    }
                }
            }

            if ($instances === []) {
                $instances[self::DEFAULT_INSTANCE_KEY] = self::normalizeInstanceConfig(self::DEFAULT_INSTANCE_KEY, [
                    'label' => getenv('NUTMEG_RUNPOD_LABEL') ?: 'Primary AI Worker',
                    'api_key' => getenv('NUTMEG_RUNPOD_API_KEY') ?: '',
                    'pod_id' => getenv('NUTMEG_RUNPOD_POD_ID') ?: '',
                    'api_base' => getenv('NUTMEG_RUNPOD_API_BASE') ?: self::DEFAULT_API_BASE,
                    'fastapi_port' => getenv('NUTMEG_RUNPOD_FASTAPI_PORT') ?: 8888,
                    'hourly_cost' => getenv('NUTMEG_RUNPOD_HOURLY_COST') ?: null,
                    'gpu_name' => getenv('NUTMEG_RUNPOD_GPU_NAME') ?: '',
                ]);
            }
        }

        if (RunpodActivePodService::isEnabled()) {
            $dynamicService = new RunpodActivePodService();
            $dynamicConfig = $dynamicService->activeInstanceConfig();
            $instances[RunpodActivePodService::DYNAMIC_INSTANCE_KEY] = self::normalizeInstanceConfig(
                RunpodActivePodService::DYNAMIC_INSTANCE_KEY,
                [
                    'label' => RunpodActivePodService::dynamicLabel(),
                    'api_key' => $dynamicConfig['api_key'] ?? (getenv('NUTMEG_RUNPOD_API_KEY') ?: ''),
                    'pod_id' => $dynamicConfig['pod_id'] ?? '',
                    'api_base' => $dynamicConfig['api_base'] ?? (getenv('NUTMEG_RUNPOD_API_BASE') ?: self::DEFAULT_API_BASE),
                    'fastapi_port' => $dynamicConfig['fastapi_port'] ?? (getenv('NUTMEG_RUNPOD_FASTAPI_PORT') ?: 8888),
                    'hourly_cost' => $dynamicConfig['hourly_cost'] ?? null,
                    'gpu_name' => $dynamicConfig['gpu_name'] ?? '',
                    'dynamic' => true,
                ]
            );
        }

        return $instances;
    }

    public static function statusSummaries(bool $useCache = true): array
    {
        $summaries = [];
        foreach (array_keys(self::configuredInstances()) as $key) {
            try {
                $summaries[$key] = (new self($key))->getStatus($useCache);
            } catch (\Throwable $e) {
                $instanceLabel = self::configuredInstances()[$key]['label'] ?? ucfirst($key);
                $summaries[$key] = [
                    'configured' => true,
                    'instance_key' => $key,
                    'instance_label' => $instanceLabel,
                    'label' => 'Unavailable',
                    'state' => 'error',
                    'message' => 'The AI worker status could not be loaded right now.',
                    'supports_stop' => false,
                    'ai_ready' => false,
                ];
            }
        }

        return $summaries;
    }

    public static function preferredInstanceKey(): string
    {
        if (RunpodActivePodService::isEnabled()) {
            $dynamicPod = (new RunpodActivePodService())->getActivePod();
            if (is_array($dynamicPod) && trim((string)($dynamicPod['pod_id'] ?? '')) !== '') {
                return RunpodActivePodService::DYNAMIC_INSTANCE_KEY;
            }
        }

        $active = self::activeInstanceKey(true);
        if ($active !== null) {
            return $active;
        }

        if (RunpodActivePodService::isEnabled()) {
            return RunpodActivePodService::DYNAMIC_INSTANCE_KEY;
        }

        $instances = self::configuredInstances();
        foreach ($instances as $key => $config) {
            if (trim((string)($config['pod_id'] ?? '')) !== '' && trim((string)($config['api_key'] ?? '')) !== '') {
                return $key;
            }
        }

        return array_key_first($instances) ?: self::DEFAULT_INSTANCE_KEY;
    }

    public static function activeInstanceKey(bool $useCache = true): ?string
    {
        foreach (self::statusSummaries($useCache) as $key => $summary) {
            if (in_array(strtolower((string)($summary['state'] ?? '')), ['ready', 'starting', 'stopping'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Start the least-cost configured worker that currently has an available GPU.
     *
     * @return array{instance_key:string,status:array}
     */
    public static function startLeastCostAvailable(?string $excludeInstanceKey = null): array
    {
        $exclude = is_string($excludeInstanceKey) && trim($excludeInstanceKey) !== ''
            ? self::normalizeInstanceKey($excludeInstanceKey)
            : null;

        if (RunpodActivePodService::isEnabled()) {
            $dynamicKey = RunpodActivePodService::DYNAMIC_INSTANCE_KEY;
            $dynamicService = new RunpodActivePodService();

            if ($exclude === $dynamicKey) {
                try {
                    (new self($dynamicKey))->stopPod();
                } catch (\Throwable) {
                    $dynamicService->forgetActivePod();
                }
            }

            try {
                $status = (new self($dynamicKey))->startPod(true);
                return [
                    'instance_key' => $dynamicKey,
                    'status' => $status,
                ];
            } catch (\Throwable $e) {
                if (self::dynamicOnlyModeEnabled()) {
                    throw $e;
                }

                // Fall back to fixed pods if the dynamic route is full or temporarily unavailable.
            }
        }

        $candidates = [];
        $excludedCandidate = null;

        foreach (self::configuredInstances() as $key => $config) {
            if ($key === RunpodActivePodService::DYNAMIC_INSTANCE_KEY) {
                continue;
            }

            $apiKey = trim((string)($config['api_key'] ?? ''));
            $podId = trim((string)($config['pod_id'] ?? ''));
            if ($apiKey === '' || $podId === '') {
                continue;
            }

            $cost = isset($config['hourly_cost']) && is_numeric($config['hourly_cost'])
                ? (float)$config['hourly_cost']
                : INF;

            $candidate = [
                'key' => (string)$key,
                'cost' => $cost,
                'label' => (string)($config['label'] ?? ucfirst((string)$key)),
                'excluded' => ($exclude !== null && (string)$key === $exclude),
            ];

            if ($candidate['excluded']) {
                $excludedCandidate = $candidate;
                continue;
            }

            $candidates[] = $candidate;
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['cost'] === $b['cost']) {
                return strcmp((string)$a['label'], (string)$b['label']);
            }

            return $a['cost'] <=> $b['cost'];
        });

        if ($candidates === [] && $excludedCandidate === null) {
            throw new RuntimeException('No AI workers are configured for migration.');
        }

        // Prefer alternate workers first, but fall back to the excluded/source worker.
        // This keeps migration resilient when only one worker is configured.
        $orderedCandidates = $candidates;
        if ($excludedCandidate !== null) {
            $orderedCandidates[] = $excludedCandidate;
        }

        $lastNoGpuMessage = null;
        $lastErrorMessage = null;
        foreach ($orderedCandidates as $candidate) {
            $candidateKey = (string)$candidate['key'];
            $service = new self($candidateKey);

            try {
                $status = $service->getStatus(false);
            } catch (\Throwable $e) {
                $lastErrorMessage = (string)$e->getMessage();
                continue;
            }

            $state = strtolower((string)($status['state'] ?? ''));
            if (in_array($state, ['ready', 'starting', 'stopping'], true)) {
                return [
                    'instance_key' => $candidateKey,
                    'status' => $status,
                ];
            }

            if ($state === 'unavailable') {
                $lastNoGpuMessage = (string)($status['message'] ?? 'No GPU is available for this AI worker right now.');
                continue;
            }

            try {
                $status = $service->startPod(true);
                if (strtolower((string)($status['state'] ?? '')) === 'unavailable') {
                    $lastNoGpuMessage = (string)($status['message'] ?? 'No GPU is available for this AI worker right now.');
                    continue;
                }

                return [
                    'instance_key' => $candidateKey,
                    'status' => $status,
                ];
            } catch (\Throwable $e) {
                if (self::isNoGpuErrorMessage((string)$e->getMessage())) {
                    $lastNoGpuMessage = (string)$e->getMessage();
                    continue;
                }

                $lastErrorMessage = (string)$e->getMessage();
            }
        }

        $message = 'No GPU is available for any configured AI worker right now. Please try again in a few minutes.';
        if (is_string($lastNoGpuMessage) && trim($lastNoGpuMessage) !== '') {
            $message = 'No GPU is available for any configured AI worker right now. Please try again in a few minutes.';
        } elseif (is_string($lastErrorMessage) && trim($lastErrorMessage) !== '') {
            $message = 'Migration candidates could not be reached right now. Please retry in a moment.';
        }

        throw new RuntimeException($message);
    }

    public static function rememberMatchInstance(int $matchId, ?string $instanceKey): void
    {
        $instanceKey = self::normalizeInstanceKey((string)$instanceKey);
        if ($matchId <= 0 || $instanceKey === '') {
            return;
        }

        Cache::put(self::matchInstanceCacheKey($matchId), $instanceKey, self::MATCH_INSTANCE_TTL);
    }

    public static function instanceForMatch(int $matchId): ?string
    {
        $value = Cache::get(self::matchInstanceCacheKey($matchId));
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function touchActivity(?string $instanceKey = null): void
    {
        $key = self::normalizeInstanceKey((string)($instanceKey ?: self::preferredInstanceKey()));
        if ($key === '') {
            return;
        }

        self::seedActivity($key);

        if ($key === RunpodActivePodService::DYNAMIC_INSTANCE_KEY) {
            (new RunpodActivePodService())->touchLastActivity();
        }
    }

    public static function seedActivity(string $instanceKey): void
    {
        $key = self::normalizeInstanceKey($instanceKey);
        if ($key === '') {
            return;
        }

        Cache::put(self::activityCacheKeyFor($key), [
            'timestamp' => time(),
        ], self::ACTIVITY_TTL);
    }

    public static function ensureActivity(string $instanceKey, ?string $activityAt = null): void
    {
        $key = self::normalizeInstanceKey($instanceKey);
        if ($key === '' || is_array(Cache::get(self::activityCacheKeyFor($key)))) {
            return;
        }

        $timestamp = is_string($activityAt) && trim($activityAt) !== ''
            ? strtotime($activityAt)
            : false;

        Cache::put(self::activityCacheKeyFor($key), [
            'timestamp' => $timestamp !== false ? $timestamp : time(),
        ], self::ACTIVITY_TTL);
    }

    public static function clearActivity(string $instanceKey): void
    {
        $key = self::normalizeInstanceKey($instanceKey);
        if ($key === '') {
            return;
        }

        Cache::forget(self::activityCacheKeyFor($key));
    }

    public function instanceKey(): string
    {
        return $this->instanceKey !== null && $this->instanceKey !== ''
            ? self::normalizeInstanceKey($this->instanceKey)
            : self::preferredInstanceKey();
    }

    public function isConfigured(): bool
    {
        if ($this->isDynamicManagedInstance()) {
            return RunpodActivePodService::isEnabled();
        }

        return $this->apiKey() !== '' && $this->podId() !== '';
    }

    public function getStatus(bool $useCache = true): array
    {
        if ($this->isDynamicManagedInstance() && $this->podId() === '') {
            if ($this->shouldRefreshDynamicPodDiscovery($useCache)) {
                $activePod = (new RunpodActivePodService())->getActivePod(true);
                if (is_array($activePod) && trim((string)($activePod['pod_id'] ?? '')) !== '') {
                    Cache::forget($this->cacheKey());
                    return $this->getStatus(false);
                }
            }

            $status = $this->dynamicPlaceholderStatus();
            $this->rememberRecentDynamicStatus($status);
            return $status;
        }

        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'instance_key' => $this->instanceKey(),
                'label' => $this->instanceLabel(),
                'state' => 'unconfigured',
                'message' => 'Add the Runpod API key and pod ID to enable AI power controls.',
                'supports_stop' => false,
                'ai_ready' => false,
            ];
        }

        $cacheKey = $this->cacheKey();
        $resolver = fn(): array => $this->resolveStatusWithDynamicFallback();
        if (!$useCache) {
            Cache::forget($cacheKey);
            $status = $resolver();
            $this->rememberRecentDynamicStatus($status);
            return $status;
        }

        $status = Cache::remember($cacheKey, $this->statusCacheSeconds(), $resolver);
        $this->rememberRecentDynamicStatus($status);
        return $status;
    }

    public function startPod(bool $allowConcurrent = false): array
    {
        if (!$allowConcurrent) {
            $active = self::activeInstanceKey(true);
            if ($active !== null && $active !== $this->instanceKey()) {
                $activeLabel = self::configuredInstances()[$active]['label'] ?? ucfirst($active);
                throw new RuntimeException('Another AI worker is already running: ' . $activeLabel . '. Stop it before starting a different worker.');
            }
        }

        if ($this->isDynamicManagedInstance() && $this->podId() === '') {
            $activePod = (new RunpodActivePodService())->getOrCreateActivePod();
            if (!is_array($activePod) || trim((string)($activePod['pod_id'] ?? '')) === '') {
                throw new RuntimeException('No GPU is available globally right now. Please try again in a few minutes.');
            }

            $this->rememberInstanceStartAttempt();
            self::touchActivity($this->instanceKey());
            return (new self($this->instanceKey()))->getStatus(false);
        }

        $this->requireConfigured();

        try {
            $this->request('POST', '/pods/' . rawurlencode($this->podId()) . '/start');
            Cache::forget($this->unavailableCacheKey());
            Cache::put($this->startAttemptCacheKey(), [
                'timestamp' => time(),
            ], self::START_ATTEMPT_TTL);
            $this->rememberInstanceStartAttempt();
            self::touchActivity($this->instanceKey());
            if ($this->isDynamicManagedInstance()) {
                (new RunpodActivePodService())->touchLastActivity($this->podId());
            }
        } catch (RuntimeException $e) {
            $message = trim($e->getMessage());
            if (stripos($message, 'not enough free gpus') !== false) {
                $this->rememberUnavailableMessage($message);
            }

            throw new RuntimeException('start pod: ' . $message);
        }

        Cache::forget($this->cacheKey());
        return $this->getStatus(false);
    }

    public function stopPod(): array
    {
        if ($this->isDynamicManagedInstance() && $this->podId() === '') {
            $this->forgetInstanceStartAttempt();
            $this->forgetRecentDynamicStatus();
            self::clearActivity($this->instanceKey());
            return $this->dynamicPlaceholderStatus();
        }

        $this->requireConfigured();
        $currentPodId = $this->podId();
        $status = $this->getStatus(false);

        if ($this->isDynamicManagedInstance() && $this->shouldTerminateDynamicPods()) {
            $this->terminateCurrentPod();
            return $this->dynamicStoppedStatus('The AI worker has been terminated, so no stopped Runpod pod is left behind.');
        }

        if (($status['supports_stop'] ?? true) !== true) {
            throw new RuntimeException('This Runpod pod cannot be stopped while a network volume is attached.');
        }

        $this->request('POST', '/pods/' . rawurlencode($this->podId()) . '/stop');
        Cache::forget($this->startAttemptCacheKey());
        Cache::forget($this->unavailableCacheKey());
        Cache::forget($this->cacheKey());
        $this->forgetInstanceStartAttempt();
        $this->forgetRecentDynamicStatus();
        self::clearActivity($this->instanceKey());
        $this->forgetWorkingAiBaseUrl($currentPodId);

        if ($this->isDynamicManagedInstance()) {
            (new RunpodActivePodService())->forgetActivePod();
            return $this->dynamicPlaceholderStatus();
        }

        return $this->getStatus(false);
    }

    /**
     * Ask Runpod to migrate this pod to a different physical host machine.
     * Useful when the current host has no free GPUs ("not enough free GPUs" error).
     * Uses the Runpod GraphQL API since the REST API has no equivalent endpoint.
     */
    public function redeployToNewMachine(): array
    {
        $this->requireConfigured();

        $apiKey = $this->apiKey();
        $podId  = $this->podId();

        $mutation = sprintf(
            'mutation { podMigrateToOtherMachine(input: {podId: "%s"}) { id } }',
            addslashes($podId)
        );

        $body = json_encode(['query' => $mutation]);
        $ch   = curl_init('https://api.runpod.io/graphql?api_key=' . urlencode($apiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => $this->shouldVerifyRunpodTls(),
            CURLOPT_SSL_VERIFYHOST => $this->shouldVerifyRunpodTls() ? 2 : 0,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = trim((string) curl_error($ch));

        // Some local Windows PHP installs miss CA bundles; retry once without verification.
        if (
            $curlError !== ''
            && $this->shouldVerifyRunpodTls()
            && $this->isTlsCertificateError($curlError)
        ) {
            curl_setopt_array($ch, [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $response   = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = trim((string) curl_error($ch));
        }

        if ($curlError !== '') {
            throw new RuntimeException('Redeploy request failed: ' . $curlError);
        }

        $decoded = is_string($response) && trim($response) !== '' ? json_decode($response, true) : null;

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid response from Runpod GraphQL API.');
        }

        if (!empty($decoded['errors'])) {
            $msg = (string)($decoded['errors'][0]['message'] ?? 'Unknown error');
            $this->rememberUnavailableMessage($msg);
            throw new RuntimeException($msg);
        }

        if (empty($decoded['data']['podMigrateToOtherMachine']['id'])) {
            throw new RuntimeException('Migration returned no pod ID. The pod may not support machine migration.');
        }

        // Clear the unavailable flag — migration is underway.
        Cache::forget($this->unavailableCacheKey());
        Cache::forget($this->startAttemptCacheKey());
        Cache::forget($this->cacheKey());
        self::touchActivity($this->instanceKey());

        return $this->getStatus(false);
    }

    public function resolveAiBaseUrl(bool $useCache = true): ?string
    {
        if (!$this->isConfigured()) {
            return $this->configuredAiBaseUrl();
        }

        $status = $this->getStatus($useCache);
        $resolved = trim((string)($status['resolved_ai_base_url'] ?? ''));
        return $resolved !== '' ? $resolved : null;
    }

    private function resolveStatus(): array
    {
        $pod = $this->request('GET', '/pods/' . rawurlencode($this->podId()));
        $desiredStatus = strtoupper(trim((string)($pod['desiredStatus'] ?? 'UNKNOWN')));
        $runtime = is_array($pod['runtime'] ?? null) ? $pod['runtime'] : [];
        $runtimePorts = is_array($runtime['ports'] ?? null) ? $runtime['ports'] : [];
        $topLevelPorts = is_array($pod['ports'] ?? null) ? $pod['ports'] : [];
        $ports = $runtimePorts !== [] ? $runtimePorts : $topLevelPorts;
        $healthResolution = $this->resolveAiHealthFromPod($pod);
        $resolvedAiBaseUrl = $healthResolution['base_url'] ?? null;
        $hasNetworkVolume = !empty($pod['networkVolumeId'])
            || !empty($pod['networkVolume'])
            || (!empty($pod['networkVolumeIds']) && is_array($pod['networkVolumeIds']));
        $lastStartedAt = trim((string)($pod['lastStartedAt'] ?? ''));
        $uptimeReference = $lastStartedAt !== ''
            ? $lastStartedAt
            : trim((string)($pod['createdAt'] ?? $pod['created_at'] ?? ''));
        $uptimeSeconds = $this->uptimeSeconds($uptimeReference, $desiredStatus);
        $idleSeconds = $this->idleSeconds();
        $idleLimit = $this->idleStopSeconds();
        $activeJobCount = $this->activeJobCount();
        $health = $healthResolution['health'] ?? [
            'ok' => false,
            'url' => null,
            'status_code' => null,
            'error' => null,
        ];
        $healthAttempts = is_array($healthResolution['attempts'] ?? null) ? $healthResolution['attempts'] : [];
        $recentStartAttempt = $this->hasRecentInstanceStartAttempt();
        $unavailableNote = Cache::get($this->unavailableCacheKey());

        $state = 'unknown';
        $label = 'Unknown';
        $message = 'Runpod returned an unexpected pod state.';

        if (in_array($desiredStatus, ['EXITED', 'STOPPED'], true)) {
            if (is_array($unavailableNote) && !empty($unavailableNote['message'])) {
                $this->forgetInstanceStartAttempt();
                $state = 'unavailable';
                $label = 'No GPU Available';
                $message = (string)$unavailableNote['message'];
            } elseif ($recentStartAttempt) {
                $state = 'starting';
                $label = 'Starting';
                $message = 'Runpod accepted the start request. Waiting for the AI worker to come online.';
            } else {
                $state = 'stopped';
                $label = 'Stopped';
                $message = 'The AI worker is off. Start it before uploading a video for AI processing.';
            }
        } elseif (in_array($desiredStatus, ['CREATED', 'STARTING', 'PROVISIONING', 'RESTARTING', 'PENDING'], true)) {
            $state = 'starting';
            $label = 'Starting';
            $message = 'The AI worker is booting up. Wait for Ready before uploading.';
        } elseif ($desiredStatus === 'RUNNING') {
            Cache::forget($this->unavailableCacheKey());
            if ($idleLimit > 0 && $activeJobCount === 0 && $idleSeconds !== null && $idleSeconds >= $idleLimit) {
                // Belt-and-suspenders before we destroy anything: redo the
                // count from scratch (no cache, treat unmapped rows as "ours")
                // and ask the AI worker itself. If anything looks busy or we
                // cannot answer with confidence, leave the pod alone.
                $safeToTerminate = $this->verifyTrulyIdleBeforeTerminate();

                try {
                    if (!$safeToTerminate) {
                        $state = 'ready';
                        $label = 'Ready';
                        $message = 'The AI worker is warm and ready to process uploads.';
                    } elseif ($this->isDynamicManagedInstance() && $this->shouldTerminateDynamicPods()) {
                        $this->terminateCurrentPod();
                        return $this->dynamicStoppedStatus('This AI worker has been idle for 30 minutes, so it was terminated automatically.');
                    } elseif (!$hasNetworkVolume) {
                        $this->request('POST', '/pods/' . rawurlencode($this->podId()) . '/stop');
                        Cache::forget($this->cacheKey());
                        $state = 'stopping';
                        $label = 'Stopping';
                        $message = 'This AI worker has been idle for 30 minutes, so it is shutting down automatically.';
                    } else {
                        $state = 'ready';
                        $label = 'Ready';
                        $message = 'The AI worker is warm and ready to process uploads.';
                    }
                } catch (\Throwable) {
                    $state = 'ready';
                    $label = 'Ready';
                    $message = 'The AI worker is warm and ready to process uploads.';
                }
            } elseif ($health['ok']) {
                Cache::forget($this->startAttemptCacheKey());
                $this->forgetInstanceStartAttempt();
                $state = 'ready';
                $label = 'Ready';
                $message = 'The AI worker is warm and ready to process uploads.';
            } else {
                $state = 'starting';
                $label = 'Warming Up';
                if ($resolvedAiBaseUrl === null || $resolvedAiBaseUrl === '') {
                    $message = 'Runpod says the pod is running, but no reachable AI endpoint is exposed yet. Check the template ports and startup command.';
                } elseif ($uptimeSeconds !== null && $uptimeSeconds >= self::WARMUP_WARNING_SECONDS) {
                    $message = 'The pod has been running for over 15 minutes without a healthy AI endpoint. Check the Runpod logs, exposed ports, and startup command.';
                } else {
                    $message = 'The pod is running, but the AI API is still starting.';
                }
            }
        } elseif (in_array($desiredStatus, ['STOPPING', 'TERMINATING'], true)) {
            $state = 'stopping';
            $label = 'Stopping';
            $message = 'The AI worker is shutting down.';
        }

        return [
            'configured' => true,
            'instance_key' => $this->instanceKey(),
            'instance_label' => $this->instanceLabel(),
            'dynamic' => $this->isDynamicManagedInstance(),
            'state' => $state,
            'label' => $label,
            'message' => $message,
            'desired_status' => $desiredStatus,
            'pod_id' => (string)($pod['id'] ?? $this->podId()),
            'pod_name' => (string)($pod['name'] ?? ''),
            'gpu_name' => $this->extractGpuName($pod),
            'cost_per_hr' => $this->extractHourlyCost($pod),
            'public_ip' => (string)($pod['publicIp'] ?? ''),
            'ports' => $ports,
            'port_mappings' => is_array($pod['portMappings'] ?? null) ? $pod['portMappings'] : [],
            'last_status_change' => (string)($pod['lastStatusChange'] ?? $pod['updatedAt'] ?? ''),
            'last_started_at' => $lastStartedAt !== '' ? $lastStartedAt : ($uptimeReference !== '' ? $uptimeReference : null),
            'uptime_seconds' => $uptimeSeconds,
            'uptime_label' => $this->humanDuration($uptimeSeconds),
            'idle_seconds' => $idleSeconds,
            'idle_limit_seconds' => $idleLimit > 0 ? $idleLimit : null,
            'active_job_count' => $activeJobCount,
            'ai_ready' => (bool)$health['ok'],
            'ai_health_url' => (string)($health['url'] ?? ''),
            'ai_health_status_code' => $health['status_code'] ?? null,
            'ai_health_error' => (string)($health['error'] ?? ''),
            'ai_health_attempts' => $healthAttempts,
            'resolved_ai_base_url' => $resolvedAiBaseUrl,
            'supports_stop' => $this->isDynamicManagedInstance() && $this->shouldTerminateDynamicPods()
                ? true
                : !$hasNetworkVolume,
            'has_network_volume' => $hasNetworkVolume,
            'manual_hourly_cost' => $this->configuredHourlyCost(),
        ];
    }

    private function resolveStatusWithDynamicFallback(): array
    {
        try {
            return $this->resolveStatus();
        } catch (RuntimeException $e) {
            $message = strtolower(trim($e->getMessage()));
            if ($this->isDynamicManagedInstance() && str_contains($message, 'http 404')) {
                if ($this->hasRecentInstanceStartAttempt()) {
                    return $this->dynamicPlaceholderStatus([
                        'state' => 'starting',
                        'label' => 'Starting',
                        'message' => 'Runpod is still provisioning the AI worker. Please wait a little longer.',
                        'desired_status' => 'PENDING',
                    ]);
                }

                (new RunpodActivePodService())->forgetActivePod();
                return $this->dynamicPlaceholderStatus();
            }

            throw $e;
        }
    }

    private static function normalizeInstanceKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_-]+/', '-', $key);
        return trim((string)$key, '-') ?: self::DEFAULT_INSTANCE_KEY;
    }

    private static function normalizeInstanceConfig(string $key, array $config): array
    {
        $label = trim((string)($config['label'] ?? $config['name'] ?? ucfirst(str_replace(['-', '_'], ' ', $key))));
        return [
            'key' => $key,
            'label' => $label !== '' ? $label : ucfirst($key),
            'api_key' => trim((string)($config['api_key'] ?? $config['apiKey'] ?? '')),
            'pod_id' => trim((string)($config['pod_id'] ?? $config['podId'] ?? '')),
            'api_base' => trim((string)($config['api_base'] ?? $config['apiBase'] ?? self::DEFAULT_API_BASE)),
            'fastapi_port' => max(1, (int)($config['fastapi_port'] ?? $config['fastApiPort'] ?? 8888)),
            'hourly_cost' => isset($config['hourly_cost']) && is_numeric($config['hourly_cost']) ? (float)$config['hourly_cost'] : null,
            'gpu_name' => trim((string)($config['gpu_name'] ?? '')),
            'dynamic' => !empty($config['dynamic']),
        ];
    }

    private function instanceConfig(): array
    {
        $instances = self::configuredInstances();
        return $instances[$this->instanceKey()] ?? $instances[self::DEFAULT_INSTANCE_KEY] ?? [];
    }

    private function instanceLabel(): string
    {
        return (string)($this->instanceConfig()['label'] ?? ucfirst($this->instanceKey()));
    }

    private function isDynamicManagedInstance(): bool
    {
        return $this->instanceKey() === RunpodActivePodService::DYNAMIC_INSTANCE_KEY;
    }

    private function dynamicPlaceholderStatus(array $overrides = []): array
    {
        $activePod = null;
        $hasActivePod = false;

        if (RunpodActivePodService::isEnabled()) {
            $activePod = (new RunpodActivePodService())->getActivePod(true);
            $hasActivePod = is_array($activePod) && trim((string)($activePod['pod_id'] ?? '')) !== '';
        }

        $recentStartAttempt = $hasActivePod && $this->hasRecentInstanceStartAttempt();

        if (!$hasActivePod) {
            $this->forgetInstanceStartAttempt();
        }

        if (!$recentStartAttempt) {
            $this->forgetRecentDynamicStatus();
        }

        return array_merge([
            'configured' => RunpodActivePodService::isEnabled(),
            'instance_key' => $this->instanceKey(),
            'instance_label' => $this->instanceLabel(),
            'dynamic' => true,
            'state' => $recentStartAttempt ? 'starting' : 'stopped',
            'label' => $recentStartAttempt ? 'Starting' : 'Stopped',
            'message' => $recentStartAttempt
                ? 'Runpod is still provisioning the AI worker. Please wait for Ready before uploading.'
                : 'The AI worker is off. Start it to rent the lowest-cost available GPU automatically.',
            'desired_status' => $recentStartAttempt ? 'PENDING' : 'STOPPED',
            'pod_id' => null,
            'pod_name' => '',
            'gpu_name' => trim((string)($this->instanceConfig()['gpu_name'] ?? '')),
            'cost_per_hr' => $this->configuredHourlyCost(),
            'public_ip' => '',
            'ports' => [],
            'port_mappings' => [],
            'last_status_change' => null,
            'last_started_at' => null,
            'uptime_seconds' => null,
            'uptime_label' => null,
            'idle_seconds' => $this->idleSeconds(),
            'idle_limit_seconds' => $this->idleStopSeconds() > 0 ? $this->idleStopSeconds() : null,
            'active_job_count' => $this->activeJobCount(),
            'ai_ready' => false,
            'ai_health_url' => '',
            'ai_health_status_code' => null,
            'ai_health_error' => '',
            'ai_health_attempts' => [],
            'resolved_ai_base_url' => null,
            'supports_stop' => false,
            'has_network_volume' => false,
            'manual_hourly_cost' => $this->configuredHourlyCost(),
        ], $overrides);
    }

    private function dynamicStoppedStatus(string $message): array
    {
        return $this->dynamicPlaceholderStatus([
            'state' => 'stopped',
            'label' => 'Stopped',
            'message' => $message,
            'desired_status' => 'STOPPED',
            'pod_id' => null,
            'pod_name' => '',
            'public_ip' => '',
            'ports' => [],
            'port_mappings' => [],
            'last_status_change' => null,
            'last_started_at' => null,
            'uptime_seconds' => null,
            'uptime_label' => null,
            'ai_ready' => false,
            'ai_health_url' => '',
            'ai_health_status_code' => null,
            'resolved_ai_base_url' => null,
            'supports_stop' => false,
            'has_network_volume' => false,
        ]);
    }

    private function shouldRefreshDynamicPodDiscovery(bool $useCache): bool
    {
        if (!$this->isDynamicManagedInstance()) {
            return false;
        }

        if (!$useCache) {
            return true;
        }

        if ($this->hasRecentInstanceStartAttempt()) {
            return true;
        }

        return self::dynamicOnlyModeEnabled();
    }

    private function extractGpuName(array $pod): string
    {
        $candidates = [
            $pod['machine']['gpuDisplayName'] ?? null,
            $pod['gpuDisplayName'] ?? null,
            $pod['gpuTypeId'] ?? null,
            $this->instanceConfig()['gpu_name'] ?? null,
        ];

        if (!empty($pod['gpuTypeIds']) && is_array($pod['gpuTypeIds'])) {
            $candidates[] = implode(', ', array_map('strval', $pod['gpuTypeIds']));
        }

        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractHourlyCost(array $pod): ?float
    {
        $candidates = [
            $pod['costPerHr'] ?? null,
            $pod['costPerHour'] ?? null,
            $pod['machine']['costPerHr'] ?? null,
            $pod['machine']['costPerHour'] ?? null,
            $pod['runtime']['costPerHr'] ?? null,
            $this->configuredHourlyCost(),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float)$candidate;
            }
        }

        return null;
    }

    private function configuredHourlyCost(): ?float
    {
        $value = $this->instanceConfig()['hourly_cost'] ?? null;
        return is_numeric($value) ? (float)$value : null;
    }

    private static function dynamicOnlyModeRequested(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_DYNAMIC_ONLY') ?: '0')));
        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    private static function dynamicOnlyModeEnabled(): bool
    {
        return self::dynamicOnlyModeRequested() && RunpodActivePodService::isEnabled();
    }

    private function shouldTerminateDynamicPods(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_TERMINATE_DYNAMIC_PODS') ?: '1')));
        return !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }

    private function terminateCurrentPod(): void
    {
        $podId = $this->podId();
        $this->request('DELETE', '/pods/' . rawurlencode($this->podId()));
        Cache::forget($this->startAttemptCacheKey());
        Cache::forget($this->unavailableCacheKey());
        Cache::forget($this->cacheKey());
        $this->forgetInstanceStartAttempt();
        $this->forgetRecentDynamicStatus();
        self::clearActivity($this->instanceKey());
        $this->forgetWorkingAiBaseUrl($podId);

        if ($this->isDynamicManagedInstance()) {
            $dynamicService = new RunpodActivePodService();
            $dynamicService->forgetActivePod();
            $dynamicService->cleanupExtraPods($podId);
        }
    }

    private function checkAiHealth(?string $base = null): array
    {
        $base = trim((string)($base ?? $this->configuredAiBaseUrl() ?? ''));
        if ($base === '') {
            return [
                'ok' => false,
                'url' => null,
                'status_code' => null,
                'error' => 'No AI base URL was available for health checks.',
            ];
        }

        $normalized = preg_replace('#/(analyze|analyze-upload|upload-video|analyze-stored|health)/?$#', '', rtrim($base, '/'));
        $normalized = is_string($normalized) ? $normalized : rtrim($base, '/');
        $endpoint = rtrim($normalized, '/') . '/health';

        $ch = curl_init();
        $verifyTls = $this->isHttpsEndpoint($endpoint) && $this->shouldVerifyTls();
        $options = array_replace($this->curlNetworkOptions(4000, 8000), [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if (!$verifyTls) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        try {
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = trim((string)curl_error($ch));

            if (
                $error !== ''
                && $verifyTls
                && $this->isTlsCertificateError($error)
            ) {
                $retryOptions = $options;
                $retryOptions[CURLOPT_SSL_VERIFYPEER] = false;
                $retryOptions[CURLOPT_SSL_VERIFYHOST] = 0;
                curl_setopt_array($ch, $retryOptions);
                $response = curl_exec($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = trim((string)curl_error($ch));
            }
        } finally {
            $this->closeCurlHandle($ch);
        }

        if ($error !== '') {
            return [
                'ok' => false,
                'url' => $endpoint,
                'status_code' => null,
                'error' => $error,
            ];
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $body = is_string($response) ? trim($response) : '';
        $plainHealthy = in_array(strtolower($body), ['ok', '"ok"', 'healthy', '"healthy"'], true);
        $ok = $statusCode >= 200 && $statusCode < 300
            && ($body === '' || is_array($decoded) || $plainHealthy);

        return [
            'ok' => $ok,
            'url' => $endpoint,
            'status_code' => $statusCode > 0 ? $statusCode : null,
            'error' => $ok ? null : ($statusCode > 0 ? 'HTTP ' . $statusCode : 'The AI endpoint returned an unhealthy response.'),
        ];
    }

    private function resolveAiBaseUrlFromPod(array $pod): ?string
    {
        $candidates = $this->candidateAiBaseUrlsFromPod($pod);
        return $candidates[0] ?? null;
    }

    private function resolveAiHealthFromPod(array $pod): array
    {
        $podId = trim((string)($pod['id'] ?? $this->podId()));
        $candidates = $this->candidateAiBaseUrlsFromPod($pod);
        $attempts = [];
        $lastFailure = [
            'ok' => false,
            'url' => null,
            'status_code' => null,
            'error' => 'No AI endpoint candidates were available.',
            'source' => null,
        ];

        foreach ($candidates as $candidate) {
            $health = $this->checkAiHealth((string)($candidate['base_url'] ?? null));
            $attempt = [
                'source' => (string)($candidate['source'] ?? 'unknown'),
                'base_url' => (string)($candidate['base_url'] ?? ''),
                'health_url' => (string)($health['url'] ?? ''),
                'ok' => (bool)($health['ok'] ?? false),
                'status_code' => $health['status_code'] ?? null,
                'error' => (string)($health['error'] ?? ''),
            ];
            $attempts[] = $attempt;

            if ($health['ok']) {
                $this->rememberWorkingAiBaseUrl($podId, (string)($candidate['base_url'] ?? ''));
                return [
                    'base_url' => (string)($candidate['base_url'] ?? ''),
                    'health' => $health,
                    'attempts' => $attempts,
                ];
            }

            $lastFailure = array_merge($health, [
                'source' => (string)($candidate['source'] ?? 'unknown'),
            ]);
        }

        return [
            'base_url' => isset($candidates[0]['base_url']) ? (string)$candidates[0]['base_url'] : null,
            'health' => $lastFailure,
            'attempts' => $attempts,
        ];
    }

    private function candidateAiBaseUrlsFromPod(array $pod): array
    {
        $podId = trim((string)($pod['id'] ?? $this->podId()));
        $candidates = [];

        $remembered = $this->workingAiBaseUrl($podId);
        if ($remembered !== null) {
            $candidates[] = [
                'source' => 'remembered',
                'base_url' => $remembered,
            ];
        }

        $proxy = $this->proxyAiBaseUrlFromPod($pod);

        if ($proxy !== null) {
            $candidates[] = [
                'source' => 'proxy',
                'base_url' => $proxy,
            ];
        }

        $configured = $this->configuredAiBaseUrl();
        if ($configured !== null) {
            $candidates[] = [
                'source' => 'configured',
                'base_url' => $configured,
            ];
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $value = trim((string)($candidate['base_url'] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalized[$value] = [
                'source' => (string)($candidate['source'] ?? 'unknown'),
                'base_url' => $value,
            ];
        }

        return array_values($normalized);
    }

    private function proxyAiBaseUrlFromPod(array $pod): ?string
    {
        $podId = trim((string)($pod['id'] ?? ''));
        $internalPort = (string)$this->fastApiInternalPort();
        if ($podId !== '' && $this->podExposesHttpProxyPort($pod, $internalPort)) {
            return sprintf('https://%s-%s.proxy.runpod.net', $podId, $internalPort);
        }

        return null;
    }

    private function extractExternalPortFromPod(array $pod, string $internalPort): ?int
    {
        $portMappings = $pod['portMappings'] ?? null;
        if (!is_array($portMappings)) {
            return null;
        }

        $direct = $portMappings[$internalPort] ?? $portMappings[(int)$internalPort] ?? null;
        $normalizedDirect = $this->normalizePortNumber($direct);
        if ($normalizedDirect !== null) {
            return $normalizedDirect;
        }

        foreach ($portMappings as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $privatePort = trim((string)(
                $entry['privatePort']
                ?? $entry['private_port']
                ?? $entry['internalPort']
                ?? $entry['internal_port']
                ?? $key
            ));

            if ($privatePort !== $internalPort) {
                continue;
            }

            foreach ([
                $entry['publicPort'] ?? null,
                $entry['public_port'] ?? null,
                $entry['externalPort'] ?? null,
                $entry['external_port'] ?? null,
                $entry['hostPort'] ?? null,
                $entry['host_port'] ?? null,
                $entry['proxyPort'] ?? null,
                $entry['port'] ?? null,
            ] as $candidate) {
                $normalizedPort = $this->normalizePortNumber($candidate);
                if ($normalizedPort !== null) {
                    return $normalizedPort;
                }
            }
        }

        return null;
    }

    private function podExposesHttpProxyPort(array $pod, string $internalPort): bool
    {
        foreach (['ports', 'runtime'] as $key) {
            $candidate = $key === 'runtime'
                ? (is_array($pod['runtime'] ?? null) ? ($pod['runtime']['ports'] ?? []) : [])
                : ($pod['ports'] ?? []);

            if (!is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $entry) {
                if ($this->portEntrySupportsHttpProxy($entry, $internalPort)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function portEntrySupportsHttpProxy(mixed $entry, string $internalPort): bool
    {
        if (is_string($entry)) {
            $normalized = strtolower(trim($entry));
            return $normalized === strtolower($internalPort . '/http')
                || $normalized === strtolower($internalPort . '/https');
        }

        if (!is_array($entry)) {
            return false;
        }

        $privatePort = trim((string)(
            $entry['privatePort']
            ?? $entry['private_port']
            ?? $entry['internalPort']
            ?? $entry['internal_port']
            ?? $entry['containerPort']
            ?? $entry['port']
            ?? ''
        ));

        if ($privatePort !== $internalPort) {
            return false;
        }

        foreach ([
            $entry['type'] ?? null,
            $entry['protocol'] ?? null,
            $entry['portType'] ?? null,
            $entry['exposedPortType'] ?? null,
            !empty($entry['http']) ? 'http' : null,
            !empty($entry['https']) ? 'https' : null,
            !empty($entry['isHttp']) ? 'http' : null,
            !empty($entry['isHttps']) ? 'https' : null,
        ] as $candidateType) {
            $normalizedType = strtolower(trim((string)$candidateType));
            if (in_array($normalizedType, ['http', 'https'], true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePortNumber(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $port = (int)$value;
            return $port > 0 ? $port : null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/\b(\d{1,5})\b/', $raw, $matches) !== 1) {
            return null;
        }

        $port = (int)$matches[1];
        return $port > 0 ? $port : null;
    }

    private function configuredAiBaseUrl(): ?string
    {
        return $this->normalizeProxyAiBaseUrl(getenv('NUTMEG_AI_FASTAPI_URL') ?: null);
    }

    private function normalizeProxyAiBaseUrl(?string $baseUrl): ?string
    {
        $base = trim((string)$baseUrl);
        if ($base === '') {
            return null;
        }

        $normalized = preg_replace('#/(analyze|analyze-url|analyze-upload|upload-video|analyze-stored|health)/?$#', '', rtrim($base, '/'));
        $normalized = is_string($normalized) ? rtrim($normalized, '/') : rtrim($base, '/');
        if (!preg_match('#^https://[A-Za-z0-9.-]+\.proxy\.runpod\.net(?:/.*)?$#i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function request(string $method, string $path): array
    {
        $this->requireConfigured();

        $endpoint = rtrim($this->apiBase(), '/') . $path;
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $this->apiKey(),
            'Accept: application/json',
        ];
        $verifyTls = $this->shouldVerifyRunpodTls();
        $connectTimeoutMs = $this->connectTimeoutMs();
        $requestTimeoutMs = $this->requestTimeoutMs();

        $options = array_replace($this->curlNetworkOptions($connectTimeoutMs, $requestTimeoutMs), [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($this->isHttpsEndpoint($endpoint) && !$verifyTls) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        try {
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = trim((string)curl_error($ch));

            // Some local Windows PHP installs miss CA bundles; retry once without verification.
            if (
                $error !== ''
                && $verifyTls
                && $this->isHttpsEndpoint($endpoint)
                && $this->isTlsCertificateError($error)
            ) {
                $retryOptions = $options;
                $retryTimeoutMs = min($requestTimeoutMs, 15000);
                $retryOptions[CURLOPT_TIMEOUT] = $this->secondsFromMilliseconds($retryTimeoutMs);
                $retryOptions[CURLOPT_TIMEOUT_MS] = $retryTimeoutMs;
                $retryOptions[CURLOPT_LOW_SPEED_TIME] = $this->secondsFromMilliseconds($retryTimeoutMs);
                $retryOptions[CURLOPT_SSL_VERIFYPEER] = false;
                $retryOptions[CURLOPT_SSL_VERIFYHOST] = 0;
                curl_setopt_array($ch, $retryOptions);
                $response = curl_exec($ch);
                $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = trim((string)curl_error($ch));
            }
        } finally {
            $this->closeCurlHandle($ch);
        }

        if ($error !== '') {
            throw new RuntimeException('Runpod request failed: ' . $error);
        }

        $decoded = is_string($response) && trim($response) !== ''
            ? json_decode($response, true)
            : [];

        if ($statusCode >= 400) {
            $message = null;
            if (is_array($decoded)) {
                $message = $decoded['error'] ?? $decoded['message'] ?? $decoded['detail'] ?? null;
            }

            $resolved = is_string($message) && $message !== '' ? $message : 'Runpod API request failed.';
            throw new RuntimeException(sprintf('HTTP %d: %s', $statusCode, $resolved));
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function requireConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Runpod API key and pod ID must be configured first.');
        }
    }

    private function apiBase(): string
    {
        $configured = trim((string)($this->instanceConfig()['api_base'] ?? ''));
        return $configured !== '' ? $configured : self::DEFAULT_API_BASE;
    }

    private function apiKey(): string
    {
        return trim((string)($this->instanceConfig()['api_key'] ?? ''));
    }

    private function podId(): string
    {
        return trim((string)($this->instanceConfig()['pod_id'] ?? ''));
    }

    private function statusCacheSeconds(): int
    {
        $value = (int)(getenv('NUTMEG_RUNPOD_STATUS_CACHE_SECONDS') ?: 0);
        return $value > 0 ? $value : 5;
    }

    private function fastApiInternalPort(): int
    {
        $value = (int)($this->instanceConfig()['fastapi_port'] ?? 0);
        return $value > 0 ? $value : 8888;
    }

    private function shouldVerifyTls(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_AI_FASTAPI_VERIFY_SSL') ?: '1')));
        return !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }

    private function shouldVerifyRunpodTls(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_VERIFY_SSL') ?: getenv('NUTMEG_AI_FASTAPI_VERIFY_SSL') ?: '1')));
        return !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }

    private function isTlsCertificateError(string $error): bool
    {
        $normalized = strtolower(trim($error));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'unable to get local issuer certificate')
            || str_contains($normalized, 'certificate verify failed')
            || str_contains($normalized, 'self signed certificate')
            || str_contains($normalized, 'ssl certificate problem');
    }

    private function isHttpsEndpoint(string $endpoint): bool
    {
        return str_starts_with(strtolower($endpoint), 'https://');
    }

    private function curlNetworkOptions(int $connectTimeoutMs, int $requestTimeoutMs): array
    {
        $options = [
            CURLOPT_CONNECTTIMEOUT => $this->secondsFromMilliseconds($connectTimeoutMs),
            CURLOPT_TIMEOUT => $this->secondsFromMilliseconds($requestTimeoutMs),
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $requestTimeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $this->secondsFromMilliseconds($requestTimeoutMs),
        ];

        if (defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }

        return $options;
    }

    private function connectTimeoutMs(): int
    {
        $value = (int)(getenv('NUTMEG_RUNPOD_CONNECT_TIMEOUT_MS') ?: 10000);
        return $value > 0 ? $value : 10000;
    }

    private function requestTimeoutMs(): int
    {
        $value = (int)(getenv('NUTMEG_RUNPOD_TIMEOUT_MS') ?: 30000);
        return $value > 0 ? $value : 30000;
    }

    private function secondsFromMilliseconds(int $milliseconds): int
    {
        return max(1, (int)ceil($milliseconds / 1000));
    }

    private function closeCurlHandle(\CurlHandle $handle): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($handle);
        }
    }

    private function cacheKey(): string
    {
        return 'runpod:pod-status:' . $this->instanceKey() . ':' . $this->podId();
    }

    private function workingAiBaseUrlCacheKey(string $podId): string
    {
        return 'runpod:working-ai-base-url:' . $this->instanceKey() . ':' . trim($podId);
    }

    private function unavailableCacheKey(): string
    {
        return 'runpod:pod-unavailable:' . $this->instanceKey() . ':' . $this->podId();
    }

    private function startAttemptCacheKey(): string
    {
        return 'runpod:pod-start-attempt:' . $this->instanceKey() . ':' . $this->podId();
    }

    private function instanceStartAttemptCacheKey(): string
    {
        return 'runpod:pod-start-attempt-instance:' . $this->instanceKey();
    }

    private static function activityCacheKeyFor(string $instanceKey): string
    {
        return 'runpod:pod-activity:' . self::normalizeInstanceKey($instanceKey);
    }

    private static function matchInstanceCacheKey(int $matchId): string
    {
        return 'runpod:match-instance:' . $matchId;
    }

    private function rememberUnavailableMessage(string $rawMessage): void
    {
        Cache::put($this->unavailableCacheKey(), [
            'message' => 'No GPU is available for this AI worker right now. Choose another worker or retry later.',
            'raw_message' => $rawMessage,
        ], self::UNAVAILABLE_TTL);
        $this->forgetInstanceStartAttempt();
        $this->forgetRecentDynamicStatus();
    }

    private function rememberWorkingAiBaseUrl(string $podId, string $baseUrl): void
    {
        $podId = trim($podId);
        $baseUrl = $this->normalizeProxyAiBaseUrl($baseUrl) ?? '';
        if ($podId === '' || $baseUrl === '') {
            return;
        }

        Cache::put($this->workingAiBaseUrlCacheKey($podId), $baseUrl, self::WORKING_AI_BASE_URL_TTL);
    }

    private function workingAiBaseUrl(string $podId): ?string
    {
        $podId = trim($podId);
        if ($podId === '') {
            return null;
        }

        $value = Cache::get($this->workingAiBaseUrlCacheKey($podId));
        return is_string($value) ? $this->normalizeProxyAiBaseUrl($value) : null;
    }

    private function forgetWorkingAiBaseUrl(string $podId): void
    {
        $podId = trim($podId);
        if ($podId === '') {
            return;
        }

        Cache::forget($this->workingAiBaseUrlCacheKey($podId));
    }

    private function rememberInstanceStartAttempt(): void
    {
        Cache::put($this->instanceStartAttemptCacheKey(), [
            'timestamp' => time(),
        ], self::INSTANCE_START_ATTEMPT_TTL);
    }

    private function forgetInstanceStartAttempt(): void
    {
        Cache::forget($this->instanceStartAttemptCacheKey());
    }

    private function hasRecentInstanceStartAttempt(): bool
    {
        $attempt = Cache::get($this->instanceStartAttemptCacheKey());
        return is_array($attempt) && !empty($attempt['timestamp']);
    }

    private function rememberRecentDynamicStatus(array $status): void
    {
        if (!$this->isDynamicManagedInstance()) {
            return;
        }

        $state = strtolower((string)($status['state'] ?? ''));
        if (!in_array($state, ['ready', 'starting', 'stopping'], true)) {
            if ($state === 'stopped' || $state === 'unavailable' || $state === 'unconfigured' || $state === 'error') {
                $this->forgetRecentDynamicStatus();
            }
            return;
        }

        Cache::put($this->recentDynamicStatusCacheKey(), [
            'timestamp' => time(),
            'status' => $status,
        ], self::RECENT_DYNAMIC_STATUS_TTL);
    }

    private function forgetRecentDynamicStatus(): void
    {
        Cache::forget($this->recentDynamicStatusCacheKey());
    }

    private function recentDynamicStatus(): ?array
    {
        $cached = Cache::get($this->recentDynamicStatusCacheKey());
        if (!is_array($cached)) {
            return null;
        }

        $status = $cached['status'] ?? null;
        return is_array($status) ? $status : null;
    }

    private static function isNoGpuErrorMessage(string $message): bool
    {
        $raw = strtolower(trim($message));
        if ($raw === '') {
            return false;
        }

        return str_contains($raw, 'not enough free gpus')
            || str_contains($raw, 'no gpu is available');
    }

    private function idleSeconds(): ?int
    {
        $activity = Cache::get(self::activityCacheKeyFor($this->instanceKey()));
        if (!is_array($activity) || empty($activity['timestamp'])) {
            return null;
        }

        return max(0, time() - (int)$activity['timestamp']);
    }

    private function idleStopSeconds(): int
    {
        $value = (int)(getenv('NUTMEG_RUNPOD_IDLE_STOP_SECONDS') ?: 1800);
        return $value > 0 ? $value : 0;
    }

    /**
     * Cached, best-effort count of jobs that look like ours. Used purely for
     * UX/telemetry — never trusted as the source of truth for terminate
     * decisions. Returns 0 on outright failure for backwards compatibility;
     * callers that *must* not over-count silently should use
     * verifyTrulyIdleBeforeTerminate() instead.
     */
    private function activeJobCount(): int
    {
        $cacheKey = $this->activeJobCountCacheKey();
        $cached = Cache::get($cacheKey);
        if (is_numeric($cached)) {
            return (int)$cached;
        }

        try {
            $count = $this->countActiveJobsFromDb(strict: false);
        } catch (\Throwable $e) {
            error_log('Runpod active job count failed: ' . $e->getMessage());
            return 0;
        }

        Cache::put($cacheKey, $count, self::ACTIVE_JOB_COUNT_TTL);
        return $count;
    }

    /**
     * Honest DB scan of queued/processing rows for this instance.
     *
     * @param bool $strict When true, rows whose remembered instance is unknown
     *                    (e.g. cache evicted) are treated as belonging to this
     *                    instance — biasing toward NOT terminating in doubt.
     * @throws \Throwable so the caller can refuse to terminate on data-layer
     *                  failure rather than mis-counting as zero.
     */
    private function countActiveJobsFromDb(bool $strict): int
    {
        $count = 0;
        $analysis = new MatchVideoAnalysisService();
        // In strict mode use the throwing variant so a Supabase outage causes
        // the caller to refuse to terminate, instead of silently seeing zero.
        $listMethod = $strict ? 'listByStatusStrict' : 'listByStatus';
        foreach (['queued', 'processing'] as $status) {
            foreach ($analysis->{$listMethod}($status, 50) as $row) {
                $matchId = (int)($row['match_id'] ?? 0);
                if ($matchId <= 0) {
                    continue;
                }

                $remembered = self::instanceForMatch($matchId);
                if ($remembered === $this->instanceKey()) {
                    $count++;
                    continue;
                }

                if ($strict && $remembered === null) {
                    // Match's instance assignment expired from cache (24h TTL).
                    // We cannot prove this row belongs to a *different* pod, so
                    // err toward keeping the pod alive.
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Final-gate verification: only return true when we are confident there is
     * nothing in flight that this pod might be servicing. Any uncertainty -
     * Supabase blip, AI worker unreachable, unmapped processing rows - returns
     * false so the caller declines to terminate.
     *
     * The status endpoint is polled by the UI every few seconds. Without the
     * cooldown below, an unreachable worker produced ~5 declines/sec into the
     * error log (each poll re-asks the worker, fails, logs, returns false).
     * We now remember the decline outcome for 30s — short-circuit the worker
     * call, suppress the duplicate log line.
     */
    private const IDLE_GATE_COOLDOWN_SECONDS = 30;

    private function verifyTrulyIdleBeforeTerminate(): bool
    {
        // Short-circuit: if a recent decline is still in cooldown, repeat its
        // decision silently. We log a one-line summary on entry/exit only.
        $cooldown = Cache::get($this->idleGateCooldownKey());
        if (is_array($cooldown) && (int)($cooldown['until'] ?? 0) > time()) {
            return false;
        }

        // 1. Authoritative DB recheck (no cache, strict counting).
        try {
            if ($this->countActiveJobsFromDb(strict: true) > 0) {
                $this->latchIdleGateDecline('db-busy', '[idle-gate] %s: declining terminate, DB shows queued/processing rows.');
                return false;
            }
        } catch (\Throwable $e) {
            $this->latchIdleGateDecline(
                'db-unavailable',
                sprintf('[idle-gate] %%s: declining terminate, DB unavailable: %s', $e->getMessage())
            );
            return false;
        }

        // 2. Ask the AI worker itself. If it reports any active subprocess we
        //    must not terminate - even if the DB row hasn't transitioned yet.
        $workerActive = $this->aiWorkerReportsActiveJobs();
        if ($workerActive === true) {
            $this->latchIdleGateDecline('worker-busy', '[idle-gate] %s: declining terminate, AI worker reports active jobs.');
            return false;
        }
        if ($workerActive === null) {
            // Worker unreachable. We *might* be terminating something live.
            // Be safe and leave it; next sweep will retry.
            $this->latchIdleGateDecline(
                'worker-unreachable',
                '[idle-gate] %s: declining terminate, AI worker unreachable for active-job check. Suppressing duplicate logs for ' . self::IDLE_GATE_COOLDOWN_SECONDS . 's.'
            );
            return false;
        }

        // Idle confirmed — clear any leftover cooldown so the next "unreachable"
        // event logs fresh instead of staying silent.
        Cache::forget($this->idleGateCooldownKey());
        return true;
    }

    private function idleGateCooldownKey(): string
    {
        return 'runpod:idle-gate-cooldown:' . $this->instanceKey();
    }

    /** Log once, then suppress duplicate logs for IDLE_GATE_COOLDOWN_SECONDS. */
    private function latchIdleGateDecline(string $reason, string $logFormat): void
    {
        Cache::put($this->idleGateCooldownKey(), [
            'until' => time() + self::IDLE_GATE_COOLDOWN_SECONDS,
            'reason' => $reason,
        ], self::IDLE_GATE_COOLDOWN_SECONDS);
        error_log(sprintf($logFormat, $this->instanceKey()));
    }

    /**
     * Returns true if the worker reports running analysis subprocesses, false
     * if it reports idle, null if we cannot reach it / cannot trust the answer.
     */
    private function aiWorkerReportsActiveJobs(): ?bool
    {
        try {
            $base = $this->resolveAiBaseUrl(true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($base) || $base === '') {
            return null;
        }

        $url = rtrim($base, '/') . '/jobs/active';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($error !== '' || $httpCode === 0) {
            return null;
        }

        // 404 is treated as "endpoint not deployed yet" -> unknown, refuse to terminate.
        if ($httpCode === 404) {
            return null;
        }
        if ($httpCode >= 400) {
            return null;
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            return null;
        }

        if (array_key_exists('busy', $decoded)) {
            return (bool)$decoded['busy'];
        }
        if (array_key_exists('active_jobs', $decoded)) {
            return (int)$decoded['active_jobs'] > 0;
        }

        return null;
    }

    private function activeJobCountCacheKey(): string
    {
        return 'runpod:pod-active-jobs:' . $this->instanceKey();
    }

    private function recentDynamicStatusCacheKey(): string
    {
        return 'runpod:dynamic-recent-status:' . $this->instanceKey();
    }

    private function uptimeSeconds(string $lastStartedAt, string $desiredStatus): ?int
    {
        if ($lastStartedAt === '' || !in_array($desiredStatus, ['RUNNING', 'STARTING', 'PROVISIONING', 'PENDING', 'RESTARTING'], true)) {
            return null;
        }

        $started = strtotime($lastStartedAt);
        if ($started === false) {
            return null;
        }

        return max(0, time() - $started);
    }

    private function humanDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            $remaining = $seconds % 60;
            return $remaining > 0 ? sprintf('%dm %02ds', $minutes, $remaining) : sprintf('%dm', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        return $remainingMinutes > 0 ? sprintf('%dh %02dm', $hours, $remainingMinutes) : sprintf('%dh', $hours);
    }
}

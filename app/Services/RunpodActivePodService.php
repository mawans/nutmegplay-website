<?php
namespace App\Services;

use App\Core\Cache;

/**
 * Keeps track of the currently active dynamically-provisioned Runpod worker.
 *
 * This service is intentionally cache-first so the app can work even when the
 * optional database migration for pod tracking has not been applied yet.
 */
class RunpodActivePodService
{
    public const DYNAMIC_INSTANCE_KEY = 'global-cheapest';

    private const ACTIVE_POD_CACHE_KEY = 'nutmeg:runpod:active-dynamic-pod';
    private const CACHE_TTL = 2592000; // 30 days

    public static function isEnabled(): bool
    {
        if (!self::autoProvisionRequested()) {
            return false;
        }

        if (trim((string)(getenv('NUTMEG_RUNPOD_API_KEY') ?: '')) === '') {
            return false;
        }

        if (self::templateConfigured()) {
            return true;
        }

        return is_array(Cache::get(self::ACTIVE_POD_CACHE_KEY));
    }

    public static function dynamicLabel(): string
    {
        $label = trim((string)(getenv('NUTMEG_RUNPOD_DYNAMIC_LABEL') ?: 'Global Cheapest AI Worker'));
        return $label !== '' ? $label : 'Global Cheapest AI Worker';
    }

    public function getActivePod(bool $refresh = false): ?array
    {
        $activePod = Cache::get(self::ACTIVE_POD_CACHE_KEY);
        if (!is_array($activePod) || trim((string)($activePod['pod_id'] ?? '')) === '') {
            // Keep normal page loads cache-first. Discovering pods can block the
            // entire video-upload screen when Runpod is slow, so only do it for
            // explicit refresh/provisioning flows.
            if (!$refresh) {
                return null;
            }

            $discovered = $this->discoverExistingPod();
            if (!is_array($discovered)) {
                return null;
            }

            $this->rememberActivePod($discovered);
            return $discovered;
        }

        if (!$refresh) {
            return $activePod;
        }

        try {
            $refreshed = $this->refreshPodRecord($activePod);
        } catch (\Throwable $e) {
            error_log('Failed to refresh dynamic Runpod pod: ' . $e->getMessage());
            return $activePod;
        }

        if ($refreshed === null) {
            $replacement = $this->discoverExistingPod((string)($activePod['pod_id'] ?? ''));
            if (is_array($replacement)) {
                $this->rememberActivePod($replacement);
                return $replacement;
            }

            $this->forgetActivePod();
            return null;
        }

        $this->rememberActivePod($refreshed);
        return $refreshed;
    }

    public function getOrCreateActivePod(): ?array
    {
        $activePod = $this->getActivePod(true);
        if (is_array($activePod) && trim((string)($activePod['pod_id'] ?? '')) !== '') {
            $this->cleanupExtraPods((string)($activePod['pod_id'] ?? ''));
            return $activePod;
        }

        try {
            $marketplace = new RunpodGpuMarketplaceService();
            $marketplace->cleanupNutmegPods();
            $gpuOptions = $marketplace->findAvailableGpuOptions();
            if ($gpuOptions === []) {
                error_log('No available GPUs found on marketplace');
                return null;
            }

            $selectedGpu = null;
            $newPodId = null;
            $maxAttempts = max(1, (int)(getenv('NUTMEG_RUNPOD_MAX_PROVISION_ATTEMPTS') ?: 3));
            $attempts = 0;

            // Let Runpod choose from all compatible types using its live
            // machine availability. Marketplace stock labels can lag behind
            // actual capacity and produce placeholders when types are tried
            // one at a time.
            $candidateGpuIds = array_values(array_filter(array_map(
                static fn(array $option): string => trim((string)($option['gpuTypeId'] ?? '')),
                array_filter($gpuOptions, 'is_array')
            )));
            if ($candidateGpuIds !== []) {
                $candidatePodId = $marketplace->rentGpuPod($candidateGpuIds, 1);
                if (is_string($candidatePodId) && trim($candidatePodId) !== '') {
                    $allocatedPod = $marketplace->waitForPodAllocation($candidatePodId);
                    if (is_array($allocatedPod)) {
                        $selectedGpu = $gpuOptions[0];
                        $newPodId = $candidatePodId;
                    } else {
                        error_log('Runpod created grouped placeholder pod without a machine; terminating ' . $candidatePodId);
                        $marketplace->terminatePod($candidatePodId);
                    }
                }
            }

            foreach ($gpuOptions as $gpuOption) {
                if ($newPodId !== null) {
                    break;
                }
                if (!is_array($gpuOption) || trim((string)($gpuOption['gpuTypeId'] ?? '')) === '') {
                    continue;
                }
                if ($attempts >= $maxAttempts) {
                    break;
                }
                $attempts++;

                error_log(
                    'Attempting cheapest GPU option: '
                    . ($gpuOption['gpuName'] ?? 'Unknown')
                    . ' @ $'
                    . ($gpuOption['pricePerHour'] ?? 'n/a')
                    . '/hr'
                );

                $candidatePodId = $marketplace->rentGpuPod((string)$gpuOption['gpuTypeId'], 1);
                if (!is_string($candidatePodId) || trim($candidatePodId) === '') {
                    continue;
                }

                $allocatedPod = $marketplace->waitForPodAllocation($candidatePodId);
                if (!is_array($allocatedPod)) {
                    error_log('Runpod created placeholder pod without a machine; terminating ' . $candidatePodId);
                    $marketplace->terminatePod($candidatePodId);
                    continue;
                }

                $selectedGpu = $gpuOption;
                $newPodId = $candidatePodId;
                break;
            }

            if (!is_array($selectedGpu) || !is_string($newPodId) || trim($newPodId) === '') {
                error_log('Marketplace reported GPUs, but none could be provisioned successfully.');
                return null;
            }

            $podDetails = $marketplace->getPodDetails($newPodId) ?? [];
            $record = [
                'instance_key' => self::DYNAMIC_INSTANCE_KEY,
                'instance_label' => self::dynamicLabel(),
                'pod_id' => $newPodId,
                'api_key' => trim((string)(getenv('NUTMEG_RUNPOD_API_KEY') ?: '')),
                'api_base' => trim((string)(getenv('NUTMEG_RUNPOD_API_BASE') ?: 'https://rest.runpod.io/v1')),
                'fastapi_port' => (int)(getenv('NUTMEG_RUNPOD_FASTAPI_PORT') ?: 8888),
                'gpu_name' => trim((string)($podDetails['gpuName'] ?? ($selectedGpu['gpuName'] ?? 'Unknown'))),
                'hourly_cost' => is_numeric($podDetails['pricePerHour'] ?? null)
                    ? (float)$podDetails['pricePerHour']
                    : (is_numeric($selectedGpu['pricePerHour'] ?? null) ? (float)$selectedGpu['pricePerHour'] : null),
                'gpu_type_id' => trim((string)($selectedGpu['gpuTypeId'] ?? '')),
                'rented_at' => gmdate('c'),
                'last_activity' => gmdate('c'),
                'provision_source' => 'marketplace',
            ];

            $this->rememberActivePod($record);
            $this->cleanupExtraPods($newPodId);
            return $record;
        } catch (\Throwable $e) {
            error_log('Failed to rent new pod: ' . $e->getMessage());
            return null;
        }
    }

    public function activeInstanceConfig(bool $refresh = false): ?array
    {
        $activePod = $this->getActivePod($refresh);
        if (!is_array($activePod)) {
            return null;
        }

        return [
            'key' => self::DYNAMIC_INSTANCE_KEY,
            'label' => self::dynamicLabel(),
            'api_key' => trim((string)($activePod['api_key'] ?? getenv('NUTMEG_RUNPOD_API_KEY') ?: '')),
            'pod_id' => trim((string)($activePod['pod_id'] ?? '')),
            'api_base' => trim((string)($activePod['api_base'] ?? getenv('NUTMEG_RUNPOD_API_BASE') ?: 'https://rest.runpod.io/v1')),
            'fastapi_port' => max(1, (int)($activePod['fastapi_port'] ?? getenv('NUTMEG_RUNPOD_FASTAPI_PORT') ?: 8888)),
            'hourly_cost' => is_numeric($activePod['hourly_cost'] ?? null) ? (float)$activePod['hourly_cost'] : null,
            'gpu_name' => trim((string)($activePod['gpu_name'] ?? '')),
            'dynamic' => true,
        ];
    }

    public function touchLastActivity(?string $podId = null): void
    {
        $activePod = $this->getActivePod();
        if (!is_array($activePod)) {
            return;
        }

        if ($podId !== null && trim($podId) !== '' && trim((string)($activePod['pod_id'] ?? '')) !== trim($podId)) {
            return;
        }

        $activePod['last_activity'] = gmdate('c');
        $this->rememberActivePod($activePod);
    }

    public function forgetActivePod(): void
    {
        Cache::forget(self::ACTIVE_POD_CACHE_KEY);
        RunpodPodService::clearActivity(self::DYNAMIC_INSTANCE_KEY);
    }

    public function cleanupExtraPods(?string $keepPodId = null): void
    {
        try {
            (new RunpodGpuMarketplaceService())->cleanupNutmegPods($keepPodId);
        } catch (\Throwable $e) {
            error_log('Failed to clean up extra Nutmeg Runpod pods: ' . $e->getMessage());
        }
    }

    public function getCostSummary(): array
    {
        $activePod = $this->getActivePod();
        if (!is_array($activePod)) {
            return ['status' => 'error', 'message' => 'No active GPU pod'];
        }

        $hourlyRate = is_numeric($activePod['hourly_cost'] ?? null) ? (float)$activePod['hourly_cost'] : 0.0;
        $rentedAtRaw = trim((string)($activePod['rented_at'] ?? ''));
        $rentedAt = $rentedAtRaw !== '' ? strtotime($rentedAtRaw) : false;
        $hoursElapsed = $rentedAt !== false ? max(1, (int)ceil((time() - $rentedAt) / 3600)) : 1;

        return [
            'status' => 'active',
            'gpu' => trim((string)($activePod['gpu_name'] ?? 'Unknown')),
            'hourly_rate' => $hourlyRate,
            'hours_rented' => $hoursElapsed,
            'estimated_cost' => $hourlyRate * $hoursElapsed,
            'pod_id' => trim((string)($activePod['pod_id'] ?? '')),
        ];
    }

    private static function autoProvisionRequested(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_AUTO_PROVISION') ?: '0')));
        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    private static function templateConfigured(): bool
    {
        return trim((string)(getenv('NUTMEG_RUNPOD_TEMPLATE_ID') ?: '')) !== ''
            || trim((string)(getenv('NUTMEG_RUNPOD_IMAGE_NAME') ?: '')) !== '';
    }

    private function rememberActivePod(array $record): void
    {
        Cache::put(self::ACTIVE_POD_CACHE_KEY, $record, self::CACHE_TTL);
        RunpodPodService::ensureActivity(
            self::DYNAMIC_INSTANCE_KEY,
            is_string($record['last_activity'] ?? null) ? $record['last_activity'] : null
        );
    }

    private function refreshPodRecord(array $activePod): ?array
    {
        $podId = trim((string)($activePod['pod_id'] ?? ''));
        $apiKey = trim((string)($activePod['api_key'] ?? getenv('NUTMEG_RUNPOD_API_KEY') ?: ''));
        $apiBase = trim((string)($activePod['api_base'] ?? getenv('NUTMEG_RUNPOD_API_BASE') ?: 'https://rest.runpod.io/v1'));
        if ($podId === '' || $apiKey === '') {
            return null;
        }

        $endpoint = rtrim($apiBase, '/') . '/pods/' . rawurlencode($podId);
        $ch = curl_init($endpoint);
        $connectTimeoutMs = $this->connectTimeoutMs();
        $requestTimeoutMs = $this->requestTimeoutMs();
        $options = array_replace($this->curlNetworkOptions($connectTimeoutMs, $requestTimeoutMs), [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);

        $verifyTls = $this->shouldVerifyRunpodTls();
        if (!$verifyTls) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        try {
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = trim((string)curl_error($ch));

            if (
                $error !== ''
                && $verifyTls
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
            throw new \RuntimeException('Runpod request failed: ' . $error);
        }

        $decoded = is_string($response) && trim($response) !== '' ? json_decode($response, true) : [];
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from Runpod API');
        }

        if ($statusCode === 404) {
            return null;
        }

        if ($statusCode >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? $decoded['detail'] ?? 'Runpod API request failed.';
            throw new \RuntimeException(sprintf('HTTP %d: %s', $statusCode, $message));
        }

        $activePod['gpu_name'] = trim((string)(
            $decoded['machine']['gpuDisplayName']
            ?? $decoded['gpuDisplayName']
            ?? $activePod['gpu_name']
            ?? ''
        ));

        foreach ([
            $decoded['costPerHr'] ?? null,
            $decoded['costPerHour'] ?? null,
            $decoded['machine']['costPerHr'] ?? null,
            $decoded['machine']['costPerHour'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate)) {
                $activePod['hourly_cost'] = (float)$candidate;
                break;
            }
        }

        $activePod['last_seen_at'] = gmdate('c');
        return $activePod;
    }

    private function discoverExistingPod(?string $excludePodId = null): ?array
    {
        try {
            $marketplace = new RunpodGpuMarketplaceService();
            $candidates = [];

            foreach ($marketplace->listPods() as $pod) {
                if (!$this->shouldTrackDiscoveredPod($pod, $excludePodId)) {
                    continue;
                }

                $candidates[] = $pod;
            }

            if ($candidates === []) {
                return null;
            }

            usort($candidates, function (array $left, array $right): int {
                $rankCompare = $this->podLifecycleRank($right) <=> $this->podLifecycleRank($left);
                if ($rankCompare !== 0) {
                    return $rankCompare;
                }

                $timeCompare = $this->podSortTimestamp($right) <=> $this->podSortTimestamp($left);
                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                return strcmp(
                    trim((string)($right['id'] ?? '')),
                    trim((string)($left['id'] ?? ''))
                );
            });

            return $this->buildDiscoveredPodRecord($candidates[0], $marketplace);
        } catch (\Throwable $e) {
            error_log('Failed to discover dynamic Runpod pod: ' . $e->getMessage());
            return null;
        }
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
        $value = (int)(getenv('NUTMEG_RUNPOD_TIMEOUT_MS') ?: 20000);
        return $value > 0 ? $value : 20000;
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

    private function shouldTrackDiscoveredPod(array $pod, ?string $excludePodId = null): bool
    {
        $podId = trim((string)($pod['id'] ?? ''));
        if ($podId === '') {
            return false;
        }

        $excludePodId = trim((string)$excludePodId);
        if ($excludePodId !== '' && $podId === $excludePodId) {
            return false;
        }

        $name = strtolower(trim((string)($pod['name'] ?? '')));
        $prefix = strtolower($this->dynamicNamePrefix()) . '-';
        if ($name === '' || !str_starts_with($name, $prefix)) {
            return false;
        }

        $status = strtoupper(trim((string)($pod['desiredStatus'] ?? $pod['status'] ?? '')));
        if (in_array($status, ['DELETED', 'TERMINATED'], true)) {
            return false;
        }

        return (new RunpodGpuMarketplaceService())->isPodAllocated($pod);
    }

    private function buildDiscoveredPodRecord(array $pod, RunpodGpuMarketplaceService $marketplace): array
    {
        $podId = trim((string)($pod['id'] ?? ''));
        $details = $podId !== '' ? ($marketplace->getPodDetails($podId) ?? []) : [];
        $createdAt = $this->firstNonEmptyString([
            $pod['createdAt'] ?? null,
            $pod['created_at'] ?? null,
            gmdate('c'),
        ]);
        $lastActivity = $this->firstNonEmptyString([
            $pod['lastStartedAt'] ?? null,
            $pod['updatedAt'] ?? null,
            $pod['createdAt'] ?? null,
            gmdate('c'),
        ]);

        return [
            'instance_key' => self::DYNAMIC_INSTANCE_KEY,
            'instance_label' => self::dynamicLabel(),
            'pod_id' => $podId,
            'api_key' => trim((string)(getenv('NUTMEG_RUNPOD_API_KEY') ?: '')),
            'api_base' => trim((string)(getenv('NUTMEG_RUNPOD_API_BASE') ?: 'https://rest.runpod.io/v1')),
            'fastapi_port' => (int)(getenv('NUTMEG_RUNPOD_FASTAPI_PORT') ?: 8888),
            'gpu_name' => $this->firstNonEmptyString([
                $details['gpuName'] ?? null,
                $pod['machine']['gpuDisplayName'] ?? null,
                $pod['gpuDisplayName'] ?? null,
                $pod['gpuTypeId'] ?? null,
            ]),
            'hourly_cost' => $this->firstNumeric([
                $details['pricePerHour'] ?? null,
                $pod['costPerHr'] ?? null,
                $pod['costPerHour'] ?? null,
                $pod['machine']['costPerHr'] ?? null,
                $pod['machine']['costPerHour'] ?? null,
            ]),
            'gpu_type_id' => $this->firstNonEmptyString([
                $pod['gpuTypeId'] ?? null,
                is_array($pod['gpuTypeIds'] ?? null) ? (string)reset($pod['gpuTypeIds']) : null,
            ]),
            'rented_at' => $createdAt,
            'last_activity' => $lastActivity,
            'last_seen_at' => gmdate('c'),
            'provision_source' => 'runpod-discovery',
        ];
    }

    private function podLifecycleRank(array $pod): int
    {
        $status = strtoupper(trim((string)($pod['desiredStatus'] ?? $pod['status'] ?? '')));

        return match ($status) {
            'RUNNING' => 500,
            'CREATED', 'STARTING', 'PROVISIONING', 'RESTARTING', 'PENDING' => 400,
            'STOPPING', 'TERMINATING' => 300,
            'STOPPED', 'EXITED' => 200,
            default => 100,
        };
    }

    private function podSortTimestamp(array $pod): int
    {
        foreach ([
            $pod['lastStartedAt'] ?? null,
            $pod['updatedAt'] ?? null,
            $pod['createdAt'] ?? null,
            $pod['created_at'] ?? null,
        ] as $candidate) {
            $value = trim((string)$candidate);
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return (int)$timestamp;
            }
        }

        return 0;
    }

    private function dynamicNamePrefix(): string
    {
        $value = trim((string)(getenv('NUTMEG_RUNPOD_DYNAMIC_NAME_PREFIX') ?: 'nutmeg-ai'));
        return $value !== '' ? $value : 'nutmeg-ai';
    }

    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function firstNumeric(array $candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float)$candidate;
            }
        }

        return null;
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
}

<?php
namespace App\Services;

use RuntimeException;

class RunpodGpuMarketplaceService
{
    private const GRAPHQL_API_BASE = 'https://api.runpod.io/graphql';
    private const REST_API_BASE = 'https://rest.runpod.io/v1';
    private const DEFAULT_PREFERRED_GPU_IDS = [
        'NVIDIA RTX 2000 Ada Generation',
        'NVIDIA L4',
        'NVIDIA A40',
        'NVIDIA RTX A5000',
        'NVIDIA RTX PRO 4500 Blackwell',
        'NVIDIA GeForce RTX 4090',
        'NVIDIA GeForce RTX 4080',
    ];
    private const DEFAULT_MIN_MEMORY_GB = 16;

    private string $apiKey;
    private ?array $provisioningBlueprint = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = trim((string)($apiKey ?: getenv('NUTMEG_RUNPOD_API_KEY')));
        if ($this->apiKey === '') {
            throw new RuntimeException('NUTMEG_RUNPOD_API_KEY is not configured.');
        }
    }

    /**
     * Find the cheapest currently available GPU type that matches this app's
     * constraints. This queries the current Runpod GraphQL schema rather than
     * relying on stale fixed pod pricing.
     */
    public function findCheapestAvailableGpu(): ?array
    {
        $options = $this->findAvailableGpuOptions();
        return $options[0] ?? null;
    }

    /**
     * Return matching marketplace GPU options sorted from cheapest to most
     * expensive so callers can retry when Runpod rejects the lowest-cost pool.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAvailableGpuOptions(): array
    {
        try {
            $query = sprintf(
                <<<'QUERY'
                query {
                    gpuTypes {
                        id
                        displayName
                        memoryInGb
                        secureCloud
                        communityCloud
                        lowestPrice(input: { gpuCount: 1, secureCloud: %s }) {
                            stockStatus
                            minimumBidPrice
                            uninterruptablePrice
                            availableGpuCounts
                        }
                    }
                }
                QUERY,
                $this->secureCloudOnly() ? 'true' : 'false'
            );

            $response = $this->graphqlRequest($query);
            $gpuTypes = $response['data']['gpuTypes'] ?? [];
            if (!is_array($gpuTypes) || $gpuTypes === []) {
                return [];
            }

            $preferred = array_fill_keys(
                array_map(
                    static fn(string $value): string => strtolower(trim($value)),
                    $this->preferredGpuIds()
                ),
                true
            );

            $options = [];
            foreach ($gpuTypes as $gpu) {
                if (!is_array($gpu)) {
                    continue;
                }

                $id = trim((string)($gpu['id'] ?? ''));
                $displayName = trim((string)($gpu['displayName'] ?? ''));
                $lookupKeys = array_filter([
                    strtolower($id),
                    strtolower($displayName),
                ]);

                $isPreferred = false;
                foreach ($lookupKeys as $lookupKey) {
                    if (isset($preferred[$lookupKey])) {
                        $isPreferred = true;
                        break;
                    }
                }
                if (!$isPreferred) {
                    continue;
                }

                $secureCloud = (bool)($gpu['secureCloud'] ?? false);
                $communityCloud = (bool)($gpu['communityCloud'] ?? false);
                if (($this->secureCloudOnly() && !$secureCloud) || (!$this->secureCloudOnly() && !$communityCloud)) {
                    continue;
                }

                $memoryGb = (int)($gpu['memoryInGb'] ?? 0);
                if ($memoryGb < $this->minMemoryGb()) {
                    continue;
                }

                $lowestPrice = is_array($gpu['lowestPrice'] ?? null) ? $gpu['lowestPrice'] : [];
                $stockStatus = strtolower(trim((string)($lowestPrice['stockStatus'] ?? '')));
                $uninterruptablePrice = $lowestPrice['uninterruptablePrice'] ?? null;
                if (!is_numeric($uninterruptablePrice) || (float)$uninterruptablePrice <= 0.0) {
                    continue;
                }

                if (in_array($stockStatus, ['', 'none', 'unavailable'], true)) {
                    continue;
                }

                $hourlyRate = (float)$uninterruptablePrice;
                $options[] = [
                    'gpuTypeId' => $id,
                    'gpuName' => $displayName !== '' ? $displayName : $id,
                    'gpuMemoryInGb' => $memoryGb,
                    'pricePerHour' => $hourlyRate,
                    'minimumBidPrice' => is_numeric($lowestPrice['minimumBidPrice'] ?? null)
                        ? (float)$lowestPrice['minimumBidPrice']
                        : null,
                    'stockStatus' => $lowestPrice['stockStatus'] ?? null,
                    'availableGpuCounts' => $lowestPrice['availableGpuCounts'] ?? null,
                    'secureCloud' => $secureCloud,
                    'communityCloud' => $communityCloud,
                ];
            }

            usort($options, static function (array $left, array $right): int {
                $leftPrice = (float)($left['pricePerHour'] ?? PHP_FLOAT_MAX);
                $rightPrice = (float)($right['pricePerHour'] ?? PHP_FLOAT_MAX);
                return $leftPrice <=> $rightPrice;
            });

            return $options;
        } catch (\Throwable $e) {
            error_log('Failed to query GPU marketplace: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a new on-demand pod from a prebuilt template or image. The pod is
     * pinned to the selected GPU type but still uses Runpod's availability-based
     * placement within that GPU pool.
     */
    public function rentGpuPod(string|array $gpuTypeId, int $durationHours = 1): ?string
    {
        try {
            $gpuTypeIds = is_array($gpuTypeId) ? $gpuTypeId : [$gpuTypeId];
            $gpuTypeIds = array_values(array_unique(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                $gpuTypeIds
            ))));
            if ($gpuTypeIds === []) {
                throw new RuntimeException('At least one GPU type is required.');
            }

            $payload = [
                'name' => sprintf('%s-%s', $this->dynamicNamePrefix(), gmdate('YmdHis')),
                'gpuCount' => 1,
                'gpuTypeIds' => $gpuTypeIds,
                'gpuTypePriority' => 'availability',
                // The HTTP proxy does not require a dedicated public IP.
                // Requiring one unnecessarily excludes Community Cloud hosts.
                'supportPublicIp' => $this->secureCloudOnly(),
                // Runpod only supports global networking on some Secure Cloud
                // machines. Requesting it for Community Cloud can leave a pod
                // placeholder permanently without an attached machine.
                'globalNetworking' => $this->secureCloudOnly(),
                'interruptible' => $this->interruptiblePods(),
                'cloudType' => $this->secureCloudOnly() ? 'SECURE' : 'COMMUNITY',
            ];

            $imageName = $this->dynamicImageName();
            $templateId = $this->dynamicTemplateId();

            if ($imageName !== '') {
                $payload['imageName'] = $imageName;
            } elseif ($this->allowTemplateFallback() && $templateId !== '') {
                error_log('Launching Runpod pod from template fallback because NUTMEG_RUNPOD_ALLOW_TEMPLATE_FALLBACK is enabled.');
                $payload['templateId'] = $templateId;
            } else {
                throw new RuntimeException(
                    'Configure NUTMEG_RUNPOD_IMAGE_NAME before auto-provisioning a new GPU pod. Template fallback is disabled by default.'
                );
            }

            $dockerEntrypoint = $this->dynamicDockerEntrypoint();
            $dockerStartCmd = $this->dynamicDockerStartCmd();
            if ($dockerEntrypoint !== []) {
                $payload['dockerEntrypoint'] = $dockerEntrypoint;
            }
            if ($dockerStartCmd !== []) {
                $payload['dockerStartCmd'] = $dockerStartCmd;
            }

            $containerDisk = $this->dynamicContainerDiskGb();
            if ($containerDisk > 0) {
                $payload['containerDiskInGb'] = $containerDisk;
            }

            $ports = $this->dynamicPorts();
            if ($ports !== []) {
                // By default avoid exposing raw TCP-only ports which can
                // interfere with Runpod HTTP proxy routing. Allow TCP ports
                // only when explicitly enabled via NUTMEG_RUNPOD_ALLOW_TCP_PORTS=1.
                if (!$this->allowTcpPorts()) {
                    $ports = array_values(array_filter($ports, static fn(string $p): bool => preg_match('#/(http|https)$#i', $p) === 1));
                }

                if ($ports !== []) {
                    $payload['ports'] = $ports;
                }
            }

            $env = $this->dynamicEnv();
            if ($env !== []) {
                $payload['env'] = $env;
            }

            $networkVolumeId = trim((string)(getenv('NUTMEG_RUNPOD_NETWORK_VOLUME_ID') ?: ''));
            if ($networkVolumeId !== '') {
                $payload['networkVolumeId'] = $networkVolumeId;
            } else {
                $volumeGb = $this->dynamicVolumeGb();
                if ($volumeGb > 0) {
                    $payload['volumeInGb'] = $volumeGb;
                    $payload['volumeMountPath'] = $this->dynamicVolumeMountPath();
                }
            }

            $response = $this->restRequest('POST', '/pods', $payload);
            if (empty($response['id'])) {
                error_log('Failed to create pod: no ID returned');
                return null;
            }

            $newPodId = (string)$response['id'];
            error_log(
                sprintf(
                    'Successfully created new pod %s for GPU %s (requested duration hint: %d hour(s))',
                    $newPodId,
                    implode(', ', $gpuTypeIds),
                    max(1, $durationHours)
                )
            );

            return $newPodId;
        } catch (\Throwable $e) {
            error_log('Failed to rent GPU pod: ' . $e->getMessage());
            return null;
        }
    }

    public function getPodDetails(string $podId): ?array
    {
        try {
            $response = $this->restRequest('GET', '/pods/' . rawurlencode($podId));
            return [
                'id' => (string)($response['id'] ?? $podId),
                'name' => (string)($response['name'] ?? ''),
                'pricePerHour' => $this->extractNumeric([
                    $response['costPerHr'] ?? null,
                    $response['costPerHour'] ?? null,
                    $response['machine']['costPerHr'] ?? null,
                    $response['machine']['costPerHour'] ?? null,
                ]),
                'gpuName' => $this->extractString([
                    $response['machine']['gpuDisplayName'] ?? null,
                    $response['gpuDisplayName'] ?? null,
                    $response['gpuTypeId'] ?? null,
                ]),
                'gpuMemoryInGb' => $this->extractNumeric([
                    $response['machine']['gpuMemoryInGb'] ?? null,
                    $response['gpuMemoryInGb'] ?? null,
                ]),
            ];
        } catch (\Throwable $e) {
            error_log('Failed to get pod details: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Wait until Runpod has attached a real machine to a newly-created pod.
     * Runpod may initially return a billable-looking placeholder with no GPU,
     * machine, runtime, or port mapping. Such a pod cannot run the AI worker.
     */
    public function waitForPodAllocation(string $podId): ?array
    {
        $podId = trim($podId);
        if ($podId === '') {
            return null;
        }

        $timeoutSeconds = max(15, (int)(getenv('NUTMEG_RUNPOD_ALLOCATION_TIMEOUT_SECONDS') ?: 90));
        $pollSeconds = max(2, (int)(getenv('NUTMEG_RUNPOD_ALLOCATION_POLL_SECONDS') ?: 5));
        $deadline = time() + $timeoutSeconds;

        do {
            try {
                $pod = $this->restRequest('GET', '/pods/' . rawurlencode($podId));
                if ($this->isPodAllocated($pod)) {
                    return $pod;
                }
            } catch (\Throwable $e) {
                error_log('Runpod allocation check failed for ' . $podId . ': ' . $e->getMessage());
                // Runpod removes rejected/unavailable placeholders and returns
                // 404. Move to the next GPU immediately instead of polling a
                // pod that can no longer be allocated.
                if (str_contains($e->getMessage(), 'HTTP 404')) {
                    return null;
                }
            }

            if (time() < $deadline) {
                sleep($pollSeconds);
            }
        } while (time() < $deadline);

        return null;
    }

    public function isPodAllocated(array $pod): bool
    {
        $machine = $pod['machine'] ?? null;
        $runtime = $pod['runtime'] ?? null;
        $portMappings = $pod['portMappings'] ?? null;
        $machineId = trim((string)($pod['machineId'] ?? ''));
        $desiredStatus = strtoupper(trim((string)($pod['desiredStatus'] ?? '')));
        $lastStatusChange = strtolower(trim((string)($pod['lastStatusChange'] ?? '')));

        // During image pull/container startup, Runpod's GET /pods/{id}
        // temporarily returns an empty `machine` object even though a host has
        // already been rented. `machineId` plus the running/rented lifecycle
        // state is the durable allocation signal in that interval.
        $hasAssignedRunningMachine = $machineId !== ''
            && $desiredStatus === 'RUNNING'
            && !str_contains($lastStatusChange, 'outbid')
            && !str_contains($lastStatusChange, 'terminated');

        return (is_array($machine) && $machine !== [])
            || (is_array($runtime) && $runtime !== [])
            || (is_array($portMappings) && $portMappings !== [])
            || $hasAssignedRunningMachine
            || trim((string)($pod['publicIp'] ?? '')) !== ''
            || trim((string)($pod['gpuDisplayName'] ?? $pod['gpuTypeId'] ?? '')) !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPods(): array
    {
        try {
            $response = $this->restRequest('GET', '/pods');
            if (!is_array($response)) {
                return [];
            }

            $pods = [];
            foreach ($response as $pod) {
                if (is_array($pod)) {
                    $pods[] = $pod;
                }
            }

            return $pods;
        } catch (\Throwable $e) {
            error_log('Failed to list Runpod pods: ' . $e->getMessage());
            return [];
        }
    }

    public function terminatePod(string $podId): bool
    {
        $podId = trim($podId);
        if ($podId === '') {
            return false;
        }

        try {
            $this->restRequest('DELETE', '/pods/' . rawurlencode($podId));
            return true;
        } catch (\Throwable $e) {
            error_log('Failed to terminate Runpod pod ' . $podId . ': ' . $e->getMessage());
            return false;
        }
    }

    public function cleanupNutmegPods(?string $keepPodId = null): int
    {
        if (!$this->autoTerminateExtraPodsEnabled()) {
            return 0;
        }

        $keepPodId = trim((string)$keepPodId);
        $terminated = 0;

        foreach ($this->listPods() as $pod) {
            if (!$this->shouldAutoTerminatePod($pod, $keepPodId)) {
                continue;
            }

            $podId = trim((string)($pod['id'] ?? ''));
            if ($podId !== '' && $this->terminatePod($podId)) {
                $terminated++;
            }
        }

        return $terminated;
    }

    private function preferredGpuIds(): array
    {
        $raw = trim((string)(getenv('NUTMEG_RUNPOD_MARKETPLACE_GPU_IDS') ?: ''));
        if ($raw === '') {
            return self::DEFAULT_PREFERRED_GPU_IDS;
        }

        $values = array_values(array_filter(array_map(
            static fn(string $value): string => trim($value),
            explode(',', $raw)
        )));

        return $values !== [] ? $values : self::DEFAULT_PREFERRED_GPU_IDS;
    }

    private function minMemoryGb(): int
    {
        $value = (int)(getenv('NUTMEG_RUNPOD_MIN_GPU_MEMORY_GB') ?: self::DEFAULT_MIN_MEMORY_GB);
        return $value > 0 ? $value : self::DEFAULT_MIN_MEMORY_GB;
    }

    private function secureCloudOnly(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_MARKETPLACE_SECURE_CLOUD') ?: '1')));
        return !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }

    private function interruptiblePods(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_INTERRUPTIBLE') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function dynamicTemplateId(): string
    {
        if (!$this->allowTemplateFallback()) {
            return '';
        }

        return $this->configuredTemplateId();
    }

    private function allowTemplateFallback(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_ALLOW_TEMPLATE_FALLBACK') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function dynamicImageName(): string
    {
        $configured = $this->configuredImageName();
        if ($configured !== '') {
            return $configured;
        }

        return 'runpod/pytorch:2.4.0-py3.11-cuda12.4.1-devel-ubuntu22.04';
    }

    private function dynamicNamePrefix(): string
    {
        $value = trim((string)(getenv('NUTMEG_RUNPOD_DYNAMIC_NAME_PREFIX') ?: 'nutmeg-ai'));
        return $value !== '' ? $value : 'nutmeg-ai';
    }

    private function autoTerminateExtraPodsEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_AUTO_TERMINATE_EXTRA_PODS') ?: '1')));
        return !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }

    private function autoTerminateConfiguredFallbackPods(): bool
    {
        $raw = trim((string)(getenv('NUTMEG_RUNPOD_AUTO_TERMINATE_CONFIGURED_PODS') ?: ''));
        if ($raw !== '') {
            return !in_array(strtolower($raw), ['0', 'false', 'no', 'off'], true);
        }

        $dynamicOnly = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_DYNAMIC_ONLY') ?: '0')));
        return !in_array($dynamicOnly, ['0', 'false', 'no', 'off', ''], true);
    }

    private function shouldAutoTerminatePod(array $pod, string $keepPodId): bool
    {
        $podId = trim((string)($pod['id'] ?? ''));
        if ($podId === '' || ($keepPodId !== '' && $podId === $keepPodId)) {
            return false;
        }

        $name = strtolower(trim((string)($pod['name'] ?? '')));
        $prefix = strtolower($this->dynamicNamePrefix()) . '-';
        if ($name !== '' && str_starts_with($name, $prefix)) {
            return true;
        }

        if ($this->autoTerminateConfiguredFallbackPods() && in_array($podId, $this->configuredPodIds(), true)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function configuredPodIds(): array
    {
        $ids = [];

        $primaryPodId = trim((string)(getenv('NUTMEG_RUNPOD_POD_ID') ?: ''));
        if ($primaryPodId !== '') {
            $ids[] = $primaryPodId;
        }

        $instancesRaw = trim((string)(getenv('NUTMEG_RUNPOD_INSTANCES_JSON') ?: getenv('NUTMEG_RUNPOD_INSTANCES') ?: ''));
        if ($instancesRaw !== '') {
            $decoded = json_decode($instancesRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $config) {
                    if (!is_array($config)) {
                        continue;
                    }

                    $podId = trim((string)($config['pod_id'] ?? $config['podId'] ?? ''));
                    if ($podId !== '') {
                        $ids[] = $podId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function dynamicContainerDiskGb(): int
    {
        $configured = trim((string)(getenv('NUTMEG_RUNPOD_CONTAINER_DISK_GB') ?: ''));
        if ($configured !== '') {
            $value = (int)$configured;
            return $value > 0 ? $value : 25;
        }

        if ($this->isTemplateLaunch()) {
            return 0;
        }

        $value = 25;
        return $value > 0 ? $value : 25;
    }

    private function dynamicVolumeGb(): int
    {
        $configured = trim((string)(getenv('NUTMEG_RUNPOD_VOLUME_GB') ?: ''));
        if ($configured !== '') {
            $value = (int)$configured;
            return $value > 0 ? $value : 0;
        }

        $value = 0;
        return $value > 0 ? $value : 0;
    }

    private function dynamicVolumeMountPath(): string
    {
        $value = trim((string)(getenv('NUTMEG_RUNPOD_VOLUME_MOUNT_PATH') ?: '/workspace'));
        return $value !== '' ? $value : '/workspace';
    }

    private function dynamicPorts(): array
    {
        $raw = trim((string)(getenv('NUTMEG_RUNPOD_DYNAMIC_PORTS') ?: ''));
        if ($raw !== '') {
            return array_values(array_filter(array_map(
                static fn(string $port): string => trim($port),
                explode(',', $raw)
            )));
        }

        if ($this->isTemplateLaunch()) {
            return [];
        }

        $fallbackPort = (int)(getenv('NUTMEG_RUNPOD_FASTAPI_PORT') ?: 8888);
        return $fallbackPort > 0 ? [sprintf('%d/http', $fallbackPort)] : [];
    }

    private function allowTcpPorts(): bool
    {
        $raw = strtolower(trim((string)(getenv('NUTMEG_RUNPOD_ALLOW_TCP_PORTS') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function dynamicEnv(): array
    {
        $raw = trim((string)(getenv('NUTMEG_RUNPOD_TEMPLATE_ENV_JSON') ?: ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function configuredTemplateId(): string
    {
        return trim((string)(getenv('NUTMEG_RUNPOD_TEMPLATE_ID') ?: ''));
    }

    private function configuredImageName(): string
    {
        return trim((string)(getenv('NUTMEG_RUNPOD_IMAGE_NAME') ?: ''));
    }

    private function dynamicDockerEntrypoint(): array
    {
        $raw = trim((string)(getenv('NUTMEG_RUNPOD_DOCKER_ENTRYPOINT_JSON') ?: ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(
                    static fn(mixed $value): string => trim((string)$value),
                    $decoded
                ), static fn(string $value): bool => $value !== ''));
            }
        }

        $startCmd = $this->dynamicDockerStartCmd();
        return $startCmd !== [] ? ['bash', '-lc'] : [];
    }

    private function dynamicDockerStartCmd(): array
    {
        $rawJson = trim((string)(getenv('NUTMEG_RUNPOD_DOCKER_START_CMD_JSON') ?: ''));
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(
                    static fn(mixed $value): string => trim((string)$value),
                    $decoded
                ), static fn(string $value): bool => $value !== ''));
            }
        }

        $raw = trim((string)(getenv('NUTMEG_RUNPOD_DOCKER_START_CMD') ?: ''));
        if ($raw !== '') {
            return [$raw];
        }

        return [
            'set -euo pipefail; if [ -z "${NUTMEG_AI_BUNDLE_URL:-}" ]; then echo "NUTMEG_AI_BUNDLE_URL is not configured" >&2; exit 1; fi; if ! getent hosts strddrjneurylxoolimj.supabase.co >/dev/null 2>&1; then printf "nameserver 1.1.1.1\nnameserver 8.8.8.8\noptions timeout:2 attempts:3\n" >/etc/resolv.conf || true; fi; APP_DIR=/workspace/nutmeg-ai; VERSION=${NUTMEG_AI_BOOTSTRAP_VERSION:-20260424c}; if [ ! -f "$APP_DIR/.bundle-version-$VERSION" ]; then rm -rf "$APP_DIR"; mkdir -p "$APP_DIR"; for attempt in 1 2 3 4 5; do if curl -4 -fsSL "$NUTMEG_AI_BUNDLE_URL" -o /tmp/nutmeg-ai-template.tgz; then break; fi; sleep 3; done; test -f /tmp/nutmeg-ai-template.tgz; tar --no-same-owner -xzf /tmp/nutmeg-ai-template.tgz -C "$APP_DIR"; rm -f "$APP_DIR"/.bundle-version-*; touch "$APP_DIR/.bundle-version-$VERSION"; fi; REQUIREMENTS_FILE="$APP_DIR/requirements-runpod.txt"; if [ ! -f "$REQUIREMENTS_FILE" ]; then REQUIREMENTS_FILE="$APP_DIR/requirements.txt"; fi; if ! python3 -c "import uvicorn, fastapi, multipart, ultralytics, cv2, scipy, filterpy" >/dev/null 2>&1; then for attempt in 1 2 3; do python3 -m pip install --no-cache-dir -r "$REQUIREMENTS_FILE" && break; sleep 5; done; fi; chmod +x "$APP_DIR/start-fastapi.sh"; exec "$APP_DIR/start-fastapi.sh"',
        ];
    }

    private function isTemplateLaunch(): bool
    {
        return $this->dynamicTemplateId() !== '';
    }

    private function provisioningBlueprint(): array
    {
        if (is_array($this->provisioningBlueprint)) {
            return $this->provisioningBlueprint;
        }

        $instancesRaw = trim((string)(getenv('NUTMEG_RUNPOD_INSTANCES_JSON') ?: getenv('NUTMEG_RUNPOD_INSTANCES') ?: ''));
        $instances = json_decode($instancesRaw, true);
        if (!is_array($instances)) {
            $instances = [];
        }

        $seedPodId = '';
        $seedApiKey = '';
        foreach ($instances as $config) {
            if (!is_array($config)) {
                continue;
            }

            $podId = trim((string)($config['pod_id'] ?? $config['podId'] ?? ''));
            $apiKey = trim((string)($config['api_key'] ?? $config['apiKey'] ?? $this->apiKey));
            if ($podId !== '' && $apiKey !== '') {
                $seedPodId = $podId;
                $seedApiKey = $apiKey;
                break;
            }
        }

        if ($seedPodId === '') {
            $seedPodId = trim((string)(getenv('NUTMEG_RUNPOD_POD_ID') ?: ''));
            $seedApiKey = trim((string)(getenv('NUTMEG_RUNPOD_API_KEY') ?: $this->apiKey));
        }

        if ($seedPodId === '' || $seedApiKey === '') {
            $this->provisioningBlueprint = [];
            return $this->provisioningBlueprint;
        }

        try {
            $this->provisioningBlueprint = $this->request(
                rtrim(self::REST_API_BASE, '/') . '/pods/' . rawurlencode($seedPodId),
                [
                    'Authorization: Bearer ' . $seedApiKey,
                    'Accept: application/json',
                ],
                null,
                'GET'
            );
        } catch (\Throwable $e) {
            error_log('Failed to load Runpod provisioning blueprint: ' . $e->getMessage());
            $this->provisioningBlueprint = [];
        }

        return $this->provisioningBlueprint;
    }

    private function graphqlRequest(string $query): array
    {
        return $this->request(
            self::GRAPHQL_API_BASE . '?api_key=' . urlencode($this->apiKey),
            ['Content-Type: application/json'],
            json_encode(['query' => $query], JSON_UNESCAPED_SLASHES)
        );
    }

    private function restRequest(string $method, string $path, ?array $payload = null): array
    {
        $body = null;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        return $this->request(
            rtrim(self::REST_API_BASE, '/') . $path,
            $headers,
            $body,
            $method
        );
    }

    private function request(string $url, array $headers, ?string $body = null, string $method = 'POST'): array
    {
        $ch = curl_init($url);
        $verifyTls = $this->shouldVerifyTls();
        $connectTimeoutMs = $this->connectTimeoutMs();
        $requestTimeoutMs = $this->requestTimeoutMs();

        $options = array_replace($this->curlNetworkOptions($connectTimeoutMs, $requestTimeoutMs), [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        if (!$verifyTls) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        try {
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = trim((string)curl_error($ch));

            if (
                $error !== ''
                && $verifyTls
                && $this->isTlsCertificateError($error)
            ) {
                $retryOptions = $options;
                $retryTimeoutMs = min($requestTimeoutMs, 20000);
                $retryOptions[CURLOPT_CONNECTTIMEOUT] = $this->secondsFromMilliseconds($connectTimeoutMs);
                $retryOptions[CURLOPT_TIMEOUT] = $this->secondsFromMilliseconds($retryTimeoutMs);
                $retryOptions[CURLOPT_CONNECTTIMEOUT_MS] = $connectTimeoutMs;
                $retryOptions[CURLOPT_TIMEOUT_MS] = $retryTimeoutMs;
                $retryOptions[CURLOPT_LOW_SPEED_TIME] = $this->secondsFromMilliseconds($retryTimeoutMs);
                $retryOptions[CURLOPT_SSL_VERIFYPEER] = false;
                $retryOptions[CURLOPT_SSL_VERIFYHOST] = 0;
                curl_setopt_array($ch, $retryOptions);
                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Runpod API');
        }

        if ($httpCode >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? null;
            if (!is_string($message) || trim($message) === '') {
                $graphqlErrors = $decoded['errors'][0]['message'] ?? null;
                $message = is_string($graphqlErrors) ? $graphqlErrors : 'Runpod API request failed.';
            }

            throw new RuntimeException(sprintf('HTTP %d: %s', $httpCode, trim($message)));
        }

        if (!empty($decoded['errors'])) {
            $errorMsg = $decoded['errors'][0]['message'] ?? 'Unknown error';
            throw new RuntimeException('Runpod API error: ' . $errorMsg);
        }

        return $decoded;
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

    private function shouldVerifyTls(): bool
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

    private function extractString(array $values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string)$value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function extractNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }
}

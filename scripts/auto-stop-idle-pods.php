<?php
declare(strict_types=1);

/**
 * Cron-driven idle-pod sweep.
 *
 * The 30-minute idle-stop logic in RunpodPodService::getStatus() fires as a
 * side-effect of any status call. On shared hosting nothing calls the status
 * endpoint while the dashboard is unattended, so the pod can run forever.
 *
 * This script triggers getStatus(false) for every configured AI instance, plus
 * any active dynamic pod, which causes the embedded check to terminate / stop
 * the pod once it has been idle for NUTMEG_RUNPOD_IDLE_STOP_SECONDS (default
 * 1800 s = 30 min) with zero queued/processing jobs.
 *
 * Recommended cron entry (every 5 minutes):
 *   *\/5 * * * * php /path/to/website/scripts/auto-stop-idle-pods.php >> /path/to/website/storage/logs/ai/idle-sweep.log 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\RunpodPodService;

function writeIdleSweepMessage(string $message): void
{
    if (PHP_SAPI === 'cli') {
        $stream = fopen('php://stdout', 'wb');
        if (is_resource($stream)) {
            fwrite($stream, $message);
            fclose($stream);
            return;
        }
    }

    echo $message;
}

$startedAt = gmdate('c');
$instances = RunpodPodService::configuredInstances();
$keysToCheck = [];

foreach (array_keys($instances) as $key) {
    $keysToCheck[(string)$key] = true;
}

// Refresh Runpod discovery on every sweep. Shared-hosting deploys can clear
// the local active-pod cache while a paid dynamic pod is still running.
$activeKey = RunpodPodService::activeInstanceKey(false);
if ($activeKey === null) {
    $activeKey = RunpodPodService::activeInstanceKey(true);
}
if (is_string($activeKey) && $activeKey !== '') {
    $keysToCheck[$activeKey] = true;
}

if ($keysToCheck === []) {
    writeIdleSweepMessage("[{$startedAt}] No Runpod instances configured. Nothing to sweep.\n");
    exit(0);
}

$summary = [];
foreach (array_keys($keysToCheck) as $instanceKey) {
    try {
        $service = new RunpodPodService($instanceKey);
        if (!$service->isConfigured()) {
            $summary[] = sprintf('%s=unconfigured', $instanceKey);
            continue;
        }

        $status = $service->getStatus(false);
        $state = strtolower((string)($status['state'] ?? ''));
        $idle = $status['idle_seconds'] ?? null;
        $idleLimit = $status['idle_limit_seconds'] ?? null;
        $summary[] = sprintf(
            '%s=%s idle=%s/%s',
            $instanceKey,
            $state !== '' ? $state : 'unknown',
            $idle === null ? 'n/a' : (string)(int)$idle,
            $idleLimit === null ? 'n/a' : (string)(int)$idleLimit
        );
    } catch (\Throwable $e) {
        $summary[] = sprintf('%s=error:%s', $instanceKey, $e->getMessage());
    }
}

writeIdleSweepMessage(sprintf("[%s] idle-sweep %s\n", $startedAt, implode(' ', $summary)));
exit(0);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\MatchVideoAnalysisService;
use App\Services\VideoAnalysisService;

function acquireMatchProcessingLock(int $matchId)
{
    $lockDir = BASE_PATH . '/storage/locks/ai';
    if (!is_dir($lockDir)) {
        mkdir($lockDir, 0775, true);
    }

    $lockPath = $lockDir . '/match_' . $matchId . '.lock';
    $handle = fopen($lockPath, 'c+');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open AI processing lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    return $handle;
}

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 1;

$queue = new MatchVideoAnalysisService();
$service = new VideoAnalysisService();
$jobs = $queue->listByStatus('queued', $limit);

if ($jobs === []) {
    fwrite(STDOUT, "No queued AI videos found.\n");
    exit(0);
}

foreach ($jobs as $job) {
    $matchId = (int)($job['match_id'] ?? 0);
    if ($matchId <= 0) {
        continue;
    }

    if ($service->expireStaleAnalysisIfNeeded($matchId)) {
        fwrite(STDOUT, "Match {$matchId} expired as stale and was marked failed.\n");
        continue;
    }

    $lockHandle = acquireMatchProcessingLock($matchId);
    if (!is_resource($lockHandle)) {
        fwrite(STDOUT, "Match {$matchId} is already being processed elsewhere.\n");
        continue;
    }

    try {
        $service->processMatchVideo($matchId);
        fwrite(STDOUT, "Processed queued video for match {$matchId}.\n");
    } catch (\Throwable $e) {
        $service->failAnalysis($matchId, $e->getMessage());
        fwrite(STDERR, "Match {$matchId} failed: " . $e->getMessage() . "\n");
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit(0);

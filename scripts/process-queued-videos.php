<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\MatchVideoAnalysisService;
use App\Services\AutoVideoAnalysisDiscoveryService;
use App\Services\VideoAnalysisService;
use App\Exceptions\AiProcessingDeferredException;

function writeQueueMessage(string $message, bool $error = false): void
{
    if (PHP_SAPI === 'cli') {
        $stream = $error ? fopen('php://stderr', 'wb') : fopen('php://stdout', 'wb');
        if (is_resource($stream)) {
            fwrite($stream, $message);
            fclose($stream);
            return;
        }
    }

    if ($error) {
        error_log(rtrim($message));
        return;
    }

    echo $message;
}

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

function shouldKeepRunpodJobAlive(int $matchId, string $message): bool
{
    $normalized = strtolower($message);
    $transient = str_contains($normalized, 'timed out')
        || str_contains($normalized, 'timeout')
        || str_contains($normalized, 'could not resolve host')
        || str_contains($normalized, 'resolving timed out')
        || str_contains($normalized, 'name lookup timed out')
        || str_contains($normalized, 'temporary failure in name resolution');
    if (!$transient) {
        return false;
    }

    $record = (new MatchVideoAnalysisService())->getByMatchId($matchId);
    $metadata = is_array($record['ai_output'] ?? null) ? $record['ai_output'] : [];
    return trim((string)($metadata['runpod_job_id'] ?? '')) !== '';
}

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 1;

$queue = new MatchVideoAnalysisService();
$service = new VideoAnalysisService();

try {
    $discovery = (new AutoVideoAnalysisDiscoveryService())->queueNewVideos(max(5, $limit));
    if (($discovery['queued'] ?? 0) > 0 || ($discovery['errors'] ?? []) !== []) {
        writeQueueMessage(sprintf(
            "Auto-discovery queued %d new video(s), skipped %d.\n",
            (int)($discovery['queued'] ?? 0),
            (int)($discovery['skipped'] ?? 0)
        ));
        foreach (($discovery['errors'] ?? []) as $error) {
            writeQueueMessage("Auto-discovery error: {$error}\n", true);
        }
    }
} catch (\Throwable $e) {
    writeQueueMessage("Auto-discovery failed: " . $e->getMessage() . "\n", true);
}

$processingJobs = $queue->listByStatus('processing', max(20, $limit));
foreach ($processingJobs as $processingJob) {
    $processingMatchId = (int)($processingJob['match_id'] ?? 0);
    if ($processingMatchId > 0) {
        $service->expireStaleAnalysisIfNeeded($processingMatchId);
    }
}
$jobs = $queue->listByStatus('queued', $limit);

if ($jobs === []) {
    writeQueueMessage("No queued AI videos found.\n");
    exit(0);
}

foreach ($jobs as $job) {
    $matchId = (int)($job['match_id'] ?? 0);
    if ($matchId <= 0) {
        continue;
    }

    if ($service->expireStaleAnalysisIfNeeded($matchId)) {
        writeQueueMessage("Match {$matchId} expired as stale and was marked failed.\n");
        continue;
    }

    $lockHandle = acquireMatchProcessingLock($matchId);
    if (!is_resource($lockHandle)) {
        writeQueueMessage("Match {$matchId} is already being processed elsewhere.\n");
        continue;
    }

    try {
        $service->processMatchVideo($matchId);
        writeQueueMessage("Processed queued video for match {$matchId}.\n");
    } catch (AiProcessingDeferredException $e) {
        writeQueueMessage("Match {$matchId} deferred: " . $e->getMessage() . "\n");
    } catch (\Throwable $e) {
        if (shouldKeepRunpodJobAlive($matchId, $e->getMessage())) {
            writeQueueMessage("Match {$matchId} hit a transient PHP/RunPod poll error while async job is still alive: " . $e->getMessage() . "\n", true);
            continue;
        }
        if ($service->requeueAfterInfrastructureFailure($matchId, $e->getMessage())) {
            writeQueueMessage("Match {$matchId} was safely requeued after an AI infrastructure failure.\n", true);
            continue;
        }
        $service->failAnalysis($matchId, $e->getMessage());
        writeQueueMessage("Match {$matchId} failed: " . $e->getMessage() . "\n", true);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit(0);

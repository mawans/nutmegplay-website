<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\VideoAnalysisService;
use App\Services\MatchVideoAnalysisService;
use App\Exceptions\AiProcessingDeferredException;

function writeMatchWorkerMessage(string $message, bool $error = false): void
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

$options = getopt('', ['match-id:']);
$matchId = isset($options['match-id']) ? (int)$options['match-id'] : 0;

if ($matchId <= 0) {
    writeMatchWorkerMessage("Missing required --match-id argument.\n", true);
    exit(1);
}

$service = new VideoAnalysisService();
$lockHandle = acquireMatchProcessingLock($matchId);

if (!is_resource($lockHandle)) {
    writeMatchWorkerMessage("Match {$matchId} is already being processed elsewhere.\n");
    exit(0);
}

try {
    $service->processMatchVideo($matchId);
    writeMatchWorkerMessage("Processed match video for match {$matchId}.\n");
    exit(0);
} catch (AiProcessingDeferredException $e) {
    writeMatchWorkerMessage("Match {$matchId} deferred: " . $e->getMessage() . "\n");
    exit(0);
} catch (\Throwable $e) {
    if (shouldKeepRunpodJobAlive($matchId, $e->getMessage())) {
        writeMatchWorkerMessage("Transient PHP/RunPod poll error while async job is still alive: " . $e->getMessage() . "\n", true);
        exit(0);
    }
    if ($service->requeueAfterInfrastructureFailure($matchId, $e->getMessage())) {
        writeMatchWorkerMessage("AI infrastructure failed; the saved clip was safely requeued for automatic retry.\n", true);
        exit(0);
    }
    $service->failAnalysis($matchId, $e->getMessage());
    writeMatchWorkerMessage($e->getMessage() . "\n", true);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

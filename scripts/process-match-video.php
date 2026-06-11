<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

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

$options = getopt('', ['match-id:']);
$matchId = isset($options['match-id']) ? (int)$options['match-id'] : 0;

if ($matchId <= 0) {
    fwrite(STDERR, "Missing required --match-id argument.\n");
    exit(1);
}

$service = new VideoAnalysisService();
$lockHandle = acquireMatchProcessingLock($matchId);

if (!is_resource($lockHandle)) {
    fwrite(STDOUT, "Match {$matchId} is already being processed elsewhere.\n");
    exit(0);
}

try {
    $service->processMatchVideo($matchId);
    fwrite(STDOUT, "Processed match video for match {$matchId}.\n");
    exit(0);
} catch (\Throwable $e) {
    $service->failAnalysis($matchId, $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

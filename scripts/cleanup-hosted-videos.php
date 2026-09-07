<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\SupabaseClient;
use App\Services\B2VideoStorageService;
use App\Services\MatchVideoAnalysisService;
use App\Services\VideoStorageProtectionService;

function writeCleanupMessage(string $message, bool $error = false): void
{
    if (PHP_SAPI === 'cli') {
        $stream = fopen($error ? 'php://stderr' : 'php://stdout', 'wb');
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

function parsePositiveInt(mixed $value, int $fallback): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $parsed === false ? $fallback : (int)$parsed;
}

function collectWebsiteVideoAliases(string $basename): array
{
    return [
        '/videos/' . $basename,
        '/public/videos/' . $basename,
    ];
}

function updateExpiredVideoReferences(SupabaseClient $db, array $aliases, string $timestamp): array
{
    $matchUpdates = 0;
    $analysisUpdates = 0;

    foreach ($aliases as $alias) {
        $matchRows = $db->from('matchs')
            ->eq('video_url', $alias)
            ->update([
                'video_url' => null,
                'video_status' => 'expired',
            ]);
        if (is_array($matchRows) && empty($matchRows['error'])) {
            $matchUpdates += count($matchRows);
        }

        $analysisRows = $db->from('match_video_analysis')
            ->eq('video_url', $alias)
            ->update([
                'video_url' => null,
                'updated_at' => $timestamp,
            ]);
        if (is_array($analysisRows) && empty($analysisRows['error'])) {
            $analysisUpdates += count($analysisRows);
        }
    }

    return [
        'match_updates' => $matchUpdates,
        'analysis_updates' => $analysisUpdates,
    ];
}

$options = getopt('', ['days::', 'dry-run']);
$retentionDays = parsePositiveInt($options['days'] ?? getenv('NUTMEG_VIDEO_RETENTION_DAYS') ?: 30, 30);
$dryRun = array_key_exists('dry-run', $options);

$uploadDir = BASE_PATH . '/public/videos';
$cutoffTs = time() - ($retentionDays * 86400);
$timestamp = gmdate('c');

$db = SupabaseClient::getInstance();
$retentionLock = VideoStorageProtectionService::acquireExclusiveLock();
$b2 = null;
if (
    strtolower(trim((string)(getenv('NUTMEG_VIDEO_STORAGE') ?: 'local'))) === 'b2'
    && B2VideoStorageService::isConfigured()
) {
    $b2 = new B2VideoStorageService();
}

try {
    $activeAnalyses = (new MatchVideoAnalysisService())->listActiveStrict();
    $protection = new VideoStorageProtectionService($activeAnalyses, $b2);
} catch (\Throwable $e) {
    VideoStorageProtectionService::releaseLock($retentionLock);
    writeCleanupMessage(
        "Video cleanup aborted because active analysis leases could not be verified: {$e->getMessage()}\n",
        true
    );
    exit(1);
}

$expiredFiles = 0;
$skippedFiles = 0;
$protectedFiles = 0;
$matchUpdates = 0;
$analysisUpdates = 0;
$failedDeletes = 0;

if (
    strtolower(trim((string)(getenv('NUTMEG_VIDEO_STORAGE') ?: 'local'))) === 'b2'
    && B2VideoStorageService::isConfigured()
) {
    try {
        $b2 = $b2 ?? new B2VideoStorageService();
        foreach ($b2->listVideos(1000) as $video) {
            $modifiedAt = (int)($video['modified_at'] ?? 0);
            $videoUrl = trim((string)($video['url'] ?? ''));
            if ($videoUrl === '' || $modifiedAt <= 0 || $modifiedAt > $cutoffTs) {
                $skippedFiles++;
                continue;
            }
            if ($protection->protects(
                $videoUrl,
                isset($video['match_id']) ? (int)$video['match_id'] : null,
                (string)($video['object_name'] ?? '')
            )) {
                writeCleanupMessage("Protected active-analysis B2 object {$videoUrl}\n");
                $protectedFiles++;
                continue;
            }
            if ($dryRun) {
                writeCleanupMessage("[dry-run] Would expire B2 object {$videoUrl}\n");
                $expiredFiles++;
                continue;
            }
            if ($b2->deleteVideo($videoUrl)) {
                $result = updateExpiredVideoReferences($db, [$videoUrl], $timestamp);
                $matchUpdates += $result['match_updates'];
                $analysisUpdates += $result['analysis_updates'];
                writeCleanupMessage("Expired B2 object {$videoUrl}\n");
                $expiredFiles++;
            } else {
                writeCleanupMessage("Failed to delete B2 object {$videoUrl}\n", true);
                $failedDeletes++;
            }
        }
    } catch (\Throwable $e) {
        writeCleanupMessage("Backblaze cleanup failed: {$e->getMessage()}\n", true);
        $failedDeletes++;
    }
}

if (!is_dir($uploadDir)) {
    writeCleanupMessage("Legacy upload directory not found: {$uploadDir}\n");
    writeCleanupMessage(sprintf(
        "Complete. expired_files=%d skipped_recent=%d protected_active=%d match_updates=%d analysis_updates=%d failed_deletes=%d retention_days=%d%s\n",
        $expiredFiles,
        $skippedFiles,
        $protectedFiles,
        $matchUpdates,
        $analysisUpdates,
        $failedDeletes,
        $retentionDays,
        $dryRun ? ' dry_run=1' : ''
    ));
    VideoStorageProtectionService::releaseLock($retentionLock);
    exit($failedDeletes > 0 ? 1 : 0);
}

$iterator = new DirectoryIterator($uploadDir);
foreach ($iterator as $fileInfo) {
    if ($fileInfo->isDot() || !$fileInfo->isFile()) {
        continue;
    }

    $basename = $fileInfo->getBasename();
    if ($basename === '.gitkeep') {
        continue;
    }

    if ($fileInfo->getMTime() > $cutoffTs) {
        $skippedFiles++;
        continue;
    }

    $localMatchId = null;
    if (preg_match('/^match_(\d+)_/i', $basename, $matchParts) === 1) {
        $localMatchId = (int)$matchParts[1];
    }
    $localUrl = '/videos/' . $basename;
    if ($protection->protects($localUrl, $localMatchId)) {
        writeCleanupMessage("Protected active-analysis local video {$basename}\n");
        $protectedFiles++;
        continue;
    }

    if ($dryRun) {
        writeCleanupMessage("[dry-run] Would expire {$basename}\n");
        $expiredFiles++;
        continue;
    }

    if (@unlink($fileInfo->getPathname())) {
        $result = updateExpiredVideoReferences($db, collectWebsiteVideoAliases($basename), $timestamp);
        $matchUpdates += $result['match_updates'];
        $analysisUpdates += $result['analysis_updates'];
        writeCleanupMessage("Expired {$basename}\n");
        $expiredFiles++;
    } else {
        writeCleanupMessage("Failed to delete {$basename}\n", true);
        $failedDeletes++;
    }
}

writeCleanupMessage(sprintf(
    "Complete. expired_files=%d skipped_recent=%d protected_active=%d match_updates=%d analysis_updates=%d failed_deletes=%d retention_days=%d%s\n",
    $expiredFiles,
    $skippedFiles,
    $protectedFiles,
    $matchUpdates,
    $analysisUpdates,
    $failedDeletes,
    $retentionDays,
    $dryRun ? ' dry_run=1' : ''
));

VideoStorageProtectionService::releaseLock($retentionLock);
exit($failedDeletes > 0 ? 1 : 0);

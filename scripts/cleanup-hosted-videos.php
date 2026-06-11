<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\SupabaseClient;

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

if (!is_dir($uploadDir)) {
    fwrite(STDOUT, "Upload directory not found: {$uploadDir}\n");
    exit(0);
}

$db = SupabaseClient::getInstance();

$expiredFiles = 0;
$skippedFiles = 0;
$matchUpdates = 0;
$analysisUpdates = 0;
$failedDeletes = 0;

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

    if ($dryRun) {
        fwrite(STDOUT, "[dry-run] Would expire {$basename}\n");
        $expiredFiles++;
        continue;
    }

    if (@unlink($fileInfo->getPathname())) {
        $result = updateExpiredVideoReferences($db, collectWebsiteVideoAliases($basename), $timestamp);
        $matchUpdates += $result['match_updates'];
        $analysisUpdates += $result['analysis_updates'];
        fwrite(STDOUT, "Expired {$basename}\n");
        $expiredFiles++;
    } else {
        fwrite(STDERR, "Failed to delete {$basename}\n");
        $failedDeletes++;
    }
}

fwrite(STDOUT, sprintf(
    "Complete. expired_files=%d skipped_recent=%d match_updates=%d analysis_updates=%d failed_deletes=%d retention_days=%d%s\n",
    $expiredFiles,
    $skippedFiles,
    $matchUpdates,
    $analysisUpdates,
    $failedDeletes,
    $retentionDays,
    $dryRun ? ' dry_run=1' : ''
));

exit($failedDeletes > 0 ? 1 : 0);

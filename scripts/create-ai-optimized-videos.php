<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\B2VideoStorageService;

$options = getopt('', ['dry-run', 'limit::', 'prefix::']);
$dryRun = array_key_exists('dry-run', $options);
$limit = max(1, min(1000, (int)($options['limit'] ?? 1000)));
$prefix = trim((string)($options['prefix'] ?? ''));

if (!B2VideoStorageService::isConfigured()) {
    fwrite(STDERR, "Backblaze B2 storage is not configured.\n");
    exit(1);
}

$storage = new B2VideoStorageService();
$videos = $storage->listVideos($limit, $prefix);
$checked = 0;
$created = 0;
$skipped = 0;
$failed = 0;

printf(
    "Creating AI-optimized copies for %d B2 video(s)%s.%s\n",
    count($videos),
    $prefix !== '' ? " under prefix {$prefix}" : '',
    $dryRun ? ' Dry run only' : ''
);

foreach ($videos as $video) {
    $checked++;
    $url = trim((string)($video['url'] ?? ''));
    $objectName = trim((string)($video['object_name'] ?? ''));
    if ($url === '' || $objectName === '') {
        $skipped++;
        continue;
    }

    try {
        if ($storage->aiOptimizedUrlForVideoUrl($url) !== null) {
            echo "skip exists {$objectName}\n";
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "[dry-run] optimize {$objectName}\n";
            $created++;
            continue;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'nutmeg-ai-src-');
        if ($inputPath === false) {
            throw new RuntimeException('Could not create a temporary download file.');
        }

        try {
            downloadFile($url, $inputPath);
            $optimized = $storage->createAiOptimizedCopy(
                $inputPath,
                $objectName,
                (string)($video['name'] ?? basename($objectName))
            );
            if (!is_array($optimized) || trim((string)($optimized['video_url'] ?? '')) === '') {
                throw new RuntimeException('AI optimized copy was not created. Check that ffmpeg is installed.');
            }
            echo "created {$objectName} -> " . basename((string)$optimized['object_name']) . "\n";
            $created++;
        } finally {
            @unlink($inputPath);
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "failed {$objectName}: {$e->getMessage()}\n");
    }
}

printf(
    "Complete. checked=%d created=%d skipped=%d failed=%d dry_run=%s\n",
    $checked,
    $created,
    $skipped,
    $failed,
    $dryRun ? 'yes' : 'no'
);

exit($failed > 0 ? 1 : 0);

function downloadFile(string $url, string $targetPath): void
{
    $handle = fopen($targetPath, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open temporary download file.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 3600,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    fclose($handle);

    if ($error !== '' || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Download HTTP ' . $status);
    }
}

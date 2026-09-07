<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\B2VideoStorageService;

$options = getopt('', ['base-url:', 'limit::', 'prefix::']);
$baseUrl = rtrim(trim((string)($options['base-url'] ?? '')), '/');
$limit = max(1, min(1000, (int)($options['limit'] ?? 1000)));
$prefix = trim((string)($options['prefix'] ?? ''));

if ($baseUrl === '') {
    fwrite(STDERR, "Usage: php scripts/runpod-optimize-b2-videos.php --base-url=https://pod-8888.proxy.runpod.net [--limit=10] [--prefix=folder/]\n");
    exit(1);
}

if (!B2VideoStorageService::isConfigured()) {
    fwrite(STDERR, "Backblaze B2 storage is not configured.\n");
    exit(1);
}

$storage = new B2VideoStorageService();
$videos = $storage->listVideos($limit, $prefix);
$missing = [];
foreach ($videos as $video) {
    $url = trim((string)($video['url'] ?? ''));
    $objectName = trim((string)($video['object_name'] ?? ''));
    if ($url === '' || $objectName === '') {
        continue;
    }
    if ($storage->aiOptimizedUrlForVideoUrl($url) !== null) {
        continue;
    }
    $missing[] = $video;
}

printf("RunPod optimizer: %d missing optimized video(s) out of %d listed.\n", count($missing), count($videos));

$done = 0;
$failed = 0;
foreach ($missing as $index => $video) {
    $objectName = (string)$video['object_name'];
    $videoUrl = (string)$video['url'];
    printf("\n[%d/%d] %s\n", $index + 1, count($missing), $objectName);

    try {
        $job = postJson($baseUrl . '/optimize-video-async', [
            'video_url' => $videoUrl,
            'object_name' => $objectName,
            'width' => 960,
            'fps' => 15,
            'crf' => 28,
        ]);
        $jobId = trim((string)($job['job_id'] ?? ''));
        if ($jobId === '') {
            throw new RuntimeException('RunPod did not return a job_id.');
        }

        waitForRunpodJob($baseUrl, $jobId);

        $temp = tempnam(sys_get_temp_dir(), 'nutmeg-runpod-opt-');
        if ($temp === false) {
            throw new RuntimeException('Could not create a temporary optimized file.');
        }
        $outputPath = $temp . '.mp4';
        try {
            downloadFile($baseUrl . '/optimized-videos/' . rawurlencode($jobId), $outputPath);
            $targetObject = aiOptimizedObjectName($objectName);
            $stored = $storage->uploadObject($outputPath, $targetObject, 'video/mp4', [
                'purpose' => 'ai_optimized',
                'original_object_name' => $objectName,
                'original_name' => (string)($video['name'] ?? basename($objectName)),
                'ai_width' => '960',
                'ai_fps' => '15',
                'optimized_by' => 'runpod',
            ]);
            printf("\ncreated %s (%0.1f MB)\n", $targetObject, filesize($outputPath) / 1048576);
            $done++;
        } finally {
            @unlink($temp);
            @unlink($outputPath);
        }
    } catch (Throwable $e) {
        $failed++;
        printf("\nFAILED %s: %s\n", $objectName, $e->getMessage());
    }
}

printf("\nComplete. created=%d failed=%d remaining_started=%d\n", $done, $failed, count($missing));
exit($failed > 0 ? 1 : 0);

function waitForRunpodJob(string $baseUrl, string $jobId): void
{
    $lastLineLength = 0;
    while (true) {
        $job = getJson($baseUrl . '/jobs/' . rawurlencode($jobId));
        $status = strtolower(trim((string)($job['status'] ?? 'processing')));
        $progress = is_array($job['progress'] ?? null) ? $job['progress'] : [];
        $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));
        $stage = (string)($progress['stage'] ?? $job['stage'] ?? $status);
        $message = (string)($progress['message'] ?? '');

        $line = progressLine($percent, $stage, $message);
        echo "\r" . str_pad($line, max($lastLineLength, strlen($line)));
        $lastLineLength = strlen($line);

        if ($status === 'completed') {
            echo "\n";
            return;
        }

        if ($status === 'failed') {
            echo "\n";
            throw new RuntimeException((string)($job['error'] ?? $message ?: 'RunPod optimization failed.'));
        }

        sleep(5);
    }
}

function progressLine(int $percent, string $stage, string $message): string
{
    $width = 32;
    $filled = (int)floor(($percent / 100) * $width);
    $bar = str_repeat('#', $filled) . str_repeat('-', $width - $filled);
    return sprintf('[%s] %3d%% %-14s %s', $bar, $percent, $stage, mb_strimwidth($message, 0, 70));
}

function aiOptimizedObjectName(string $objectName): string
{
    $objectName = ltrim(trim($objectName), '/');
    $directory = trim((string)pathinfo($objectName, PATHINFO_DIRNAME), '.');
    $filename = pathinfo($objectName, PATHINFO_FILENAME);
    return 'ai-optimized/' . ($directory !== '' ? $directory . '/' : '') . $filename . '_ai960_15fps.mp4';
}

function postJson(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    return decodeCurlResponse($ch);
}

function getJson(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    return decodeCurlResponse($ch);
}

function decodeCurlResponse($ch): array
{
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    $decoded = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException(sprintf('HTTP %d: %s', $status, substr((string)$body, 0, 500)));
    }
    return $decoded;
}

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

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

$ffprobe = findBinary('ffprobe');
$ffmpeg = findBinary('ffmpeg');
if ($ffprobe === '' || $ffmpeg === '') {
    fwrite(STDERR, "ffmpeg and ffprobe are required on this machine.\n");
    exit(1);
}
$videoEncoder = supportsEncoder($ffmpeg, 'h264_videotoolbox')
    ? 'h264_videotoolbox'
    : 'libx264';

$storage = new B2VideoStorageService();
$videos = $storage->listVideos($limit, $prefix);
$checked = 0;
$converted = 0;
$skipped = 0;
$failed = 0;

printf(
    "Checking %d B2 video(s)%s.%s\n",
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
        $probe = probeVideo($ffprobe, $url);
        $videoStream = firstVideoStream($probe);
        if ($videoStream === null) {
            echo "skip no-video {$objectName}\n";
            $skipped++;
            continue;
        }

        $codec = strtolower((string)($videoStream['codec_name'] ?? ''));
        $tag = strtolower((string)($videoStream['codec_tag_string'] ?? ''));
        $pixFmt = strtolower((string)($videoStream['pix_fmt'] ?? ''));
        $needsTranscode = $codec !== 'h264' || $tag !== 'avc1' || $pixFmt !== 'yuv420p';

        if (!$needsTranscode) {
            echo "skip ios-ok {$objectName} codec={$codec} tag={$tag} pix_fmt={$pixFmt}\n";
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "[dry-run] transcode {$objectName} codec={$codec} tag={$tag} pix_fmt={$pixFmt}\n";
            $converted++;
            continue;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'nutmeg-b2-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'nutmeg-b2-out-');
        if ($inputPath === false || $outputPath === false) {
            throw new RuntimeException('Could not create temporary files.');
        }
        $outputMp4 = $outputPath . '.mp4';

        try {
            downloadFile($url, $inputPath);
            transcodeToH264($ffmpeg, $inputPath, $outputMp4, $videoEncoder);
            $stored = $storage->uploadObject($outputMp4, $objectName, 'video/mp4', [
                'codec' => 'h264',
                'profile' => 'main-4.2',
                'compatibility' => 'ios-android',
                'original_name' => (string)($video['name'] ?? basename($objectName)),
                'transcoded_from_codec' => $codec !== '' ? $codec : 'unknown',
            ]);
            $converted++;
            echo "converted {$objectName} -> " . ($stored['video_url'] ?? $url) . "\n";
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
            @unlink($outputMp4);
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "failed {$objectName}: {$e->getMessage()}\n");
    }
}

printf(
    "Complete. checked=%d converted=%d skipped=%d failed=%d dry_run=%s\n",
    $checked,
    $converted,
    $skipped,
    $failed,
    $dryRun ? 'yes' : 'no'
);

exit($failed > 0 ? 1 : 0);

function findBinary(string $name): string
{
    $path = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return is_executable($path) ? $path : '';
}

function probeVideo(string $ffprobe, string $url): array
{
    $command = [
        $ffprobe,
        '-v',
        'error',
        '-show_entries',
        'stream=index,codec_type,codec_name,codec_tag_string,pix_fmt,width,height,profile',
        '-of',
        'json',
        $url,
    ];
    $output = runCommand($command);
    $decoded = json_decode($output, true);
    return is_array($decoded) ? $decoded : [];
}

function firstVideoStream(array $probe): ?array
{
    foreach (($probe['streams'] ?? []) as $stream) {
        if (is_array($stream) && ($stream['codec_type'] ?? '') === 'video') {
            return $stream;
        }
    }
    return null;
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
        CURLOPT_TIMEOUT => 1800,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    fclose($handle);

    if ($error !== '' || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Download HTTP ' . $status);
    }
}

function supportsEncoder(string $ffmpeg, string $encoder): bool
{
    $command = escapeshellarg($ffmpeg)
        . ' -hide_banner -encoders 2>/dev/null | grep -q '
        . escapeshellarg($encoder);
    exec($command, $output, $status);
    return $status === 0;
}

function transcodeToH264(
    string $ffmpeg,
    string $inputPath,
    string $outputPath,
    string $videoEncoder
): void
{
    $command = [
        $ffmpeg,
        '-y',
        '-i',
        $inputPath,
        '-map',
        '0:v:0',
        '-map',
        '0:a?',
        '-c:v',
        $videoEncoder,
        '-profile:v',
        'main',
        '-level',
        '4.2',
        '-pix_fmt',
        'yuv420p',
    ];
    if ($videoEncoder === 'h264_videotoolbox') {
        array_push($command, '-b:v', '8M', '-realtime', 'true');
    } else {
        array_push($command, '-preset', 'veryfast', '-crf', '22');
    }
    array_push(
        $command,
        '-c:a',
        'aac',
        '-b:a',
        '128k',
        '-movflags',
        '+faststart',
        $outputPath,
    );
    runCommand($command);
}

function runCommand(array $parts): string
{
    $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    $text = implode("\n", $output);
    if ($status !== 0) {
        throw new RuntimeException($text !== '' ? $text : 'Command failed.');
    }
    return $text;
}

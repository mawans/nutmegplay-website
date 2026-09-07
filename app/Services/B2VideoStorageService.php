<?php
namespace App\Services;

use RuntimeException;

/**
 * Server-side Backblaze B2 video storage.
 *
 * Credentials stay in the PHP environment. Mobile and browser clients receive
 * only public object URLs (or authorized URLs if the bucket becomes private).
 */
class B2VideoStorageService
{
    private const AUTHORIZE_URL = 'https://api.backblazeb2.com/b2api/v4/b2_authorize_account';
    private const AI_OPTIMIZED_PREFIX = 'ai-optimized/';

    private string $keyId;
    private string $applicationKey;
    private string $bucketName;
    private string $bucketId;
    private string $downloadUrl;
    private string $apiUrl;
    private string $authorizationToken;
    private string $bucketType;

    public function __construct()
    {
        $this->keyId = trim((string)(getenv('NUTMEG_B2_KEY_ID') ?: ''));
        $this->applicationKey = trim((string)(getenv('NUTMEG_B2_APPLICATION_KEY') ?: ''));
        $this->bucketName = trim((string)(getenv('NUTMEG_B2_BUCKET') ?: 'foot-videos'));
        $this->bucketId = trim((string)(getenv('NUTMEG_B2_BUCKET_ID') ?: ''));
        $this->downloadUrl = '';
        $this->apiUrl = '';
        $this->authorizationToken = '';
        $this->bucketType = '';
    }

    public static function isConfigured(): bool
    {
        return trim((string)(getenv('NUTMEG_B2_KEY_ID') ?: '')) !== ''
            && trim((string)(getenv('NUTMEG_B2_APPLICATION_KEY') ?: '')) !== ''
            && trim((string)(getenv('NUTMEG_B2_BUCKET') ?: '')) !== '';
    }

    public function uploadVideo(string $path, string $originalName, string $mimeType, int $matchId): array
    {
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['mp4', 'mov', 'avi', 'webm', 'mkv'], true)) {
            $extension = 'mp4';
        }

        $objectName = sprintf(
            'matches/%d/match_%d_%s.%s',
            $matchId,
            $matchId,
            bin2hex(random_bytes(8)),
            $extension
        );

        return $this->uploadObject($path, $objectName, $mimeType, [
            'match_id' => (string)$matchId,
            'original_name' => $originalName,
        ]);
    }

    public function uploadObject(
        string $path,
        string $objectName,
        string $mimeType = 'application/octet-stream',
        array $fileInfo = []
    ): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The uploaded video could not be read.');
        }

        $objectName = ltrim(trim($objectName), '/');
        if ($objectName === '' || str_contains($objectName, '..')) {
            throw new RuntimeException('The Backblaze object name is invalid.');
        }

        $this->authorize();
        $uploadTarget = $this->postJson(
            $this->apiUrl . '/b2api/v4/b2_get_upload_url',
            ['bucketId' => $this->bucketId],
            $this->authorizationToken
        );
        $uploadUrl = trim((string)($uploadTarget['uploadUrl'] ?? ''));
        $uploadToken = trim((string)($uploadTarget['authorizationToken'] ?? ''));
        if ($uploadUrl === '' || $uploadToken === '') {
            throw new RuntimeException('Backblaze did not provide an upload URL.');
        }

        $size = filesize($path);
        $sha1 = sha1_file($path);
        if ($size === false || $sha1 === false) {
            throw new RuntimeException('The video checksum could not be calculated.');
        }

        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The uploaded video could not be opened.');
        }

        $infoHeaders = [];
        foreach ($fileInfo as $name => $value) {
            $normalizedName = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$name);
            if (!is_string($normalizedName) || $normalizedName === '') {
                continue;
            }
            $infoHeaders[] = 'X-Bz-Info-' . $normalizedName . ': ' . rawurlencode((string)$value);
        }

        $ch = curl_init($uploadUrl);
        try {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 900,
                CURLOPT_HTTPHEADER => array_merge([
                    'Authorization: ' . $uploadToken,
                    'Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'),
                    'Content-Length: ' . $size,
                    'X-Bz-Content-Sha1: ' . $sha1,
                    'X-Bz-File-Name: ' . $this->encodeObjectName($objectName),
                    'X-Bz-Info-src_last_modified_millis: ' . ((string)(time() * 1000)),
                ], $infoHeaders),
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = trim((string)curl_error($ch));
        } finally {
            fclose($handle);
        }

        if ($error !== '') {
            throw new RuntimeException('Backblaze upload failed: ' . $error);
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded)
                ? (string)($decoded['message'] ?? $decoded['code'] ?? 'Upload failed.')
                : 'Upload failed.';
            throw new RuntimeException(sprintf('Backblaze upload failed (HTTP %d): %s', $status, $message));
        }

        return [
            'file_id' => (string)($decoded['fileId'] ?? ''),
            'object_name' => $objectName,
            'size_bytes' => (int)$size,
            'video_url' => $this->downloadUrlForObject($objectName),
        ];
    }

    public function listVideos(int $limit = 250, string $prefix = ''): array
    {
        $this->authorize();
        $payload = $this->postJson(
            $this->apiUrl . '/b2api/v4/b2_list_file_names',
            [
                'bucketId' => $this->bucketId,
                'prefix' => $prefix,
                'maxFileCount' => max(1, min(1000, $limit)),
            ],
            $this->authorizationToken
        );

        $videos = [];
        foreach (($payload['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $objectName = trim((string)($file['fileName'] ?? ''));
            if (str_starts_with($objectName, self::AI_OPTIMIZED_PREFIX)) {
                continue;
            }
            $extension = strtolower((string)pathinfo($objectName, PATHINFO_EXTENSION));
            if ($objectName === '' || !in_array($extension, ['mp4', 'mov', 'avi', 'webm', 'mkv'], true)) {
                continue;
            }
            $modifiedAt = (int)floor(((int)($file['uploadTimestamp'] ?? 0)) / 1000);
            $metadata = $this->metadataForObjectName($objectName, $modifiedAt);
            $matchId = null;
            if (preg_match('#^matches/(\d+)/#', $objectName, $matches) === 1) {
                $matchId = (int)$matches[1];
            }
            $displayName = $this->originalNameFromFileInfo($file)
                ?: (string)($metadata['file_name'] ?? basename($objectName));
            $videos[] = [
                'file_id' => (string)($file['fileId'] ?? ''),
                'object_name' => $objectName,
                'name' => $displayName,
                'file_name' => $displayName,
                'url' => $this->downloadUrlForObject($objectName),
                'size_bytes' => (int)($file['contentLength'] ?? 0),
                'modified_at' => $modifiedAt,
                'match_id' => $matchId,
            ] + $metadata;
        }

        usort(
            $videos,
            static fn(array $left, array $right): int =>
                (int)$right['modified_at'] <=> (int)$left['modified_at']
        );
        return $videos;
    }

    public function createAiOptimizedCopy(
        string $sourcePath,
        string $originalObjectName,
        string $originalName = ''
    ): ?array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }

        $ffmpeg = $this->findBinary('ffmpeg');
        if ($ffmpeg === '') {
            return null;
        }

        $originalObjectName = ltrim(trim($originalObjectName), '/');
        if ($originalObjectName === '' || str_starts_with($originalObjectName, self::AI_OPTIMIZED_PREFIX)) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'nutmeg-ai-video-');
        if ($temp === false) {
            return null;
        }
        $outputPath = $temp . '.mp4';

        try {
            $this->transcodeForAi($ffmpeg, $sourcePath, $outputPath);
            if (!is_file($outputPath) || filesize($outputPath) === 0) {
                return null;
            }

            return $this->uploadObject(
                $outputPath,
                $this->aiOptimizedObjectName($originalObjectName),
                'video/mp4',
                [
                    'purpose' => 'ai_optimized',
                    'original_object_name' => $originalObjectName,
                    'original_name' => $originalName !== '' ? $originalName : basename($originalObjectName),
                    'ai_width' => '960',
                    'ai_fps' => '15',
                ]
            );
        } finally {
            @unlink($temp);
            @unlink($outputPath);
        }
    }

    public function aiOptimizedUrlForVideoUrl(string $videoUrl): ?string
    {
        $objectName = $this->objectNameFromUrl($videoUrl);
        if ($objectName === null || str_starts_with($objectName, self::AI_OPTIMIZED_PREFIX)) {
            return null;
        }

        $optimizedObject = $this->aiOptimizedObjectName($objectName);
        return $this->objectExists($optimizedObject)
            ? $this->downloadUrlForObject($optimizedObject)
            : null;
    }

    public function objectExists(string $objectName): bool
    {
        $objectName = ltrim(trim($objectName), '/');
        if ($objectName === '') {
            return false;
        }

        $this->authorize();
        $payload = $this->postJson(
            $this->apiUrl . '/b2api/v4/b2_list_file_names',
            [
                'bucketId' => $this->bucketId,
                'prefix' => $objectName,
                'maxFileCount' => 1,
            ],
            $this->authorizationToken
        );

        foreach (($payload['files'] ?? []) as $file) {
            if (is_array($file) && trim((string)($file['fileName'] ?? '')) === $objectName) {
                return true;
            }
        }

        return false;
    }

    private function metadataForObjectName(string $objectName, int $modifiedAt = 0): array
    {
        $objectName = ltrim(trim($objectName), '/');
        $directory = trim((string)pathinfo($objectName, PATHINFO_DIRNAME), '.');
        $filename = basename($objectName);
        $folderName = $directory !== '' ? basename($directory) : '';
        $folderMeta = $this->metadataFromFolderName($folderName, $modifiedAt);
        $clipMeta = $this->metadataFromClipName($filename);

        $groupKey = $directory !== ''
            ? 'b2-folder-' . strtolower($directory)
            : 'b2-file-' . strtolower((string)pathinfo($filename, PATHINFO_FILENAME));

        return array_filter([
            'folder_name' => $folderName !== '' ? $folderName : null,
            'folder_path' => $directory !== '' ? $directory : null,
            'file_name' => $filename,
            'video_group_key' => $groupKey,
            'video_part_number' => $clipMeta['part_number'] ?? null,
            'camera_number' => $clipMeta['camera_number'] ?? null,
            'recording_date' => $folderMeta['date'] ?? null,
            'recording_time' => $folderMeta['time'] ?? null,
            'recording_field' => $folderMeta['field'] ?? null,
            'recording_title' => $folderMeta['title'] ?? null,
            'recording_location' => $folderMeta['location'] ?? null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function metadataFromFolderName(string $folderName, int $modifiedAt = 0): array
    {
        $folderName = trim($folderName);
        if ($folderName === '') {
            return [];
        }

        if (preg_match('/^(?:(\d{4}))?(\d{2})(\d{2})_(\d{1,2})h(\d{2})_(fenomeno|san_siro)$/i', $folderName, $matches) !== 1) {
            return [];
        }

        $year = trim((string)($matches[1] ?? ''));
        if ($year === '') {
            $year = $modifiedAt > 0 ? gmdate('Y', $modifiedAt) : gmdate('Y');
        }

        $month = (int)$matches[2];
        $day = (int)$matches[3];
        $hour = (int)$matches[4];
        $minute = (int)$matches[5];
        if (!checkdate($month, $day, (int)$year) || $hour > 23 || $minute > 59) {
            return [];
        }

        $fieldKey = strtolower((string)$matches[6]);
        $field = $fieldKey === 'san_siro' ? 'San Siro' : 'Fenomeno';
        $date = sprintf('%04d-%02d-%02d', (int)$year, $month, $day);
        $time = sprintf('%02d:%02d:00', $hour, $minute);

        return [
            'date' => $date,
            'time' => $time,
            'field' => $fieldKey,
            'location' => $field,
            'title' => sprintf('%s %02d/%02d %02d:%02d', $field, $month, $day, $hour, $minute),
        ];
    }

    private function metadataFromClipName(string $filename): array
    {
        $name = (string)pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/(?:^|[_-])(?:cam|camera)[_-]?(\d+)[_-]part[_-]?(\d+)(?:$|[_-])/i', $name, $matches) !== 1) {
            return [];
        }

        return [
            'camera_number' => (int)$matches[1],
            'part_number' => (int)$matches[2],
        ];
    }

    private function aiOptimizedObjectName(string $objectName): string
    {
        $objectName = ltrim(trim($objectName), '/');
        $directory = trim((string)pathinfo($objectName, PATHINFO_DIRNAME), '.');
        $filename = pathinfo($objectName, PATHINFO_FILENAME);
        $target = ($directory !== '' ? $directory . '/' : '') . $filename . '_ai960_15fps.mp4';
        return self::AI_OPTIMIZED_PREFIX . $target;
    }

    private function findBinary(string $name): string
    {
        $path = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return is_executable($path) ? $path : '';
    }

    private function transcodeForAi(string $ffmpeg, string $inputPath, string $outputPath): void
    {
        $command = [
            $ffmpeg,
            '-y',
            '-i',
            $inputPath,
            '-map',
            '0:v:0',
            '-vf',
            'fps=15,scale=trunc(min(960\,iw)/2)*2:-2',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '28',
            '-pix_fmt',
            'yuv420p',
            '-an',
            '-movflags',
            '+faststart',
            $outputPath,
        ];

        $output = [];
        $status = 0;
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException(implode("\n", $output) ?: 'AI video optimization failed.');
        }
    }

    private function originalNameFromFileInfo(array $file): ?string
    {
        $fileInfo = is_array($file['fileInfo'] ?? null) ? $file['fileInfo'] : [];
        $value = trim(rawurldecode((string)(
            $fileInfo['original_name']
            ?? $fileInfo['original-name']
            ?? ''
        )));
        return $value !== '' ? basename($value) : null;
    }

    public function deleteVideo(string $videoUrl): bool
    {
        $objectName = $this->objectNameFromUrl($videoUrl);
        if ($objectName === null) {
            return false;
        }

        $this->authorize();
        foreach ($this->listVideos(1000, $objectName) as $file) {
            if ((string)($file['object_name'] ?? '') !== $objectName) {
                continue;
            }
            $fileId = trim((string)($file['file_id'] ?? ''));
            if ($fileId === '') {
                continue;
            }
            $this->postJson(
                $this->apiUrl . '/b2api/v4/b2_delete_file_version',
                ['fileName' => $objectName, 'fileId' => $fileId],
                $this->authorizationToken
            );
            return true;
        }

        return true;
    }

    public function isManagedUrl(string $videoUrl): bool
    {
        return $this->objectNameFromUrl($videoUrl) !== null;
    }

    public function objectNameFromUrl(string $videoUrl): ?string
    {
        $value = trim($videoUrl);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'b2://')) {
            $withoutScheme = substr($value, 5);
            $separator = strpos($withoutScheme, '/');
            if ($separator === false) {
                return null;
            }
            $bucket = substr($withoutScheme, 0, $separator);
            return $bucket === $this->bucketName
                ? ltrim(substr($withoutScheme, $separator + 1), '/')
                : null;
        }

        $parts = parse_url($value);
        $path = rawurldecode((string)($parts['path'] ?? ''));
        $marker = '/file/' . $this->bucketName . '/';
        $position = strpos($path, $marker);
        if ($position !== false) {
            return ltrim(substr($path, $position + strlen($marker)), '/');
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $s3Endpoint = strtolower((string)parse_url((string)(getenv('NUTMEG_B2_S3_ENDPOINT') ?: ''), PHP_URL_HOST));
        if ($s3Endpoint !== '' && $host === $s3Endpoint) {
            $prefix = '/' . $this->bucketName . '/';
            return str_starts_with($path, $prefix)
                ? ltrim(substr($path, strlen($prefix)), '/')
                : null;
        }

        return null;
    }

    private function authorize(): void
    {
        if ($this->authorizationToken !== '') {
            return;
        }
        if ($this->keyId === '' || $this->applicationKey === '' || $this->bucketName === '') {
            throw new RuntimeException('Backblaze B2 storage is not configured.');
        }

        $ch = curl_init(self::AUTHORIZE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->applicationKey),
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        $decoded = is_string($body) ? json_decode($body, true) : null;

        if ($error !== '' || $status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('Backblaze authorization failed.');
        }

        $storage = $decoded['apiInfo']['storageApi'] ?? [];
        $this->authorizationToken = trim((string)($decoded['authorizationToken'] ?? ''));
        $this->apiUrl = rtrim((string)($storage['apiUrl'] ?? ''), '/');
        $this->downloadUrl = rtrim((string)($storage['downloadUrl'] ?? ''), '/');
        if ($this->authorizationToken === '' || $this->apiUrl === '' || $this->downloadUrl === '') {
            throw new RuntimeException('Backblaze authorization response was incomplete.');
        }

        if ($this->bucketId === '') {
            $buckets = $this->postJson(
                $this->apiUrl . '/b2api/v4/b2_list_buckets',
                ['accountId' => (string)($decoded['accountId'] ?? ''), 'bucketName' => $this->bucketName],
                $this->authorizationToken
            );
            $bucket = $buckets['buckets'][0] ?? null;
            if (!is_array($bucket)) {
                throw new RuntimeException('Backblaze video bucket was not found.');
            }
            $this->bucketId = trim((string)($bucket['bucketId'] ?? ''));
            $this->bucketType = trim((string)($bucket['bucketType'] ?? ''));
        }
    }

    private function downloadUrlForObject(string $objectName): string
    {
        $base = $this->downloadUrl !== ''
            ? $this->downloadUrl
            : rtrim((string)(getenv('NUTMEG_B2_DOWNLOAD_URL') ?: ''), '/');
        if ($base === '') {
            throw new RuntimeException('Backblaze download URL is not configured.');
        }
        return $base . '/file/' . rawurlencode($this->bucketName) . '/' . $this->encodeObjectPath($objectName);
    }

    private function postJson(string $url, array $payload, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        $decoded = is_string($body) ? json_decode($body, true) : null;

        if ($error !== '') {
            throw new RuntimeException('Backblaze request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded)
                ? (string)($decoded['message'] ?? $decoded['code'] ?? 'Request failed.')
                : 'Request failed.';
            throw new RuntimeException(sprintf('Backblaze request failed (HTTP %d): %s', $status, $message));
        }

        return $decoded;
    }

    private function encodeObjectName(string $objectName): string
    {
        return $this->encodeObjectPath($objectName);
    }

    private function encodeObjectPath(string $objectName): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($objectName, '/'))));
    }
}

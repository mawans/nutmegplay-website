<?php
namespace App\Services;

use RuntimeException;

/**
 * Coordinates analysis queueing with destructive video cleanup.
 */
class VideoStorageProtectionService
{
    /** @var array<int, true> */
    private array $matchIds = [];

    /** @var array<string, true> */
    private array $urlKeys = [];

    /** @var array<string, true> */
    private array $objectDirectories = [];

    /**
     * @param array<int, array<string, mixed>> $activeAnalyses
     */
    public function __construct(array $activeAnalyses, ?B2VideoStorageService $storage = null)
    {
        foreach ($activeAnalyses as $analysis) {
            if (!is_array($analysis)) {
                continue;
            }

            $status = strtolower(trim((string)($analysis['processing_status'] ?? '')));
            if (!in_array($status, ['queued', 'processing'], true)) {
                continue;
            }

            $matchId = (int)($analysis['match_id'] ?? 0);
            if ($matchId > 0) {
                $this->matchIds[$matchId] = true;
            }

            $this->protectUrl((string)($analysis['video_url'] ?? ''), $storage);
            $metadata = is_array($analysis['ai_output'] ?? null) ? $analysis['ai_output'] : [];
            $selected = is_array($metadata['selected_clip'] ?? null) ? $metadata['selected_clip'] : [];
            $this->protectUrl((string)($selected['video_url'] ?? ''), $storage);
            $this->protectUrl((string)($selected['ai_video_url'] ?? ''), $storage);

            foreach (($metadata['runpod_jobs'] ?? []) as $job) {
                if (!is_array($job)) {
                    continue;
                }
                $this->protectUrl((string)($job['video_url'] ?? ''), $storage);
                $this->protectUrl((string)($job['original_video_url'] ?? ''), $storage);
            }
        }
    }

    public static function acquireSharedLock()
    {
        return self::acquireLock(LOCK_SH);
    }

    public static function acquireExclusiveLock()
    {
        return self::acquireLock(LOCK_EX);
    }

    public static function releaseLock(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function protects(string $videoUrl, ?int $matchId = null, ?string $objectName = null): bool
    {
        if ($matchId !== null && $matchId > 0 && isset($this->matchIds[$matchId])) {
            return true;
        }

        $urlKey = self::urlKey($videoUrl);
        if ($urlKey !== '' && isset($this->urlKeys[$urlKey])) {
            return true;
        }

        $objectName = ltrim(trim((string)$objectName), '/');
        if ($objectName === '') {
            return false;
        }

        foreach (array_keys($this->objectDirectories) as $directory) {
            if ($objectName === $directory || str_starts_with($objectName, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function acquireLock(int $mode)
    {
        $lockDir = BASE_PATH . '/storage/locks';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Could not create the video storage lock directory.');
        }

        $handle = fopen($lockDir . '/video-storage.lock', 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open the video storage lock.');
        }

        if (!flock($handle, $mode)) {
            fclose($handle);
            throw new RuntimeException('Could not acquire the video storage lock.');
        }

        return $handle;
    }

    private function protectUrl(string $videoUrl, ?B2VideoStorageService $storage): void
    {
        $urlKey = self::urlKey($videoUrl);
        if ($urlKey === '') {
            return;
        }
        $this->urlKeys[$urlKey] = true;

        if ($storage === null) {
            return;
        }

        $objectName = $storage->objectNameFromUrl($videoUrl);
        if ($objectName === null) {
            return;
        }

        $directory = trim((string)pathinfo($objectName, PATHINFO_DIRNAME), './');
        if ($directory !== '') {
            $this->objectDirectories[$directory] = true;
        }
    }

    private static function urlKey(string $videoUrl): string
    {
        $videoUrl = trim($videoUrl);
        if ($videoUrl === '') {
            return '';
        }

        $parts = parse_url($videoUrl);
        if (!is_array($parts)) {
            return strtolower($videoUrl);
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = rawurldecode((string)($parts['path'] ?? ''));
        return $host . '/' . ltrim($path, '/');
    }
}

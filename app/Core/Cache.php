<?php
namespace App\Core;

class Cache
{
    /** @var array<string, array{expires_at:int,value:mixed}> */
    private static array $memory = [];
    private static ?string $writableDirectory = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $normalized = self::normalizeKey($key);
        $now = time();

        if (isset(self::$memory[$normalized]) && self::$memory[$normalized]['expires_at'] >= $now) {
            return self::$memory[$normalized]['value'];
        }

        foreach (self::pathsForKey($normalized) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                @unlink($path);
                continue;
            }

            $payload = @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($payload) || (int)($payload['expires_at'] ?? 0) < $now) {
                @unlink($path);
                continue;
            }

            self::$memory[$normalized] = [
                'expires_at' => (int)$payload['expires_at'],
                'value' => $payload['value'] ?? null,
            ];

            return self::$memory[$normalized]['value'];
        }

        return $default;
    }

    public static function put(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            self::forget($key);
            return;
        }

        $normalized = self::normalizeKey($key);
        $payload = [
            'expires_at' => time() + $ttlSeconds,
            'value' => $value,
        ];

        self::$memory[$normalized] = $payload;

        $path = self::writablePathForKey($normalized);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, serialize($payload), LOCK_EX);
    }

    public static function remember(string $key, int $ttlSeconds, callable $resolver): mixed
    {
        if ($ttlSeconds <= 0) {
            return $resolver();
        }

        $normalized = self::normalizeKey($key);
        $now = time();

        if (isset(self::$memory[$normalized]) && self::$memory[$normalized]['expires_at'] >= $now) {
            return self::$memory[$normalized]['value'];
        }

        foreach (self::pathsForKey($normalized) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $payload = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($payload) && (int)($payload['expires_at'] ?? 0) >= $now) {
                    self::$memory[$normalized] = [
                        'expires_at' => (int)$payload['expires_at'],
                        'value' => $payload['value'] ?? null,
                    ];
                    return self::$memory[$normalized]['value'];
                }
            }

            @unlink($path);
        }

        $value = $resolver();
        $payload = [
            'expires_at' => $now + $ttlSeconds,
            'value' => $value,
        ];

        self::$memory[$normalized] = $payload;

        $path = self::writablePathForKey($normalized);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, serialize($payload), LOCK_EX);

        return $value;
    }

    public static function forget(string $key): void
    {
        $normalized = self::normalizeKey($key);
        unset(self::$memory[$normalized]);
        foreach (self::pathsForKey($normalized) as $path) {
            @unlink($path);
        }
    }

    private static function normalizeKey(string $key): string
    {
        return sha1($key);
    }

    private static function pathsForKey(string $normalizedKey): array
    {
        return array_map(
            static fn(string $dir): string => rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $normalizedKey . '.cache',
            self::cacheDirectories()
        );
    }

    private static function writablePathForKey(string $normalizedKey): string
    {
        return rtrim(self::writableDirectory(), '/\\') . DIRECTORY_SEPARATOR . $normalizedKey . '.cache';
    }

    /**
     * @return array<int, string>
     */
    private static function cacheDirectories(): array
    {
        $directories = [];

        $override = trim((string)(getenv('NUTMEG_CACHE_DIR') ?: ''));
        if ($override !== '') {
            $directories[] = $override;
        }

        $directories[] = BASE_PATH . '/storage/cache/app';
        $directories[] = BASE_PATH . '/storage/runtime-cache/app';

        $tempDir = rtrim(sys_get_temp_dir(), '/\\');
        if ($tempDir !== '') {
            $directories[] = $tempDir . '/nutmegplay-cache/app';
        }

        $unique = [];
        foreach ($directories as $directory) {
            $normalized = rtrim(str_replace('\\', '/', $directory), '/');
            if ($normalized === '' || isset($unique[$normalized])) {
                continue;
            }

            $unique[$normalized] = true;
        }

        return array_keys($unique);
    }

    private static function writableDirectory(): string
    {
        if (is_string(self::$writableDirectory) && self::$writableDirectory !== '') {
            return self::$writableDirectory;
        }

        foreach (self::cacheDirectories() as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                continue;
            }

            $probe = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.cache-write-probe-' . uniqid('', true);
            $wrote = @file_put_contents($probe, '1', LOCK_EX);
            if ($wrote === false) {
                continue;
            }

            @unlink($probe);
            self::$writableDirectory = $directory;
            return self::$writableDirectory;
        }

        self::$writableDirectory = BASE_PATH . '/storage/runtime-cache/app';
        if (!is_dir(self::$writableDirectory)) {
            @mkdir(self::$writableDirectory, 0755, true);
        }

        return self::$writableDirectory;
    }
}

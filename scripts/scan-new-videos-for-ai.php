<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AutoVideoAnalysisDiscoveryService;

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 20;

try {
    $result = (new AutoVideoAnalysisDiscoveryService())->queueNewVideos($limit);
    printf(
        "Auto video discovery queued %d new video(s), skipped %d.\n",
        (int)($result['queued'] ?? 0),
        (int)($result['skipped'] ?? 0)
    );

    foreach (($result['errors'] ?? []) as $error) {
        fwrite(STDERR, "Auto video discovery error: {$error}\n");
    }

    exit(($result['errors'] ?? []) === [] ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, "Auto video discovery failed: " . $e->getMessage() . "\n");
    exit(1);
}

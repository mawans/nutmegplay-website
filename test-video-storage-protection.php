<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use App\Services\B2VideoStorageService;
use App\Services\VideoStorageProtectionService;

function assertProtection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$bucket = trim((string)(getenv('NUTMEG_B2_BUCKET') ?: 'foot-videos'));
$selected = 'b2://' . $bucket . '/matches/42/camera1_part1.mp4';
$optimized = 'b2://' . $bucket . '/ai-optimized/matches/42/camera1_part1_ai960_15fps.mp4';
$active = [[
    'match_id' => 42,
    'processing_status' => 'processing',
    'video_url' => $selected,
    'ai_output' => [
        'selected_clip' => [
            'video_url' => $selected,
            'ai_video_url' => $optimized,
        ],
        'runpod_jobs' => [[
            'video_url' => $optimized,
            'original_video_url' => $selected,
        ]],
    ],
], [
    'match_id' => 99,
    'processing_status' => 'failed',
    'video_url' => 'b2://' . $bucket . '/matches/99/old.mp4',
]];

$storage = new B2VideoStorageService();
$guard = new VideoStorageProtectionService($active, $storage);

assertProtection($guard->protects($selected, 42, 'matches/42/camera1_part1.mp4'), 'Selected clip was not protected.');
assertProtection(
    $guard->protects('b2://' . $bucket . '/matches/42/camera2_part2.mp4', 42, 'matches/42/camera2_part2.mp4'),
    'Sibling match clip was not protected.'
);
assertProtection($guard->protects($optimized, null, 'ai-optimized/matches/42/camera1_part1_ai960_15fps.mp4'), 'AI copy was not protected.');
assertProtection(
    !$guard->protects('b2://' . $bucket . '/matches/99/old.mp4', 99, 'matches/99/old.mp4'),
    'Failed analysis incorrectly protected an unrelated expired clip.'
);

$lock = VideoStorageProtectionService::acquireSharedLock();
assertProtection(is_resource($lock), 'Storage lock could not be acquired.');
VideoStorageProtectionService::releaseLock($lock);

echo "Video storage protection tests passed.\n";

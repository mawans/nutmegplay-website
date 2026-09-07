<?php
declare(strict_types=1);

/**
 * Prints the three cron lines you need to paste into DirectAdmin / cPanel /
 * crontab, with the absolute paths already filled in for whichever host
 * this is running on. Use it like:
 *
 *   php scripts/print-cron-lines.php
 *
 * The script never reads the database or makes any network call, so it is
 * safe to invoke on any host.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$root = realpath(BASE_PATH);
if ($root === false) {
    fwrite(STDERR, "Could not resolve BASE_PATH.\n");
    exit(1);
}

// Pick the PHP binary explicitly. DirectAdmin / CloudLinux hosts often expose
// /opt/alt/phpXY/usr/bin/php in addition to /usr/bin/php; if our current
// SAPI exposes its own binary path we use that.
$candidates = array_filter([
    PHP_BINARY ?: '',
    '/opt/alt/php82/usr/bin/php',
    '/opt/alt/php83/usr/bin/php',
    '/usr/bin/php',
    'php',
]);
$phpBin = '';
foreach ($candidates as $candidate) {
    if ($candidate === '') {
        continue;
    }
    if ($candidate === 'php' || (is_file($candidate) && is_executable($candidate))) {
        $phpBin = $candidate;
        break;
    }
}
if ($phpBin === '') {
    $phpBin = 'php';
}

$lines = [
    [
        'desc'    => 'New video scanner (every minute) — REQUIRED',
        'minute'  => '*',
        'hour'    => '*',
        'script'  => 'scripts/scan-new-videos-for-ai.php',
        'log'     => 'storage/logs/ai/discovery.log',
    ],
    [
        'desc'    => 'Queue worker (every minute) — REQUIRED',
        'minute'  => '*',
        'hour'    => '*',
        'script'  => 'scripts/process-queued-videos.php',
        'log'     => 'storage/logs/ai/queue.log',
    ],
    [
        'desc'    => 'Idle-pod auto-terminate (every 5 minutes)',
        'minute'  => '*/5',
        'hour'    => '*',
        'script'  => 'scripts/auto-stop-idle-pods.php',
        'log'     => 'storage/logs/ai/idle.log',
    ],
    [
        'desc'    => 'Daily cleanup of expired hosted videos',
        'minute'  => '17',
        'hour'    => '4',
        'script'  => 'scripts/cleanup-hosted-videos.php',
        'log'     => 'storage/logs/ai/cleanup.log',
    ],
];

echo "\n";
echo "Detected website root: $root\n";
echo "Detected PHP binary:   $phpBin\n";
echo "\n";
echo "Paste each of these into DirectAdmin → Cron Jobs → Create Cron Job.\n";
echo "Crontab-line form is shown so you can also drop them straight into\n";
echo "`crontab -e` if you have shell access.\n";
echo str_repeat('=', 78) . "\n";

foreach ($lines as $entry) {
    $cmd = sprintf(
        '%s -q %s/%s >> %s/%s 2>&1',
        escapeshellarg($phpBin),
        $root,
        $entry['script'],
        $root,
        $entry['log']
    );

    echo "\n# {$entry['desc']}\n";
    echo sprintf(
        "%s %s * * * %s\n",
        $entry['minute'],
        $entry['hour'],
        $cmd
    );
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Verify the queue cron is firing:\n";
echo "  tail -f $root/storage/logs/ai/queue.log\n";
echo "\nYou should see one line per minute.\n";
echo "\n";

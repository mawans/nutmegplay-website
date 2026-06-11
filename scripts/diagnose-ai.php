<?php
declare(strict_types=1);

/**
 * One-shot diagnostic for "match stuck at 25%". Run from the host:
 *
 *   /opt/alt/php82/usr/bin/php scripts/diagnose-ai.php
 *
 * Tells you in plain English where the analysis is wedged: pod down,
 * worker running the wrong service, callbacks unreachable, no row in DB,
 * etc. Read-only — does not mutate anything.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AiSecurity;
use App\Services\MatchVideoAnalysisService;
use App\Services\RunpodActivePodService;
use App\Services\RunpodPodService;
use App\Services\VideoAnalysisService;

function line(string $label, mixed $value, int $width = 28): void
{
    $value = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
    echo sprintf('  %-' . $width . 's %s' . PHP_EOL, $label, $value);
}
function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

section('1. Configured AI mode');
line('AUTO_PROVISION', getenv('NUTMEG_RUNPOD_AUTO_PROVISION') ?: '(unset)');
line('DYNAMIC_ONLY',   getenv('NUTMEG_RUNPOD_DYNAMIC_ONLY') ?: '(unset)');
line('TERMINATE_DYN',  getenv('NUTMEG_RUNPOD_TERMINATE_DYNAMIC_PODS') ?: '(unset)');
line('IDLE_STOP_SEC',  getenv('NUTMEG_RUNPOD_IDLE_STOP_SECONDS') ?: '(unset)');
line('STATIC POD_ID',  getenv('NUTMEG_RUNPOD_POD_ID') ?: '(empty -> good for pure dynamic)');
line('INSTANCES_JSON', getenv('NUTMEG_RUNPOD_INSTANCES_JSON') ? 'SET' : '(empty -> good for pure dynamic)');

section('2. Active dynamic pod');
$active = (new RunpodActivePodService())->getActivePod(false);
if (!is_array($active) || empty($active['pod_id'])) {
    echo "  No active dynamic pod cached. Either nothing has been provisioned" . PHP_EOL;
    echo "  yet, or the cache file rotated. Trigger a Run AI to provision one." . PHP_EOL;
} else {
    line('pod_id',    (string)($active['pod_id'] ?? ''));
    line('gpu',       (string)($active['gpu_name'] ?? '?'));
    line('hourly $',  (string)($active['hourly_cost'] ?? '?'));
    line('api_base',  (string)($active['api_base'] ?? '?'));
    line('fastapi_port', (string)($active['fastapi_port'] ?? '?'));
}

$svc = new RunpodPodService();
section('3. Pod status from Runpod');
try {
    $status = $svc->getStatus(false);
    line('state',         (string)($status['state'] ?? '?'));
    line('label',         (string)($status['label'] ?? ''));
    line('message',       (string)($status['message'] ?? ''));
    line('idle_seconds',  $status['idle_seconds'] ?? 'n/a');
    line('uptime_label',  (string)($status['uptime_label'] ?? ''));
    line('proxy ai base', (string)($status['ai_base_url'] ?? ''));
    if (isset($status['health'])) {
        line('health.ok',   ($status['health']['ok'] ?? false) ? 'YES' : 'NO');
        line('health.url',  (string)($status['health']['url'] ?? ''));
        line('health.code', (string)($status['health']['status_code'] ?? ''));
    }
} catch (\Throwable $e) {
    echo '  ERROR: ' . $e->getMessage() . PHP_EOL;
}

section('4. AI worker /jobs/active probe');
try {
    $base = $svc->resolveAiBaseUrl(true);
    if (!is_string($base) || $base === '') {
        echo '  No reachable AI base URL — pod is not exposing a port yet.' . PHP_EOL;
    } else {
        $url = rtrim($base, '/') . '/jobs/active';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        line('GET', $url);
        line('http_code', (string)$code);
        if ($err !== '') line('curl_error', $err);
        if (is_string($body)) {
            $snippet = substr($body, 0, 240);
            line('body (first 240B)', $snippet);
            $isJupyter = str_contains(strtolower($snippet), '<html') || str_contains($snippet, 'JupyterLab');
            line('looks like Jupyter?', $isJupyter ? 'YES — pod is running the wrong service!' : 'no (good)');
        }
    }
} catch (\Throwable $e) {
    echo '  ERROR: ' . $e->getMessage() . PHP_EOL;
}

section('5. In-flight DB rows');
$mva = new MatchVideoAnalysisService();
$queued     = $mva->listByStatus('queued', 20);
$processing = $mva->listByStatus('processing', 20);
$failed     = $mva->listByStatus('failed', 5);
line('queued rows',     (string)count($queued));
line('processing rows', (string)count($processing));
line('failed (last 5)', (string)count($failed));
foreach ($processing as $row) {
    $matchId = (int)($row['match_id'] ?? 0);
    $updated = (string)($row['updated_at'] ?? '');
    $err     = (string)($row['error_message'] ?? '');
    $age     = $updated !== '' ? max(0, time() - strtotime($updated)) : 0;
    echo sprintf(
        "    [processing] match #%-5d updated=%s (%ds ago) err=%s\n",
        $matchId,
        $updated ?: '?',
        $age,
        $err === '' ? '-' : substr($err, 0, 60)
    );
}
foreach ($failed as $row) {
    $matchId = (int)($row['match_id'] ?? 0);
    $updated = (string)($row['updated_at'] ?? '');
    $err     = (string)($row['error_message'] ?? '');
    $aiOut   = $row['ai_output'] ?? null;
    if (is_string($aiOut)) {
        $aiOut = json_decode($aiOut, true);
    }
    $detail  = is_array($aiOut) ? (string)($aiOut['error_detail'] ?? '') : '';
    echo sprintf(
        "    [FAILED]    match #%-5d at=%s\n      public: %s\n      detail: %s\n",
        $matchId,
        $updated ?: '?',
        $err === '' ? '-' : $err,
        $detail === '' ? '(none captured — pre-improved-error-mapping)' : substr($detail, 0, 240)
    );
}

section('6. Callback URL the pod should be hitting');
try {
    $analysisService = new VideoAnalysisService();
    $reflect = new ReflectionMethod($analysisService, 'callbackUrlForAi');
    $reflect->setAccessible(true);
    $cb = $reflect->invoke($analysisService);
    line('callback_url', is_string($cb) ? $cb : '(null — host not publicly reachable)');
    if ($cb === null) {
        echo '    Live-progress callbacks from the pod will be skipped because' . PHP_EOL;
        echo '    the website URL resolved to localhost / a private IP. The' . PHP_EOL;
        echo '    cron-side polling (waitForAsyncStoredAnalysis) is the only' . PHP_EOL;
        echo '    way the row will move forward.' . PHP_EOL;
    }
    line('shared secret set?', AiSecurity::callbackSecret() !== '' ? 'yes' : 'no');
} catch (\Throwable $e) {
    echo '  ERROR: ' . $e->getMessage() . PHP_EOL;
}

section('7. Cron heartbeat');
$queueLog = BASE_PATH . '/storage/logs/ai/queue.log';
if (!is_file($queueLog)) {
    echo "  No queue.log yet — cron has not fired or the path is wrong." . PHP_EOL;
} else {
    $size = filesize($queueLog);
    $mtime = filemtime($queueLog);
    line('queue.log size', (string)$size);
    line('last write',     gmdate('c', $mtime) . ' (' . (time() - $mtime) . 's ago)');
    if ((time() - $mtime) > 120) {
        echo "  ⚠ The queue cron has not written in 2+ minutes. Check that" . PHP_EOL;
        echo "    the cron entry is enabled and using the correct PHP path." . PHP_EOL;
    }
    echo "  --- last 6 lines ---" . PHP_EOL;
    $lines = @file($queueLog);
    foreach (array_slice($lines ?: [], -6) as $l) {
        echo '    ' . rtrim($l) . PHP_EOL;
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;

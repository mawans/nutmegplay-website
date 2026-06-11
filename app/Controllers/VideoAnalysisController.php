<?php
namespace App\Controllers;

use App\Core\AiSecurity;
use App\Core\Auth;
use App\Core\Controller;
use App\Services\AiProgressService;
use App\Services\MatchService;
use App\Services\MatchVideoAnalysisService;
use App\Services\VideoAnalysisService;
use RuntimeException;
use Throwable;

class VideoAnalysisController extends Controller
{
    private const SIGNED_VIDEO_PREFIX = '/v/';

    /** GET /v/<file>?exp=&sig= — auth-free signed video for the AI worker only. */
    public function serveSignedVideo(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (!str_starts_with($requestUri, self::SIGNED_VIDEO_PREFIX)) {
            $this->json(['error' => 'Not found.'], 404);
            return;
        }

        $filename = rawurldecode(substr($requestUri, strlen(self::SIGNED_VIDEO_PREFIX)));
        if (!preg_match('/^match_\d+_[A-Za-z0-9]+\.[A-Za-z0-9]{2,5}$/', $filename)) {
            $this->json(['error' => 'Invalid video filename.'], 400);
            return;
        }

        $exp = (int)($_GET['exp'] ?? 0);
        $sig = (string)($_GET['sig'] ?? '');
        if (!AiSecurity::verifyVideoSignature(self::SIGNED_VIDEO_PREFIX . $filename, $exp, $sig)) {
            $this->json(['error' => 'Signature is invalid or expired.'], 403);
            return;
        }

        $path = BASE_PATH . '/public/videos/' . $filename;
        if (!is_file($path)) {
            $this->json(['error' => 'Video no longer available.'], 404);
            return;
        }

        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            default => 'application/octet-stream',
        };

        $size = (int)(filesize($path) ?: 0);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Cache-Control: private, max-age=300');
        header('X-Robots-Tag: noindex');
        readfile($path);
    }

    /** POST /video-analysis/callback — AI worker pushes status/progress to PHP. */
    public function callback(): void
    {
        $rawBody = (string)file_get_contents('php://input');
        $signature = trim((string)($_SERVER['HTTP_X_NUTMEG_SIGNATURE'] ?? ''));

        try {
            $payload = AiSecurity::verifyCallback($rawBody, $signature);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 401);
            return;
        }

        $matchId = (int)($payload['match_id'] ?? 0);
        if ($matchId <= 0) {
            $this->json(['ok' => false, 'error' => 'match_id is required.'], 400);
            return;
        }

        try {
            (new VideoAnalysisService())->applyCallback($matchId, $payload);
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->reportException('ai-callback', $e);
            $this->json(['ok' => false, 'error' => 'Callback could not be processed.'], 500);
        }
    }

    /** GET /video-analysis/stream?match_id=N — Server-Sent Events progress feed. */
    public function stream(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');

        $matchId = (int)($_GET['match_id'] ?? 0);
        if ($matchId <= 0) {
            $this->json(['error' => 'Missing match_id.'], 400);
            return;
        }

        $match = (new MatchService())->getById($matchId);
        if (!$match) {
            $this->json(['error' => 'Forbidden.'], 403);
            return;
        }

        // SSE setup: disable buffering, keep alive, no FastCGI compression.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ignore_user_abort(false);
        @set_time_limit(0);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $service = new VideoAnalysisService();
        // Cap SSE windows short by default so we never pin a PHP-FPM / dev
        // worker for minutes. EventSource auto-reconnects via the retry
        // directive, so a 45-second window still feels live to the client
        // but recycles freely on the server. Single-threaded local dev
        // (php -S without workers) drops even shorter.
        $sapi = strtolower((string)PHP_SAPI);
        $isSingleThreaded = $sapi === 'cli-server'
            && (int)getenv('PHP_CLI_SERVER_WORKERS') <= 1;
        $window = $isSingleThreaded ? 20 : 45;
        $deadline = time() + $window;
        // 2 s feels live enough on a video analysis that takes minutes, and
        // halves PHP-FPM occupancy when many dashboards are open.
        $pollInterval = 2;
        $heartbeatEvery = 15;
        $lastSerialized = '';
        $lastHeartbeatAt = 0;
        $previousStatus = null;

        echo "retry: 3000\n\n";
        $this->flushOutput();

        while (time() < $deadline) {
            if (connection_aborted()) {
                return;
            }

            try {
                $progress = $service->getLiveProgress($matchId);
            } catch (Throwable $e) {
                echo "event: error\n";
                echo 'data: ' . json_encode(['message' => 'Could not load progress.'], JSON_UNESCAPED_SLASHES) . "\n\n";
                $this->flushOutput();
                sleep(2);
                continue;
            }

            $serialized = json_encode($progress, JSON_UNESCAPED_SLASHES);
            if ($serialized !== $lastSerialized) {
                echo "event: progress\n";
                echo 'data: ' . $serialized . "\n\n";
                $this->flushOutput();
                $lastSerialized = $serialized;
            }

            $status = strtolower((string)($progress['status'] ?? ''));
            if ($status === 'processed' || $status === 'failed') {
                if ($previousStatus !== $status) {
                    // Send a final terminal event so the client can close gracefully.
                    echo "event: done\n";
                    echo 'data: ' . json_encode(['status' => $status], JSON_UNESCAPED_SLASHES) . "\n\n";
                    $this->flushOutput();
                    $previousStatus = $status;
                }
                // Hold open briefly for late reconnects, then close.
                sleep(2);
                return;
            }

            if (time() - $lastHeartbeatAt >= $heartbeatEvery) {
                echo ": ping\n\n";
                $this->flushOutput();
                $lastHeartbeatAt = time();
            }

            $previousStatus = $status;
            sleep($pollInterval);
        }
    }

    /**
     * POST /video-analysis/kick — non-blocking queue nudge.
     *
     * Calls kickBackgroundProcessing() (a fire-and-forget nohup) and returns
     * immediately. We DO NOT run processMatchVideo() synchronously here:
     * doing so kept FPM workers busy for minutes per kick, and with several
     * matches + a 18 s JS poll the pool was exhausted → 503. The cron in
     * scripts/process-queued-videos.php is the only place that should run
     * the analysis loop.
     */
    public function kick(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'Invalid request. Refresh the page and try again.'], 419);
            return;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        if ($matchId <= 0) {
            $this->json(['ok' => false, 'error' => 'Missing match.'], 400);
            return;
        }

        $analysis = (new MatchVideoAnalysisService())->getByMatchId($matchId);
        $status = strtolower((string)($analysis['processing_status'] ?? ''));
        if (!in_array($status, ['queued', 'processing'], true)) {
            $this->json(['ok' => true, 'kicked' => false, 'status' => $status]);
            return;
        }

        $kicked = false;
        try {
            $kicked = (new VideoAnalysisService())->kickBackgroundProcessing($matchId);
        } catch (Throwable $e) {
            error_log('[ai-kick] ' . $e->getMessage());
        }

        $this->json(['ok' => true, 'kicked' => $kicked, 'status' => $status]);
    }

    /** POST /video-upload/ai/retry — re-queue a failed analysis without re-uploading. */
    public function retry(): void
    {
        Auth::requirePermission('video.manage', 'Only instructors and admins can manage match videos.');
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'Invalid request. Please refresh and try again.'], 419);
            return;
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        if ($matchId <= 0) {
            $this->json(['ok' => false, 'error' => 'Missing match_id.'], 400);
            return;
        }

        $match = (new MatchService())->getById($matchId);
        if (!$match) {
            $this->json(['ok' => false, 'error' => 'Match not found.'], 404);
            return;
        }

        $videoUrl = trim((string)($match['video_url'] ?? ''));
        if ($videoUrl === '') {
            $analysisRow = (new MatchVideoAnalysisService())->getByMatchId($matchId);
            $videoUrl = trim((string)($analysisRow['video_url'] ?? ''));
        }

        if ($videoUrl === '') {
            $this->json(['ok' => false, 'error' => 'No video is attached to this match.'], 422);
            return;
        }

        try {
            $service = new VideoAnalysisService();
            $service->retryAnalysis($matchId, $videoUrl, (string)Auth::uid());
            VideoAnalysisService::rememberMatchWebsiteBaseUrl($matchId, $this->currentRequestBaseUrl());
            $service->kickBackgroundProcessing($matchId);
            $this->json([
                'ok' => true,
                'message' => 'AI processing has been re-queued. Watch the progress card for updates.',
            ]);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            $this->reportException('ai-retry', $e);
            $this->json(['ok' => false, 'error' => 'AI processing could not be re-queued right now.'], 500);
        }
    }

    private function flushOutput(): void
    {
        @flush();
        if (function_exists('fastcgi_finish_request') === false) {
            // Nothing else to do; flush is enough for cli-server / mod_php.
        }
    }

    private function currentRequestBaseUrl(): ?string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }
        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = trim((string)($_SERVER['HTTPS'] ?? ''));
        $scheme = strtolower($forwardedProto) === 'https' || ($https !== '' && strtolower($https) !== 'off')
            ? 'https'
            : 'http';
        return $scheme . '://' . $host;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}

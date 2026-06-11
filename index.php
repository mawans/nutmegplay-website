<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    $directFile = realpath(__DIR__ . $path);
    if ($directFile !== false && is_file($directFile) && str_starts_with($directFile, __DIR__)) {
        return false;
    }

    $publicFile = realpath(__DIR__ . '/public' . $path);
    $publicRoot = realpath(__DIR__ . '/public');
    if (
        $publicFile !== false
        && $publicRoot !== false
        && str_starts_with($publicFile, $publicRoot)
        && is_file($publicFile)
    ) {
        $extension = strtolower((string)pathinfo($publicFile, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'application/javascript',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            default => (function_exists('mime_content_type') ? mime_content_type($publicFile) : null) ?: 'application/octet-stream',
        };
        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($publicFile));
        readfile($publicFile);
        return true;
    }
}

require_once __DIR__ . '/app/bootstrap.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Signed video URLs handed to the AI worker bypass the auth router.
if ($method === 'GET' && str_starts_with($path, '/v/')) {
    (new \App\Controllers\VideoAnalysisController())->serveSignedVideo();
    return;
}

// /videos/<file>  ->  public/videos/<file>
//
// On shared hosts where the document root is the project root (not the
// public/ folder), Apache cannot find the uploaded clip and falls through
// to this front controller, so the browser receives HTML instead of the
// video. Detect that case and stream the real file with the right MIME.
if ($method === 'GET' && (str_starts_with($path, '/videos/') || str_starts_with($path, '/public/videos/'))) {
    $filename = basename($path);
    if (preg_match('/^match_\d+_[A-Za-z0-9]+\.[A-Za-z0-9]{2,5}$/', $filename) === 1) {
        $diskPath = __DIR__ . '/public/videos/' . $filename;
        if (is_file($diskPath)) {
            $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                'mov' => 'video/quicktime',
                'mkv' => 'video/x-matroska',
                'avi' => 'video/x-msvideo',
                default => 'application/octet-stream',
            };
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($diskPath));
            header('Accept-Ranges: bytes');
            header('Cache-Control: private, max-age=300');
            // Honor a Range request so the user can scrub the video.
            $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
            if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
                $size  = filesize($diskPath);
                $start = $m[1] === '' ? 0 : (int)$m[1];
                $end   = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
                if ($start <= $end && $start >= 0 && $end < $size) {
                    http_response_code(206);
                    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
                    header('Content-Length: ' . (string)($end - $start + 1));
                    $fp = fopen($diskPath, 'rb');
                    if ($fp) {
                        fseek($fp, $start);
                        $remaining = $end - $start + 1;
                        while ($remaining > 0 && !feof($fp)) {
                            $chunk = fread($fp, min(8192, $remaining));
                            if ($chunk === false) break;
                            echo $chunk;
                            $remaining -= strlen($chunk);
                        }
                        fclose($fp);
                    }
                    return;
                }
            }
            readfile($diskPath);
            return;
        }
    }
}

$router = require __DIR__ . '/app/routes.php';
$router->dispatch($path, $method);

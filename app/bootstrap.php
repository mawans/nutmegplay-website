<?php
declare(strict_types=1);

const BASE_PATH = __DIR__ . '/..';

/**
 * Lightweight .env loader (no external dependency).
 * Existing environment variables are not overwritten.
 */
function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $pair = explode('=', $line, 2);
        if (count($pair) !== 2) {
            continue;
        }

        $name = trim($pair[0]);
        $value = trim($pair[1]);

        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            continue;
        }

        $isQuoted = false;
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
            ($value[0] === "'" && $value[strlen($value) - 1] === "'")
        )) {
            $isQuoted = true;
            $value = substr($value, 1, -1);
        }

        if (!$isQuoted && strpos($value, ' #') !== false) {
            $value = strstr($value, ' #', true);
        }

        $shouldOverrideExisting = str_starts_with($name, 'NUTMEG_');

        if (
            !$shouldOverrideExisting &&
            (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER) || getenv($name) !== false)
        ) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

loadEnvFile(BASE_PATH . '/.env');

function renderPublicErrorResponse(int $statusCode, string $title, string $message): void
{
    http_response_code($statusCode);

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($requestPath, '/api/')) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => true,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    $view = BASE_PATH . '/app/views/errors/error.php';
    if (is_file($view)) {
        $pageTitle = $title;
        include $view;
        return;
    }

    echo $statusCode . ' - ' . $message;
}

if (PHP_SAPI !== 'cli') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    $logDir = BASE_PATH . '/storage/logs/app';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/php-error.log');

    set_exception_handler(static function (\Throwable $e): void {
        error_log(sprintf(
            '[uncaught-exception] %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        renderPublicErrorResponse(
            500,
            'Server error',
            'Something went wrong. Please try again later.'
        );
    });

    set_error_handler(static function (
        int $severity,
        string $message,
        string $file = '',
        int $line = 0
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        error_log(sprintf(
            '[fatal-error] %s in %s:%d',
            (string)($error['message'] ?? 'Fatal error'),
            (string)($error['file'] ?? 'unknown'),
            (int)($error['line'] ?? 0)
        ));

        if (!headers_sent()) {
            renderPublicErrorResponse(
                500,
                'Server error',
                'Something went wrong. Please try again later.'
            );
        }
    });
}

// Start session for auth with hardened cookie settings
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    if (str_starts_with($class, $prefix) === false) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relativeClass) . '.php';
    $file = $baseDir . $relativePath;

    if (file_exists($file)) {
        require $file;
    }
});

// Handle CORS preflight for /api/* routes
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (str_starts_with($requestUri ?? '', '/api/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

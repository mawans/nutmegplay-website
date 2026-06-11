<?php
namespace App\Core;

use Throwable;

class Controller
{
    /**
     * Render a view file. Changes the working directory so relative includes
     * inside views continue to resolve correctly.
     *
     * Each view handles its own HTMX-aware conditional rendering (header/footer
     * inclusion) via HtmxHelper::isHtmxRequest().
     */
    protected function renderRaw(string $absolutePath, array $data = []): void
    {
        if (!is_file($absolutePath)) {
            self::respondWithError(404, 'Page not found', 'The page you requested could not be found.');
            return;
        }

        $originalCwd = getcwd();
        extract($data, EXTR_SKIP);
        chdir(dirname($absolutePath));
        include $absolutePath;
        chdir($originalCwd);
    }

    public static function respondWithError(int $statusCode, string $title, string $message): void
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
        if (!is_file($view)) {
            echo $statusCode . ' - ' . $message;
            return;
        }

        $pageTitle = $title;
        include $view;
    }

    protected function safeExceptionMessage(Throwable $e, string $fallback, array $allowedMessages = []): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && in_array($message, $allowedMessages, true)) {
            return $message;
        }

        return $fallback;
    }

    protected function reportException(string $context, Throwable $e): void
    {
        error_log(sprintf(
            '[%s] %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}

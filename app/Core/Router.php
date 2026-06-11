<?php
namespace App\Core;

class Router
{
    /** @var array<string, array<string, array|callable>> */
    private array $routes = [];

    public function get(string $path, array|callable $handler): void
    {
        $normalized = $this->normalize($path);
        $this->routes['GET'][$normalized] = $handler;
    }

    public function post(string $path, array|callable $handler): void
    {
        $normalized = $this->normalize($path);
        $this->routes['POST'][$normalized] = $handler;
    }

    public function dispatch(string $path, string $method = 'GET'): void
    {
        $normalized = $this->normalize($path);
        $method = strtoupper($method);
        $effectiveMethod = $method === 'HEAD' ? 'GET' : $method;
        $handler = $this->routes[$effectiveMethod][$normalized] ?? null;

        if ($handler === null) {
            Controller::respondWithError(404, 'Page not found', 'The page you requested could not be found.');
            return;
        }

        if ($method === 'HEAD') {
            ob_start();
            $this->invoke($handler);
            ob_end_clean();
            return;
        }

        $this->invoke($handler);
    }

    private function invoke(array|callable $handler): void
    {
        if (is_array($handler)) {
            [$controllerClass, $method] = $handler;
            $controller = new $controllerClass();
            $controller->$method();
            return;
        }

        $handler();
    }

    private function normalize(string $path): string
    {
        $trimmed = '/' . trim($path, '/');
        return $trimmed === '//' ? '/' : $trimmed;
    }
}

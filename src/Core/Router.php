<?php

namespace Rider\Core;

final class Router
{
    /** @var array<int, array{method:string, path:string, pattern:string, handler:callable|array}> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $pattern = preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $path);
        $this->routes[] = ['method' => $method, 'path' => $path, 'pattern' => '#^' . $pattern . '$#', 'handler' => $handler];
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['pattern'], $request->path, $matches)) {
                array_shift($matches);
                $paramNames = [];
                preg_match_all('#\{([a-zA-Z_]+)\}#', $route['path'], $paramNames);
                $request->params = array_combine($paramNames[1], $matches) ?: [];

                call_user_func($route['handler'], $request);
                return;
            }
        }

        Response::notFound('Route not found: ' . $request->method . ' ' . $request->path);
    }
}

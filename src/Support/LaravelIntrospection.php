<?php

namespace AppGraph\Support;

use Illuminate\Routing\Route;

class LaravelIntrospection
{
    /**
     * @return array<int, Route>
     */
    public function routes(): array
    {
        if (! app()->bound('router')) {
            return [];
        }

        return app('router')->getRoutes()->getRoutes();
    }

    /**
     * @return array<int, string>
     */
    public function routeMethods(Route $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => strtoupper($method) !== 'HEAD'
        ));
    }

    public function routeUri(Route $route): string
    {
        $uri = $route->uri();

        return $uri === '/' ? '/' : '/'.ltrim($uri, '/');
    }

    /**
     * @return array{class: string, method: string}|null
     */
    public function controllerAction(Route $route): ?array
    {
        $action = $route->getAction();
        $controller = $action['controller'] ?? $action['uses'] ?? null;

        if (! is_string($controller)) {
            return null;
        }

        if (str_contains($controller, '@')) {
            [$class, $method] = explode('@', $controller, 2);

            return [
                'class' => $class,
                'method' => $method,
            ];
        }

        if (class_exists($controller) && method_exists($controller, '__invoke')) {
            return [
                'class' => $controller,
                'method' => '__invoke',
            ];
        }

        return null;
    }
}

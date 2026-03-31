<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use ReflectionClass;
use ReflectionMethod;
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Exceptions\WorkaroundExpiredException;

final class DeadlockGuardMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! App::environment('local')) {
            return $next($request);
        }

        $route = $request->route();

        if (! $route) {
            return $next($request);
        }

        $resolved = $this->resolveControllerAction($route);

        if ($resolved === null) {
            return $next($request);
        }

        [$controller, $method] = $resolved;

        $this->checkController($controller, $method);

        return $next($request);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveControllerAction(Route $route): ?array
    {
        $action = $route->getAction('controller') ?? $route->getAction('uses');

        if (is_array($action) && count($action) === 2) {
            $controller = is_object($action[0]) ? $action[0]::class : $action[0];
            $method = $action[1];

            return is_string($controller) && is_string($method)
                ? [$controller, $method]
                : null;
        }

        if (! is_string($action)) {
            return null;
        }

        if (str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);

            return [$controller, $method];
        }

        if (class_exists($action) && method_exists($action, '__invoke')) {
            return [$action, '__invoke'];
        }

        return null;
    }

    private function checkController(string $controller, string $method): void
    {
        if (! class_exists($controller)) {
            return;
        }

        $reflectionClass = new ReflectionClass($controller);

        // Class-level attributes
        $this->inspectAttributes(
            $reflectionClass->getAttributes(Workaround::class),
            $reflectionClass->getName()
        );

        if (! $reflectionClass->hasMethod($method)) {
            return;
        }

        $reflectionMethod = new ReflectionMethod($controller, $method);

        // Method-level attributes
        $this->inspectAttributes(
            $reflectionMethod->getAttributes(Workaround::class),
            $reflectionClass->getName().'::'.$reflectionMethod->getName()
        );
    }

    private function inspectAttributes(array $attributes, string $location): void
    {
        foreach ($attributes as $attribute) {
            /** @var Workaround $instance */
            $instance = $attribute->newInstance();

            $deadline = Carbon::parse($instance->expires)->startOfDay();

            if (now()->startOfDay()->gt($deadline)) {
                throw new WorkaroundExpiredException(
                    description: $instance->description,
                    expires: $instance->expires,
                    location: $location
                );
            }
        }
    }
}

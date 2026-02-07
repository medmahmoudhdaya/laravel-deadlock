<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        $action = $route->getAction('controller');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return $next($request);
        }

        [$controller, $method] = explode('@', $action);

        $this->checkController($controller, $method);

        return $next($request);
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

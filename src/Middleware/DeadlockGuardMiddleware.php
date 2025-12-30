<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use ReflectionClass;
use ReflectionMethod;
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Exceptions\DeadlockExpiredException;

final class DeadlockGuardMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Never enforce in non-local environments
        if (!App::environment('local')) {
            return $next($request);
        }

        $route = $request->route();

        if (!$route) {
            return $next($request);
        }

        $action = $route->getAction('controller');

        if (!is_string($action) || !str_contains($action, '@')) {
            return $next($request);
        }

        [$controller, $method] = explode('@', $action);

        $this->checkController($controller, $method);

        return $next($request);
    }

    private function checkController(string $controller, string $method): void
    {
        if (!class_exists($controller)) {
            return;
        }

        $reflectionClass = new ReflectionClass($controller);

        // Check class-level attributes
        $this->inspectAttributes(
            $reflectionClass->getAttributes(Workaround::class)
        );

        if (!$reflectionClass->hasMethod($method)) {
            return;
        }

        // Check method-level attributes
        $reflectionMethod = new ReflectionMethod($controller, $method);

        $this->inspectAttributes(
            $reflectionMethod->getAttributes(Workaround::class)
        );
    }

    private function inspectAttributes(array $attributes): void
    {
        foreach ($attributes as $attribute) {
            /** @var Workaround $instance */
            $instance = $attribute->newInstance();

            if (strtotime($instance->expires) < strtotime('today')) {
                throw new DeadlockExpiredException(
                    "Expired workaround: {$instance->description}"
                );
            }
        }
    }
}

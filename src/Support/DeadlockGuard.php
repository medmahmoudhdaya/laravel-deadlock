<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Support;

use Illuminate\Support\Facades\App;
use ReflectionClass;
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Exceptions\WorkaroundExpiredException;

final class DeadlockGuard
{
    /**
     * Enforce expired workaround checks at runtime.
     *
     * @param object|string $target  Object instance or class name
     * @param string|null   $method  Method name (explicit, optional)
     */
    public static function check(object|string $target, ?string $method = null): void
    {
        if (!App::environment('local')) {
            return;
        }

        $className = is_object($target)
            ? $target::class
            : $target;

        if (!class_exists($className)) {
            return;
        }

        $reflectionClass = new ReflectionClass($className);

        // Class-level workarounds
        self::inspectAttributes(
            $reflectionClass->getAttributes(Workaround::class),
            $reflectionClass->getName()
        );

        // Method-level workarounds
        if ($method && $reflectionClass->hasMethod($method)) {
            $reflectionMethod = $reflectionClass->getMethod($method);

            self::inspectAttributes(
                $reflectionMethod->getAttributes(Workaround::class),
                $className . '::' . $method
            );
        }
    }

    /**
     * @param array  $attributes
     * @param string $location
     */
    private static function inspectAttributes(array $attributes, string $location): void
    {
        foreach ($attributes as $attribute) {
            /** @var Workaround $instance */
            $instance = $attribute->newInstance();

            if (strtotime($instance->expires) < strtotime('today')) {
                throw new WorkaroundExpiredException(
                    description: $instance->description,
                    expires: $instance->expires,
                    location: $location
                );
            }
        }
    }
}

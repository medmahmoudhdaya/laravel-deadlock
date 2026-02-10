<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Scanner;

use Illuminate\Support\Facades\Date;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockResultTest extends TestCase
{
    public function test_location_prefers_class_and_method(): void
    {
        $result = new DeadlockResult(
            description: 'desc',
            expires: '2099-01-01',
            file: '/tmp/Test.php',
            line: 10,
            class: 'ExampleClass',
            method: 'run'
        );

        $this->assertSame('ExampleClass::run', $result->location());
    }

    public function test_location_uses_class_when_no_method(): void
    {
        $result = new DeadlockResult(
            description: 'desc',
            expires: '2099-01-01',
            file: '/tmp/Test.php',
            line: 10,
            class: 'ExampleClass',
            method: null
        );

        $this->assertSame('ExampleClass', $result->location());
    }

    public function test_location_falls_back_to_file_and_line(): void
    {
        $result = new DeadlockResult(
            description: 'desc',
            expires: '2099-01-01',
            file: '/tmp/Test.php',
            line: 10,
            class: null,
            method: null
        );

        $this->assertSame('/tmp/Test.php:10', $result->location());
    }

    public function test_is_expired_is_false_on_same_day(): void
    {
        Date::setTestNow(Date::create(2025, 1, 1, 12, 0, 0, 'UTC'));

        try {
            $result = new DeadlockResult(
                description: 'desc',
                expires: '2025-01-01',
                file: '/tmp/Test.php',
                line: 10,
                class: null,
                method: null
            );

            $this->assertFalse($result->isExpired());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_is_expired_is_true_after_deadline(): void
    {
        Date::setTestNow(Date::create(2025, 1, 2, 12, 0, 0, 'UTC'));

        try {
            $result = new DeadlockResult(
                description: 'desc',
                expires: '2025-01-01',
                file: '/tmp/Test.php',
                line: 10,
                class: null,
                method: null
            );

            $this->assertTrue($result->isExpired());
        } finally {
            Date::setTestNow();
        }
    }
}

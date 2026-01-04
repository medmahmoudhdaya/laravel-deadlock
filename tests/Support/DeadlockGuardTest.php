<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Support;

use Zidbih\Deadlock\Exceptions\WorkaroundExpiredException;
use Zidbih\Deadlock\Support\DeadlockGuard;
use Zidbih\Deadlock\Tests\Fixtures\ActiveService;
use Zidbih\Deadlock\Tests\Fixtures\ExpiredService;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockGuardTest extends TestCase
{
    public function test_class_level_expired_workaround_throws(): void
    {
        $this->expectException(WorkaroundExpiredException::class);

        DeadlockGuard::check(new ExpiredService);
    }

    public function test_method_level_expired_workaround_throws(): void
    {
        $this->expectException(WorkaroundExpiredException::class);

        DeadlockGuard::check(new ActiveService, 'run');
    }
}

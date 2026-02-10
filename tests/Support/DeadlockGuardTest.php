<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Support;

use Illuminate\Support\Facades\Date;
use Zidbih\Deadlock\Attributes\Workaround;
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

    public function test_check_is_noop_outside_local_env(): void
    {
        $this->app['env'] = 'production';
        $this->app['config']->set('app.env', 'production');

        try {
            DeadlockGuard::check(new ExpiredService);
            $this->assertTrue(true);
        } finally {
            $this->app['env'] = 'local';
            $this->app['config']->set('app.env', 'local');
        }
    }

    public function test_missing_class_is_ignored(): void
    {
        DeadlockGuard::check('App\\MissingClass');

        $this->assertTrue(true);
    }

    public function test_missing_method_is_ignored(): void
    {
        DeadlockGuard::check(new ActiveService, 'missingMethod');

        $this->assertTrue(true);
    }

    public function test_class_string_target_is_supported(): void
    {
        $this->expectException(WorkaroundExpiredException::class);

        DeadlockGuard::check(ExpiredService::class);
    }

    public function test_expires_today_is_not_expired(): void
    {
        Date::setTestNow(Date::create(2025, 1, 1, 12, 0, 0, 'UTC'));

        try {
            DeadlockGuard::check(new TodayService);
            $this->assertTrue(true);
        } finally {
            Date::setTestNow();
        }
    }
}

#[Workaround(description: 'Today workaround', expires: '2025-01-01')]
final class TodayService {}

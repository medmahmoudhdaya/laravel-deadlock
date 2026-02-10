<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Provider;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use Zidbih\Deadlock\DeadlockServiceProvider;
use Zidbih\Deadlock\Middleware\DeadlockGuardMiddleware;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockServiceProviderTest extends TestCase
{
    public function test_registers_middleware_in_local_environment(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $groups = $kernel->getMiddlewareGroups();

        $this->assertContains(DeadlockGuardMiddleware::class, $groups['web']);
        $this->assertContains(DeadlockGuardMiddleware::class, $groups['api']);
    }

    public function test_registers_commands_in_console(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('deadlock:list', $commands);
        $this->assertArrayHasKey('deadlock:check', $commands);
    }
}

final class DeadlockServiceProviderNonLocalTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DeadlockServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('app.env', 'production');
    }

    public function test_does_not_register_middleware_outside_local_environment(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $groups = $kernel->getMiddlewareGroups();

        $this->assertNotContains(DeadlockGuardMiddleware::class, $groups['web']);
        $this->assertNotContains(DeadlockGuardMiddleware::class, $groups['api']);
    }
}

final class DeadlockServiceProviderNonConsoleTest extends Orchestra
{
    protected function setUp(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [DeadlockServiceProvider::class];
    }

    public function test_does_not_register_commands_outside_console(): void
    {
        $commands = Artisan::all();

        $this->assertArrayNotHasKey('deadlock:list', $commands);
        $this->assertArrayNotHasKey('deadlock:check', $commands);
    }
}

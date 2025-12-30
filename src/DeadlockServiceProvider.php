<?php

declare(strict_types=1);

namespace Zidbih\Deadlock;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Zidbih\Deadlock\Console\ListDeadlocksCommand;
use Zidbih\Deadlock\Console\CheckDeadlocksCommand;
use Zidbih\Deadlock\Middleware\DeadlockGuardMiddleware;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class DeadlockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeadlockScanner::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('local')) {
            $kernel = $this->app->make(Kernel::class);

            foreach (['web', 'api'] as $group) {
                $kernel->appendMiddlewareToGroup(
                    $group,
                    DeadlockGuardMiddleware::class
                );
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListDeadlocksCommand::class,
                CheckDeadlocksCommand::class,
            ]);
        }
    }
}

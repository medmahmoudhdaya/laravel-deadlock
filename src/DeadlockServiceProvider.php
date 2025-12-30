<?php

declare(strict_types=1);

namespace Zidbih\Deadlock;

use Illuminate\Support\ServiceProvider;
use Zidbih\Deadlock\Console\ListDeadlocksCommand;
use Zidbih\Deadlock\Console\CheckDeadlocksCommand;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class DeadlockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeadlockScanner::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListDeadlocksCommand::class,
                CheckDeadlocksCommand::class,
            ]);
        }
    }
}

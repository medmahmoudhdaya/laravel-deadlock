<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zidbih\Deadlock\DeadlockServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DeadlockServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['env'] = 'local';

        $app['config']->set('app.env', 'local');
    }
}

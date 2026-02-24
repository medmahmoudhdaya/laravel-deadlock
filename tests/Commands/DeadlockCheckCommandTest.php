<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockCheckCommandTest extends TestCase
{
    public function test_deadlock_check_fails_when_expired(): void
    {
        $path = app_path('ExpiredTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Expired test workaround',
                expires: '2020-01-01'
            )]
            class ExpiredTestService {}
            PHP
        );

        $this->artisan('deadlock:check')
            ->assertExitCode(1);

        File::delete($path);
    }

    public function test_deadlock_check_succeeds_when_no_expired_workarounds(): void
    {
        $path = app_path('ActiveTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Active test workaround',
                expires: '2099-01-01'
            )]
            class ActiveTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check')
                ->assertExitCode(0)
                ->expectsOutputToContain('No expired workarounds found.');
        } finally {
            File::delete($path);
        }
    }
}

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
}

<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Zidbih\Deadlock\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class DeadlockCheckCommandTest extends TestCase
{
    public function test_deadlock_check_fails_when_expired(): void
    {
        // 1. Create an expired workaround inside app/
        $path = app_path('ExpiredTestService.php');

        File::put($path, <<<PHP
            <?php

            namespace App;

            use Zidbih\\Deadlock\\Attributes\\Workaround;

            #[Workaround(
                description: 'Expired test workaround',
                expires: '2020-01-01'
            )]
            class ExpiredTestService {}
            PHP
                    );

        // 2. Run the command
        $this->artisan('deadlock:check')
            ->assertExitCode(1);

        // 3. Cleanup
        File::delete($path);
    }
}

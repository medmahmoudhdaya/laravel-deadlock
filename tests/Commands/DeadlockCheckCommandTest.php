<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
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

        try {
            $this->artisan('deadlock:check')
                ->assertExitCode(1);
        } finally {
            File::delete($path);
        }
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

    public function test_deadlock_check_outputs_json_when_no_expired_workarounds_exist(): void
    {
        $path = app_path('ActiveJsonTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Active JSON test workaround',
                expires: '2099-01-01'
            )]
            class ActiveJsonTestService {}
            PHP
        );

        try {
            $exitCode = Artisan::call('deadlock:check', ['--json' => true]);
            $output = Artisan::output();
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exitCode);
            $this->assertTrue($payload['success']);
            $this->assertSame(0, $payload['expired_count']);
            $this->assertSame([], $payload['expired']);
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_outputs_json_when_expired_workarounds_exist(): void
    {
        $path = app_path('ExpiredJsonTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Expired JSON test workaround',
                expires: '2020-01-01'
            )]
            class ExpiredJsonTestService {}
            PHP
        );

        try {
            $exitCode = Artisan::call('deadlock:check', ['--json' => true]);
            $output = Artisan::output();
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertFalse($payload['success']);
            $this->assertSame(1, $payload['expired_count']);
            $this->assertCount(1, $payload['expired']);
            $this->assertSame('Expired JSON test workaround', $payload['expired'][0]['description']);
            $this->assertSame('2020-01-01', $payload['expired'][0]['expires']);
            $this->assertSame('ExpiredJsonTestService', $payload['expired'][0]['location']);
            $this->assertStringEndsWith('ExpiredJsonTestService.php', $payload['expired'][0]['file']);
            $this->assertSame('ExpiredJsonTestService', $payload['expired'][0]['class']);
            $this->assertNull($payload['expired'][0]['method']);
        } finally {
            File::delete($path);
        }
    }
}

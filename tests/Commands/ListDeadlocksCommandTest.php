<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Tests\TestCase;

final class ListDeadlocksCommandTest extends TestCase
{
    private string $expiredPath;

    private string $activePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expiredPath = app_path('ExpiredListTestService.php');
        $this->activePath = app_path('ActiveListTestService.php');

        File::put($this->expiredPath, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'Expired list test workaround',
    expires: '2020-01-01'
)]
class ExpiredListTestService {}
PHP
        );

        File::put($this->activePath, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'Active list test workaround',
    expires: '2099-01-01'
)]
class ActiveListTestService {}
PHP
        );
    }

    protected function tearDown(): void
    {
        File::delete($this->expiredPath);
        File::delete($this->activePath);

        parent::tearDown();
    }

    public function test_list_command_shows_all_workarounds(): void
    {
        $this->artisan('deadlock:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired list test workaround')
            ->expectsOutputToContain('Active list test workaround');
    }

    public function test_list_command_filters_expired_workarounds(): void
    {
        $this->artisan('deadlock:list --expired')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired list test workaround')
            ->doesntExpectOutputToContain('Active list test workaround');
    }

    public function test_list_command_filters_active_workarounds(): void
    {
        $this->artisan('deadlock:list --active')
            ->assertExitCode(0)
            ->expectsOutputToContain('Active list test workaround')
            ->doesntExpectOutputToContain('Expired list test workaround');
    }

    public function test_list_command_rejects_conflicting_filters(): void
    {
        $this->artisan('deadlock:list --expired --active')
            ->assertExitCode(2)
            ->expectsOutputToContain('You cannot use --expired and --active together.');
    }
}

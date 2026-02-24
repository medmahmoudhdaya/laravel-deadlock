<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Scanner\DeadlockScanner;
use Zidbih\Deadlock\Tests\TestCase;

final class ListDeadlocksCommandTest extends TestCase
{
    private string $expiredPath;

    private string $activePath;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow(Date::create(2025, 1, 1, 12, 0, 0, 'UTC'));

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

        Date::setTestNow();

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

    public function test_list_command_throws_on_invalid_expires_date_format(): void
    {
        $invalidPath = app_path('InvalidDateListTestService.php');

        File::put($invalidPath, <<<'PHP'
    <?php

    namespace App;

    use Zidbih\Deadlock\Attributes\Workaround;

    #[Workaround(
        description: 'Invalid date list test workaround',
        expires: '01-31-2025'
    )]
    class InvalidDateListTestService {}
    PHP
        );

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("Invalid expires date '01-31-2025'. Expected YYYY-MM-DD.");

            $this->artisan('deadlock:list');
        } finally {
            File::delete($invalidPath);
        }
    }

    public function test_list_command_filters_critical_workarounds(): void
    {
        $criticalPath = app_path('CriticalTest.php');
        $nonCriticalPath = app_path('NonCriticalTest.php');

        $criticalExpires = '2025-01-06';   // should be included (5 days after test now)
        $activeExpires = '2025-01-31';  // should be excluded (30 days after test now)

        File::put($criticalPath, <<<PHP
    <?php

    namespace App;

    use Zidbih\Deadlock\Attributes\Workaround;

    #[Workaround(description: 'Critical test workaround', expires: '{$criticalExpires}')]
    class CriticalTest {}
    PHP
        );

        File::put($nonCriticalPath, <<<PHP
    <?php

    namespace App;

    use Zidbih\Deadlock\Attributes\Workaround;

    #[Workaround(description: 'Non critical test workaround', expires: '{$activeExpires}')]
    class NonCriticalTest {}
    PHP
        );

        try {
            $this->artisan('deadlock:list --critical')
                ->assertExitCode(0)
                ->expectsOutputToContain('Critical test workaround')
                ->doesntExpectOutputToContain('Non critical test workaround')
                ->doesntExpectOutputToContain('Expired list test workaround')
                ->doesntExpectOutputToContain('Active list test workaround');
        } finally {
            File::delete($criticalPath);
            File::delete($nonCriticalPath);
        }
    }

    public function test_list_command_reports_no_matches_for_critical_filter(): void
    {
        $this->artisan('deadlock:list --critical')
            ->assertExitCode(0)
            ->expectsOutputToContain('No matching workarounds found.');
    }

    public function test_list_command_sorts_by_date_by_default(): void
    {
        // Run and capture output, then assert relative order.
        Artisan::call('deadlock:list');
        $output = Artisan::output();

        $this->assertNotSame('', $output);

        $posExpired = strpos($output, 'Expired list test workaround');
        $posActive = strpos($output, 'Active list test workaround');

        $this->assertIsInt($posExpired);
        $this->assertIsInt($posActive);

        $this->assertTrue(
            $posExpired < $posActive,
            'Expected expired workaround to appear before active workaround in the output.'
        );
    }

    public function test_list_command_displays_urgency_tags_correctly(): void
    {
        $this->artisan('deadlock:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('EXPIRED')
            ->expectsOutputToContain('ACTIVE');
    }

    public function test_list_command_shows_line_numbers_in_location(): void
    {
        $this->artisan('deadlock:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('ExpiredListTestService (line ');
    }

    public function test_list_command_reports_no_workarounds_when_none_found(): void
    {
        File::delete($this->expiredPath);
        File::delete($this->activePath);

        $this->artisan('deadlock:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('No workarounds found.')
            ->expectsOutputToContain('Note: #[Workaround] is supported on classes and methods only.');
    }

    public function test_list_command_truncates_long_descriptions_with_ellipsis(): void
    {
        $longPath = app_path('LongDescriptionTestService.php');
        $description = str_repeat('A', 90);
        $expected = str_repeat('A', 79).'…';

        File::put($longPath, <<<PHP
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: '{$description}',
    expires: '2099-01-01'
)]
class LongDescriptionTestService {}
PHP
        );

        try {
            $this->artisan('deadlock:list')
                ->assertExitCode(0)
                ->expectsOutputToContain($expected);
        } finally {
            File::delete($longPath);
        }
    }

    public function test_list_command_uses_singular_day_label_for_one_day_remaining(): void
    {
        $oneDayPath = app_path('OneDayTestService.php');

        File::put($oneDayPath, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'One day workaround',
    expires: '2025-01-02'
)]
        class OneDayTestService {}
PHP
        );

        try {
            Artisan::call('deadlock:list');
            $output = Artisan::output();

            $this->assertNotSame('', $output);
            $this->assertTrue(
                str_contains($output, 'CRITICAL')
            );
            $this->assertTrue(
                str_contains($output, 'day left') || str_contains($output, 'days left')
            );
        } finally {
            File::delete($oneDayPath);
        }
    }

    public function test_list_command_keeps_file_line_location_as_is(): void
    {
        $anonymousPath = app_path('AnonymousWorkaround.php');

        File::put($anonymousPath, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

return new class {
    #[Workaround('Anonymous method workaround', '2099-01-01')]
    public function run(): void {}
};
PHP
        );

        try {
            $scanner = $this->app->make(DeadlockScanner::class);
            $results = $scanner->scan(app_path());

            $location = null;
            foreach ($results as $result) {
                if ($result->description === 'Anonymous method workaround') {
                    $location = $result->location();
                    break;
                }
            }

            $this->assertIsString($location);

            $this->artisan('deadlock:list')
                ->assertExitCode(0)
                ->expectsOutputToContain($location);
        } finally {
            File::delete($anonymousPath);
        }
    }
}

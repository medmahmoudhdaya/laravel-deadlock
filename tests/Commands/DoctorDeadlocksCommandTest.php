<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Tests\TestCase;

final class DoctorDeadlocksCommandTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    public function test_doctor_command_reports_issues(): void
    {
        $this->path = app_path('DoctorCommandIssue.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class DoctorCommandIssue
{
    #[Workaround(description: 'Temporary command issue', expires: '2099-01-01')]
    public function run(): void
    {
    }
}
PHP);

        $this->artisan('deadlock:doctor')
            ->assertExitCode(1)
            ->expectsOutputToContain('Laravel Deadlock Doctor')
            ->expectsOutputToContain('supported workaround found')
            ->expectsOutputToContain('Guard issues')
            ->expectsOutputToContain('Method-level workaround is not explicitly guarded.');
    }

    public function test_doctor_command_succeeds_when_no_issues_are_found(): void
    {
        $this->path = app_path('DoctorCommandClean.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorCommandClean
{
    #[Workaround(description: 'Temporary clean command', expires: '2099-01-01')]
    public function run(): void
    {
        DeadlockGuard::check($this, __FUNCTION__);
    }
}
PHP);

        $this->artisan('deadlock:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('Laravel Deadlock Doctor')
            ->expectsOutputToContain('OK No doctor issues found.');
    }

    public function test_doctor_command_reports_invalid_attributes_when_supported_scan_fails(): void
    {
        $this->path = app_path('DoctorCommandInvalidAttribute.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Missing expires')]
class DoctorCommandInvalidAttribute
{
}
PHP);

        $this->artisan('deadlock:doctor')
            ->assertExitCode(1)
            ->expectsOutputToContain('WARN Supported workaround scan could not complete:')
            ->expectsOutputToContain('Invalid attributes')
            ->expectsOutputToContain('Workaround attribute must receive exactly 2 arguments.');
    }

    public function test_doctor_command_groups_unsupported_and_parse_issues(): void
    {
        $this->path = app_path('DoctorCommandUnsupported.php');
        $brokenPath = app_path('DoctorCommandBrokenSyntax.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class DoctorCommandUnsupported
{
    #[Workaround(description: 'Unsupported property', expires: '2099-01-01')]
    public string $name;
}
PHP);

        File::put($brokenPath, <<<'PHP'
<?php

namespace App;

class DoctorCommandBrokenSyntax
{
    public function broken(: void
    {
    }
}
PHP);

        try {
            $this->artisan('deadlock:doctor')
                ->assertExitCode(1)
                ->expectsOutputToContain('Unsupported targets')
                ->expectsOutputToContain('#[Workaround] is used on a property.')
                ->expectsOutputToContain('Parse issues')
                ->expectsOutputToContain('The file could not be parsed:');
        } finally {
            File::delete($brokenPath);
        }
    }
}

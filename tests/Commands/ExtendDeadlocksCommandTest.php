<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Tests\TestCase;

final class ExtendDeadlocksCommandTest extends TestCase
{
    public function test_extend_command_requires_a_file_option(): void
    {
        $this->artisan('deadlock:extend --all --days=1')
            ->assertExitCode(2)
            ->expectsOutputToContain('The --file option is required.');
    }

    public function test_extend_command_requires_a_target_mode(): void
    {
        $path = app_path('ExtendModeTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-01')]
class ExtendModeTest {}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--days' => 1,
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain('Use either --all or --class with --method.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_rejects_combining_all_with_class_and_method(): void
    {
        $path = app_path('ExtendConflictTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-01')]
class ExtendConflictTest
{
    #[Workaround('Method', '2025-01-01')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--all' => true,
                '--class' => 'App\\ExtendConflictTest',
                '--method' => 'handle',
                '--days' => 1,
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain('Use either --all or --class with --method.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_rejects_invalid_date_option_combinations(): void
    {
        $path = app_path('ExtendDateConflictTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-01')]
class ExtendDateConflictTest {}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--all' => true,
                '--days' => 1,
                '--date' => '2026-01-01',
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain('The --date option cannot be combined with --days or --months.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_updates_all_workarounds_in_a_file(): void
    {
        $path = app_path('ExtendAllWorkaroundsTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'Class workaround',
    expires: '2025-01-01'
)]
class ExtendAllWorkaroundsTest
{
    #[Workaround('Method workaround', '2025-02-01')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--all' => true,
                '--days' => 7,
                '--months' => 1,
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 2 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("expires: '2025-02-08'", $contents);
            $this->assertStringContainsString("'Method workaround', '2025-03-08'", $contents);
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_updates_only_the_targeted_method_workaround(): void
    {
        $path = app_path('ExtendMethodWorkaroundTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class ExtendMethodWorkaroundTest
{
    #[Workaround('Target method workaround', '2025-01-15')]
    public function handle(): void {}

    #[Workaround('Other method workaround', '2025-02-20')]
    public function skip(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--class' => 'App\\ExtendMethodWorkaroundTest',
                '--method' => 'handle',
                '--date' => '2026-06-01',
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 1 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("'Target method workaround', '2026-06-01'", $contents);
            $this->assertStringContainsString("'Other method workaround', '2025-02-20'", $contents);
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_target_method_has_no_workaround(): void
    {
        $path = app_path('ExtendMissingWorkaroundTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

class ExtendMissingWorkaroundTest
{
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--class' => 'App\\ExtendMissingWorkaroundTest',
                '--method' => 'handle',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('No workaround was found for the specified class and method.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_file_has_no_workarounds_for_all_mode(): void
    {
        $path = app_path('ExtendNoWorkaroundsTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

class ExtendNoWorkaroundsTest
{
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--file' => $path,
                '--all' => true,
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('No workarounds were found in the target file.');
        } finally {
            File::delete($path);
        }
    }
}

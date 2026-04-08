<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Tests\TestCase;

final class ExtendDeadlocksCommandTest extends TestCase
{
    public function test_extend_command_requires_a_class_option(): void
    {
        $this->artisan('deadlock:extend --days=1')
            ->assertExitCode(2)
            ->expectsOutputToContain('The --class option is required.');
    }

    public function test_extend_command_rejects_combining_all_with_method(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--all' => true,
            '--method' => 'index',
            '--days' => 1,
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('The --all option cannot be combined with --method.');
    }

    public function test_extend_command_rejects_invalid_date_option_combinations(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--days' => 1,
            '--date' => '2026-01-01',
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('The --date option cannot be combined with --days or --months.');
    }

    public function test_extend_command_rejects_invalid_months(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--months' => 0,
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('The --months option must be a positive integer.');
    }

    public function test_extend_command_updates_class_level_workaround(): void
    {
        $path = app_path('ExtendClassLevelWorkaroundTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Class workaround', '2025-01-01')]
class ExtendClassLevelWorkaroundTest
{
    #[Workaround('Method workaround', '2025-02-01')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendClassLevelWorkaroundTest',
                '--days' => 3,
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 1 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("'Class workaround', '2025-01-04'", $contents);
            $this->assertStringContainsString("'Method workaround', '2025-02-01'", $contents);
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_updates_all_workarounds_on_the_class(): void
    {
        $path = app_path('ExtendAllWorkaroundsOnClassTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(
    description: 'Class workaround',
    expires: '2025-01-01'
)]
class ExtendAllWorkaroundsOnClassTest
{
    #[Workaround('Method workaround', '2025-02-01')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendAllWorkaroundsOnClassTest',
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

    public function test_extend_command_updates_named_expires_arguments(): void
    {
        $path = app_path('ExtendNamedExpiresTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class ExtendNamedExpiresTest
{
    #[Workaround(description: 'Named workaround', expires: '2025-01-15')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendNamedExpiresTest',
                '--method' => 'handle',
                '--days' => 10,
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 1 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("expires: '2025-01-25'", $contents);
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

    public function test_extend_command_only_updates_the_targeted_class_in_a_multi_class_file(): void
    {
        $path = app_path('SecondExtendMultiClassWorkaroundTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class FirstExtendMultiClassWorkaroundTest
{
    #[Workaround('First workaround', '2025-01-15')]
    public function handle(): void {}
}

class SecondExtendMultiClassWorkaroundTest
{
    #[Workaround('Second workaround', '2025-02-20')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\SecondExtendMultiClassWorkaroundTest',
                '--method' => 'handle',
                '--days' => 5,
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 1 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("'First workaround', '2025-01-15'", $contents);
            $this->assertStringContainsString("'Second workaround', '2025-02-25'", $contents);
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_target_class_cannot_be_resolved(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => 'App\\DoesNotExist',
            '--days' => 1,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('The class "App\DoesNotExist" could not be resolved to a PHP file.');
    }

    public function test_extend_command_fails_when_target_method_is_missing(): void
    {
        $path = app_path('ExtendMissingMethodTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class ExtendMissingMethodTest
{
    #[Workaround('Example', '2025-01-15')]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendMissingMethodTest',
                '--method' => 'missing',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('The method "missing" was not found on class "App\ExtendMissingMethodTest".');
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

    public function test_extend_command_fails_when_class_has_no_workaround(): void
    {
        $path = app_path('ExtendMissingClassWorkaroundTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

class ExtendMissingClassWorkaroundTest
{
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendMissingClassWorkaroundTest',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('No workaround was found for the specified class.');
        } finally {
            File::delete($path);
        }
    }
}

final class ExtendContractController
{
    public function index(): void {}
}

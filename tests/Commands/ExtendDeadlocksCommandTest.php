<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

final class FileIoHook
{
    public static ?string $forceUnreadable = null;

    public static ?string $forceUnwritable = null;
}

function file_get_contents(
    string $filename,
    bool $use_include_path = false,
    $context = null,
    int $offset = 0,
    ?int $length = null
) {
    $realPath = realpath($filename) ?: $filename;

    if (FileIoHook::$forceUnreadable !== null && $realPath === FileIoHook::$forceUnreadable) {
        return false;
    }

    return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
}

function file_put_contents(
    string $filename,
    mixed $data,
    int $flags = 0,
    $context = null
) {
    $realPath = realpath($filename) ?: $filename;

    if (FileIoHook::$forceUnwritable !== null && $realPath === FileIoHook::$forceUnwritable) {
        return false;
    }

    return \file_put_contents($filename, $data, $flags, $context);
}

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Console\FileIoHook;
use Zidbih\Deadlock\Tests\TestCase;

final class ExtendDeadlocksCommandTest extends TestCase
{
    public function test_extend_command_requires_a_class_option(): void
    {
        $this->artisan('deadlock:extend --days=1')
            ->assertExitCode(2)
            ->expectsOutputToContain('Use exactly one of --class or --controller.');
    }

    public function test_extend_command_rejects_combining_class_with_controller(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--controller' => 'TestController',
            '--days' => 1,
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('Use exactly one of --class or --controller.');
    }

    public function test_extend_command_updates_controller_workaround(): void
    {
        $path = app_path('Http/Controllers/ExtendControllerTargetTest.php');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Controller workaround', '2025-01-01')]
class ExtendControllerTargetTest
{
    #[Workaround('Method workaround', '2025-02-01')]
    public function index(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--controller' => 'ExtendControllerTargetTest',
                '--days' => 2,
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 1 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("'Controller workaround', '2025-01-03'", $contents);
            $this->assertStringContainsString("'Method workaround', '2025-02-01'", $contents);
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_updates_nested_controller_method_workaround(): void
    {
        $path = app_path('Http/Controllers/Admin/ExtendNestedControllerTargetTest.php');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, <<<'PHP'
<?php

namespace App\Http\Controllers\Admin;

use Zidbih\Deadlock\Attributes\Workaround;

class ExtendNestedControllerTargetTest
{
    #[Workaround('Nested controller method workaround', '2025-02-01')]
    public function index(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--controller' => 'Admin/ExtendNestedControllerTargetTest',
                '--method' => 'index',
                '--months' => 1,
            ])
                ->assertExitCode(0)
                ->expectsOutputToContain('Extended 1 workaround(s).');

            $contents = File::get($path);

            $this->assertStringContainsString("'Nested controller method workaround', '2025-03-01'", $contents);
        } finally {
            File::delete($path);
        }
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

    public function test_extend_command_rejects_invalid_date_format(): void
    {
        $path = app_path('ExtendInvalidDateFormatTargetTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-01')]
class ExtendInvalidDateFormatTargetTest {}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendInvalidDateFormatTargetTest',
                '--date' => '01-31-2026',
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain("Invalid expires date '01-31-2026'. Expected YYYY-MM-DD.");
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_rejects_invalid_calendar_date(): void
    {
        $path = app_path('ExtendInvalidCalendarDateTargetTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-01')]
class ExtendInvalidCalendarDateTargetTest {}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendInvalidCalendarDateTargetTest',
                '--date' => '2026-02-30',
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain("Invalid expires date '2026-02-30'.");
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_requires_at_least_one_date_modifier(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('Provide at least one of --days, --months, or --date.');
    }

    public function test_extend_command_rejects_invalid_days(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--days' => 0,
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('The --days option must be a positive integer.');
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

    public function test_extend_command_rejects_empty_method(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--method' => '   ',
            '--days' => 1,
        ])
            ->assertExitCode(2)
            ->expectsOutputToContain('The --method option must not be empty.');
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

    public function test_extend_command_resolves_non_app_classes_via_reflection(): void
    {
        $this->artisan('deadlock:extend', [
            '--class' => ExtendContractController::class,
            '--days' => 1,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('No workaround was found for the specified class.');
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

    public function test_extend_command_fails_when_controller_cannot_be_resolved(): void
    {
        $this->artisan('deadlock:extend', [
            '--controller' => 'MissingController',
            '--days' => 1,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('The class "App\Http\Controllers\MissingController" could not be resolved to a PHP file.');
    }

    public function test_extend_command_fails_when_resolved_file_does_not_contain_the_class(): void
    {
        $path = app_path('ExtendMismatchedClassTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Different class workaround', '2025-01-15')]
class DifferentClassName {}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendMismatchedClassTest',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('The class "App\ExtendMismatchedClassTest" was not found in the resolved file.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_resolved_file_cannot_be_parsed(): void
    {
        $path = app_path('ExtendInvalidPhpTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

class ExtendInvalidPhpTest
{
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendInvalidPhpTest',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('The target file could not be parsed:');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_resolved_file_cannot_be_read(): void
    {
        $path = app_path('ExtendUnreadableTargetTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-15')]
class ExtendUnreadableTargetTest {}
PHP
        );

        $realPath = realpath($path);
        $this->assertNotFalse($realPath);

        FileIoHook::$forceUnreadable = $realPath;

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendUnreadableTargetTest',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('The target file could not be read.');
        } finally {
            FileIoHook::$forceUnreadable = null;
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_workaround_attribute_arguments_are_invalid(): void
    {
        $path = app_path('ExtendInvalidWorkaroundArgsTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Only description')]
class ExtendInvalidWorkaroundArgsTest {}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendInvalidWorkaroundArgsTest',
                '--days' => 1,
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain('Workaround attribute must receive exactly 2 arguments: description and expires.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_expires_is_not_a_string_literal(): void
    {
        $path = app_path('ExtendInvalidExpiresLiteralTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class ExtendInvalidExpiresLiteralTest
{
    #[Workaround('Example', 123)]
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendInvalidExpiresLiteralTest',
                '--method' => 'handle',
                '--days' => 1,
            ])
                ->assertExitCode(2)
                ->expectsOutputToContain('Workaround expires must be a string literal in YYYY-MM-DD format.');
        } finally {
            File::delete($path);
        }
    }

    public function test_extend_command_fails_when_updated_file_cannot_be_written(): void
    {
        $path = app_path('ExtendUnwritableTargetTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Example', '2025-01-15')]
class ExtendUnwritableTargetTest {}
PHP
        );

        $realPath = realpath($path);
        $this->assertNotFalse($realPath);

        FileIoHook::$forceUnwritable = $realPath;

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendUnwritableTargetTest',
                '--days' => 1,
            ])
                ->assertExitCode(1)
                ->expectsOutputToContain('The updated file could not be written.');
        } finally {
            FileIoHook::$forceUnwritable = null;
            File::delete($path);
        }
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
        $path = app_path('ExtendClassWithoutAnyWorkaroundTargetTest.php');

        File::put($path, <<<'PHP'
<?php

namespace App;

class ExtendClassWithoutAnyWorkaroundTargetTest
{
    public function handle(): void {}
}
PHP
        );

        try {
            $this->artisan('deadlock:extend', [
                '--class' => 'App\\ExtendClassWithoutAnyWorkaroundTargetTest',
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

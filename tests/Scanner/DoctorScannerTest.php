<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Scanner;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Scanner\DoctorScanner;
use Zidbih\Deadlock\Tests\TestCase;

final class DoctorScannerTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    public function test_it_reports_unsupported_workaround_targets(): void
    {
        $this->path = app_path('DoctorUnsupportedProperty.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class DoctorUnsupportedProperty
{
    #[Workaround(description: 'Temporary property', expires: '2099-01-01')]
    public string $gateway;
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on a property.');
    }

    public function test_it_reports_missing_class_level_guard_for_non_controller_classes(): void
    {
        $this->path = app_path('DoctorUnguardedClass.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(description: 'Temporary class', expires: '2099-01-01')]
class DoctorUnguardedClass
{
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'guard', 'Class-level workaround is not explicitly guarded.');
    }

    public function test_it_reports_missing_method_level_guard_for_non_controller_methods(): void
    {
        $this->path = app_path('DoctorUnguardedMethod.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class DoctorUnguardedMethod
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function calculate(): int
    {
        return 42;
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'guard', 'Method-level workaround is not explicitly guarded.');
    }

    public function test_it_reports_incorrect_method_level_guard_usage(): void
    {
        $this->path = app_path('DoctorIncorrectMethodGuard.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorIncorrectMethodGuard
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function calculate(): int
    {
        DeadlockGuard::check($this);

        return 42;
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists(
            $issues,
            'guard',
            'Method-level workaround has a DeadlockGuard::check() call, but it does not guard this method.'
        );
    }

    public function test_it_reports_invalid_workaround_dates(): void
    {
        $this->path = app_path('DoctorInvalidDate.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(description: 'Invalid date', expires: '01-01-2099')]
class DoctorInvalidDate
{
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'invalid-attribute', "Invalid expires date '01-01-2099'. Expected YYYY-MM-DD.");
    }

    public function test_it_accepts_correct_method_level_guard_usage(): void
    {
        $this->path = app_path('DoctorGuardedMethod.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorGuardedMethod
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function calculate(): int
    {
        DeadlockGuard::check($this, __FUNCTION__);

        return 42;
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $matchingIssues = array_filter(
            $issues,
            fn ($issue): bool => str_ends_with($issue->file, 'DoctorGuardedMethod.php')
        );

        $this->assertSame([], array_values($matchingIssues));
    }

    public function test_it_skips_missing_guard_warnings_for_controllers(): void
    {
        $directory = app_path('Http/Controllers');
        File::ensureDirectoryExists($directory);
        $this->path = $directory.DIRECTORY_SEPARATOR.'DoctorController.php';

        File::put($this->path, <<<'PHP'
<?php

namespace App\Http\Controllers;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(description: 'Temporary controller', expires: '2099-01-01')]
class DoctorController
{
    #[Workaround(description: 'Temporary action', expires: '2099-01-01')]
    public function index(): void
    {
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $matchingIssues = array_filter(
            $issues,
            fn ($issue): bool => str_ends_with($issue->file, 'DoctorController.php')
        );

        $this->assertSame([], array_values($matchingIssues));
    }

    /**
     * @param  array<int, object{type: string, message: string}>  $issues
     */
    private function assertIssueExists(array $issues, string $type, string $message): void
    {
        foreach ($issues as $issue) {
            if ($issue->type === $type && $issue->message === $message) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("Expected {$type} issue was not found.");
    }

    private function scanner(): DoctorScanner
    {
        return $this->app->make(DoctorScanner::class);
    }
}

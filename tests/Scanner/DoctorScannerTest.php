<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Scanner;

use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Scanner\DoctorScanner;
use Zidbih\Deadlock\Tests\TestCase;

final class DoctorScannerTest extends TestCase
{
    private string $path;

    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            File::delete($this->path);
        }

        foreach ($this->paths as $path) {
            File::delete($path);
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

    public function test_it_reports_invalid_attribute_arguments(): void
    {
        $this->path = app_path('DoctorInvalidAttributes.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Missing expires')]
class DoctorMissingArgument {}

#[Workaround(description: 123, expires: '2099-01-01')]
class DoctorInvalidDescription {}

#[Workaround(description: 'Invalid expires type', expires: 123)]
class DoctorInvalidExpiresType {}

#[Workaround(description: 'Impossible date', expires: '2099-02-31')]
class DoctorImpossibleDate {}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'invalid-attribute', 'Workaround attribute must receive exactly 2 arguments.');
        $this->assertIssueExists($issues, 'invalid-attribute', 'Workaround description must be a string literal.');
        $this->assertIssueExists($issues, 'invalid-attribute', 'Workaround expires must be a string literal in YYYY-MM-DD format.');
        $this->assertIssueExists($issues, 'invalid-attribute', "Invalid expires date '2099-02-31'.");
    }

    public function test_it_reports_multiple_unsupported_target_types(): void
    {
        $this->path = app_path('DoctorUnsupportedTargets.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(description: 'Unsupported function', expires: '2099-01-01')]
function doctorUnsupportedFunction(): void {}

$callback = #[Workaround(description: 'Unsupported closure', expires: '2099-01-01')]
    function (): void {};

class DoctorUnsupportedTargets
{
    public function handle(
        #[Workaround(description: 'Unsupported parameter', expires: '2099-01-01')]
        string $name
    ): void {}

    #[Workaround(description: 'Unsupported constant', expires: '2099-01-01')]
    public const MODE = 'test';
}

#[Workaround(description: 'Unsupported interface', expires: '2099-01-01')]
interface DoctorUnsupportedInterface {}

#[Workaround(description: 'Unsupported trait', expires: '2099-01-01')]
trait DoctorUnsupportedTrait {}

#[Workaround(description: 'Unsupported enum', expires: '2099-01-01')]
enum DoctorUnsupportedEnum
{
    #[Workaround(description: 'Unsupported enum case', expires: '2099-01-01')]
    case Draft;
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on a function.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on a closure.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on a parameter.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on a class constant.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on an interface.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on a trait.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on an enum.');
        $this->assertIssueExists($issues, 'unsupported-target', '#[Workaround] is used on an enum case.');
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

    public function test_it_accepts_valid_class_level_guard_usage(): void
    {
        $this->path = app_path('DoctorGuardedClass.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

#[Workaround(description: 'Temporary class', expires: '2099-01-01')]
class DoctorGuardedClass
{
    public function __construct()
    {
        DeadlockGuard::check($this);
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $matchingIssues = array_filter(
            $issues,
            fn ($issue): bool => str_ends_with($issue->file, 'DoctorGuardedClass.php')
        );

        $this->assertSame([], array_values($matchingIssues));
    }

    public function test_it_accepts_class_string_guard_targets(): void
    {
        $this->path = app_path('DoctorClassStringGuards.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorClassStringGuards
{
    #[Workaround(description: 'Self class guard', expires: '2099-01-01')]
    public function selfClass(): void
    {
        DeadlockGuard::check(self::class, __FUNCTION__);
    }

    #[Workaround(description: 'String class guard', expires: '2099-01-01')]
    public function stringClass(): void
    {
        DeadlockGuard::check(__CLASS__, 'stringClass');
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $matchingIssues = array_filter(
            $issues,
            fn ($issue): bool => str_ends_with($issue->file, 'DoctorClassStringGuards.php')
        );

        $this->assertSame([], array_values($matchingIssues));
    }

    public function test_it_reports_class_level_constructor_with_invalid_guard(): void
    {
        $this->path = app_path('DoctorInvalidClassGuard.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

#[Workaround(description: 'Temporary class', expires: '2099-01-01')]
class DoctorInvalidClassGuard
{
    public function __construct()
    {
        DeadlockGuard::check();
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'guard', 'Class-level workaround does not have a valid DeadlockGuard::check($this) call.');
    }

    public function test_it_reports_wrong_method_name_in_guard(): void
    {
        $this->path = app_path('DoctorWrongMethodNameGuard.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorWrongMethodNameGuard
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function calculate(): void
    {
        DeadlockGuard::check($this, 'otherMethod');
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

    public function test_it_accepts_aliased_guard_imports(): void
    {
        $this->path = app_path('DoctorAliasedGuard.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard as Guard;

class DoctorAliasedGuard
{
    #[Workaround(description: 'Aliased guard', expires: '2099-01-01')]
    public function run(): void
    {
        Guard::check($this, __FUNCTION__);
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $matchingIssues = array_filter(
            $issues,
            fn ($issue): bool => str_ends_with($issue->file, 'DoctorAliasedGuard.php')
        );

        $this->assertSame([], array_values($matchingIssues));
    }

    public function test_it_ignores_static_calls_that_are_not_guard_checks(): void
    {
        $this->path = app_path('DoctorNonGuardStaticCalls.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorNonGuardStaticCalls
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function run(): void
    {
        $guard = DeadlockGuard::class;

        $guard::check($this, __FUNCTION__);
        DeadlockGuard::inspect($this, __FUNCTION__);
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists(
            $issues,
            'guard',
            'Method-level workaround is not explicitly guarded.'
        );
    }

    public function test_it_rejects_method_magic_constant_as_guard_method_name(): void
    {
        $this->path = app_path('DoctorMethodMagicGuard.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorMethodMagicGuard
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function run(): void
    {
        DeadlockGuard::check($this, __METHOD__);
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

    public function test_it_rejects_unknown_guard_method_arguments(): void
    {
        $this->path = app_path('DoctorUnknownMethodArgument.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Support\DeadlockGuard;

class DoctorUnknownMethodArgument
{
    #[Workaround(description: 'Temporary method', expires: '2099-01-01')]
    public function run(): void
    {
        DeadlockGuard::check($this, __LINE__);
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

    public function test_it_reports_abstract_methods_with_workarounds_as_unguarded(): void
    {
        $this->path = app_path('DoctorAbstractMethod.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

abstract class DoctorAbstractMethod
{
    #[Workaround(description: 'Temporary abstract method', expires: '2099-01-01')]
    abstract public function run(): void;
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        $this->assertIssueExists($issues, 'guard', 'Method-level workaround is not explicitly guarded.');
    }

    public function test_it_skips_empty_php_files(): void
    {
        $this->path = app_path('DoctorEmpty.php');

        File::put($this->path, '<?php');

        $issues = $this->scanner()->scan(app_path());

        $matchingIssues = array_filter(
            $issues,
            fn ($issue): bool => str_ends_with($issue->file, 'DoctorEmpty.php')
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

    public function test_it_reports_parse_errors(): void
    {
        $this->path = app_path('DoctorBrokenSyntax.php');

        File::put($this->path, <<<'PHP'
<?php

namespace App;

class DoctorBrokenSyntax
{
    public function broken(: void
    {
    }
}
PHP);

        $issues = $this->scanner()->scan(app_path());

        foreach ($issues as $issue) {
            if ($issue->type === 'parse' && str_ends_with($issue->file, 'DoctorBrokenSyntax.php')) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail('Expected parse issue was not found.');
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

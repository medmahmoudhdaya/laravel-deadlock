<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

final class FileReadHook
{
    public static ?string $forceUnreadable = null;
}

function file_get_contents(
    string $filename,
    bool $use_include_path = false,
    $context = null,
    int $offset = 0,
    ?int $length = null
) {
    $realPath = realpath($filename) ?: $filename;

    if (FileReadHook::$forceUnreadable !== null && $realPath === FileReadHook::$forceUnreadable) {
        return false;
    }

    return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
}

namespace Zidbih\Deadlock\Tests\Scanner;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Zidbih\Deadlock\Scanner\DeadlockScanner;
use Zidbih\Deadlock\Scanner\FileReadHook;
use Zidbih\Deadlock\Tests\TestCase;

final class WorkaroundVisitorValidationTest extends TestCase
{
    public function test_accepts_short_and_fqcn_attribute_names(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            $path = $dir.'/Attributes.php';

            File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Short attribute', '2099-01-01')]
class ShortAttribute {}

#[\Zidbih\Deadlock\Attributes\Workaround('FQCN attribute', '2099-01-01')]
class FqcnAttribute {}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);
            $results = $scanner->scan($dir);

            $this->assertCount(2, $results);

            $byDescription = [];
            foreach ($results as $result) {
                $byDescription[$result->description] = $result;
            }

            $this->assertSame('ShortAttribute', $byDescription['Short attribute']->class);
            $this->assertSame('FqcnAttribute', $byDescription['FQCN attribute']->class);
        });
    }

    public function test_throws_when_argument_count_is_invalid(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/InvalidArgs.php', <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Only one')]
class InvalidArgs {}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Workaround attribute must receive exactly 2 arguments: description and expires.');

            $scanner->scan($dir);
        });
    }

    public function test_throws_when_description_is_not_string_literal(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/InvalidDescription.php', <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround(123, '2025-01-01')]
class InvalidDescription {}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Workaround description must be a string literal.');

            $scanner->scan($dir);
        });
    }

    public function test_throws_when_expires_is_not_string_literal(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/InvalidExpiresType.php', <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Description', 123)]
class InvalidExpiresType {}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Workaround expires must be a string literal in YYYY-MM-DD format.');

            $scanner->scan($dir);
        });
    }

    public function test_throws_on_invalid_date_format(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/InvalidDateFormat.php', <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Description', '01-31-2025')]
class InvalidDateFormat {}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage("Invalid expires date '01-31-2025'. Expected YYYY-MM-DD.");

            $scanner->scan($dir);
        });
    }

    public function test_throws_on_invalid_calendar_date(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/InvalidCalendarDate.php', <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Description', '2025-02-30')]
class InvalidCalendarDate {}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage("Invalid expires date '2025-02-30'.");

            $scanner->scan($dir);
        });
    }

    public function test_scanner_skips_non_php_files(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/NotPhp.txt', <<<'TXT'
<?php
#[Workaround('Desc', '2099-01-01')]
class NotPhp {}
TXT
            );

            $scanner = $this->app->make(DeadlockScanner::class);
            $results = $scanner->scan($dir);

            $this->assertCount(0, $results);
        });
    }

    public function test_scanner_skips_invalid_php_files(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/Invalid.php', <<<'PHP'
<?php

class {
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);
            $results = $scanner->scan($dir);

            $this->assertCount(0, $results);
        });
    }

    public function test_scanner_skips_unreadable_files(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            $path = $dir.'/Unreadable.php';

            File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Unreadable', '2099-01-01')]
class Unreadable {}
PHP
            );

            $realPath = realpath($path);
            $this->assertNotFalse($realPath);

            FileReadHook::$forceUnreadable = $realPath;

            try {
                $scanner = $this->app->make(DeadlockScanner::class);
                $results = $scanner->scan($dir);

                $this->assertCount(0, $results);
            } finally {
                FileReadHook::$forceUnreadable = null;
            }
        });
    }

    public function test_scanner_injects_file_path_and_line_numbers(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            $path = $dir.'/LineInjection.php';

            File::put($path, <<<'PHP'
<?php

namespace App;

use Zidbih\Deadlock\Attributes\Workaround;

class LineInjection
{
    #[Workaround('Line injection', '2099-01-01')]
    public function run(): void {}
}
PHP
            );

            $scanner = $this->app->make(DeadlockScanner::class);
            $results = $scanner->scan($dir);

            $this->assertCount(1, $results);
            $result = $results[0];

            $this->assertSame(realpath($path), $result->file);
            $this->assertSame(9, $result->line);
        });
    }

    public function test_scanner_skips_empty_php_files(): void
    {
        $this->withTempDirectory(function (string $dir): void {
            File::put($dir.'/Empty.php', '');

            $scanner = $this->app->make(DeadlockScanner::class);
            $results = $scanner->scan($dir);

            $this->assertCount(0, $results);
        });
    }

    public function test_is_workaround_attribute_helper_recognizes_names(): void
    {
        $visitor = new \Zidbih\Deadlock\Scanner\NodeVisitors\WorkaroundVisitor;
        $method = new \ReflectionMethod($visitor, 'isWorkaroundAttribute');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($visitor, 'Workaround'));
        $this->assertTrue($method->invoke($visitor, 'Zidbih\\Deadlock\\Attributes\\Workaround'));
        $this->assertFalse($method->invoke($visitor, 'OtherAttribute'));
    }

    private function withTempDirectory(callable $callback): void
    {
        $dir = sys_get_temp_dir().'/deadlock-tests-'.uniqid('', true);
        File::makeDirectory($dir, 0755, true);

        try {
            $callback($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}

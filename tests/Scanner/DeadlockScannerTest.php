<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Scanner;

use Zidbih\Deadlock\Scanner\DeadlockScanner;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockScannerTest extends TestCase
{
    public function test_scanner_detects_workarounds(): void
    {
        $scanner = $this->app->make(DeadlockScanner::class);

        $results = $scanner->scan(__DIR__.'/../Fixtures');

        $this->assertNotEmpty($results);
    }
}

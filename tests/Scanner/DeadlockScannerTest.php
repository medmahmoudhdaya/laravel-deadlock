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

    public function test_scanner_extracts_expected_metadata(): void
    {
        $scanner = $this->app->make(DeadlockScanner::class);

        $results = $scanner->scan(__DIR__.'/../Fixtures');

        $this->assertCount(2, $results);

        $byDescription = [];

        foreach ($results as $result) {
            $byDescription[$result->description] = $result;
        }

        $this->assertArrayHasKey('Expired class workaround', $byDescription);
        $this->assertArrayHasKey('Expired method workaround', $byDescription);

        $classResult = $byDescription['Expired class workaround'];
        $this->assertSame('2020-01-01', $classResult->expires);
        $this->assertSame('ExpiredService', $classResult->class);
        $this->assertNull($classResult->method);
        $this->assertTrue(str_ends_with($classResult->file, 'ExpiredService.php'));
        $this->assertSame(9, $classResult->line);

        $methodResult = $byDescription['Expired method workaround'];
        $this->assertSame('2020-01-01', $methodResult->expires);
        $this->assertSame('ActiveService', $methodResult->class);
        $this->assertSame('run', $methodResult->method);
        $this->assertTrue(str_ends_with($methodResult->file, 'ActiveService.php'));
        $this->assertSame(11, $methodResult->line);
    }
}

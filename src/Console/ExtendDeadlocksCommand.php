<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Zidbih\Deadlock\Scanner\NodeVisitors\WorkaroundExpiryExtender;

final class ExtendDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:extend
    {--file= : File containing the workaround(s) to extend}
    {--all : Extend all workarounds in the file}
    {--class= : Fully qualified class name}
    {--method= : Method name for a targeted workaround}
    {--days= : Number of days to add}
    {--months= : Number of months to add}
    {--date= : Exact expiry date in YYYY-MM-DD format}';

    protected $description = 'Extend workaround expiration dates';

    public function handle(): int
    {
        $validation = $this->validateOptions();

        if ($validation !== null) {
            $this->error($validation);

            return self::INVALID;
        }

        $path = $this->resolvePath();

        if (! is_file($path)) {
            $this->error('The --file option must point to an existing PHP file.');

            return self::INVALID;
        }

        $code = @file_get_contents($path);

        if ($code === false) {
            $this->error('The target file could not be read.');

            return self::FAILURE;
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $originalStatements = $parser->parse($code);
        } catch (\Throwable $exception) {
            $this->error('The target file could not be parsed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($originalStatements === null) {
            $this->error('The target file is empty or could not be parsed.');

            return self::FAILURE;
        }

        $cloneTraverser = new NodeTraverser;
        $cloneTraverser->addVisitor(new CloningVisitor);
        $modifiedStatements = $cloneTraverser->traverse($originalStatements);

        $extender = new WorkaroundExpiryExtender(
            targetClass: $this->option('class'),
            targetMethod: $this->option('method'),
            extendAll: (bool) $this->option('all'),
            days: $this->integerOption('days'),
            months: $this->integerOption('months'),
            date: $this->option('date'),
        );

        try {
            $updateTraverser = new NodeTraverser;
            $updateTraverser->addVisitor($extender);
            $modifiedStatements = $updateTraverser->traverse($modifiedStatements);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($extender->updatedCount === 0) {
            $this->error($this->option('all')
                ? 'No workarounds were found in the target file.'
                : 'No workaround was found for the specified class and method.');

            return self::FAILURE;
        }

        $printer = new Standard;
        $updatedCode = $printer->printFormatPreserving(
            $modifiedStatements,
            $originalStatements,
            $parser->getTokens()
        );

        if ($updatedCode !== $code) {
            file_put_contents($path, $updatedCode);
        }

        $this->info("Extended {$extender->updatedCount} workaround(s).");

        return self::SUCCESS;
    }

    private function validateOptions(): ?string
    {
        if (! is_string($this->option('file')) || trim($this->option('file')) === '') {
            return 'The --file option is required.';
        }

        $all = (bool) $this->option('all');
        $class = $this->option('class');
        $method = $this->option('method');

        if ($all === ($class !== null || $method !== null)) {
            return 'Use either --all or --class with --method.';
        }

        if (! $all && (! is_string($class) || trim($class) === '' || ! is_string($method) || trim($method) === '')) {
            return 'The --class and --method options are required unless --all is used.';
        }

        $hasDays = $this->option('days') !== null;
        $hasMonths = $this->option('months') !== null;
        $hasDate = $this->option('date') !== null;

        if (! $hasDays && ! $hasMonths && ! $hasDate) {
            return 'Provide at least one of --days, --months, or --date.';
        }

        if ($hasDate && ($hasDays || $hasMonths)) {
            return 'The --date option cannot be combined with --days or --months.';
        }

        if ($hasDays && $this->integerOption('days') <= 0) {
            return 'The --days option must be a positive integer.';
        }

        if ($hasMonths && $this->integerOption('months') <= 0) {
            return 'The --months option must be a positive integer.';
        }

        return null;
    }

    private function resolvePath(): string
    {
        $path = trim((string) $this->option('file'));

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function integerOption(string $name): int
    {
        $value = $this->option($name);

        if (! is_string($value) && ! is_int($value)) {
            return 0;
        }

        $normalized = (string) $value;

        if (! preg_match('/^[1-9]\d*$/', $normalized)) {
            return 0;
        }

        return (int) $normalized;
    }
}

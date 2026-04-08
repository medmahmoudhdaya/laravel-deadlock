<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use Zidbih\Deadlock\Scanner\NodeVisitors\WorkaroundExpiryExtender;

final class ExtendDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:extend
    {--class= : Fully qualified class name}
    {--method= : Method name for a targeted workaround}
    {--all : Extend the class-level workaround and every method workaround on the class}
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

        $class = trim((string) $this->option('class'));
        $path = $this->resolveClassFile($class);

        if ($path === null) {
            $this->error(sprintf(
                'The class "%s" could not be resolved to a PHP file.',
                $class
            ));

            return self::FAILURE;
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
            targetClass: $class,
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

        if (! $extender->foundTargetClass) {
            $this->error(sprintf(
                'The class "%s" was not found in the resolved file.',
                $class
            ));

            return self::FAILURE;
        }

        if ($this->option('method') !== null && ! $extender->foundTargetMethod) {
            $this->error(sprintf(
                'The method "%s" was not found on class "%s".',
                $this->option('method'),
                $class
            ));

            return self::FAILURE;
        }

        if ($extender->updatedCount === 0) {
            $this->error($this->option('method') !== null
                ? 'No workaround was found for the specified class and method.'
                : 'No workaround was found for the specified class.');

            return self::FAILURE;
        }

        $printer = new Standard;
        $updatedCode = $printer->printFormatPreserving(
            $modifiedStatements,
            $originalStatements,
            $parser->getTokens()
        );

        if ($updatedCode !== $code) {
            if (@file_put_contents($path, $updatedCode) === false) {
                $this->error('The updated file could not be written.');

                return self::FAILURE;
            }
        }

        $this->info("Extended {$extender->updatedCount} workaround(s).");

        return self::SUCCESS;
    }

    private function validateOptions(): ?string
    {
        if (! is_string($this->option('class')) || trim($this->option('class')) === '') {
            return 'The --class option is required.';
        }

        $all = (bool) $this->option('all');
        $method = $this->option('method');

        if ($all && $method !== null) {
            return 'The --all option cannot be combined with --method.';
        }

        if ($method !== null && (! is_string($method) || trim($method) === '')) {
            return 'The --method option must not be empty.';
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

    private function resolveClassFile(string $class): ?string
    {
        if (str_starts_with($class, 'App\\')) {
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)).'.php';
            $appPath = app_path($relativePath);

            if (is_file($appPath)) {
                return $appPath;
            }
        }

        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $path = $reflection->getFileName();

        if (! is_string($path) || $path === '' || pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
            return null;
        }

        return $path;
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

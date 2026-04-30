<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Zidbih\Deadlock\Scanner\NodeVisitors\WorkaroundDoctorVisitor;

final class DoctorScanner
{
    /**
     * @return DoctorIssue[]
     */
    public function scan(string $path): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $issues = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $pathName = $file->getRealPath();

            if (! is_string($pathName)) {
                continue;
            }

            $code = @file_get_contents($pathName);

            if ($code === false) {
                continue;
            }

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable $exception) {
                $issues[] = new DoctorIssue(
                    type: 'parse',
                    message: 'The file could not be parsed: '.$exception->getMessage(),
                    file: $pathName,
                    line: 1,
                );

                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new WorkaroundDoctorVisitor($pathName);
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            array_push($issues, ...$visitor->issues);
        }

        return $issues;
    }
}

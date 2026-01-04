<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Zidbih\Deadlock\Scanner\NodeVisitors\WorkaroundVisitor;

final class DeadlockScanner
{
    /**
     * @return DeadlockResult[]
     */
    public function scan(string $path): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $results = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = @file_get_contents($file->getRealPath());

            if ($code === false) {
                continue;
            }

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                // Skip invalid PHP files safely
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new WorkaroundVisitor;
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->results as $result) {
                // Inject file path after AST analysis
                $results[] = new DeadlockResult(
                    description: $result->description,
                    expires: $result->expires,
                    file: $file->getRealPath(),
                    line: $result->line,
                    class: $result->class,
                    method: $result->method
                );
            }
        }

        return $results;
    }
}

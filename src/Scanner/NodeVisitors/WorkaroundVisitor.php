<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner\NodeVisitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Zidbih\Deadlock\Scanner\DeadlockResult;

final class WorkaroundVisitor extends NodeVisitorAbstract
{
    /** @var DeadlockResult[] */
    public array $results = [];

    private ?string $currentClass = null;

    public function enterNode(Node $node): void
    {
        // Track current class name
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name?->toString();
        }

        // Only classes and methods can have Workaround attributes
        if (! ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\ClassMethod)) {
            return;
        }

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (! $this->isWorkaroundAttribute($attribute->name->toString())) {
                    continue;
                }

                $args = $attribute->args;

                // We expect exactly two scalar string arguments
                if (count($args) < 2) {
                    continue;
                }

                $this->results[] = new DeadlockResult(
                    description: $args[0]->value->value,
                    expires: $args[1]->value->value,
                    file: '', // injected later by scanner
                    line: $node->getLine(),
                    class: $this->currentClass,
                    method: $node instanceof Node\Stmt\ClassMethod
                        ? $node->name->toString()
                        : null
                );
            }
        }
    }

    private function isWorkaroundAttribute(string $name): bool
    {
        return $name === 'Workaround'
            || $name === 'Zidbih\\Deadlock\\Attributes\\Workaround';
    }
}

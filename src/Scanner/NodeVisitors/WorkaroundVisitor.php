<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner\NodeVisitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\WorkaroundAttributeParser;

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
                if (! WorkaroundAttributeMatcher::matches($attribute->name->toString())) {
                    continue;
                }

                $workaround = WorkaroundAttributeParser::parse($attribute);

                $this->results[] = new DeadlockResult(
                    description: $workaround->description,
                    expires: $workaround->expires,
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
}

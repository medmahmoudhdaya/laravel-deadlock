<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner\NodeVisitors;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
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
                if (count($args) !== 2) {
                    throw new InvalidArgumentException(
                        'Workaround attribute must receive exactly 2 arguments: description and expires.'
                    );
                }

                $descNode = $args[0]->value;
                $expNode = $args[1]->value;

                if (! $descNode instanceof String_) {
                    throw new InvalidArgumentException('Workaround description must be a string literal.');
                }

                if (! $expNode instanceof String_) {
                    throw new InvalidArgumentException('Workaround expires must be a string literal in YYYY-MM-DD format.');
                }

                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expNode->value)) {
                    throw new InvalidArgumentException("Invalid expires date '{$expNode->value}'. Expected YYYY-MM-DD.");
                }

                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $expNode->value, new DateTimeZone('UTC'));
                $errors = DateTimeImmutable::getLastErrors();

                if ($dt === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
                    throw new InvalidArgumentException("Invalid expires date '{$expNode->value}'.");
                }

                $this->results[] = new DeadlockResult(
                    description: $descNode->value,
                    expires: $expNode->value,
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

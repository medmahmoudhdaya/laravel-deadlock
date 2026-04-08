<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner\NodeVisitors;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

final class WorkaroundExpiryExtender extends NodeVisitorAbstract
{
    public int $updatedCount = 0;

    public bool $foundTargetClass = false;

    public bool $foundTargetMethod = false;

    private ?string $namespace = null;

    private ?string $currentClass = null;

    public function __construct(
        private readonly ?string $targetClass,
        private readonly ?string $targetMethod,
        private readonly bool $extendAll,
        private readonly int $days,
        private readonly int $months,
        private readonly ?string $date,
    ) {}

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();

            return;
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name === null
                ? null
                : ltrim(($this->namespace ? $this->namespace.'\\' : '').$node->name->toString(), '\\');

            if (! $this->extendAll && $this->currentClass === $this->targetClass) {
                $this->foundTargetClass = true;
            }
        }

        if (! ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\ClassMethod)) {
            return;
        }

        if (! $this->extendAll
            && $node instanceof Node\Stmt\ClassMethod
            && $this->currentClass === $this->targetClass
            && $node->name->toString() === $this->targetMethod) {
            $this->foundTargetMethod = true;
        }

        if (! $this->shouldUpdate($node)) {
            return;
        }

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (! WorkaroundAttributeMatcher::matches($attribute->name->toString())) {
                    continue;
                }

                $expiresArgument = $this->resolveExpiresArgument($attribute->args);
                $expiresNode = $expiresArgument->value;

                if (! $expiresNode instanceof String_) {
                    throw new InvalidArgumentException('Workaround expires must be a string literal in YYYY-MM-DD format.');
                }

                $expiresValue = $this->resolveExpiresValue($expiresNode->value);

                $expiresArgument->value = new String_($expiresValue, $expiresNode->getAttributes());
                $this->updatedCount++;
            }
        }
    }

    private function shouldUpdate(Node $node): bool
    {
        if ($this->extendAll) {
            return true;
        }

        if ($this->currentClass !== $this->targetClass || ! $node instanceof Node\Stmt\ClassMethod) {
            return false;
        }

        return $node->name->toString() === $this->targetMethod;
    }

    /**
     * @param  Arg[]  $arguments
     */
    private function resolveExpiresArgument(array $arguments): Arg
    {
        if (count($arguments) !== 2) {
            throw new InvalidArgumentException(
                'Workaround attribute must receive exactly 2 arguments: description and expires.'
            );
        }

        foreach ($arguments as $argument) {
            if ($argument->name?->toString() === 'expires') {
                return $argument;
            }
        }

        return $arguments[1];
    }

    private function resolveExpiresValue(string $expires): string
    {
        if ($this->date !== null) {
            return $this->validateDate($this->date);
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->validateDate($expires), new DateTimeZone('UTC'));

        if ($date === false) {
            throw new InvalidArgumentException("Invalid expires date '{$expires}'.");
        }

        if ($this->months > 0) {
            $date = $date->modify("+{$this->months} months");
        }

        if ($this->days > 0) {
            $date = $date->modify("+{$this->days} days");
        }

        return $date->format('Y-m-d');
    }

    private function validateDate(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException("Invalid expires date '{$date}'. Expected YYYY-MM-DD.");
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            throw new InvalidArgumentException("Invalid expires date '{$date}'.");
        }

        return $date;
    }
}

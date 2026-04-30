<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner\NodeVisitors;

use DateTimeImmutable;
use DateTimeZone;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute as PhpAttribute;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Function_ as FunctionMagicConst;
use PhpParser\Node\Scalar\MagicConst\Method;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_ as FunctionStatement;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use Zidbih\Deadlock\Scanner\DoctorIssue;

final class WorkaroundDoctorVisitor extends NodeVisitorAbstract
{
    /** @var DoctorIssue[] */
    public array $issues = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node): void
    {
        $attributes = $this->workaroundAttributes($node);

        if ($attributes === [] && ! $node instanceof Class_) {
            return;
        }

        foreach ($attributes as $attribute) {
            $this->validateAttribute($attribute);
        }

        if ($attributes !== [] && ! $this->isSupportedTarget($node)) {
            $this->issues[] = new DoctorIssue(
                type: 'unsupported-target',
                message: '#[Workaround] is used on '.$this->targetName($node).'.',
                file: $this->file,
                line: $node->getLine(),
                suggestion: 'Use #[Workaround] on a class or method.'
            );

            return;
        }

        if ($node instanceof Class_) {
            $this->inspectClass($node);
        }
    }

    /**
     * @return PhpAttribute[]
     */
    private function workaroundAttributes(Node $node): array
    {
        if (! property_exists($node, 'attrGroups')) {
            return [];
        }

        $attributes = [];

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (WorkaroundAttributeMatcher::matches($attribute->name->toString())) {
                    $attributes[] = $attribute;
                }
            }
        }

        return $attributes;
    }

    private function validateAttribute(PhpAttribute $attribute): void
    {
        if (count($attribute->args) !== 2) {
            $this->issues[] = new DoctorIssue(
                type: 'invalid-attribute',
                message: 'Workaround attribute must receive exactly 2 arguments.',
                file: $this->file,
                line: $attribute->getLine(),
                suggestion: 'Use #[Workaround(description: "...", expires: "YYYY-MM-DD")].'
            );

            return;
        }

        $description = $attribute->args[0]->value;
        $expires = $attribute->args[1]->value;

        if (! $description instanceof String_) {
            $this->issues[] = new DoctorIssue(
                type: 'invalid-attribute',
                message: 'Workaround description must be a string literal.',
                file: $this->file,
                line: $attribute->getLine()
            );
        }

        if (! $expires instanceof String_) {
            $this->issues[] = new DoctorIssue(
                type: 'invalid-attribute',
                message: 'Workaround expires must be a string literal in YYYY-MM-DD format.',
                file: $this->file,
                line: $attribute->getLine()
            );

            return;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires->value)) {
            $this->issues[] = new DoctorIssue(
                type: 'invalid-attribute',
                message: "Invalid expires date '{$expires->value}'. Expected YYYY-MM-DD.",
                file: $this->file,
                line: $attribute->getLine()
            );

            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires->value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            $this->issues[] = new DoctorIssue(
                type: 'invalid-attribute',
                message: "Invalid expires date '{$expires->value}'.",
                file: $this->file,
                line: $attribute->getLine()
            );
        }
    }

    private function isSupportedTarget(Node $node): bool
    {
        return $node instanceof Class_ || $node instanceof ClassMethod;
    }

    private function inspectClass(Class_ $class): void
    {
        if ($this->isController($class)) {
            return;
        }

        if ($this->workaroundAttributes($class) !== []) {
            $this->inspectClassGuard($class);
        }

        foreach ($class->getMethods() as $method) {
            if ($this->workaroundAttributes($method) === []) {
                continue;
            }

            $this->inspectMethodGuard($method);
        }
    }

    private function inspectClassGuard(Class_ $class): void
    {
        $constructor = $this->constructor($class);

        if ($constructor === null) {
            $this->issues[] = new DoctorIssue(
                type: 'guard',
                message: 'Class-level workaround is not explicitly guarded.',
                file: $this->file,
                line: $class->getLine(),
                suggestion: 'Add DeadlockGuard::check($this) inside __construct().'
            );

            return;
        }

        $guardCalls = $this->guardCalls($constructor);

        foreach ($guardCalls as $guardCall) {
            if ($this->hasValidTargetArgument($guardCall) && count($guardCall->args) === 1) {
                return;
            }
        }

        $this->issues[] = new DoctorIssue(
            type: 'guard',
            message: 'Class-level workaround does not have a valid DeadlockGuard::check($this) call.',
            file: $this->file,
            line: $constructor->getLine(),
            suggestion: 'Call DeadlockGuard::check($this) from the constructor.'
        );
    }

    private function inspectMethodGuard(ClassMethod $method): void
    {
        $guardCalls = $this->guardCalls($method);

        foreach ($guardCalls as $guardCall) {
            if ($this->isValidMethodGuard($guardCall, $method->name->toString())) {
                return;
            }
        }

        $message = 'Method-level workaround is not explicitly guarded.';
        $suggestion = 'Add DeadlockGuard::check($this, __FUNCTION__) inside the method.';

        if ($guardCalls !== []) {
            $message = 'Method-level workaround has a DeadlockGuard::check() call, but it does not guard this method.';
        }

        $this->issues[] = new DoctorIssue(
            type: 'guard',
            message: $message,
            file: $this->file,
            line: $method->getLine(),
            suggestion: $suggestion
        );
    }

    private function constructor(Class_ $class): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                return $method;
            }
        }

        return null;
    }

    /**
     * @return StaticCall[]
     */
    private function guardCalls(ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $finder = new NodeFinder;

        return array_values(array_filter(
            $finder->findInstanceOf($method->stmts, StaticCall::class),
            fn (StaticCall $call): bool => $this->isDeadlockGuardCall($call)
        ));
    }

    private function isDeadlockGuardCall(StaticCall $call): bool
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return false;
        }

        if ($call->name->toString() !== 'check') {
            return false;
        }

        $name = ltrim($call->class->toString(), '\\');
        $resolvedName = $call->class->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            $name = ltrim($resolvedName->toString(), '\\');
        }

        return $name === 'DeadlockGuard'
            || $name === 'Zidbih\\Deadlock\\Support\\DeadlockGuard'
            || str_ends_with($name, '\\DeadlockGuard');
    }

    private function isValidMethodGuard(StaticCall $call, string $methodName): bool
    {
        if (! $this->hasValidTargetArgument($call) || count($call->args) < 2) {
            return false;
        }

        $methodArgument = $call->args[1]->value;

        if ($methodArgument instanceof FunctionMagicConst) {
            return true;
        }

        if ($methodArgument instanceof String_) {
            return $methodArgument->value === $methodName;
        }

        if ($methodArgument instanceof Method) {
            return false;
        }

        return false;
    }

    private function hasValidTargetArgument(StaticCall $call): bool
    {
        if (! isset($call->args[0]) || ! $call->args[0] instanceof Arg) {
            return false;
        }

        $argument = $call->args[0]->value;

        if ($argument instanceof Variable && $argument->name === 'this') {
            return true;
        }

        if ($argument instanceof String_) {
            return true;
        }

        return $argument instanceof ClassConstFetch
            && $argument->name instanceof Identifier
            && strtolower($argument->name->toString()) === 'class';
    }

    private function isController(Class_ $class): bool
    {
        $normalizedFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->file);

        if (str_contains($normalizedFile, DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        $name = $class->namespacedName ?? $class->name;

        return $name instanceof Name && str_ends_with($name->toString(), 'Controller');
    }

    private function targetName(Node $node): string
    {
        return match (true) {
            $node instanceof Property => 'a property',
            $node instanceof FunctionStatement => 'a function',
            $node instanceof Node\Expr\Closure => 'a closure',
            $node instanceof Node\Param => 'a parameter',
            $node instanceof ClassConst => 'a class constant',
            $node instanceof EnumCase => 'an enum case',
            $node instanceof Interface_ => 'an interface',
            $node instanceof Trait_ => 'a trait',
            $node instanceof Enum_ => 'an enum',
            default => 'an unsupported target',
        };
    }
}

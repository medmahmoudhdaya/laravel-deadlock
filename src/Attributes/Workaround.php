<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Workaround
{
    public function __construct(
        public readonly string $description,
        public readonly string $expires
    ) {}
}

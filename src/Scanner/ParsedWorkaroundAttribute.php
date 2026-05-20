<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

final class ParsedWorkaroundAttribute
{
    public function __construct(
        public readonly string $description,
        public readonly string $expires,
    ) {}
}

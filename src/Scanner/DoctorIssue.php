<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

final class DoctorIssue
{
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $suggestion = null,
    ) {}
}

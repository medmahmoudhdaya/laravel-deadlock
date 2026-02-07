<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

use Illuminate\Support\Carbon;

final class DeadlockResult
{
    public function __construct(
        public readonly string $description,
        public readonly string $expires,
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $class,
        public readonly ?string $method,
    ) {}

    public function isExpired(): bool
    {
        $deadline = Carbon::parse($this->expires)->startOfDay();

        return now()->startOfDay()->gt($deadline);
    }

    public function location(): string
    {
        if ($this->class && $this->method) {
            return "{$this->class}::{$this->method}";
        }

        if ($this->class) {
            return $this->class;
        }

        return $this->file.':'.$this->line;
    }
}

<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner\NodeVisitors;

use Zidbih\Deadlock\Attributes\Workaround;

final class WorkaroundAttributeMatcher
{
    public static function matches(string $name): bool
    {
        return in_array(ltrim($name, '\\'), [
            'Workaround',
            Workaround::class,
        ], true);
    }
}

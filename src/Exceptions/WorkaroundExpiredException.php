<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Exceptions;

use RuntimeException;

final class WorkaroundExpiredException extends RuntimeException
{
    public function __construct(
        string $description,
        string $expires,
        string $location
    ) {
        parent::__construct(
            sprintf(
                'Expired workaround detected: "%s" (expired on %s) at %s',
                $description,
                $expires,
                $location
            )
        );
    }
}

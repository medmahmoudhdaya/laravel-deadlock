<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Fixtures;

use Zidbih\Deadlock\Attributes\Workaround;

#[Workaround('Expired class workaround', '2020-01-01')]
class ExpiredService {}

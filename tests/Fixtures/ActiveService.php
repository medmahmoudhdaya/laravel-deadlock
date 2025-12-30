<?php

namespace Zidbih\Deadlock\Tests\Fixtures;

use Zidbih\Deadlock\Attributes\Workaround;

class ActiveService
{
    #[Workaround('Expired method workaround', '2020-01-01')]
    public function run(): void {}
}
<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class ListDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:list';

    protected $description = 'List all technical debt workarounds';

    public function handle(DeadlockScanner $scanner): int
    {
        $results = $scanner->scan(app_path());

        if (empty($results)) {
            $this->info('No workarounds found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Status', 'Expires', 'Location', 'Description'],
            array_map(fn (DeadlockResult $r) => [
                $r->isExpired() ? 'EXPIRED' : 'OK',
                $r->expires,
                $r->location(),
                $r->description,
            ], $results)
        );

        return self::SUCCESS;
    }
}

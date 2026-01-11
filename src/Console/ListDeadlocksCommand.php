<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class ListDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:list
    {--expired : Show only expired workarounds}
    {--active : Show only active (non-expired) workarounds}';

    protected $description = 'List all technical debt workarounds';

    public function handle(DeadlockScanner $scanner): int
    {
        if ($this->option('expired') && $this->option('active')) {
            $this->error('You cannot use --expired and --active together.');

            return self::INVALID;
        }

        $this->info('Scanning for workarounds...');

        $results = $scanner->scan(app_path());

        if (empty($results)) {
            $this->info('No workarounds found.');

            return self::SUCCESS;
        }

        $filtered = array_filter(
            $results,
            function (DeadlockResult $result): bool {
                if ($this->option('expired')) {
                    return $result->isExpired();
                }

                if ($this->option('active')) {
                    return ! $result->isExpired();
                }

                return true;
            }
        );

        if (empty($filtered)) {
            $this->info('No matching workarounds found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Status', 'Expires', 'Location', 'Description'],
            array_map(fn (DeadlockResult $r) => [
                $r->isExpired() ? 'EXPIRED' : 'OK',
                $r->expires,
                $r->location(),
                $r->description,
            ], $filtered)
        );

        return self::SUCCESS;
    }
}

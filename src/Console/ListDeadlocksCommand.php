<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

        $data = collect($results)
            ->filter(function (DeadlockResult $result) {
                if ($this->option('expired')) {
                    return $result->isExpired();
                }
                if ($this->option('active')) {
                    return ! $result->isExpired();
                }

                return true;
            })
            ->sortBy('expires');

        if ($data->isEmpty()) {
            $this->info('No matching workarounds found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Urgency', 'Expires', 'Location', 'Description'],
            $data->map(fn (DeadlockResult $r) => [
                $this->getUrgencyTag($r),
                $r->isExpired() ? "<fg=red>{$r->expires}</>" : $r->expires,
                $r->location(),
                $r->description,
            ])->toArray()
        );

        return self::SUCCESS;
    }

    private function getUrgencyTag(DeadlockResult $r): string
    {
        if ($r->isExpired()) {
            return '<fg=red;options=bold>✖ EXPIRED</>';
        }

        $deadline = Carbon::parse($r->expires);
        $days = (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false);

        $dayLabel = $days === 1 ? 'day' : 'days';

        if ($days <= 7) {
            return "<fg=yellow;options=bold>⚠ CRITICAL</> ({$days} {$dayLabel} left)";
        }

        return "<fg=green>✓ ACTIVE</> ({$days} {$dayLabel} left)";
    }
}

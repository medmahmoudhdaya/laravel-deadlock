<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class CheckDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:check';

    protected $description = 'Fail if any technical debt workaround is expired';

    public function handle(DeadlockScanner $scanner): int
    {
        $results = $scanner->scan(app_path());

        $expired = array_filter(
            $results,
            fn (DeadlockResult $r) => $r->isExpired()
        );

        if (empty($expired)) {
            $this->info('No expired workarounds found.');

            return self::SUCCESS;
        }

        $this->error('Expired workarounds detected:');

        foreach ($expired as $result) {
            $this->line(sprintf(
                '- %s | expires: %s | %s',
                $result->description,
                $result->expires,
                $result->location()
            ));
        }

        return self::FAILURE;
    }
}

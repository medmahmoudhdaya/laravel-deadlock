<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class CheckDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:check
    {--json : Output the results as JSON}';

    protected $description = 'Fail if any technical debt workaround is expired';

    public function handle(DeadlockScanner $scanner): int
    {
        $results = $scanner->scan(app_path());

        $expired = array_filter(
            $results,
            fn (DeadlockResult $r) => $r->isExpired()
        );

        if ($this->option('json')) {
            $this->line($this->toJson($expired));

            return empty($expired) ? self::SUCCESS : self::FAILURE;
        }

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

    /**
     * @param  array<int, DeadlockResult>  $expired
     */
    private function toJson(array $expired): string
    {
        return (string) json_encode([
            'success' => empty($expired),
            'expired_count' => count($expired),
            'expired' => array_map(
                static fn (DeadlockResult $result): array => [
                    'description' => $result->description,
                    'expires' => $result->expires,
                    'location' => $result->location(),
                    'file' => $result->file,
                    'line' => $result->line,
                    'class' => $result->class,
                    'method' => $result->method,
                ],
                array_values($expired)
            ),
        ], JSON_THROW_ON_ERROR);
    }
}

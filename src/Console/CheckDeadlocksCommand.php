<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\DeadlockScanner;

final class CheckDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:check
    {--json : Output the results as JSON}
    {--fail-within= : Fail when active workarounds expire within the given number of days}';

    protected $description = 'Fail if any technical debt workaround is expired';

    public function handle(DeadlockScanner $scanner): int
    {
        $failWithin = $this->failWithinDays();

        if ($failWithin === false) {
            $this->error('The --fail-within option must be a non-negative integer.');

            return self::INVALID;
        }

        $results = $scanner->scan(app_path());

        $expired = array_filter(
            $results,
            fn (DeadlockResult $r) => $r->isExpired()
        );

        $expiringSoon = $failWithin === null
            ? []
            : array_filter(
                $results,
                fn (DeadlockResult $r) => ! $r->isExpired() && $this->expiresWithin($r, $failWithin)
            );

        if ($this->option('json')) {
            $this->line($this->toJson($expired, $expiringSoon, $failWithin));

            return empty($expired) && empty($expiringSoon) ? self::SUCCESS : self::FAILURE;
        }

        if (empty($expired) && empty($expiringSoon)) {
            $this->info($failWithin === null
                ? 'No expired workarounds found.'
                : 'No expired or upcoming workarounds found.'
            );

            return self::SUCCESS;
        }

        if (! empty($expired)) {
            $this->error('Expired workarounds detected:');

            $this->renderResults($expired);
        }

        if (! empty($expiringSoon)) {
            if (! empty($expired)) {
                $this->line('');
            }

            $this->warn("Workarounds expiring within {$failWithin} days detected:");

            $this->renderResults($expiringSoon);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<int, DeadlockResult>  $expired
     * @param  array<int, DeadlockResult>  $expiringSoon
     */
    private function toJson(array $expired, array $expiringSoon, ?int $failWithin): string
    {
        return (string) json_encode([
            'success' => empty($expired) && empty($expiringSoon),
            'fail_within_days' => $failWithin,
            'expired_count' => count($expired),
            'expiring_soon_count' => count($expiringSoon),
            'expired' => $this->resultsToArray($expired),
            'expiring_soon' => $this->resultsToArray($expiringSoon),
        ], JSON_THROW_ON_ERROR);
    }

    private function failWithinDays(): int|false|null
    {
        $value = $this->option('fail-within');

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : false;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return false;
        }

        return (int) $value;
    }

    private function expiresWithin(DeadlockResult $result, int $days): bool
    {
        $deadline = Carbon::parse($result->expires)->startOfDay();
        $daysRemaining = (int) now()->startOfDay()->diffInDays($deadline, false);

        return $daysRemaining >= 0 && $daysRemaining <= $days;
    }

    /**
     * @param  array<int, DeadlockResult>  $results
     */
    private function renderResults(array $results): void
    {
        foreach ($results as $result) {
            $this->line(sprintf(
                '- %s | expires: %s | %s',
                $result->description,
                $result->expires,
                $result->location()
            ));
        }
    }

    /**
     * @param  array<int, DeadlockResult>  $results
     * @return array<int, array<string, int|string|null>>
     */
    private function resultsToArray(array $results): array
    {
        return array_map(
            static fn (DeadlockResult $result): array => [
                'description' => $result->description,
                'expires' => $result->expires,
                'location' => $result->location(),
                'file' => $result->file,
                'line' => $result->line,
                'class' => $result->class,
                'method' => $result->method,
            ],
            array_values($results)
        );
    }
}

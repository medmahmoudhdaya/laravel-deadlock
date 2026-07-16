<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Zidbih\Deadlock\Scanner\DeadlockResult;
use Zidbih\Deadlock\Scanner\DeadlockScanner;
use Zidbih\Deadlock\Scanner\DoctorIssue;
use Zidbih\Deadlock\Scanner\DoctorScanner;

final class CheckDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:check
    {--json : Output the results as JSON}
    {--strict : Also fail when doctor issues are found}
    {--fail-within= : Fail when active workarounds expire within the given number of days}';

    protected $description = 'Fail if any technical debt workaround is expired';

    public function handle(DeadlockScanner $scanner, DoctorScanner $doctorScanner): int
    {
        $failWithin = $this->failWithinDays();

        if ($failWithin === false) {
            $this->error('The --fail-within option must be a non-negative integer.');

            return self::INVALID;
        }

        $strict = (bool) $this->option('strict');
        $doctorIssues = $strict ? $doctorScanner->scan(app_path()) : [];

        try {
            $results = $scanner->scan(app_path());
        } catch (\Throwable $exception) {
            if (! $strict) {
                throw $exception;
            }

            $results = [];
        }

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

        $failed = ! empty($expired) || ! empty($expiringSoon) || ! empty($doctorIssues);

        if ($this->option('json')) {
            $this->line($this->toJson($expired, $expiringSoon, $doctorIssues, $failWithin, $strict));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if (! $failed) {
            $this->info($this->successMessage($failWithin, $strict));

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

        if (! empty($doctorIssues)) {
            if (! empty($expired) || ! empty($expiringSoon)) {
                $this->line('');
            }

            $this->error('Doctor issues detected:');

            $this->renderDoctorIssues($doctorIssues);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<int, DeadlockResult>  $expired
     * @param  array<int, DeadlockResult>  $expiringSoon
     * @param  array<int, DoctorIssue>  $doctorIssues
     */
    private function toJson(array $expired, array $expiringSoon, array $doctorIssues, ?int $failWithin, bool $strict): string
    {
        $payload = [
            'success' => empty($expired) && empty($expiringSoon) && empty($doctorIssues),
            'fail_within_days' => $failWithin,
            'expired_count' => count($expired),
            'expiring_soon_count' => count($expiringSoon),
            'expired' => $this->resultsToArray($expired),
            'expiring_soon' => $this->resultsToArray($expiringSoon),
        ];

        if ($strict) {
            $payload['doctor_issue_count'] = count($doctorIssues);
            $payload['doctor_issues'] = $this->doctorIssuesToArray($doctorIssues);
        }

        return (string) json_encode($payload, JSON_THROW_ON_ERROR);
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

    private function successMessage(?int $failWithin, bool $strict): string
    {
        if ($strict && $failWithin !== null) {
            return 'No expired, upcoming, or doctor issues found.';
        }

        if ($strict) {
            return 'No expired workarounds or doctor issues found.';
        }

        if ($failWithin !== null) {
            return 'No expired or upcoming workarounds found.';
        }

        return 'No expired workarounds found.';
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
     * @param  array<int, DoctorIssue>  $issues
     */
    private function renderDoctorIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->line(sprintf(
                '- %s | %s:%d',
                $issue->message,
                $issue->file,
                $issue->line
            ));

            if ($issue->suggestion !== null) {
                $this->line('  '.$issue->suggestion);
            }
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

    /**
     * @param  array<int, DoctorIssue>  $issues
     * @return array<int, array<string, int|string|null>>
     */
    private function doctorIssuesToArray(array $issues): array
    {
        return array_map(
            static fn (DoctorIssue $issue): array => [
                'type' => $issue->type,
                'message' => $issue->message,
                'file' => $issue->file,
                'line' => $issue->line,
                'suggestion' => $issue->suggestion,
            ],
            array_values($issues)
        );
    }
}

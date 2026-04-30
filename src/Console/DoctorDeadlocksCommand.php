<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Zidbih\Deadlock\Scanner\DeadlockScanner;
use Zidbih\Deadlock\Scanner\DoctorIssue;
use Zidbih\Deadlock\Scanner\DoctorScanner;

final class DoctorDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:doctor';

    protected $description = 'Diagnose unsupported workaround usage and runtime guard issues';

    public function handle(DeadlockScanner $deadlockScanner, DoctorScanner $doctorScanner): int
    {
        $this->info('Laravel Deadlock Doctor');
        $this->line('');

        try {
            $workarounds = $deadlockScanner->scan(app_path());
            $this->line("OK {$this->countLabel(count($workarounds), 'supported workaround')} found.");
        } catch (\Throwable $exception) {
            $this->warn('WARN Supported workaround scan could not complete: '.$exception->getMessage());
        }

        $issues = $doctorScanner->scan(app_path());

        if ($issues === []) {
            $this->line('OK No doctor issues found.');

            return self::SUCCESS;
        }

        $this->warn("WARN {$this->countLabel(count($issues), 'doctor issue')} found.");
        $this->line('');

        foreach ($this->groupByType($issues) as $type => $groupedIssues) {
            $this->line($this->heading($type).':');

            foreach ($groupedIssues as $issue) {
                $this->line(sprintf('- %s:%d', $issue->file, $issue->line));
                $this->line('  '.$issue->message);

                if ($issue->suggestion !== null) {
                    $this->line('  '.$issue->suggestion);
                }
            }

            $this->line('');
        }

        return self::FAILURE;
    }

    private function countLabel(int $count, string $label): string
    {
        return $count.' '.$label.($count === 1 ? '' : 's');
    }

    /**
     * @param  DoctorIssue[]  $issues
     * @return array<string, DoctorIssue[]>
     */
    private function groupByType(array $issues): array
    {
        $grouped = [];

        foreach ($issues as $issue) {
            $grouped[$issue->type][] = $issue;
        }

        return $grouped;
    }

    private function heading(string $type): string
    {
        return match ($type) {
            'guard' => 'Guard issues',
            'invalid-attribute' => 'Invalid attributes',
            'parse' => 'Parse issues',
            'unsupported-target' => 'Unsupported targets',
            default => ucfirst(str_replace('-', ' ', $type)),
        };
    }
}

<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Zidbih\Deadlock\Middleware\DeadlockGuardMiddleware;
use Zidbih\Deadlock\Scanner\DeadlockScanner;
use Zidbih\Deadlock\Scanner\DoctorIssue;
use Zidbih\Deadlock\Scanner\DoctorScanner;

final class DoctorDeadlocksCommand extends Command
{
    protected $signature = 'deadlock:doctor';

    protected $description = 'Diagnose unsupported workaround usage and runtime guard issues';

    public function handle(DeadlockScanner $deadlockScanner, DoctorScanner $doctorScanner): int
    {
        $this->line('<fg=cyan;options=bold>Laravel Deadlock Doctor</>');
        $this->line('');

        $this->renderHealthChecks();
        $this->section('Scan results');

        try {
            $workarounds = $deadlockScanner->scan(app_path());
            $this->ok($this->countLabel(count($workarounds), 'supported workaround').' found');
        } catch (\Throwable $exception) {
            $this->warning('Supported workaround scan could not complete: '.$exception->getMessage());
        }

        $issues = $doctorScanner->scan(app_path());

        if ($issues === []) {
            $this->ok('No doctor issues found');

            return self::SUCCESS;
        }

        $this->warning($this->countLabel(count($issues), 'doctor issue').' found');
        $this->line('');

        foreach ($this->groupByType($issues) as $type => $groupedIssues) {
            $this->section($this->heading($type));

            foreach ($groupedIssues as $issue) {
                $this->line(sprintf(
                    '<fg=yellow>[WARN]</> <fg=gray>%s:%d</>',
                    $issue->file,
                    $issue->line
                ));
                $this->line('       '.$issue->message);

                if ($issue->suggestion !== null) {
                    $this->line('       <fg=green>'.$issue->suggestion.'</>');
                }
            }

            $this->line('');
        }

        return self::FAILURE;
    }

    private function renderHealthChecks(): void
    {
        $rows = [
            $this->healthRow('OK', 'green', 'Package service provider loaded'),
        ];

        $missingCommands = array_diff(
            ['deadlock:list', 'deadlock:check', 'deadlock:doctor', 'deadlock:extend'],
            array_keys(Artisan::all())
        );

        if ($missingCommands === []) {
            $rows[] = $this->healthRow('OK', 'green', 'Deadlock commands registered');
        } else {
            $rows[] = $this->healthRow('WARN', 'yellow', 'Missing commands: '.implode(', ', $missingCommands));
        }

        if ($this->laravel->environment('local')) {
            $missingGroups = $this->missingMiddlewareGroups();

            if ($missingGroups === []) {
                $rows[] = $this->healthRow('OK', 'green', 'Controller middleware active for web and api routes');
            } else {
                $rows[] = $this->healthRow('WARN', 'yellow', 'Controller middleware missing from '.implode(', ', $missingGroups).' routes');
            }

            $rows[] = $this->healthRow('OK', 'green', 'Runtime enforcement active in local environment');
        } else {
            $rows[] = $this->healthRow('INFO', 'blue', 'Controller middleware is only registered in local environment');
            $rows[] = $this->healthRow('INFO', 'blue', 'Runtime enforcement disabled outside local environment');
        }

        $this->renderHealthBox($rows);
        $this->line('');
    }

    /**
     * @return string[]
     */
    private function missingMiddlewareGroups(): array
    {
        $groups = $this->laravel->make(Kernel::class)->getMiddlewareGroups();
        $missing = [];

        foreach (['web', 'api'] as $group) {
            if (! in_array(DeadlockGuardMiddleware::class, $groups[$group] ?? [], true)) {
                $missing[] = $group;
            }
        }

        return $missing;
    }

    private function countLabel(int $count, string $label): string
    {
        return $count.' '.$label.($count === 1 ? '' : 's');
    }

    private function ok(string $message): void
    {
        $this->status('OK', 'green', $message);
    }

    private function warning(string $message): void
    {
        $this->status('WARN', 'yellow', $message);
    }

    private function infoLine(string $message): void
    {
        $this->status('INFO', 'blue', $message);
    }

    private function healthRow(string $label, string $color, string $message): array
    {
        return compact('label', 'color', 'message');
    }

    /**
     * @param  array<int, array{label: string, color: string, message: string}>  $rows
     */
    private function renderHealthBox(array $rows): void
    {
        $width = 72;
        $this->line('<fg=gray>+'.str_repeat('-', $width - 2).'+</>');
        $this->line('<fg=gray>|</> <fg=cyan;options=bold>'.str_pad('Health checks', $width - 4).'</> <fg=gray>|</>');
        $this->line('<fg=gray>|</> '.str_repeat(' ', $width - 4).' <fg=gray>|</>');

        foreach ($rows as $row) {
            $plain = str_pad("[{$row['label']}]", 6).' '.$row['message'];
            $padding = max(0, $width - 4 - strlen($plain));

            $this->line(sprintf(
                '<fg=gray>|</> <fg=%s>%s</> %s%s <fg=gray>|</>',
                $row['color'],
                str_pad("[{$row['label']}]", 6),
                $row['message'],
                str_repeat(' ', $padding)
            ));
        }

        $this->line('<fg=gray>+'.str_repeat('-', $width - 2).'+</>');
    }

    private function status(string $label, string $color, string $message): void
    {
        $this->line(sprintf('<fg=%s>%s</> %s', $color, str_pad("[{$label}]", 6), $message));
    }

    private function section(string $title): void
    {
        $this->line('<fg=cyan;options=bold>'.$title.':</>');
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

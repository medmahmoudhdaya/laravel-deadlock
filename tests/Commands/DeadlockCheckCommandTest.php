<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockCheckCommandTest extends TestCase
{
    public function test_deadlock_check_fails_when_expired(): void
    {
        $path = app_path('ExpiredTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Expired test workaround',
                expires: '2020-01-01'
            )]
            class ExpiredTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check')
                ->assertExitCode(1);
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_succeeds_when_no_expired_workarounds(): void
    {
        $path = app_path('ActiveTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Active test workaround',
                expires: '2099-01-01'
            )]
            class ActiveTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check')
                ->assertExitCode(0)
                ->expectsOutputToContain('No expired workarounds found.');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_fails_when_workaround_expires_within_configured_window(): void
    {
        $path = app_path('ExpiringSoonTestService.php');
        $expires = now()->addDays(3)->toDateString();

        File::put($path, <<<PHP
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Soon expiring test workaround',
                expires: '{$expires}'
            )]
            class ExpiringSoonTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check --fail-within=7')
                ->assertExitCode(1)
                ->expectsOutputToContain('Workarounds expiring within 7 days detected:')
                ->expectsOutputToContain('Soon expiring test workaround');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_succeeds_when_workaround_expires_after_configured_window(): void
    {
        $path = app_path('FutureActiveTestService.php');
        $expires = now()->addDays(10)->toDateString();

        File::put($path, <<<PHP
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Future active test workaround',
                expires: '{$expires}'
            )]
            class FutureActiveTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check --fail-within=7')
                ->assertExitCode(0)
                ->expectsOutputToContain('No expired or upcoming workarounds found.');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_rejects_invalid_fail_within_option(): void
    {
        $this->artisan('deadlock:check --fail-within=soon')
            ->assertExitCode(2)
            ->expectsOutputToContain('The --fail-within option must be a non-negative integer.');
    }

    public function test_deadlock_check_reports_expired_and_upcoming_workarounds_together(): void
    {
        $expiredPath = app_path('ExpiredAndUpcomingExpiredTestService.php');
        $upcomingPath = app_path('ExpiredAndUpcomingSoonTestService.php');
        $upcomingExpires = now()->addDays(2)->toDateString();

        File::put($expiredPath, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Already expired combined workaround',
                expires: '2020-01-01'
            )]
            class ExpiredAndUpcomingExpiredTestService {}
            PHP
        );

        File::put($upcomingPath, <<<PHP
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Soon expiring combined workaround',
                expires: '{$upcomingExpires}'
            )]
            class ExpiredAndUpcomingSoonTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check --fail-within=7')
                ->assertExitCode(1)
                ->expectsOutputToContain('Expired workarounds detected:')
                ->expectsOutputToContain('Already expired combined workaround')
                ->expectsOutputToContain('Workarounds expiring within 7 days detected:')
                ->expectsOutputToContain('Soon expiring combined workaround');
        } finally {
            File::delete($expiredPath);
            File::delete($upcomingPath);
        }
    }

    public function test_deadlock_check_strict_fails_when_doctor_issues_are_found(): void
    {
        $path = app_path('StrictDoctorIssueTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            class StrictDoctorIssueTestService
            {
                #[Workaround(
                    description: 'Strict mode unguarded workaround',
                    expires: '2099-01-01'
                )]
                public function run(): void {}
            }
            PHP
        );

        try {
            $this->artisan('deadlock:check --strict')
                ->assertExitCode(1)
                ->expectsOutputToContain('Doctor issues detected:')
                ->expectsOutputToContain('Method-level workaround is not explicitly guarded.');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_strict_succeeds_when_no_failures_are_found(): void
    {
        $path = app_path('StrictCleanTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;
            use Zidbih\Deadlock\Support\DeadlockGuard;

            class StrictCleanTestService
            {
                #[Workaround(
                    description: 'Strict mode guarded workaround',
                    expires: '2099-01-01'
                )]
                public function run(): void
                {
                    DeadlockGuard::check($this, __FUNCTION__);
                }
            }
            PHP
        );

        try {
            $this->artisan('deadlock:check --strict')
                ->assertExitCode(0)
                ->expectsOutputToContain('No expired workarounds or doctor issues found.');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_strict_with_fail_within_succeeds_when_no_failures_are_found(): void
    {
        $path = app_path('StrictCleanWithWindowTestService.php');
        $expires = now()->addDays(10)->toDateString();

        File::put($path, <<<PHP
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;
            use Zidbih\Deadlock\Support\DeadlockGuard;

            class StrictCleanWithWindowTestService
            {
                #[Workaround(
                    description: 'Strict mode guarded future workaround',
                    expires: '{$expires}'
                )]
                public function run(): void
                {
                    DeadlockGuard::check(\$this, __FUNCTION__);
                }
            }
            PHP
        );

        try {
            $this->artisan('deadlock:check --strict --fail-within=7')
                ->assertExitCode(0)
                ->expectsOutputToContain('No expired, upcoming, or doctor issues found.');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_strict_reports_deadline_failures_and_doctor_issues_together(): void
    {
        $expiredPath = app_path('StrictExpiredAndDoctorExpiredTestService.php');
        $doctorPath = app_path('StrictExpiredAndDoctorIssueTestService.php');

        File::put($expiredPath, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;
            use Zidbih\Deadlock\Support\DeadlockGuard;

            #[Workaround(
                description: 'Strict expired guarded workaround',
                expires: '2020-01-01'
            )]
            class StrictExpiredAndDoctorExpiredTestService
            {
                public function __construct()
                {
                    DeadlockGuard::check($this);
                }
            }
            PHP
        );

        File::put($doctorPath, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            class StrictExpiredAndDoctorIssueTestService
            {
                #[Workaround(
                    description: 'Strict unguarded companion workaround',
                    expires: '2099-01-01'
                )]
                public function run(): void {}
            }
            PHP
        );

        try {
            $this->artisan('deadlock:check --strict')
                ->assertExitCode(1)
                ->expectsOutputToContain('Expired workarounds detected:')
                ->expectsOutputToContain('Strict expired guarded workaround')
                ->expectsOutputToContain('Doctor issues detected:')
                ->expectsOutputToContain('Method-level workaround is not explicitly guarded.');
        } finally {
            File::delete($expiredPath);
            File::delete($doctorPath);
        }
    }

    public function test_deadlock_check_strict_reports_invalid_attributes_without_crashing(): void
    {
        $path = app_path('StrictInvalidAttributeTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround('Missing expires')]
            class StrictInvalidAttributeTestService {}
            PHP
        );

        try {
            $this->artisan('deadlock:check --strict')
                ->assertExitCode(1)
                ->expectsOutputToContain('Doctor issues detected:')
                ->expectsOutputToContain('Workaround attribute must receive exactly 2 arguments.');
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_outputs_json_when_no_expired_workarounds_exist(): void
    {
        $path = app_path('ActiveJsonTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Active JSON test workaround',
                expires: '2099-01-01'
            )]
            class ActiveJsonTestService {}
            PHP
        );

        try {
            $exitCode = Artisan::call('deadlock:check', ['--json' => true]);
            $output = Artisan::output();
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exitCode);
            $this->assertTrue($payload['success']);
            $this->assertSame(0, $payload['expired_count']);
            $this->assertSame([], $payload['expired']);
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_outputs_json_when_expired_workarounds_exist(): void
    {
        $path = app_path('ExpiredJsonTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Expired JSON test workaround',
                expires: '2020-01-01'
            )]
            class ExpiredJsonTestService {}
            PHP
        );

        try {
            $exitCode = Artisan::call('deadlock:check', ['--json' => true]);
            $output = Artisan::output();
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertFalse($payload['success']);
            $this->assertSame(1, $payload['expired_count']);
            $this->assertCount(1, $payload['expired']);
            $this->assertSame('Expired JSON test workaround', $payload['expired'][0]['description']);
            $this->assertSame('2020-01-01', $payload['expired'][0]['expires']);
            $this->assertSame('ExpiredJsonTestService', $payload['expired'][0]['location']);
            $this->assertStringEndsWith('ExpiredJsonTestService.php', $payload['expired'][0]['file']);
            $this->assertSame('ExpiredJsonTestService', $payload['expired'][0]['class']);
            $this->assertNull($payload['expired'][0]['method']);
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_outputs_json_when_strict_doctor_issues_are_found(): void
    {
        $path = app_path('StrictDoctorIssueJsonTestService.php');

        File::put($path, <<<'PHP'
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            class StrictDoctorIssueJsonTestService
            {
                #[Workaround(
                    description: 'Strict JSON unguarded workaround',
                    expires: '2099-01-01'
                )]
                public function run(): void {}
            }
            PHP
        );

        try {
            $exitCode = Artisan::call('deadlock:check', [
                '--json' => true,
                '--strict' => true,
            ]);
            $output = Artisan::output();
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertFalse($payload['success']);
            $this->assertSame(0, $payload['expired_count']);
            $this->assertSame(1, $payload['doctor_issue_count']);
            $this->assertCount(1, $payload['doctor_issues']);
            $this->assertSame('guard', $payload['doctor_issues'][0]['type']);
            $this->assertSame('Method-level workaround is not explicitly guarded.', $payload['doctor_issues'][0]['message']);
        } finally {
            File::delete($path);
        }
    }

    public function test_deadlock_check_outputs_json_when_workarounds_expire_within_configured_window(): void
    {
        $path = app_path('ExpiringSoonJsonTestService.php');
        $expires = now()->addDays(2)->toDateString();

        File::put($path, <<<PHP
            <?php

            namespace App;

            use Zidbih\Deadlock\Attributes\Workaround;

            #[Workaround(
                description: 'Expiring soon JSON test workaround',
                expires: '{$expires}'
            )]
            class ExpiringSoonJsonTestService {}
            PHP
        );

        try {
            $exitCode = Artisan::call('deadlock:check', [
                '--json' => true,
                '--fail-within' => 7,
            ]);
            $output = Artisan::output();
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertFalse($payload['success']);
            $this->assertSame(7, $payload['fail_within_days']);
            $this->assertSame(0, $payload['expired_count']);
            $this->assertSame(1, $payload['expiring_soon_count']);
            $this->assertSame([], $payload['expired']);
            $this->assertCount(1, $payload['expiring_soon']);
            $this->assertSame('Expiring soon JSON test workaround', $payload['expiring_soon'][0]['description']);
            $this->assertSame($expires, $payload['expiring_soon'][0]['expires']);
        } finally {
            File::delete($path);
        }
    }
}

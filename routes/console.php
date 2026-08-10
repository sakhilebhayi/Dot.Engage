<?php

use App\Console\Commands\ScanDependencyAdvisories;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Dot.Engage Scheduled Maintenance ────────────────────────────────────────

// Clean up stale video sessions (waiting/active for more than 24 h) every hour.
Schedule::command('dotengage:clean-expired-sessions --hours=24')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/clean-expired-sessions.log'));

// Re-queue any contract uploads stuck in draft every 15 minutes.
Schedule::command('dotengage:retry-failed-uploads --limit=50')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/retry-failed-uploads.log'));

// Generate monthly team activity reports on the 1st of each month at 06:00.
Schedule::command('dotengage:team-activity-report')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/team-activity-report.log'));

// Remind pending signers on contracts expiring within 3 days, and flag
// unsigned contracts already past their expiry. Once daily is enough --
// signers don't need a fresh nudge every few minutes.
Schedule::command('dotengage:send-expiration-reminders --days=3')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/send-expiration-reminders.log'));

// Scan composer/npm dependencies for security advisories weekly and propose a patch per
// manager for operator approval -- never applies automatically. See docs/superpowers/specs/
// 2026-08-09-dependency-patch-approval-gate-design.md.
Schedule::command(ScanDependencyAdvisories::class)
    ->weekly()
    ->withoutOverlapping();

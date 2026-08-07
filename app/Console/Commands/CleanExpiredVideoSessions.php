<?php

namespace App\Console\Commands;

use App\Jobs\ArchiveVideoSession;
use App\Models\VideoSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

#[Signature('dotengage:clean-expired-sessions
    {--dry-run : List sessions that would be cleaned without modifying data}
    {--hours=24 : Mark sessions older than this many hours as ended}')]
#[Description('Mark stale video sessions as ended and archive their data.')]
class CleanExpiredVideoSessions extends Command
{
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        /** @var Collection<int, VideoSession> $staleSessions */
        $staleSessions = VideoSession::query()
            ->whereIn('status', ['waiting', 'active'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($staleSessions->isEmpty()) {
            $this->info('No stale video sessions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Room', 'Status', 'Last Updated'],
            $staleSessions->map(fn ($s) => [
                $s->id,
                $s->room_id,
                $s->status,
                $s->updated_at->toDateTimeString(),
            ])
        );

        if ($dryRun) {
            $this->warn('[dry-run] '.$staleSessions->count().' session(s) would be cleaned.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($staleSessions->count());
        $bar->start();

        foreach ($staleSessions as $session) {
            $session->update([
                'status' => 'ended',
                'ended_at' => $session->ended_at ?? now(),
            ]);

            ArchiveVideoSession::dispatch($session);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Cleaned '.$staleSessions->count().' stale session(s).');
        Log::info('CleanExpiredVideoSessions: cleaned '.$staleSessions->count().' sessions older than '.$hours.'h.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\FinaliseEnvelope;
use App\Models\SigningEnvelope;
use Illuminate\Console\Command;

class EngageFinalisePending extends Command
{
    protected $signature = 'engage:finalise-pending {--now : run in this process instead of queueing}';

    protected $description = 'Finish signed agreements whose final copy was never produced.';

    public function handle(): int
    {
        $ids = SigningEnvelope::whereNotNull('all_signed_at')->whereNull('finalised_at')
            ->where('all_signed_at', '<', now()->subMinutes(2))
            ->where('finalise_attempts', '<', 12)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->option('now') ? FinaliseEnvelope::dispatchSync($id) : FinaliseEnvelope::dispatch($id);
        }

        $this->info($ids->count().' agreement(s) queued for finishing.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Engage\MeetingService;
use Illuminate\Console\Command;

class EngageMeetingsClean extends Command
{
    protected $signature = 'engage:meetings-clean';

    protected $description = 'End abandoned meetings and prune old signalling messages.';

    public function handle(MeetingService $meetings): int
    {
        $r = $meetings->cleanUp();
        $this->info("meetings ended: {$r['ended']}, signals pruned: {$r['signals']}");

        return self::SUCCESS;
    }
}

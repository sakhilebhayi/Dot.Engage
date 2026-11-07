<?php

namespace App\Console\Commands;

use App\Models\Engagement;
use App\Services\Engage\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EngageVerifyAudit extends Command
{
    protected $signature = 'engage:verify-audit {--all : check every engagement, not only those active in the last two days}';

    protected $description = 'Recompute the hash chain of engagement audit trails and report any break.';

    public function handle(AuditTrail $audit): int
    {
        $q = Engagement::withoutGlobalScopes()->withTrashed();
        if (! $this->option('all')) {
            $q->where('updated_at', '>', now()->subDays(2));
        }

        $broken = 0;
        foreach ($q->cursor() as $engagement) {
            $r = $audit->verify($engagement);
            if (! $r['ok']) {
                $broken++;
                Log::error('Audit chain broken', ['engagement' => $engagement->reference, 'at_event' => $r['broken_at']]);
                $this->error($engagement->reference.': chain broken at event '.$r['broken_at']);
            }
        }

        $this->info($broken === 0 ? 'All audit chains are intact.' : $broken.' audit chain(s) are broken.');

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\EngagementState;
use App\Models\Engagement;
use App\Services\Engage\EngagementService;
use App\Services\Engage\SigningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class EngageHousekeeping extends Command
{
    protected $signature = 'engage:housekeeping';

    protected $description = 'Expire overdue engagements and signing requests, send signing reminders and expiry warnings.';

    public function handle(SigningService $signing, EngagementService $engagements): int
    {
        $expiredSigning = $signing->expireDue();
        $reminded = $signing->remindDue();

        $expired = 0;
        $warned = 0;
        $open = array_map(fn ($s) => $s->value, EngagementState::open());

        foreach (Engagement::withoutGlobalScopes()->whereIn('state', $open)->whereNotNull('expires_at')->get() as $engagement) {
            if ($engagement->expires_at->isPast()) {
                $engagements->expire($engagement);
                $expired++;
            } elseif ($engagement->expires_at->lt(now()->addDays(3)) && Cache::add('engage.expiry-warning.'.$engagement->id, 1, 86400 * 4)) {
                $engagements->notifyOthers($engagement, null, 'engagement_expiring', $engagement->title.' closes on '.$engagement->expires_at->format('j M Y').' if it is not signed', route('engagements.show', $engagement), 'Open the engagement');
                $warned++;
            }
        }

        $this->info("signing requests expired: {$expiredSigning}, reminders sent: {$reminded}, engagements expired: {$expired}, expiry warnings: {$warned}");

        return self::SUCCESS;
    }
}

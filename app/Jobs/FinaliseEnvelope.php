<?php

namespace App\Jobs;

use App\Services\Engage\EnvelopeFinaliser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinaliseEnvelope implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function __construct(public readonly int $envelopeId) {}

    public function handle(EnvelopeFinaliser $finaliser): void
    {
        $finaliser->finalise($this->envelopeId);
    }
}

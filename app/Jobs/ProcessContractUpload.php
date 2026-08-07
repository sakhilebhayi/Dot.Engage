<?php

namespace App\Jobs;

use App\Models\Contract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessContractUpload implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    public function __construct(public readonly Contract $contract) {}

    /**
     * Validate the uploaded file exists and update the contract status
     * from 'draft' to 'pending' once the upload is confirmed on disk.
     * Additional processing (thumbnail generation, page count) can be
     * wired in here without blocking the HTTP request.
     */
    public function handle(): void
    {
        $path = $this->contract->file_path;

        if (! $path || ! Storage::disk('contracts')->exists($path)) {
            Log::warning('ProcessContractUpload: file not found for contract '.$this->contract->id);

            return;
        }

        // Derive basic metadata from the file.
        $size = Storage::disk('contracts')->size($path);

        $this->contract->update([
            'status' => 'pending',
            'file_size' => $size,
        ]);

        Log::info('ProcessContractUpload: contract '.$this->contract->id.' processed ('.$size.' bytes).');
    }
}

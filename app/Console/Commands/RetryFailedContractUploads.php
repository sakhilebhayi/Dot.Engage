<?php

namespace App\Console\Commands;

use App\Jobs\ProcessContractUpload;
use App\Models\Contract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

#[Signature('dotengage:retry-failed-uploads
    {--dry-run : List contracts that would be retried without dispatching jobs}
    {--limit=50 : Maximum number of contracts to retry in one run}')]
#[Description('Re-dispatch ProcessContractUpload for contracts stuck in draft status.')]
class RetryFailedContractUploads extends Command
{
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        // Contracts that never moved past draft are considered failed uploads.
        /** @var Collection<int, Contract> $stuck */
        $stuck = Contract::query()
            ->where('status', 'draft')
            ->whereNotNull('file_path')
            ->where('created_at', '<', now()->subMinutes(30))
            ->limit($limit)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck contract uploads found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Title', 'File Path', 'Created At'],
            $stuck->map(fn ($c) => [
                $c->id,
                $c->title,
                $c->file_path,
                $c->created_at->toDateTimeString(),
            ])
        );

        if ($dryRun) {
            $this->warn('[dry-run] '.$stuck->count().' contract(s) would be retried.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($stuck->count());
        $bar->start();

        foreach ($stuck as $contract) {
            ProcessContractUpload::dispatch($contract);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Dispatched '.$stuck->count().' ProcessContractUpload job(s).');
        Log::info('RetryFailedContractUploads: dispatched '.$stuck->count().' jobs.');

        return self::SUCCESS;
    }
}

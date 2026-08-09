<?php

namespace App\Jobs;

use App\Models\DependencyPatchProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ApplyDependencyPatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public DependencyPatchProposal $proposal) {}

    /**
     * Always resolves the proposal to 'applied' or 'failed' -- never
     * leaves it stuck 'approved' with no recorded outcome, even if the
     * process itself throws.
     */
    public function handle(): void
    {
        $process = Process::fromShellCommandline($this->proposal->proposed_command, base_path());
        $process->setTimeout(300);
        $output = '';

        try {
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->proposal->update([
                'status' => 'applied',
                'applied_log' => $output,
                'applied_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->proposal->update([
                'status' => 'failed',
                'applied_log' => trim($output."\n".$exception->getMessage()),
                'applied_at' => now(),
            ]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\ContractVersion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateSignedContractPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly Contract $contract) {}

    public function handle(): void
    {
        if ($this->contract->status !== 'signed') {
            Log::info('GenerateSignedContractPdf: contract '.$this->contract->id.' not fully signed, skipping.');

            return;
        }

        $contract = $this->contract->load(['creator', 'team', 'signatures.user', 'versions']);
        $signatures = $contract->signatures;

        if ($signatures->isEmpty()) {
            Log::warning('GenerateSignedContractPdf: no signatures for contract '.$contract->id);

            return;
        }

        // Embed each signature image as a base64 data URI so DomPDF can render it.
        $signaturesWithImages = $signatures->map(function ($sig) {
            $imageData = null;
            if ($sig->signature_image_path && Storage::disk('signatures')->exists($sig->signature_image_path)) {
                $raw = Storage::disk('signatures')->get($sig->signature_image_path);
                $imageData = 'data:image/png;base64,'.base64_encode($raw);
            }

            return array_merge($sig->toArray(), ['image_data_uri' => $imageData]);
        });

        $nextVersion = ($contract->versions()->max('version_number') ?? 0) + 1;

        $pdf = Pdf::loadView('pdf.signed-contract', [
            'contract' => $contract,
            'signatures' => $signaturesWithImages,
            'signedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $pdfContent = $pdf->output();
        $signedPath = 'signed/contract_'.$contract->id.'_v'.$nextVersion.'_signed.pdf';

        Storage::disk('contracts')->put($signedPath, $pdfContent);

        ContractVersion::create([
            'contract_id' => $contract->id,
            'created_by' => $contract->created_by,
            'version_number' => $nextVersion,
            'file_path' => $signedPath,
            'change_notes' => 'Signed by all parties — '.now()->toFormattedDateString(),
        ]);

        Log::info('GenerateSignedContractPdf: v'.$nextVersion.' created for contract '.$contract->id);

        DispatchSignedContractEmail::dispatch($contract->fresh());
    }
}

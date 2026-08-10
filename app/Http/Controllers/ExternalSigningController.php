<?php

namespace App\Http\Controllers;

use App\Events\ContractSigned;
use App\Jobs\GenerateSignedContractPdf;
use App\Models\ContractExternalSigner;
use App\Models\ContractSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Guest-facing contract viewing and signing for invited external signers.
 *
 * There is no User account here -- authorization is the signed URL itself
 * (Laravel's `signed` middleware, verified against the {signer} route
 * parameter and expiry), the same trust model DocuSign/PandaDoc-style
 * emailed signing links use.
 */
class ExternalSigningController extends Controller
{
    public function show(Request $request, ContractExternalSigner $signer): View
    {
        abort_if($signer->isExpired(), 410, 'This signing link has expired.');

        $signer->markViewed();

        $signActionUrl = URL::temporarySignedRoute(
            'external.contracts.sign',
            $signer->expires_at,
            ['signer' => $signer->id],
        );

        $downloadUrl = URL::temporarySignedRoute(
            'external.contracts.download',
            $signer->expires_at,
            ['signer' => $signer->id],
        );

        return view('external.show', [
            'contract' => $signer->contract,
            'signer' => $signer,
            'signActionUrl' => $signActionUrl,
            'downloadUrl' => $downloadUrl,
        ]);
    }

    public function download(Request $request, ContractExternalSigner $signer): Response
    {
        abort_if($signer->isExpired(), 410, 'This signing link has expired.');

        $contract = $signer->contract;
        $path = $contract->file_path;

        abort_unless(
            $path && Storage::disk('contracts')->exists($path),
            404,
            'Contract file not found.'
        );

        $filename = 'contract_'.$contract->id.'_'.str($contract->title)->slug().'.pdf';

        return response((string) Storage::disk('contracts')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    public function sign(Request $request, ContractExternalSigner $signer): RedirectResponse
    {
        abort_if($signer->isExpired(), 410, 'This signing link has expired.');

        if ($signer->status === 'signed') {
            return redirect($this->signedShowUrl($signer));
        }

        if (! $signer->isSignable()) {
            return back()->withErrors(['signature_data' => "It's not your turn to sign yet -- another signer needs to sign first."]);
        }

        $validated = $request->validate([
            'signature_data' => ['required', 'string'],
        ]);

        if (! preg_match('/^data:image\/\w+;base64,/', $validated['signature_data'])) {
            return back()->withErrors(['signature_data' => 'Invalid signature data format.']);
        }

        $imageData = substr($validated['signature_data'], strpos($validated['signature_data'], ',') + 1);
        $decoded = base64_decode($imageData, strict: true);

        if ($decoded === false) {
            return back()->withErrors(['signature_data' => 'Could not decode signature image.']);
        }

        $contract = $signer->contract;
        $filename = 'sig_ext_'.$signer->id.'_'.Str::uuid().'.png';
        Storage::disk('signatures')->put($filename, $decoded);

        $signature = ContractSignature::create([
            'contract_id' => $contract->id,
            'contract_external_signer_id' => $signer->id,
            'signer_name' => $signer->name,
            'signer_email' => $signer->email,
            'signature_image_path' => $filename,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'signed_at' => now(),
        ]);

        $signer->update(['status' => 'signed', 'signed_at' => now()]);

        if ($contract->signatures()->count() >= $contract->requiredSignatureCount()) {
            $contract->update(['status' => 'signed']);
            ContractSigned::dispatch($contract, $signature);
            GenerateSignedContractPdf::dispatch($contract);
        }

        return redirect($this->signedShowUrl($signer))->with('signed', true);
    }

    /**
     * Mint a fresh signed URL back to the show page, matching the signer's
     * own expiry -- a plain route() call would 403 against the `signed`
     * middleware since it carries no signature.
     */
    private function signedShowUrl(ContractExternalSigner $signer): string
    {
        return URL::temporarySignedRoute(
            'external.contracts.show',
            $signer->expires_at,
            ['signer' => $signer->id],
        );
    }
}

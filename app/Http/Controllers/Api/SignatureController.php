<?php

namespace App\Http\Controllers\Api;

use App\Events\ContractSigned;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SignatureController extends Controller
{
    /**
     * Store a base64 canvas signature for a contract.
     *
     * Accepts:  { contract_id, signature_data (data URI) }
     * Returns:  { signature_id, signed_at }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'signature_data' => ['required', 'string'],
        ]);

        $contract = Contract::findOrFail($validated['contract_id']);

        Gate::authorize('sign', $contract);

        // Validate and decode the base64 data URI (e.g. data:image/png;base64,<data>).
        if (! preg_match('/^data:image\/\w+;base64,/', $validated['signature_data'])) {
            return response()->json(['message' => 'Invalid signature data format.'], 422);
        }

        $imageData = substr($validated['signature_data'], strpos($validated['signature_data'], ',') + 1);
        $decoded = base64_decode($imageData, strict: true);

        if ($decoded === false) {
            return response()->json(['message' => 'Could not decode signature image.'], 422);
        }

        $filename = 'sig_'.$request->user()->id.'_'.Str::uuid().'.png';
        Storage::disk('signatures')->put($filename, $decoded);

        $signature = ContractSignature::create([
            'contract_id' => $contract->id,
            'user_id' => $request->user()->id,
            'signature_image_path' => $filename,
            'ip_address' => $request->ip(),
            'signed_at' => now(),
        ]);

        // Mark contract as fully signed once every team member and every
        // invited external signer has signed.
        if ($contract->signatures()->count() >= $contract->requiredSignatureCount()) {
            $contract->update(['status' => 'signed']);
            ContractSigned::dispatch($contract, $signature);
        }

        return response()->json([
            'signature_id' => $signature->id,
            'signed_at' => $signature->signed_at->toIso8601String(),
        ], 201);
    }
}

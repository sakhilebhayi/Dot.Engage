<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\SigningEnvelope;
use App\Services\Engage\EvidenceSeal;
use Illuminate\Http\Request;

/**
 * Public check of a signed agreement. It says whether a file is a signed copy Dot.Engage completed, and whether the
 * stored evidence record is still sealed. It shows a reference, a date and a count, never names or contents.
 */
class VerifyController extends Controller
{
    public function __construct(private readonly EvidenceSeal $seal) {}

    public function show(?string $reference = null)
    {
        $engagement = $reference ? Engagement::withoutGlobalScopes()->where('reference', $reference)->first() : null;
        $envelope = $engagement ? SigningEnvelope::where('engagement_id', $engagement->id)->where('status', 'completed')->latest('id')->first() : null;

        return view('verify.show', ['reference' => $reference, 'record' => $envelope ? $this->summary($envelope, $engagement) : null, 'result' => null, 'key' => $this->seal->publicKeyHex()]);
    }

    public function check(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:40960']]);
        $hash = hash_file('sha256', $request->file('file')->getRealPath());

        $envelope = SigningEnvelope::where('status', 'completed')
            ->where(fn ($q) => $q->where('final_sha256', $hash)->orWhere('document_sha256', $hash)->orWhere('signed_body_sha256', $hash)->orWhere('certificate_sha256', $hash))
            ->first();

        $result = ['hash' => $hash, 'match' => null];
        $record = null;
        if ($envelope) {
            $engagement = Engagement::withoutGlobalScopes()->find($envelope->engagement_id);
            $record = $this->summary($envelope, $engagement);
            $result['match'] = match (true) {
                $envelope->final_sha256 === $hash => 'final',
                $envelope->signed_body_sha256 === $hash => 'body',
                $envelope->certificate_sha256 === $hash => 'certificate',
                default => 'original',
            };
        }

        return view('verify.show', ['reference' => $record['reference'] ?? null, 'record' => $record, 'result' => $result, 'key' => $this->seal->publicKeyHex()]);
    }

    /** @return array<string, mixed> */
    private function summary(SigningEnvelope $envelope, Engagement $engagement): array
    {
        $recomputed = $envelope->evidence ? hash('sha256', $envelope->evidence) : null;
        $sealOk = $recomputed !== null && hash_equals((string) $envelope->evidence_sha256, $recomputed)
            && $this->seal->verify($recomputed, (string) $envelope->evidence_seal, (string) $envelope->seal_public_key);

        return [
            'reference' => $engagement->reference,
            'completed_at' => $envelope->finalised_at,
            'signers' => $envelope->signers()->where('status', 'signed')->count(),
            'final_sha256' => $envelope->final_sha256,
            'evidence_sha256' => $envelope->evidence_sha256,
            'seal_ok' => $sealOk,
            'seal_key' => $envelope->seal_public_key,
            'timestamped' => $envelope->timestamp_token !== null,
            'tsa' => $envelope->timestamp_authority,
            'stamped' => $envelope->stamped,
        ];
    }
}

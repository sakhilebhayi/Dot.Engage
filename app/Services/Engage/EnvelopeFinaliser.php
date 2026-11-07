<?php

namespace App\Services\Engage;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Engagement;
use App\Models\SigningEnvelope;
use App\Notifications\EngagementNotice;
use App\Services\Pdf\IncrementalStamper;
use App\Services\Pdf\PdfPageAppender;
use App\Support\Engage\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Once every signature is in: draw the signatures onto the original, add the certificate of completion, write the evidence
 * record, seal it, timestamp it if an authority is configured, and store everything. Safe to run again after a failure
 * (a lease stops two workers doing it at once) and a no-op once it has finished.
 */
class EnvelopeFinaliser
{
    private const LEASE_MINUTES = 5;

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
        private readonly EngagementService $engagements,
        private readonly ParticipantAccess $access,
        private readonly DocumentService $documents,
        private readonly EvidenceSeal $seal,
        private readonly Rfc3161Client $tsa,
    ) {}

    public function finalise(int $envelopeId): bool
    {
        // Claim the work. Only one worker gets a row back from this update.
        $claimed = DB::table('signing_envelopes')
            ->where('id', $envelopeId)
            ->whereNull('finalised_at')
            ->whereNotNull('all_signed_at')
            ->where(fn ($q) => $q->whereNull('finalising_at')->orWhere('finalising_at', '<', now()->subMinutes(self::LEASE_MINUTES)))
            ->update(['finalising_at' => now(), 'finalise_attempts' => DB::raw('finalise_attempts + 1')]);

        if (! $claimed) {
            return false;
        }

        $envelope = SigningEnvelope::with(['signers.participant', 'signers.fields', 'version', 'contract'])->findOrFail($envelopeId);
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($envelope->engagement_id);

        try {
            $this->run($envelope, $engagement);
        } catch (Throwable $e) {
            report($e);
            $envelope->forceFill(['finalising_at' => null, 'finalise_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->audit->record($engagement, 'signing.finalise_failed', Actor::system('finaliser'), $envelope, ['attempt' => $envelope->finalise_attempts, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            throw $e;
        }

        return true;
    }

    private function run(SigningEnvelope $envelope, Engagement $engagement): void
    {
        $version = $envelope->version;
        $contract = Contract::withoutGlobalScopes()->findOrFail($envelope->contract_id);

        $original = $this->documents->versionFile($version);
        if (! hash_equals($envelope->document_sha256, hash('sha256', $original))) {
            throw new RuntimeException('The document no longer matches the hash that was signed. Nothing was stored.');
        }

        $signers = $envelope->signers()->with(['participant', 'fields'])->where('status', 'signed')->orderBy('signed_at')->get();
        $stamped = $version->stampable;
        $body = $original;

        if ($stamped) {
            $stamper = new IncrementalStamper($original);
            $pages = $version->page_count ?: 1;
            $doc = $stamper->document();
            // Text fields are set at a readable size for the box they sit in, never larger than 11 points.
            $textSize = function (int $page, float $boxFraction) use ($doc): float {
                $height = $doc->page($page)->height * $boxFraction;

                return round(min(11.0, max(6.0, $height * 0.62)), 1);
            };
            foreach ($signers as $signer) {
                $sig = $signer->signature_path ? Storage::disk('signatures')->get($signer->signature_path) : null;
                $ini = $signer->initials_path ? Storage::disk('signatures')->get($signer->initials_path) : null;
                foreach ($signer->fields as $f) {
                    if ($f->value === null || $f->value === '') {
                        continue;
                    }
                    match ($f->type) {
                        'signature' => $sig ? $stamper->stampImage($f->page, $f->x, $f->y, $f->w, $f->h, $sig) : null,
                        'initials' => $ini ? $stamper->stampImage($f->page, $f->x, $f->y, $f->w, $f->h, $ini) : null,
                        'date', 'text', 'checkbox' => $stamper->stampText($f->page, $f->x, $f->y, $f->w, $f->h, (string) $f->value, $textSize($f->page, $f->h), [11, 26, 58]),
                        default => null,
                    };
                }
            }
            // A small mark at the foot of every page ties it to the engagement and the document hash.
            $mark = $engagement->reference.' · signed copy · '.substr($envelope->document_sha256, 0, 12);
            for ($p = 1; $p <= $pages; $p++) {
                $stamper->stampText($p, 0.08, 0.972, 0.84, 0.014, $mark, 6, [110, 116, 122]);
            }
            $body = $stamper->toBytes();
        }

        $certificate = $this->certificate($envelope, $engagement, $contract, $version, $signers, $stamped);

        $final = $body;
        if ($stamped) {
            $final = (new PdfPageAppender)->appendPagesFrom($body, $certificate);
        }

        $finalSha = hash('sha256', $final);
        $bodySha = hash('sha256', $body);
        $certSha = hash('sha256', $certificate);

        $evidence = $this->evidence($envelope, $engagement, $contract, $version, $signers, $finalSha, $bodySha, $certSha, $stamped);
        $json = AuditTrail::canonicalJson($evidence);
        $evidenceSha = hash('sha256', $json);
        $sealed = $this->seal->seal($evidenceSha);
        $stampToken = $this->tsa->stamp($evidenceSha);

        $disk = Storage::disk(DocumentService::DISK);
        $base = 'signed/env'.$envelope->id;
        $disk->put($base.'-final.pdf', $final);
        $disk->put($base.'-certificate.pdf', $certificate);
        $disk->put($base.'-evidence.json', $json);

        DB::transaction(function () use ($envelope, $engagement, $contract, $base, $final, $finalSha, $bodySha, $certSha, $json, $evidenceSha, $sealed, $stampToken, $stamped) {
            $number = ((int) ContractVersion::where('contract_id', $contract->id)->max('version_number')) + 1;
            $signedVersion = ContractVersion::create([
                'contract_id' => $contract->id, 'created_by' => $contract->created_by, 'version_number' => $number, 'kind' => 'signed',
                'file_path' => $base.'-final.pdf', 'sha256' => $finalSha, 'size' => strlen($final), 'page_count' => null,
                'mime' => 'application/pdf', 'label' => 'Signed copy', 'stampable' => false,
                'change_notes' => 'Signed by all parties, '.now()->format('j F Y'),
            ]);

            $contract->forceFill(['file_path' => $base.'-final.pdf', 'sha256' => $finalSha, 'file_size' => strlen($final), 'current_version' => $number, 'status' => 'signed', 'locked_at' => $contract->locked_at ?? now()])->save();

            $envelope->forceFill([
                'status' => 'completed', 'finalised_at' => now(), 'finalising_at' => null, 'finalise_error' => null,
                'final_path' => $base.'-final.pdf', 'final_sha256' => $finalSha, 'signed_body_sha256' => $bodySha,
                'certificate_path' => $base.'-certificate.pdf', 'certificate_sha256' => $certSha,
                'stamped' => $stamped, 'evidence' => $json, 'evidence_sha256' => $evidenceSha,
                'evidence_seal' => $sealed['signature'], 'seal_public_key' => $sealed['public_key'],
                'timestamp_token' => $stampToken['token'] ?? null, 'timestamp_authority' => $stampToken['authority'] ?? null,
            ])->save();

            $engagement->forceFill(['completed_at' => now()])->save();
            $this->audit->record($engagement, 'signing.finalised', Actor::system('finaliser'), $envelope, [
                'final_sha256' => $finalSha, 'evidence_sha256' => $evidenceSha, 'stamped' => $stamped, 'timestamped' => $stampToken !== null,
            ]);
        });

        $this->states->sync($engagement, Actor::system('finaliser'));
        $this->announce($envelope, $engagement);
    }

    /** Tell everyone, and keep outside signers' access open long enough to collect the signed copy. */
    private function announce(SigningEnvelope $envelope, Engagement $engagement): void
    {
        $days = (int) config('engage.signing.completed_access_days');
        foreach ($engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')->whereNotNull('accepted_at')->get() as $p) {
            if (! $p->user_id) {
                $token = $this->access->issueToken($p);
                $p->forceFill(['token_expires_at' => now()->addDays($days)])->save();
                Notification::route('mail', $p->email)->notify(new EngagementNotice(
                    $engagement, 'agreement_completed', 'Everyone has signed '.$engagement->title.'. The signed agreement is ready.',
                    route('join.show', ['token' => $token, 'to' => 'signing']), 'Get the signed agreement', emailOnly: true,
                ));
            } else {
                $this->engagements->notifyParticipant($engagement, $p, 'agreement_completed', 'Everyone has signed '.$engagement->title.'. The signed agreement is ready.', route('engagements.signing', $engagement), 'Open the signed agreement');
            }
        }
    }

    /** @return array<string, mixed> */
    private function evidence(SigningEnvelope $envelope, Engagement $engagement, Contract $contract, ContractVersion $version, $signers, string $finalSha, string $bodySha, string $certSha, bool $stamped): array
    {
        return [
            'format' => 'dot-engage-evidence/1',
            'platform' => 'Dot.Engage',
            'engagement' => ['reference' => $engagement->reference, 'title' => $engagement->title, 'host' => $engagement->team->name],
            'document' => [
                'contract_id' => $contract->id, 'title' => $contract->title, 'version_id' => $version->id, 'version_number' => $version->version_number,
                'sha256' => $envelope->document_sha256, 'pages' => $version->page_count,
            ],
            'signing' => [
                'envelope_id' => $envelope->id, 'mode' => $envelope->mode, 'level' => 'basic electronic signature',
                'sent_at' => $envelope->sent_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'all_signed_at' => $envelope->all_signed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'consent_text_sha256' => hash('sha256', SigningService::CONSENT),
            ],
            'signers' => $signers->map(fn ($s) => [
                'order' => $s->sign_order, 'name' => $s->participant->name, 'email' => $s->signed_email, 'role' => $s->participant->role->value,
                'identity' => ['method' => $s->identity_method, 'verified_at' => $s->identity_verified_at?->utc()->format('Y-m-d\TH:i:s\Z')],
                'consent_at' => $s->consent_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'signed_at' => $s->signed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'method' => $s->method, 'typed_name' => $s->typed_name,
                'signature_sha256' => $s->signature_path ? hash('sha256', (string) Storage::disk('signatures')->get($s->signature_path)) : null,
                'ip' => $s->ip, 'user_agent' => $s->user_agent,
                'fields' => $s->fields->map(fn ($f) => ['type' => $f->type, 'page' => $f->page, 'x' => $f->x, 'y' => $f->y, 'w' => $f->w, 'h' => $f->h, 'filled' => $f->value !== null])->values()->all(),
            ])->values()->all(),
            'audit' => ['head_hash' => $this->audit->head($engagement), 'chain' => $this->audit->verify($engagement)],
            'result' => ['final_sha256' => $finalSha, 'signed_body_sha256' => $bodySha, 'certificate_sha256' => $certSha, 'signatures_drawn_on_pages' => $stamped],
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function certificate(SigningEnvelope $envelope, Engagement $engagement, Contract $contract, ContractVersion $version, $signers, bool $stamped): string
    {
        return Pdf::loadView('pdf.certificate', [
            'envelope' => $envelope, 'engagement' => $engagement, 'contract' => $contract, 'version' => $version, 'signers' => $signers,
            'stamped' => $stamped, 'consent' => SigningService::CONSENT, 'verifyUrl' => route('verify.show', ['reference' => $engagement->reference]),
            'events' => $engagement->auditEvents()->whereIn('event', ['signing.sent', 'signing.viewed', 'signing.consented', 'signing.signed', 'signing.reminded', 'signing.all_signed'])->orderBy('id')->get(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait')->output();
    }
}

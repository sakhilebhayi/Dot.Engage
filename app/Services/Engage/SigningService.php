<?php

namespace App\Services\Engage;

use App\Jobs\FinaliseEnvelope;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningSigner;
use App\Notifications\EngagementNotice;
use App\Support\Engage\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The signing request: who signs, in what order, where, and the moment each person does. Every step that changes
 * state runs in a transaction with the envelope locked, repeats of the same request are harmless, and nothing is
 * called "signed" until every required signature has actually been stored.
 */
class SigningService
{
    public const CONSENT = 'I agree to sign this document electronically. I understand that my drawn, typed or uploaded signature is meant as my signature on this agreement, and that Dot.Engage records my email address, the time, my network address and my device as evidence of it.';

    public const FIELD_TYPES = ['signature', 'initials', 'date', 'text', 'checkbox'];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
        private readonly EngagementService $engagements,
        private readonly ParticipantAccess $access,
        private readonly SigningReadiness $readiness,
        private readonly SignatureImages $images,
        private readonly DocumentService $documents,
    ) {}

    // ── Preparing ────────────────────────────────────────────────────────────

    /**
     * Create or update the draft signing request for the document's current version.
     *
     * @param  array{mode: string, expires_days?: int, reminder_days?: int, signers: list<array{participant_id: int, order?: int}>}  $input
     */
    public function saveDraft(Engagement $engagement, Contract $contract, array $input, Actor $actor, int $userId): SigningEnvelope
    {
        if (! $engagement->isOpen()) {
            throw new InvalidArgumentException('This engagement is closed.');
        }
        if (! $contract->isPdf()) {
            throw new InvalidArgumentException('Only PDF documents can be signed.');
        }
        $version = $contract->currentVersionRecord();
        abort_unless($version, 422, 'There is no version to sign.');

        $mode = $input['mode'] === 'parallel' ? 'parallel' : 'sequential';

        return DB::transaction(function () use ($engagement, $contract, $version, $input, $mode, $actor, $userId) {
            Contract::withoutGlobalScopes()->whereKey($contract->id)->lockForUpdate()->first();

            $active = SigningEnvelope::where('contract_id', $contract->id)->whereIn('status', ['draft', 'sent'])->first();
            if ($active && $active->status === 'sent') {
                throw new InvalidArgumentException('A signing request is already out for this document. Cancel it first to change who signs.');
            }

            $envelope = $active ?? SigningEnvelope::create([
                'engagement_id' => $engagement->id,
                'contract_id' => $contract->id,
                'contract_version_id' => $version->id,
                'document_sha256' => $version->sha256 ?? '',
                'created_by' => $userId,
            ]);

            // A draft always follows the current version: if the document moved on, the old fields no longer apply.
            if ($envelope->contract_version_id !== $version->id) {
                $envelope->fields()->delete();
                $envelope->forceFill(['contract_version_id' => $version->id, 'document_sha256' => $version->sha256 ?? ''])->save();
            }

            $envelope->forceFill([
                'mode' => $mode,
                'reminder_days' => max(1, min(30, (int) ($input['reminder_days'] ?? config('engage.signing.reminder_days')))),
                'expires_at' => now()->addDays(max(1, min(90, (int) ($input['expires_days'] ?? config('engage.signing.default_days'))))),
            ])->save();

            $wanted = collect($input['signers'] ?? [])->values();
            $people = $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')
                ->whereIn('id', $wanted->pluck('participant_id'))->get()->keyBy('id');

            foreach ($wanted as $row) {
                $p = $people->get((int) $row['participant_id']);
                if (! $p || ! $p->hasCapability('sign')) {
                    throw new InvalidArgumentException('Everyone who signs must be a participant with the sign capability.');
                }
            }

            $envelope->signers()->whereNotIn('participant_id', $people->keys())->where('status', '!=', 'reassigned')->delete();
            foreach ($wanted as $i => $row) {
                SigningSigner::updateOrCreate(
                    ['envelope_id' => $envelope->id, 'participant_id' => (int) $row['participant_id'], 'status' => 'pending'],
                    ['sign_order' => $mode === 'sequential' ? max(1, (int) ($row['order'] ?? ($i + 1))) : 1],
                );
            }

            $this->audit->record($engagement, 'signing.prepared', $actor, $envelope, ['signers' => $people->pluck('email')->values()->all(), 'mode' => $mode, 'version' => $version->version_number]);

            return $envelope->fresh(['signers.participant', 'fields']);
        });
    }

    /**
     * Replace every field of a draft. Positions are fractions of the page, origin top-left.
     *
     * @param  list<array{signer_id: int, type: string, page: int, x: float, y: float, w: float, h: float, required?: bool, label?: ?string}>  $fields
     */
    public function saveFields(SigningEnvelope $envelope, array $fields, Actor $actor): SigningEnvelope
    {
        if (! $envelope->isDraft()) {
            throw new InvalidArgumentException('Fields can only be changed before the request is sent.');
        }
        if (count($fields) > 200) {
            throw new InvalidArgumentException('That is more fields than one document needs.');
        }

        $version = $envelope->version;
        $pages = $version->page_count ?: 1;
        $signerIds = $envelope->signers()->pluck('id')->all();

        $rows = [];
        foreach ($fields as $f) {
            $type = (string) ($f['type'] ?? '');
            if (! in_array($type, self::FIELD_TYPES, true)) {
                throw new InvalidArgumentException('Unknown field type.');
            }
            if (! in_array((int) ($f['signer_id'] ?? 0), $signerIds, true)) {
                throw new InvalidArgumentException('Every field must belong to one of the signatories.');
            }
            $page = (int) ($f['page'] ?? 0);
            if ($page < 1 || $page > $pages) {
                throw new InvalidArgumentException('A field is on a page that does not exist.');
            }
            [$x, $y, $w, $h] = array_map(fn ($k) => (float) ($f[$k] ?? 0), ['x', 'y', 'w', 'h']);
            if ($w < 0.01 || $h < 0.004 || $x < 0 || $y < 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
                throw new InvalidArgumentException('A field sits outside the page.');
            }
            $rows[] = [
                'envelope_id' => $envelope->id, 'signer_id' => (int) $f['signer_id'], 'type' => $type, 'page' => $page,
                'x' => round($x, 6), 'y' => round($y, 6), 'w' => round($w, 6), 'h' => round($h, 6),
                'required' => (bool) ($f['required'] ?? true), 'label' => isset($f['label']) ? mb_substr((string) $f['label'], 0, 80) : null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($envelope, $rows) {
            $envelope->fields()->delete();
            if ($rows !== []) {
                SigningField::insert($rows);
            }
        });

        return $envelope->fresh(['signers.participant', 'fields']);
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    public function send(SigningEnvelope $envelope, Actor $actor): SigningEnvelope
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($envelope->engagement_id);
        $contract = Contract::withoutGlobalScopes()->findOrFail($envelope->contract_id);

        $sent = DB::transaction(function () use ($envelope, $engagement, $contract, $actor) {
            $locked = SigningEnvelope::whereKey($envelope->id)->lockForUpdate()->first();
            if ($locked->status === 'sent') {
                return $locked; // pressed twice: the first press already did it
            }
            if ($locked->status !== 'draft') {
                throw new InvalidArgumentException('This signing request is '.$locked->status.' and cannot be sent.');
            }
            if (! $engagement->isOpen()) {
                throw new InvalidArgumentException('This engagement is closed.');
            }

            $failed = collect($this->readiness->check($engagement, $contract, $locked))->firstWhere('ok', false);
            if ($failed) {
                throw new InvalidArgumentException('Not ready to send: '.$failed['label'].'. '.$failed['detail']);
            }

            $version = $locked->version;
            if (! $this->documents->intact($version)) {
                throw new RuntimeException('The stored file no longer matches its recorded hash. Signing was not started.');
            }

            $locked->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'document_sha256' => $version->sha256,
                'expires_at' => $locked->expires_at && $locked->expires_at->isFuture() ? $locked->expires_at : now()->addDays((int) config('engage.signing.default_days')),
            ])->save();

            $contract->forceFill(['locked_at' => now(), 'status' => 'pending'])->save();
            $this->audit->record($engagement, 'signing.sent', $actor, $locked, ['sha256' => $version->sha256, 'version' => $version->version_number, 'mode' => $locked->mode, 'expires_at' => $locked->expires_at->toIso8601String()]);

            return $locked;
        });

        $this->notifyReadySigners($sent, $engagement);
        $this->states->sync($engagement, $actor);

        return $sent->fresh(['signers.participant', 'fields']);
    }

    /** Tell the signers whose turn it is (everyone in parallel mode, the next in line in sequential mode). */
    private function notifyReadySigners(SigningEnvelope $envelope, Engagement $engagement): void
    {
        $outstanding = $envelope->signers()->whereIn('status', ['pending', 'notified', 'viewed'])->orderBy('sign_order')->get();
        if ($outstanding->isEmpty()) {
            return;
        }
        $turn = $envelope->mode === 'parallel' ? $outstanding : $outstanding->where('sign_order', $outstanding->first()->sign_order);

        foreach ($turn as $signer) {
            if ($signer->status === 'pending') {
                $signer->forceFill(['status' => 'notified', 'notified_at' => now()])->save();
                $this->sendSigningRequest($signer, $engagement, false);
            }
        }
    }

    private function sendSigningRequest(SigningSigner $signer, Engagement $engagement, bool $reminder): void
    {
        $participant = $signer->participant;
        $message = $reminder
            ? 'Reminder: '.$engagement->owner->name.' is waiting for your signature on '.$engagement->title
            : $engagement->owner->name.' asked you to sign '.$engagement->title;

        if ($participant->user_id && $participant->user) {
            $participant->user->notify(new EngagementNotice($engagement, 'signature_requested', $message, route('engagements.sign', $engagement), 'Review and sign'));

            return;
        }

        // Outside parties get a fresh link, valid until the request expires, that opens the code page and then the signing page.
        $token = $this->access->issueToken($participant);
        $envelope = $signer->envelope;
        if ($envelope->expires_at && $envelope->expires_at->gt($participant->token_expires_at)) {
            $participant->forceFill(['token_expires_at' => $envelope->expires_at->copy()->addDay()])->save();
        }
        Notification::route('mail', $participant->email)->notify(new EngagementNotice(
            $engagement, 'signature_requested', $message, route('join.show', ['token' => $token, 'to' => 'sign']), 'Review and sign', emailOnly: true,
        ));
    }

    // ── Signing ──────────────────────────────────────────────────────────────

    /** Record that the signer opened the request (once). */
    public function markViewed(SigningSigner $signer, Actor $actor): void
    {
        if (in_array($signer->status, ['notified', 'pending'], true) && $signer->envelope->isActive() && $this->isTurn($signer)) {
            $signer->forceFill(['status' => 'viewed', 'viewed_at' => $signer->viewed_at ?? now()])->save();
            $this->audit->record($signer->envelope->engagement_id, 'signing.viewed', $actor, $signer);
        }
    }

    public function isTurn(SigningSigner $signer): bool
    {
        $envelope = $signer->envelope;
        if ($envelope->mode === 'parallel') {
            return true;
        }

        return ! SigningSigner::where('envelope_id', $envelope->id)
            ->where('sign_order', '<', $signer->sign_order)
            ->whereIn('status', ['pending', 'notified', 'viewed'])
            ->exists();
    }

    /**
     * @param  array{signature?: ?string, initials?: ?string, method?: ?string, typed_name?: ?string, consent?: mixed, key?: ?string, fields?: array<int|string, mixed>}  $payload
     * @return array{status: 'signed'|'already', all_signed: bool}
     */
    public function sign(SigningSigner $signer, array $payload, Actor $actor, Request $request, bool $internalSession): array
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($signer->envelope->engagement_id);
        $key = (string) ($payload['key'] ?? '');
        if ($key === '' || strlen($key) > 64) {
            throw new InvalidArgumentException('The page is out of date. Reload it and try again.');
        }
        if (empty($payload['consent'])) {
            throw new InvalidArgumentException('Tick the box to confirm you agree to sign electronically.');
        }

        $method = in_array($payload['method'] ?? '', ['drawn', 'typed', 'uploaded'], true) ? $payload['method'] : null;
        if (! $method) {
            throw new InvalidArgumentException('Choose how you want to sign.');
        }

        // Clean the images before opening the transaction: decoding can be slow and needs no lock.
        $signaturePng = $this->images->clean((string) ($payload['signature'] ?? ''));
        $initialsPng = ! empty($payload['initials']) ? $this->images->clean((string) $payload['initials']) : null;

        $result = DB::transaction(function () use ($signer, $payload, $actor, $request, $internalSession, $engagement, $key, $method, $signaturePng, $initialsPng) {
            $envelope = SigningEnvelope::whereKey($signer->envelope_id)->lockForUpdate()->first();
            $row = SigningSigner::whereKey($signer->id)->lockForUpdate()->first();

            if ($row->status === 'signed') {
                if (hash_equals((string) $row->completion_key, $key)) {
                    return ['status' => 'already', 'all_signed' => $envelope->all_signed_at !== null];
                }
                throw new InvalidArgumentException('You have already signed this document.');
            }
            if ($envelope->status !== 'sent') {
                throw new InvalidArgumentException('This signing request is '.($envelope->status === 'completed' ? 'finished' : $envelope->status).'.');
            }
            if ($envelope->expires_at && $envelope->expires_at->isPast()) {
                throw new InvalidArgumentException('This signing request has expired. Ask the sender for a new one.');
            }
            if (! in_array($row->status, ['pending', 'notified', 'viewed'], true)) {
                throw new InvalidArgumentException('You can no longer sign this request.');
            }
            if (! $this->isTurn($row)) {
                throw new InvalidArgumentException('Another person has to sign before you. You will be emailed when it is your turn.');
            }

            $participant = $row->participant;
            $fields = $row->fields()->get();
            $submitted = (array) ($payload['fields'] ?? []);
            $signedAt = now();

            foreach ($fields as $field) {
                $value = match ($field->type) {
                    'signature' => 'image',
                    'initials' => $initialsPng ? 'image' : null,
                    'date' => $signedAt->format('j M Y'),
                    'text' => isset($submitted[$field->id]) ? trim(mb_substr((string) $submitted[$field->id], 0, 200)) : null,
                    'checkbox' => ! empty($submitted[$field->id]) ? 'X' : null,
                    default => null,
                };
                if ($field->required && ($value === null || $value === '')) {
                    throw new InvalidArgumentException('Complete every required field'.($field->label ? ' ('.$field->label.')' : '').' before signing.');
                }
                $field->forceFill(['value' => $value, 'filled_at' => $value !== null ? $signedAt : null])->save();
            }

            $disk = Storage::disk('signatures');
            $sigPath = 'sig_'.$envelope->id.'_'.$row->id.'_'.Str::uuid().'.png';
            $disk->put($sigPath, $signaturePng);
            $iniPath = null;
            if ($initialsPng) {
                $iniPath = 'ini_'.$envelope->id.'_'.$row->id.'_'.Str::uuid().'.png';
                $disk->put($iniPath, $initialsPng);
            }

            $row->forceFill([
                'status' => 'signed',
                'signed_at' => $signedAt,
                'consent_at' => $signedAt,
                'consent_text_sha256' => hash('sha256', self::CONSENT),
                'method' => $method,
                'typed_name' => $method === 'typed' ? mb_substr(trim((string) ($payload['typed_name'] ?? '')), 0, 120) : null,
                'signature_path' => $sigPath,
                'initials_path' => $iniPath,
                'identity_method' => $internalSession ? 'infodot_session' : 'email_code',
                'identity_verified_at' => $internalSession ? $signedAt : $participant->verified_at,
                'signed_email' => $participant->email,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'completion_key' => $key,
            ])->save();

            $this->audit->record($engagement, 'signing.consented', $actor, $row, ['consent_sha256' => hash('sha256', self::CONSENT)]);
            $this->audit->record($engagement, 'signing.signed', $actor, $row, [
                'method' => $method, 'signature_sha256' => hash('sha256', $signaturePng), 'document_sha256' => $envelope->document_sha256,
                'identity' => $internalSession ? 'infodot_session' : 'email_code', 'fields' => $fields->count(),
            ]);

            $remaining = SigningSigner::where('envelope_id', $envelope->id)->whereIn('status', ['pending', 'notified', 'viewed'])->count();
            if ($remaining === 0) {
                $envelope->forceFill(['all_signed_at' => $signedAt])->save();
                $this->audit->record($engagement, 'signing.all_signed', Actor::system('signing complete'), $envelope, ['signers' => SigningSigner::where('envelope_id', $envelope->id)->where('status', 'signed')->count()]);
            }

            return ['status' => 'signed', 'all_signed' => $remaining === 0];
        });

        if ($result['status'] === 'signed') {
            $envelope = SigningEnvelope::findOrFail($signer->envelope_id);
            if ($result['all_signed']) {
                FinaliseEnvelope::dispatch($envelope->id)->afterCommit();
            } else {
                $this->notifyReadySigners($envelope, $engagement);
            }
            $this->states->sync($engagement, $actor);
            $this->engagements->notifyOthers($engagement, $signer->participant, 'signed', $signer->participant->name.' signed '.$engagement->title, route('engagements.signing', $engagement), 'See signing progress');
        }

        return $result;
    }

    public function decline(SigningSigner $signer, string $reason, Actor $actor): void
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($signer->envelope->engagement_id);

        DB::transaction(function () use ($signer, $reason, $actor, $engagement) {
            $envelope = SigningEnvelope::whereKey($signer->envelope_id)->lockForUpdate()->first();
            $row = SigningSigner::whereKey($signer->id)->lockForUpdate()->first();
            if ($envelope->status !== 'sent' || ! in_array($row->status, ['pending', 'notified', 'viewed'], true)) {
                throw new InvalidArgumentException('This request can no longer be declined.');
            }

            $row->forceFill(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => mb_substr($reason, 0, 500)])->save();
            $envelope->forceFill(['status' => 'declined', 'declined_at' => now()])->save();
            Contract::withoutGlobalScopes()->whereKey($envelope->contract_id)->update(['locked_at' => null]);
            $engagement->forceFill(['agreed_version_id' => null, 'agreed_at' => null])->save();

            $this->audit->record($engagement, 'signing.declined', $actor, $row, ['reason' => $reason]);
            $engagement->messages()->create(['author_name' => 'Dot.Engage', 'body' => $row->participant->name.' declined to sign: '.mb_substr($reason, 0, 300), 'is_system' => true]);
        });

        $this->states->sync($engagement, $actor);
        $this->engagements->notifyOthers($engagement, $signer->participant, 'signing_declined', $signer->participant->name.' declined to sign '.$engagement->title, route('engagements.show', $engagement), 'Open the agreement');
    }

    // ── After sending ────────────────────────────────────────────────────────

    public function cancel(SigningEnvelope $envelope, string $reason, Actor $actor): void
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($envelope->engagement_id);

        DB::transaction(function () use ($envelope, $reason, $actor, $engagement) {
            $locked = SigningEnvelope::whereKey($envelope->id)->lockForUpdate()->first();
            if (! in_array($locked->status, ['draft', 'sent'], true) || $locked->all_signed_at) {
                throw new InvalidArgumentException('This signing request can no longer be cancelled.');
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 500)])->save();
            Contract::withoutGlobalScopes()->whereKey($locked->contract_id)->update(['locked_at' => null]);
            $engagement->forceFill(['agreed_version_id' => null, 'agreed_at' => null])->save();
            $this->audit->record($engagement, 'signing.cancelled', $actor, $locked, ['reason' => $reason]);
        });

        $this->states->sync($engagement, $actor);
        $this->engagements->notifyOthers($engagement, null, 'signing_cancelled', 'The signing request for '.$engagement->title.' was cancelled', route('engagements.show', $engagement), 'Open the agreement');
    }

    public function remind(SigningSigner $signer, Actor $actor): bool
    {
        $envelope = $signer->envelope;
        if (! $envelope->isActive() || ! in_array($signer->status, ['notified', 'viewed'], true) || $signer->reminder_count >= (int) config('engage.signing.max_reminders')) {
            return false;
        }
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($envelope->engagement_id);
        $signer->forceFill(['reminder_count' => $signer->reminder_count + 1, 'last_reminded_at' => now()])->save();
        $this->sendSigningRequest($signer, $engagement, true);
        $this->audit->record($engagement, 'signing.reminded', $actor, $signer, ['email' => $signer->participant->email]);

        return true;
    }

    /** Move an outstanding signature to someone else (for example when the signatory left the business). */
    public function reassign(SigningSigner $signer, EngagementParticipant $to, Actor $actor): SigningSigner
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($signer->envelope->engagement_id);
        if ($to->engagement_id !== $engagement->id || ! $to->isActive()) {
            throw new InvalidArgumentException('Pick someone who is part of this engagement.');
        }

        $new = DB::transaction(function () use ($signer, $to, $actor, $engagement) {
            $envelope = SigningEnvelope::whereKey($signer->envelope_id)->lockForUpdate()->first();
            $row = SigningSigner::whereKey($signer->id)->lockForUpdate()->first();
            if ($envelope->status !== 'sent' || ! in_array($row->status, ['pending', 'notified', 'viewed'], true)) {
                throw new InvalidArgumentException('Only an outstanding signature can be reassigned.');
            }
            if (SigningSigner::where('envelope_id', $envelope->id)->where('participant_id', $to->id)->where('status', '!=', 'reassigned')->exists()) {
                throw new InvalidArgumentException($to->name.' is already a signatory on this request.');
            }
            if (! $to->hasCapability('sign')) {
                $to->forceFill(['capabilities' => array_values(array_unique([...($to->capabilities ?? []), 'sign']))])->save();
            }

            $new = SigningSigner::create(['envelope_id' => $envelope->id, 'participant_id' => $to->id, 'sign_order' => $row->sign_order, 'status' => $row->status === 'pending' ? 'pending' : 'notified', 'notified_at' => $row->status === 'pending' ? null : now()]);
            $row->fields()->update(['signer_id' => $new->id]);
            $row->forceFill(['status' => 'reassigned', 'reassigned_to_id' => $new->id])->save();
            $this->audit->record($engagement, 'signing.reassigned', $actor, $row, ['from' => $row->participant->email, 'to' => $to->email]);

            return $new;
        });

        if ($new->status === 'notified') {
            $this->sendSigningRequest($new, $engagement, false);
        }

        return $new;
    }

    /** Scheduler: close requests past their deadline. */
    public function expireDue(): int
    {
        $n = 0;
        foreach (SigningEnvelope::where('status', 'sent')->whereNull('all_signed_at')->where('expires_at', '<', now())->get() as $envelope) {
            $engagement = Engagement::withoutGlobalScopes()->find($envelope->engagement_id);
            DB::transaction(function () use ($envelope, $engagement) {
                $envelope->forceFill(['status' => 'expired'])->save();
                Contract::withoutGlobalScopes()->whereKey($envelope->contract_id)->update(['locked_at' => null]);
                if ($engagement) {
                    $this->audit->record($engagement, 'signing.expired', Actor::system('scheduler'), $envelope);
                }
            });
            if ($engagement) {
                $this->states->sync($engagement, Actor::system('scheduler'));
                $this->engagements->notifyOthers($engagement, null, 'signing_expired', 'The signing request for '.$engagement->title.' expired', route('engagements.signing', $engagement), 'Open the engagement');
            }
            $n++;
        }

        return $n;
    }

    /** Scheduler: nudge signers who have not signed within the reminder interval. */
    public function remindDue(): int
    {
        $n = 0;
        $signers = SigningSigner::with(['envelope', 'participant.user'])->whereIn('status', ['notified', 'viewed'])->whereHas('envelope', fn ($q) => $q->where('status', 'sent'))->get();
        foreach ($signers as $signer) {
            $last = $signer->last_reminded_at ?? $signer->notified_at;
            if ($last && $last->lt(now()->subDays($signer->envelope->reminder_days)) && $this->remind($signer, Actor::system('scheduler'))) {
                $n++;
            }
        }

        return $n;
    }
}

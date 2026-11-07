<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\SigningEnvelope;
use App\Models\SigningSigner;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\SigningReadiness;
use App\Services\Engage\SigningService;
use App\Services\Engage\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class SigningController extends Controller
{
    public function __construct(
        private readonly SigningService $signing,
        private readonly SigningReadiness $readiness,
        private readonly AuditTrail $audit,
    ) {}

    private function access(Request $request, Engagement $engagement, string $ability = 'view'): EngagementAccess
    {
        $access = EngagementAccess::resolve($request, $engagement);
        abort_if($access === null, $request->user() ? 404 : 403);
        abort_unless($access->can($ability), 403, 'Your role does not allow that.');

        return $access;
    }

    private function envelopeOf(Engagement $engagement, SigningEnvelope $envelope): SigningEnvelope
    {
        abort_unless($envelope->engagement_id === $engagement->id, 404);

        return $envelope;
    }

    private function fail(InvalidArgumentException|RuntimeException $e, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], $status);
    }

    private function mySigner(EngagementAccess $access, ?SigningEnvelope $envelope): ?SigningSigner
    {
        $participant = $access->isInternal() ? $access->participant : $access->participant;
        if (! $envelope || ! $participant) {
            return null;
        }

        return SigningSigner::where('envelope_id', $envelope->id)->where('participant_id', $participant->id)->where('status', '!=', 'reassigned')->first();
    }

    // ── The signing tab ──────────────────────────────────────────────────────

    public function tab(Request $request, Engagement $engagement)
    {
        $access = $this->access($request, $engagement, 'view');
        $engagement->load('parties');
        $contract = $access->documents()->last();
        $envelope = $engagement->envelopes()->orderByRaw("case status when 'sent' then 0 when 'draft' then 1 when 'completed' then 2 else 3 end")->latest('id')->first();
        $mine = $this->mySigner($access, $envelope);

        return view('engagements.signing', [
            'engagement' => $engagement, 'access' => $access, 'ws' => new Workspace($engagement, $access), 'tab' => 'signing',
            'contract' => $contract, 'envelope' => $envelope?->load(['signers.participant', 'signers.fields']),
            'mine' => $mine, 'readiness' => $contract ? $this->readiness->check($engagement, $contract, $envelope?->isDraft() ? $envelope : null) : [],
            'signable' => $contract ? $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')->get()->filter(fn ($p) => $p->hasCapability('sign'))->values() : collect(),
            'past' => $engagement->envelopes()->whereIn('status', ['cancelled', 'expired', 'declined'])->latest('id')->get(),
        ]);
    }

    /** The page a signatory uses to review and sign. */
    public function page(Request $request, Engagement $engagement)
    {
        $access = $this->access($request, $engagement, 'sign');
        $envelope = $engagement->envelopes()->where('status', 'sent')->latest('id')->first();
        $mine = $this->mySigner($access, $envelope);
        if (! $envelope || ! $mine) {
            return redirect()->route('engagements.signing', $engagement)->with('status', $envelope ? 'You are not asked to sign this one.' : 'There is nothing to sign right now.');
        }

        $this->signing->markViewed($mine, $access->actor($request));

        return view($request->boolean('embed') ? 'engagements.sign-embed' : 'engagements.sign', [
            'engagement' => $engagement->load('parties'), 'access' => $access, 'envelope' => $envelope, 'signer' => $mine->fresh(), 'contract' => $envelope->contract,
            'turn' => $this->signing->isTurn($mine), 'consent' => SigningService::CONSENT,
            'ws' => new Workspace($engagement, $access),
            'internal' => $access->isInternal(),
        ]);
    }

    // ── Viewer state (preparing and signing share it) ────────────────────────

    public function state(Request $request, Engagement $engagement, SigningEnvelope $envelope): JsonResponse
    {
        $access = $this->access($request, $engagement, 'view');
        $this->envelopeOf($engagement, $envelope);
        abort_unless($access->canSeeDocument($envelope->contract), 404);
        $ws = new Workspace($engagement->load('parties'), $access);
        $mine = $this->mySigner($access, $envelope);
        $preparing = $access->can('prepare_signing');
        abort_unless($preparing || $mine || $envelope->status === 'completed', 403);

        $signers = $envelope->signers()->with('participant')->where('status', '!=', 'reassigned')->get();
        $fields = $envelope->fields()->get();
        $isMine = fn ($f) => $mine && $f->signer_id === $mine->id;

        return response()->json([
            'envelope' => [
                'id' => $envelope->id, 'status' => $envelope->status, 'mode' => $envelope->mode, 'pages' => $envelope->version->page_count,
                'draft' => $envelope->isDraft(), 'version' => $envelope->version->version_number,
            ],
            'file_url' => route('documents.file', [$engagement, $envelope->contract_id, 'v' => $envelope->contract_version_id]),
            'signers' => $signers->map(fn ($s) => [
                'id' => $s->id, 'participant_id' => $s->participant_id, 'name' => $s->participant->name, 'email' => $s->participant->email,
                'order' => $s->sign_order, 'status' => $s->status, 'pen' => $ws->penFor($s->participant->party_id), 'mine' => $mine?->id === $s->id,
            ])->values(),
            'fields' => $fields->map(fn ($f) => [
                'id' => $f->id, 'signer_id' => $f->signer_id, 'type' => $f->type, 'page' => $f->page, 'x' => $f->x, 'y' => $f->y, 'w' => $f->w, 'h' => $f->h,
                'required' => $f->required, 'label' => $f->label, 'mine' => $isMine($f),
            ])->values(),
            'me' => ['signer_id' => $mine?->id, 'can_prepare' => $preparing && $envelope->isDraft()],
        ])->header('Cache-Control', 'private, no-store');
    }

    // ── Preparing ────────────────────────────────────────────────────────────

    public function draft(Request $request, Engagement $engagement): JsonResponse
    {
        $access = $this->access($request, $engagement, 'prepare_signing');
        $data = $request->validate([
            'mode' => ['required', 'in:sequential,parallel'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'reminder_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.participant_id' => ['required', 'integer'],
            'signers.*.order' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $contract = $engagement->primaryContract();
        abort_unless($contract, 422, 'Upload the agreement first.');

        try {
            $envelope = $this->signing->saveDraft($engagement, $contract, $data, $access->actor($request), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return response()->json(['envelope_id' => $envelope->id, 'state_url' => route('signing.state', [$engagement, $envelope])]);
    }

    public function fields(Request $request, Engagement $engagement, SigningEnvelope $envelope): JsonResponse
    {
        $access = $this->access($request, $engagement, 'prepare_signing');
        $this->envelopeOf($engagement, $envelope);
        $data = $request->validate(['fields' => ['present', 'array', 'max:200']]);

        try {
            $this->signing->saveFields($envelope, $data['fields'], $access->actor($request));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    public function send(Request $request, Engagement $engagement, SigningEnvelope $envelope): JsonResponse
    {
        $access = $this->access($request, $engagement, 'send_signing');
        $this->envelopeOf($engagement, $envelope);

        try {
            $this->signing->send($envelope, $access->actor($request));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        } catch (RuntimeException $e) {
            report($e);

            return $this->fail($e, 409);
        }

        return response()->json(['ok' => true, 'redirect' => route('engagements.signing', $engagement)]);
    }

    public function cancel(Request $request, Engagement $engagement, SigningEnvelope $envelope): JsonResponse
    {
        $access = $this->access($request, $engagement, 'send_signing');
        $this->envelopeOf($engagement, $envelope);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->signing->cancel($envelope, $data['reason'], $access->actor($request));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true, 'redirect' => route('engagements.signing', $engagement)]);
    }

    public function remind(Request $request, Engagement $engagement, SigningSigner $signer): JsonResponse
    {
        $access = $this->access($request, $engagement, 'send_signing');
        abort_unless($signer->envelope->engagement_id === $engagement->id, 404);

        $sent = $this->signing->remind($signer, $access->actor($request));

        return response()->json(['sent' => $sent, 'message' => $sent ? 'Reminder sent.' : 'No reminder was sent. The signer may have signed, or the limit was reached.'], $sent ? 200 : 422);
    }

    public function reassign(Request $request, Engagement $engagement, SigningSigner $signer): JsonResponse
    {
        $access = $this->access($request, $engagement, 'send_signing');
        abort_unless($signer->envelope->engagement_id === $engagement->id, 404);
        $data = $request->validate(['participant_id' => ['required', 'integer']]);
        $to = EngagementParticipant::where('engagement_id', $engagement->id)->findOrFail($data['participant_id']);

        try {
            $this->signing->reassign($signer, $to, $access->actor($request));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    // ── Signing ──────────────────────────────────────────────────────────────

    public function submit(Request $request, Engagement $engagement): JsonResponse
    {
        $access = $this->access($request, $engagement, 'sign');
        $envelope = $engagement->envelopes()->where('status', 'sent')->latest('id')->first();
        $signer = $this->mySigner($access, $envelope);

        // A repeat of a request that already worked (the page was refreshed or the network dropped) answers the same way.
        if (! $signer) {
            $done = $engagement->envelopes()->whereIn('status', ['sent', 'completed'])->latest('id')->first();
            $prior = $this->mySigner($access, $done);
            if ($prior && $prior->status === 'signed' && hash_equals((string) $prior->completion_key, (string) $request->input('key'))) {
                return response()->json(['status' => 'already', 'all_signed' => $done->all_signed_at !== null]);
            }

            return response()->json(['message' => 'There is nothing for you to sign.'], 404);
        }

        $payload = $request->validate([
            'signature' => ['required', 'string', 'max:700000'],
            'initials' => ['nullable', 'string', 'max:700000'],
            'method' => ['required', 'in:drawn,typed,uploaded'],
            'typed_name' => ['nullable', 'string', 'max:120'],
            'consent' => ['required', 'accepted'],
            'key' => ['required', 'string', 'max:64'],
            'fields' => ['nullable', 'array', 'max:200'],
        ]);

        try {
            $result = $this->signing->sign($signer, $payload, $access->actor($request), $request, $access->isInternal());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e, str_contains($e->getMessage(), 'already signed') ? 409 : 422);
        }

        return response()->json($result + ['redirect' => route('engagements.signing', $engagement)]);
    }

    public function decline(Request $request, Engagement $engagement): JsonResponse
    {
        $access = $this->access($request, $engagement, 'sign');
        $envelope = $engagement->envelopes()->where('status', 'sent')->latest('id')->first();
        $signer = $this->mySigner($access, $envelope);
        abort_unless($signer, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->signing->decline($signer, $data['reason'], $access->actor($request));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true, 'redirect' => route('engagements.show', $engagement)]);
    }

    // ── Completed documents ──────────────────────────────────────────────────

    /** kind: final | certificate | evidence */
    public function download(Request $request, Engagement $engagement, SigningEnvelope $envelope, string $kind): Response
    {
        $access = $this->access($request, $engagement, 'view');
        $this->envelopeOf($engagement, $envelope);
        abort_unless($envelope->status === 'completed' && $envelope->finalised_at, 404);
        abort_unless(in_array($kind, ['final', 'certificate', 'evidence'], true), 404);
        if ($kind === 'evidence') {
            abort_unless($access->can('view_audit') || $access->participant?->hasCapability('sign'), 403);
        }

        $path = match ($kind) {
            'final' => $envelope->final_path, 'certificate' => $envelope->certificate_path, 'evidence' => 'signed/env'.$envelope->id.'-evidence.json',
        };
        abort_unless($path && Storage::disk('contracts')->exists($path), 404);
        $bytes = Storage::disk('contracts')->get($path);

        $key = 'engage.dl.'.$envelope->id.'.'.$kind.'.'.($access->participant?->id ?? 'u'.$access->user?->id);
        if (Cache::add($key, 1, 600)) {
            $this->audit->record($engagement, 'document.downloaded', $access->actor($request), $envelope, ['kind' => $kind]);
        }

        $name = $engagement->reference.'-'.['final' => 'signed.pdf', 'certificate' => 'certificate.pdf', 'evidence' => 'evidence.json'][$kind];

        return response($bytes, 200, [
            'Content-Type' => $kind === 'evidence' ? 'application/json' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

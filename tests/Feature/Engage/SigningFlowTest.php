<?php

namespace Tests\Feature\Engage;

use App\Enums\EngagementState;
use App\Jobs\FinaliseEnvelope;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementAuditEvent;
use App\Models\EngagementParticipant;
use App\Models\SigningEnvelope;
use App\Models\SigningSigner;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\DocumentService;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementStateMachine;
use App\Services\Engage\EnvelopeFinaliser;
use App\Services\Engage\EvidenceSeal;
use App\Services\Engage\NegotiationService;
use App\Services\Engage\SigningService;
use App\Services\Pdf\PdfDocument;
use App\Support\Engage\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class SigningFlowTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private Engagement $engagement;

    private Contract $contract;

    private EngagementParticipant $thandi;

    private EngagementParticipant $pieter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->engagement = $this->makeEngagement();
        $this->contract = $this->addDocument($this->engagement);
        [$this->thandi] = $this->inviteExternal($this->engagement, 'thandi@acme.example', ['sign', 'approve']);
        [$this->pieter] = $this->inviteExternal($this->engagement, 'pieter@acme.example', ['sign']);
        $this->thandi = $this->verifyParticipant($this->thandi);
        $this->pieter = $this->verifyParticipant($this->pieter);

        // The owner signs too.
        $owner = $this->engagement->participants()->where('role', 'owner')->first();
        $owner->update(['capabilities' => ['host', 'sign']]);
        Notification::fake();
    }

    private function ownerAccess(): EngagementAccess
    {
        return EngagementAccess::forUser($this->engagement->fresh(), $this->engagement->owner);
    }

    private function agree(): void
    {
        if ($this->engagement->fresh()->agreed_version_id) {
            return;
        }
        // Thandi must approve; she is the only approver.
        $nego = app(NegotiationService::class);
        $nego->requestApproval($this->contract->fresh(), $this->ownerAccess());
        $approval = $this->engagement->approvals()->first();
        $nego->decideApproval($approval, EngagementAccess::forParticipant($this->engagement->fresh(), $this->thandi->fresh()), 'approved');
    }

    private function draft(string $mode = 'sequential'): SigningEnvelope
    {
        $owner = $this->engagement->participants()->where('role', 'owner')->first();
        $svc = app(SigningService::class);
        $envelope = $svc->saveDraft($this->engagement->fresh(), $this->contract->fresh(), [
            'mode' => $mode,
            'signers' => [['participant_id' => $owner->id, 'order' => 1], ['participant_id' => $this->thandi->id, 'order' => 2], ['participant_id' => $this->pieter->id, 'order' => 3]],
        ], Actor::user($this->engagement->owner), $this->engagement->owner->id);

        $fields = [];
        foreach ($envelope->signers as $i => $s) {
            $fields[] = ['signer_id' => $s->id, 'type' => 'signature', 'page' => 3, 'x' => 0.1 + $i * 0.28, 'y' => 0.80, 'w' => 0.25, 'h' => 0.06, 'required' => true];
            $fields[] = ['signer_id' => $s->id, 'type' => 'date', 'page' => 3, 'x' => 0.1 + $i * 0.28, 'y' => 0.87, 'w' => 0.25, 'h' => 0.03, 'required' => true];
        }

        return $svc->saveFields($envelope, $fields, Actor::user($this->engagement->owner));
    }

    private function sendReady(string $mode = 'sequential'): SigningEnvelope
    {
        $this->agree();
        $envelope = $this->draft($mode);

        return app(SigningService::class)->send($envelope, Actor::user($this->engagement->owner));
    }

    private function signAs(SigningSigner $signer, string $key = 'k1'): array
    {
        $participant = $signer->participant;
        $internal = $participant->user_id !== null;
        $request = Request::create('/sign', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'PHPUnit Browser']);

        return app(SigningService::class)->sign($signer->fresh(), [
            'signature' => $this->signatureDataUri(), 'method' => 'drawn', 'consent' => true, 'key' => $key, 'fields' => [],
        ], $internal ? Actor::user($participant->user) : Actor::participant($participant), $request, $internal);
    }

    // ── Preparing and sending ────────────────────────────────────────────────

    public function test_sending_is_refused_until_the_checklist_passes(): void
    {
        $envelope = $this->draft();

        try {
            app(SigningService::class)->send($envelope, Actor::user($this->engagement->owner));
            $this->fail('Sending should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Not ready to send', $e->getMessage());
            $this->assertStringContainsString('approvals', strtolower($e->getMessage()));
        }

        $this->assertSame('draft', $envelope->fresh()->status);
        $this->assertNull($this->contract->fresh()->locked_at);
    }

    public function test_every_signer_needs_a_signature_field(): void
    {
        $this->agree();
        $svc = app(SigningService::class);
        $owner = $this->engagement->participants()->where('role', 'owner')->first();
        $envelope = $svc->saveDraft($this->engagement->fresh(), $this->contract->fresh(), ['mode' => 'parallel', 'signers' => [['participant_id' => $owner->id], ['participant_id' => $this->thandi->id]]], Actor::user($this->engagement->owner), $this->engagement->owner->id);
        $first = $envelope->signers->first();
        $svc->saveFields($envelope, [['signer_id' => $first->id, 'type' => 'signature', 'page' => 1, 'x' => .1, 'y' => .1, 'w' => .2, 'h' => .05]], Actor::user($this->engagement->owner));

        $this->expectExceptionMessageMatches('/still need a signature field/');
        $svc->send($envelope->fresh(), Actor::user($this->engagement->owner));
    }

    public function test_fields_must_sit_on_the_page_and_belong_to_a_signer(): void
    {
        $envelope = $this->draft();
        $svc = app(SigningService::class);
        $actor = Actor::user($this->engagement->owner);

        foreach ([
            ['x' => .9, 'w' => .3, 'page' => 1],   // off the right edge
            ['x' => .1, 'w' => .3, 'page' => 99],  // no such page
            ['x' => .1, 'w' => .3, 'page' => 1, 'type' => 'scribble'],
        ] as $bad) {
            try {
                $svc->saveFields($envelope, [array_merge(['signer_id' => $envelope->signers->first()->id, 'type' => 'signature', 'page' => 1, 'x' => .1, 'y' => .1, 'w' => .2, 'h' => .05], $bad)], $actor);
                $this->fail('Bad field accepted: '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $svc->saveFields($envelope, [['signer_id' => 999999, 'type' => 'signature', 'page' => 1, 'x' => .1, 'y' => .1, 'w' => .2, 'h' => .05]], $actor);
    }

    public function test_sending_locks_the_document_and_records_its_hash(): void
    {
        $envelope = $this->sendReady();

        $this->assertSame('sent', $envelope->status);
        $this->assertTrue($this->contract->fresh()->isLocked());
        $this->assertSame($this->contract->currentVersionRecord()->sha256, $envelope->document_sha256);
        $this->assertSame(EngagementState::ReadyForSigning, $this->engagement->fresh()->state);

        $this->expectException(InvalidArgumentException::class);
        app(DocumentService::class)->addVersion($this->contract->fresh(), UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x'), 'sneaky', Actor::user($this->engagement->owner), $this->engagement->owner->id);
    }

    public function test_sending_twice_is_harmless(): void
    {
        $envelope = $this->sendReady();
        $again = app(SigningService::class)->send($envelope->fresh(), Actor::user($this->engagement->owner));

        $this->assertSame('sent', $again->status);
        $this->assertSame(1, EngagementAuditEvent::where('engagement_id', $this->engagement->id)->where('event', 'signing.sent')->count());
    }

    // ── Signing ──────────────────────────────────────────────────────────────

    public function test_sequential_signing_enforces_the_order(): void
    {
        $envelope = $this->sendReady('sequential');
        [$first, $second, $third] = $envelope->signers->all();

        try {
            $this->signAs($second);
            $this->fail('Out of order signing was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Another person has to sign before you', $e->getMessage());
        }

        $this->assertSame('signed', $this->signAs($first)['status']);
        $this->assertSame('notified', $second->fresh()->status);
        $this->assertSame('pending', $third->fresh()->status);
        $this->assertSame(EngagementState::PartiallySigned, $this->engagement->fresh()->state);
    }

    public function test_parallel_signing_lets_anyone_sign_at_once(): void
    {
        Bus::fake([FinaliseEnvelope::class]);
        $envelope = $this->sendReady('parallel');
        [$first, $second, $third] = $envelope->signers->all();

        $this->signAs($third);
        $this->signAs($first);
        $this->assertNull($envelope->fresh()->all_signed_at);
        $this->signAs($second);

        $this->assertNotNull($envelope->fresh()->all_signed_at);
        Bus::assertDispatched(FinaliseEnvelope::class, 1);
    }

    public function test_the_document_is_not_fully_signed_until_the_last_signature(): void
    {
        Bus::fake([FinaliseEnvelope::class]);
        $envelope = $this->sendReady('parallel');
        [$first, $second, $third] = $envelope->signers->all();

        $this->signAs($first);
        $this->signAs($second);

        $this->assertNull($envelope->fresh()->all_signed_at);
        $this->assertNotSame(EngagementState::FullySigned, $this->engagement->fresh()->state);
        $this->assertNotSame('signed', $this->contract->fresh()->status);
        Bus::assertNotDispatched(FinaliseEnvelope::class);
    }

    public function test_repeating_the_same_signing_request_is_idempotent_and_another_one_is_refused(): void
    {
        $envelope = $this->sendReady('parallel');
        $first = $envelope->signers->first();

        $this->assertSame('signed', $this->signAs($first, 'same-key')['status']);
        $this->assertSame('already', $this->signAs($first, 'same-key')['status']);

        try {
            $this->signAs($first, 'different-key');
            $this->fail('A second signature was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already signed', $e->getMessage());
        }

        $this->assertSame(1, EngagementAuditEvent::where('engagement_id', $this->engagement->id)->where('event', 'signing.signed')->count());
    }

    public function test_consent_and_a_real_signature_are_required(): void
    {
        $envelope = $this->sendReady('parallel');
        $signer = $envelope->signers->first();
        $request = Request::create('/sign', 'POST');
        $svc = app(SigningService::class);
        $actor = Actor::user($this->engagement->owner);

        $cases = [
            ['signature' => $this->signatureDataUri(), 'method' => 'drawn', 'consent' => false, 'key' => 'a'],
            ['signature' => 'data:image/png;base64,AAAA', 'method' => 'drawn', 'consent' => true, 'key' => 'b'],
            ['signature' => 'not an image', 'method' => 'drawn', 'consent' => true, 'key' => 'c'],
            ['signature' => $this->signatureDataUri(), 'method' => 'telepathy', 'consent' => true, 'key' => 'd'],
        ];
        foreach ($cases as $payload) {
            try {
                $svc->sign($signer->fresh(), $payload, $actor, $request, true);
                $this->fail('Accepted: '.json_encode(array_keys($payload)));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('notified', $signer->fresh()->status);
    }

    public function test_an_empty_canvas_is_not_a_signature(): void
    {
        $envelope = $this->sendReady('parallel');
        $img = imagecreatetruecolor(300, 100);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        ob_start();
        imagepng($img);
        $blank = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

        $this->expectExceptionMessageMatches('/empty/');
        app(SigningService::class)->sign($envelope->signers->first()->fresh(), ['signature' => $blank, 'method' => 'drawn', 'consent' => true, 'key' => 'k'], Actor::user($this->engagement->owner), Request::create('/'), true);
    }

    public function test_the_signer_must_be_asked_to_sign_and_an_expired_request_cannot_be_signed(): void
    {
        $envelope = $this->sendReady('parallel');
        $this->travel(15)->days();

        $this->expectExceptionMessageMatches('/expired/');
        $this->signAs($envelope->signers->first());
    }

    public function test_expiry_closes_the_request_and_unlocks_the_document(): void
    {
        $envelope = $this->sendReady('parallel');
        $this->travel(15)->days();

        $this->assertSame(1, app(SigningService::class)->expireDue());
        $this->assertSame('expired', $envelope->fresh()->status);
        $this->assertFalse($this->contract->fresh()->isLocked());
    }

    public function test_declining_ends_the_request_and_sends_the_parties_back_to_talking(): void
    {
        $envelope = $this->sendReady('parallel');
        $signer = $envelope->signers->firstWhere('participant_id', $this->thandi->id);

        app(SigningService::class)->decline($signer, 'Clause 5.1 is not acceptable', Actor::participant($this->thandi));

        $this->assertSame('declined', $envelope->fresh()->status);
        $this->assertFalse($this->contract->fresh()->isLocked());
        $this->assertNull($this->engagement->fresh()->agreed_version_id);
        $this->assertNotSame(EngagementState::ReadyForSigning, $this->engagement->fresh()->state);
        $this->assertTrue($this->engagement->messages()->where('body', 'like', '%Clause 5.1%')->exists());
    }

    public function test_cancelling_unlocks_and_stops_further_signing(): void
    {
        $envelope = $this->sendReady('parallel');
        app(SigningService::class)->cancel($envelope, 'Wrong rates', Actor::user($this->engagement->owner));

        $this->assertSame('cancelled', $envelope->fresh()->status);
        $this->assertFalse($this->contract->fresh()->isLocked());

        $this->expectException(InvalidArgumentException::class);
        $this->signAs($envelope->signers->first());
    }

    public function test_an_outstanding_signature_can_be_reassigned(): void
    {
        $envelope = $this->sendReady('parallel');
        $signer = $envelope->signers->firstWhere('participant_id', $this->pieter->id);
        [$colleague] = $this->inviteExternal($this->engagement, 'colleague@acme.example', []);

        $new = app(SigningService::class)->reassign($signer, $colleague, Actor::user($this->engagement->owner));

        $this->assertSame('reassigned', $signer->fresh()->status);
        $this->assertSame($colleague->id, $new->participant_id);
        $this->assertTrue($colleague->fresh()->hasCapability('sign'));
        $this->assertSame($new->id, $envelope->fields()->where('type', 'signature')->where('signer_id', $new->id)->first()->signer_id);
    }

    // ── Finishing ────────────────────────────────────────────────────────────

    private function signEveryone(string $mode = 'parallel'): SigningEnvelope
    {
        Bus::fake([FinaliseEnvelope::class]);
        $envelope = $this->sendReady($mode);
        foreach ($envelope->signers as $s) {
            $this->signAs($s->fresh());
            if ($mode === 'sequential') {
                $envelope = $envelope->fresh(['signers']);
            }
        }
        Bus::fake([]); // allow the real job to run below

        return $envelope->fresh();
    }

    public function test_finalising_stamps_the_pages_stores_the_evidence_and_completes_the_engagement(): void
    {
        Bus::fake([FinaliseEnvelope::class]);
        $envelope = $this->sendReady('parallel');
        foreach ($envelope->signers as $s) {
            $this->signAs($s->fresh());
        }
        $original = Storage::disk('contracts')->get($this->contract->currentVersionRecord()->file_path);
        $originalSha = hash('sha256', $original);

        $this->assertTrue(app(EnvelopeFinaliser::class)->finalise($envelope->id));

        $done = $envelope->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertTrue($done->stamped);
        $this->assertNotNull($done->finalised_at);

        $final = Storage::disk('contracts')->get($done->final_path);
        $this->assertSame($done->final_sha256, hash('sha256', $final));
        // The signed file starts with the exact bytes that were agreed, so the original hash is a prefix of it.
        $this->assertSame($originalSha, hash('sha256', substr($final, 0, strlen($original))));
        $this->assertGreaterThan(PdfDocument::fromBytes($original)->pageCount(), PdfDocument::fromBytes($final)->pageCount(), 'the certificate pages were appended');

        $evidence = json_decode($done->evidence, true);
        $this->assertSame($originalSha, $evidence['document']['sha256']);
        $this->assertSame($done->final_sha256, $evidence['result']['final_sha256']);
        $this->assertCount(3, $evidence['signers']);
        $this->assertSame('email_code', collect($evidence['signers'])->firstWhere('email', 'thandi@acme.example')['identity']['method']);
        $this->assertTrue($evidence['audit']['chain']['ok']);

        $this->assertSame(EngagementState::Completed, $this->engagement->fresh()->state);
        $this->assertSame('signed', $this->contract->fresh()->status);
        $this->assertSame('signed', $this->contract->currentVersionRecord()->kind);
        $this->assertTrue(app(AuditTrail::class)->verify($this->engagement)['ok']);
    }

    public function test_the_seal_verifies_and_breaks_when_the_evidence_changes(): void
    {
        $envelope = $this->signEveryone();
        app(EnvelopeFinaliser::class)->finalise($envelope->id);
        $done = $envelope->fresh();
        $seal = app(EvidenceSeal::class);

        $this->assertTrue($seal->verify($done->evidence_sha256, $done->evidence_seal, $done->seal_public_key));
        $this->assertSame($done->evidence_sha256, hash('sha256', $done->evidence));

        $tampered = hash('sha256', str_replace('Thandi', 'Mallory', $done->evidence));
        $this->assertFalse($seal->verify($tampered, $done->evidence_seal, $done->seal_public_key));
    }

    public function test_finalising_twice_does_nothing_the_second_time(): void
    {
        $envelope = $this->signEveryone();
        $finaliser = app(EnvelopeFinaliser::class);

        $this->assertTrue($finaliser->finalise($envelope->id));
        $this->assertFalse($finaliser->finalise($envelope->id));
        $this->assertSame(1, EngagementAuditEvent::where('engagement_id', $this->engagement->id)->where('event', 'signing.finalised')->count());
        $this->assertSame(1, $this->contract->versions()->where('kind', 'signed')->count());
    }

    public function test_a_changed_original_stops_finalising_and_is_retried_later(): void
    {
        $envelope = $this->signEveryone();
        Storage::disk('contracts')->put($this->contract->currentVersionRecord()->file_path, '%PDF-1.4 something else');

        try {
            app(EnvelopeFinaliser::class)->finalise($envelope->id);
            $this->fail('Finalised a document that changed.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no longer matches', $e->getMessage());
        }

        $failed = $envelope->fresh();
        $this->assertNull($failed->finalised_at);
        $this->assertNull($failed->finalising_at, 'the lease is released so a retry can run');
        $this->assertNotNull($failed->finalise_error);
        $this->assertTrue(EngagementAuditEvent::where('engagement_id', $this->engagement->id)->where('event', 'signing.finalise_failed')->exists());
        $this->assertNotSame(EngagementState::Completed, $this->engagement->fresh()->state);
    }

    public function test_a_pdf_our_engine_cannot_stamp_still_completes_with_the_certificate_only(): void
    {
        $this->contract->versions()->update(['stampable' => false]);
        $envelope = $this->signEveryone();

        app(EnvelopeFinaliser::class)->finalise($envelope->id);
        $done = $envelope->fresh();

        $this->assertFalse($done->stamped);
        $original = Storage::disk('contracts')->get($this->contract->versions()->where('kind', 'original')->first()->file_path);
        $this->assertSame(hash('sha256', $original), $done->final_sha256, 'the original is delivered unchanged');
        $this->assertTrue(Storage::disk('contracts')->exists($done->certificate_path));
        $this->assertSame(EngagementState::Completed, $this->engagement->fresh()->state);
    }

    public function test_the_signed_copy_cannot_be_replaced_and_a_change_needs_a_new_version_and_a_new_request(): void
    {
        $envelope = $this->signEveryone();
        app(EnvelopeFinaliser::class)->finalise($envelope->id);
        $signedSha = $envelope->fresh()->final_sha256;

        $this->expectException(InvalidArgumentException::class);
        try {
            app(DocumentService::class)->addVersion($this->contract->fresh(), UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x'), 'edit', Actor::user($this->engagement->owner), $this->engagement->owner->id);
        } finally {
            $this->assertSame($signedSha, hash('sha256', Storage::disk('contracts')->get($envelope->fresh()->final_path)));
        }
    }

    public function test_the_state_machine_walks_from_ready_to_completed(): void
    {
        $this->agree();
        $this->assertSame(EngagementState::ReadyForSigning, $this->engagement->fresh()->state);

        $envelope = $this->signEveryone('sequential');
        app(EnvelopeFinaliser::class)->finalise($envelope->id);

        $this->assertSame(EngagementState::Completed, app(EngagementStateMachine::class)->evaluate($this->engagement->fresh()));
    }
}

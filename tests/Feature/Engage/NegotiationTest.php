<?php

namespace Tests\Feature\Engage;

use App\Enums\EngagementState;
use App\Enums\ParticipantRole;
use App\Models\Amendment;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\DocumentComment;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Models\EngagementParticipant;
use App\Notifications\EngagementNotice;
use App\Services\Engage\AmendmentSchedule;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\DocumentService;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Services\Engage\EngagementStateMachine;
use App\Services\Engage\NegotiationService;
use App\Services\Pdf\PdfDocument;
use App\Support\Engage\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class NegotiationTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private Engagement $e;

    private Contract $doc;

    private EngagementParticipant $thandi;

    private EngagementParticipant $pieter;

    private NegotiationService $nego;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->nego = app(NegotiationService::class);
        $this->e = $this->makeEngagement();
        $this->doc = $this->addDocument($this->e);
        [$this->thandi] = $this->inviteExternal($this->e, 'thandi@a.example', ['approve', 'sign']);
        [$this->pieter] = $this->inviteExternal($this->e, 'pieter@a.example', ['approve'], ParticipantRole::Reviewer);
        $this->thandi = $this->verifyParticipant($this->thandi);
        $this->pieter = $this->verifyParticipant($this->pieter);
    }

    private function owner(): EngagementAccess
    {
        return EngagementAccess::forUser($this->e->fresh(), $this->e->owner);
    }

    private function as(EngagementParticipant $p): EngagementAccess
    {
        return EngagementAccess::forParticipant($this->e->fresh(), $p->fresh());
    }

    private function state(): EngagementState
    {
        return $this->e->fresh()->state;
    }

    public function test_the_state_follows_what_happens_from_draft_to_ready_for_signing(): void
    {
        $e = $this->makeEngagement();
        $this->assertSame(EngagementState::Draft, $e->state);

        // Invitation goes out, nobody has opened it.
        [$p] = $this->inviteExternal($e, 'one@x.example');
        $this->assertSame(EngagementState::InvitationSent, $e->fresh()->state);

        // The other party joins, and talks.
        $p = $this->verifyParticipant($p);
        app(EngagementStateMachine::class)->sync($e);
        $this->assertSame(EngagementState::InDiscussion, $e->fresh()->state);

        // A note is left on the document: negotiation.
        $doc = $this->addDocument($e);
        $thread = $this->nego->addComment($doc, EngagementAccess::forParticipant($e->fresh(), $p), 'Question about 3.2');
        $this->assertSame(EngagementState::Negotiation, $e->fresh()->state);

        // Resolved, so the conversation is just in discussion again.
        $this->nego->setStatus($thread, EngagementAccess::forUser($e->fresh(), $e->owner), 'resolved');
        $this->assertSame(EngagementState::InDiscussion, $e->fresh()->state);
    }

    public function test_a_note_belongs_to_the_version_it_was_written_on_and_carries_its_position(): void
    {
        $c = $this->nego->addComment($this->doc, $this->as($this->thandi), 'Rate card?', ['page' => 2, 'quote' => 'R 310.00', 'x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.05]);

        $this->assertSame($this->doc->currentVersionRecord()->id, $c->contract_version_id);
        $this->assertSame(2, $c->page);
        $this->assertSame('R 310.00', $c->quote);
        $this->assertEqualsWithDelta(0.3, $c->w, 0.0001);
        $this->assertSame(EngagementState::Negotiation, $this->state());
    }

    public function test_positions_outside_the_page_are_clamped(): void
    {
        $c = $this->nego->addComment($this->doc, $this->as($this->thandi), 'x', ['x' => -3, 'y' => 9, 'w' => 2, 'h' => 0.1]);

        $this->assertSame(0.0, $c->x);
        $this->assertSame(1.0, $c->y);
        $this->assertSame(1.0, $c->w);
    }

    public function test_replying_to_a_resolved_note_reopens_it(): void
    {
        $c = $this->nego->addComment($this->doc, $this->as($this->thandi), 'First');
        $this->nego->setStatus($c, $this->owner(), 'resolved');
        $this->assertSame(EngagementState::InDiscussion, $this->state());

        $this->nego->reply($c->fresh(), $this->owner(), 'Wait, one more thing');

        $this->assertSame('open', $c->fresh()->status);
        $this->assertSame(EngagementState::Negotiation, $this->state());
    }

    public function test_a_note_marked_discussed_still_counts_as_acknowledged_for_signing(): void
    {
        $c = $this->nego->addComment($this->doc, $this->as($this->thandi), 'Talk about 5.1');
        $this->nego->setStatus($c, $this->owner(), 'discussed');

        $this->assertNotNull($c->fresh()->discussed_at);
        $this->assertNotSame(EngagementState::Negotiation, $this->state());
    }

    public function test_mentions_find_people_by_first_name_or_mailbox(): void
    {
        $ids = app(EngagementService::class)->mentionsIn($this->e, 'Thanks @Thandi and @pieter, also @nobody');

        $this->assertEqualsCanonicalizing([$this->thandi->id, $this->pieter->id], $ids);
    }

    // ── Amendments ───────────────────────────────────────────────────────────

    public function test_a_proposal_does_not_touch_the_document_and_needs_a_second_pair_of_eyes(): void
    {
        $before = $this->doc->currentVersionRecord()->sha256;
        $a = $this->nego->propose($this->doc, $this->as($this->thandi), ['type' => 'replace', 'original_text' => '24 hours', 'proposed_text' => '12 hours', 'explanation' => 'Buyers need it sooner']);

        $this->assertSame($before, $this->doc->fresh()->currentVersionRecord()->sha256);
        $this->assertSame('proposed', $a->status);
        $this->assertSame(EngagementState::Negotiation, $this->state());

        try {
            $this->nego->review($a, $this->as($this->thandi), 'accepted');
            $this->fail('Author reviewed their own proposal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Someone else', $e->getMessage());
        }

        $this->nego->review($a, $this->owner(), 'accepted', 'Fine');
        $this->assertSame('accepted', $a->fresh()->status);
        $this->assertSame(EngagementState::AwaitingAmendments, $this->state());
    }

    public function test_a_proposal_needs_its_wording(): void
    {
        foreach ([['type' => 'replace', 'original_text' => 'x'], ['type' => 'add'], ['type' => 'replace', 'proposed_text' => 'y'], ['type' => 'delete']] as $bad) {
            try {
                $this->nego->propose($this->doc, $this->as($this->thandi), $bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_change_cannot_be_reviewed_twice_and_can_be_withdrawn_by_its_author(): void
    {
        $a = $this->nego->propose($this->doc, $this->as($this->thandi), ['type' => 'remove', 'original_text' => 'Clause 9.2']);
        $this->nego->withdraw($a, $this->as($this->thandi));
        $this->assertSame('withdrawn', $a->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        $this->nego->review($a->fresh(), $this->owner(), 'accepted');
    }

    public function test_only_the_author_or_a_manager_can_withdraw(): void
    {
        $a = $this->nego->propose($this->doc, $this->as($this->thandi), ['type' => 'add', 'proposed_text' => 'New clause']);

        $this->expectException(InvalidArgumentException::class);
        $this->nego->withdraw($a, $this->as($this->pieter));
    }

    public function test_accepted_changes_become_a_new_version_with_a_schedule_and_the_old_version_is_kept(): void
    {
        $a = $this->nego->propose($this->doc, $this->as($this->thandi), ['type' => 'replace', 'clause_ref' => '3.2', 'original_text' => '24 hours', 'proposed_text' => '12 hours']);
        $this->nego->review($a, $this->owner(), 'accepted');
        $v1 = $this->doc->currentVersionRecord();
        $v1Bytes = Storage::disk('contracts')->get($v1->file_path);

        $v2 = app(AmendmentSchedule::class)->createRevision($this->doc->fresh(), Actor::user($this->e->owner), $this->e->owner->id);

        $this->assertSame(2, $v2->version_number);
        $new = Storage::disk('contracts')->get($v2->file_path);
        $this->assertSame(hash('sha256', $v1Bytes), hash('sha256', substr($new, 0, strlen($v1Bytes))), 'the earlier version is an exact prefix');
        $this->assertSame(PdfDocument::fromBytes($v1Bytes)->pageCount() + 1, PdfDocument::fromBytes($new)->pageCount());
        $this->assertSame($v2->id, $a->fresh()->applied_in_version_id);
        $this->assertSame($v1Bytes, Storage::disk('contracts')->get($v1->fresh()->file_path), 'version 1 is untouched');
        $this->assertSame(hash('sha256', $new), $v2->sha256);
        $this->assertNotSame(EngagementState::AwaitingAmendments, $this->state());
    }

    public function test_a_revision_without_accepted_changes_is_refused(): void
    {
        $this->expectExceptionMessage('no accepted changes');
        app(AmendmentSchedule::class)->createRevision($this->doc, Actor::user($this->e->owner), $this->e->owner->id);
    }

    // ── Approvals and agreement ──────────────────────────────────────────────

    public function test_approval_is_asked_of_every_approver_and_agreement_needs_all_of_them(): void
    {
        $this->assertSame(2, $this->nego->requestApproval($this->doc, $this->owner()));
        $this->assertSame(EngagementState::AwaitingApproval, $this->state());

        $rows = $this->e->approvals()->get();
        $this->nego->decideApproval($rows->firstWhere('participant_id', $this->thandi->id), $this->as($this->thandi), 'approved');
        $this->assertSame(EngagementState::AwaitingApproval, $this->state());
        $this->assertNull($this->e->fresh()->agreed_version_id);

        $this->nego->decideApproval($rows->firstWhere('participant_id', $this->pieter->id), $this->as($this->pieter), 'approved');
        $this->assertSame($this->doc->currentVersionRecord()->id, $this->e->fresh()->agreed_version_id);
        $this->assertSame(EngagementState::ReadyForSigning, $this->state());
    }

    public function test_asking_twice_creates_no_duplicates_and_a_decision_is_final(): void
    {
        $this->nego->requestApproval($this->doc, $this->owner());
        $this->assertSame(0, $this->nego->requestApproval($this->doc, $this->owner()));
        $this->assertSame(2, $this->e->approvals()->count());

        $row = $this->e->approvals()->where('participant_id', $this->thandi->id)->first();
        $this->nego->decideApproval($row, $this->as($this->thandi), 'approved');
        $this->expectExceptionMessage('already decided');
        $this->nego->decideApproval($row->fresh(), $this->as($this->thandi), 'rejected');
    }

    public function test_nobody_can_decide_someone_elses_approval(): void
    {
        $this->nego->requestApproval($this->doc, $this->owner());
        $row = $this->e->approvals()->where('participant_id', $this->thandi->id)->first();

        $this->expectExceptionMessage('belongs to someone else');
        $this->nego->decideApproval($row, $this->as($this->pieter), 'approved');
    }

    public function test_a_rejection_sends_the_engagement_back_to_negotiation(): void
    {
        $this->nego->requestApproval($this->doc, $this->owner());
        $row = $this->e->approvals()->where('participant_id', $this->pieter->id)->first();

        $this->nego->decideApproval($row, $this->as($this->pieter), 'rejected', 'Clause 5 is wrong');

        $this->assertSame(EngagementState::Negotiation, $this->state());
        $this->assertNull($this->e->fresh()->agreed_version_id);
    }

    public function test_a_new_version_clears_agreement_and_starts_approvals_again(): void
    {
        $this->nego->requestApproval($this->doc, $this->owner());
        foreach ($this->e->approvals()->get() as $row) {
            $who = $row->participant_id === $this->thandi->id ? $this->thandi : $this->pieter;
            $this->nego->decideApproval($row, $this->as($who), 'approved');
        }
        $this->assertNotNull($this->e->fresh()->agreed_version_id);

        $bytes = Pdf::loadView('pdf.demo-agreement', ['title' => 'v2', 'host' => 'H', 'other' => 'O'])->setPaper('a4')->output();
        app(DocumentService::class)->addVersion($this->doc->fresh(), UploadedFile::fake()->createWithContent('v2.pdf', $bytes), 'Rates changed', Actor::user($this->e->owner), $this->e->owner->id);

        $this->assertNull($this->e->fresh()->agreed_version_id);
        $this->assertSame(0, EngagementApproval::where('contract_version_id', $this->doc->currentVersionRecord()->id)->count());
        $this->assertNotSame(EngagementState::ReadyForSigning, $this->state());
        $this->assertSame(2, ContractVersion::where('contract_id', $this->doc->id)->count());
    }

    public function test_agreement_can_be_recorded_directly_only_when_nobody_has_to_approve(): void
    {
        try {
            $this->nego->recordAgreement($this->doc, $this->owner());
            $this->fail('Recorded agreement while approvers exist');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->e->participants()->update(['capabilities' => []]);
        $this->nego->recordAgreement($this->doc->fresh(), $this->owner());
        $this->assertSame(EngagementState::ReadyForSigning, $this->state());
    }

    public function test_the_other_party_is_told_but_not_flooded(): void
    {
        Notification::fake();
        $this->nego->addComment($this->doc, $this->owner(), 'One');
        $this->nego->addComment($this->doc, $this->owner(), 'Two');
        $this->nego->addComment($this->doc, $this->owner(), 'Three');

        Notification::assertSentOnDemandTimes(EngagementNotice::class, 2); // one per outside person, not one per note
    }

    public function test_the_notification_mail_never_carries_clause_text(): void
    {
        $this->nego->addComment($this->doc, $this->owner(), 'SECRET RATE R 1 234.56 for Acme');

        Notification::assertSentOnDemand(EngagementNotice::class, function ($n) {
            $mail = (string) json_encode($n->toMail(new \stdClass)->toArray());

            return ! str_contains($mail, 'SECRET RATE') && str_contains($mail, $this->e->reference);
        });
    }

    public function test_every_step_is_in_the_audit_trail(): void
    {
        $a = $this->nego->propose($this->doc, $this->as($this->thandi), ['type' => 'add', 'proposed_text' => 'New']);
        $this->nego->review($a, $this->owner(), 'rejected');
        $c = $this->nego->addComment($this->doc, $this->as($this->pieter), 'hm');
        $this->nego->reply($c, $this->owner(), 'ok');

        $events = $this->e->auditEvents()->pluck('event')->all();
        foreach (['amendment.proposed', 'amendment.rejected', 'comment.added', 'comment.replied'] as $expected) {
            $this->assertContains($expected, $events);
        }
        $this->assertTrue(app(AuditTrail::class)->verify($this->e)['ok']);
        $this->assertSame(1, DocumentComment::whereNull('parent_id')->count());
        $this->assertSame(1, Amendment::count());
    }
}

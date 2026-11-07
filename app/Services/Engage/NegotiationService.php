<?php

namespace App\Services\Engage;

use App\Models\Amendment;
use App\Models\Contract;
use App\Models\DocumentComment;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Models\EngagementParticipant;
use App\Support\Engage\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Comments, proposed changes, approvals and the moment the parties agree. Nothing here edits the document: a change
 * only becomes part of the agreement through a new version (DocumentService / AmendmentSchedule).
 */
class NegotiationService
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
        private readonly EngagementService $engagements,
    ) {}

    /** @param array{page?: ?int, quote?: ?string, x?: ?float, y?: ?float, w?: ?float, h?: ?float, kind?: string} $anchor */
    public function addComment(Contract $contract, EngagementAccess $who, string $body, array $anchor = []): DocumentComment
    {
        $engagement = $this->engagementOf($contract);
        $participant = $who->participantRecord();
        $version = $contract->currentVersionRecord();

        $comment = DB::transaction(function () use ($contract, $engagement, $who, $participant, $version, $body, $anchor) {
            $comment = DocumentComment::create([
                'engagement_id' => $engagement->id,
                'contract_id' => $contract->id,
                'contract_version_id' => $version?->id,
                'participant_id' => $participant?->id,
                'user_id' => $who->user?->id,
                'author_name' => $who->name(),
                'kind' => ($anchor['kind'] ?? 'comment') === 'question' ? 'question' : 'comment',
                'body' => $body,
                'page' => $anchor['page'] ?? null,
                'quote' => isset($anchor['quote']) ? mb_substr($anchor['quote'], 0, 1200) : null,
                'x' => $this->fraction($anchor['x'] ?? null),
                'y' => $this->fraction($anchor['y'] ?? null),
                'w' => $this->fraction($anchor['w'] ?? null),
                'h' => $this->fraction($anchor['h'] ?? null),
                'mentions' => $this->engagements->mentionsIn($engagement, $body),
                'status' => 'open',
            ]);

            $this->audit->record($engagement, 'comment.added', $who->actor(), $comment, ['page' => $comment->page, 'version' => $version?->version_number]);

            return $comment;
        });

        $this->states->sync($engagement, $who->actor());
        $this->notifyMentionedAndOthers($engagement, $participant, $comment->mentions ?? [], $who->name().' added a note on '.$contract->title);

        return $comment;
    }

    public function reply(DocumentComment $thread, EngagementAccess $who, string $body): DocumentComment
    {
        if ($thread->parent_id !== null) {
            $thread = $thread->parent;
        }
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($thread->engagement_id);
        $participant = $who->participantRecord();

        $reply = DocumentComment::create([
            'engagement_id' => $engagement->id,
            'contract_id' => $thread->contract_id,
            'contract_version_id' => $thread->contract_version_id,
            'parent_id' => $thread->id,
            'participant_id' => $participant?->id,
            'user_id' => $who->user?->id,
            'author_name' => $who->name(),
            'body' => $body,
            'mentions' => $this->engagements->mentionsIn($engagement, $body),
            'status' => 'open',
        ]);

        // A reply on a resolved note reopens the conversation.
        if ($thread->status === 'resolved') {
            $thread->forceFill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null])->save();
            $this->audit->record($engagement, 'comment.reopened', $who->actor(), $thread);
        }

        $this->audit->record($engagement, 'comment.replied', $who->actor(), $reply);
        $this->states->sync($engagement, $who->actor());
        $this->notifyMentionedAndOthers($engagement, $participant, $reply->mentions ?? [], $who->name().' replied to a note');

        return $reply;
    }

    /** open | discussed | resolved */
    public function setStatus(DocumentComment $thread, EngagementAccess $who, string $status): DocumentComment
    {
        if (! in_array($status, ['open', 'discussed', 'resolved'], true)) {
            throw new InvalidArgumentException('Unknown status.');
        }
        if ($thread->parent_id !== null) {
            $thread = $thread->parent;
        }
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($thread->engagement_id);
        $participant = $who->participantRecord();

        $thread->forceFill([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? now() : null,
            'resolved_by' => $status === 'resolved' ? $participant?->id : null,
            'discussed_at' => $status === 'discussed' ? now() : $thread->discussed_at,
        ])->save();

        $event = ['open' => 'comment.reopened', 'discussed' => 'comment.discussed', 'resolved' => 'comment.resolved'][$status];
        $this->audit->record($engagement, $event, $who->actor(), $thread);
        $this->states->sync($engagement, $who->actor());

        return $thread;
    }

    /** @param array{type: string, clause_ref?: ?string, page?: ?int, original_text?: ?string, proposed_text?: ?string, explanation?: ?string} $data */
    public function propose(Contract $contract, EngagementAccess $who, array $data): Amendment
    {
        $type = $data['type'];
        if (! in_array($type, ['replace', 'add', 'remove'], true)) {
            throw new InvalidArgumentException('Unknown kind of change.');
        }
        if ($type !== 'remove' && trim((string) ($data['proposed_text'] ?? '')) === '') {
            throw new InvalidArgumentException('Write the wording you propose.');
        }
        if ($type !== 'add' && trim((string) ($data['original_text'] ?? '')) === '') {
            throw new InvalidArgumentException('Quote the wording this changes.');
        }

        $engagement = $this->engagementOf($contract);
        $participant = $who->participantRecord();
        $version = $contract->currentVersionRecord();

        $amendment = Amendment::create([
            'engagement_id' => $engagement->id,
            'contract_id' => $contract->id,
            'base_version_id' => $version?->id,
            'participant_id' => $participant?->id,
            'user_id' => $who->user?->id,
            'author_name' => $who->name(),
            'type' => $type,
            'clause_ref' => isset($data['clause_ref']) ? mb_substr($data['clause_ref'], 0, 120) : null,
            'page' => $data['page'] ?? null,
            'original_text' => $type === 'add' ? null : mb_substr((string) $data['original_text'], 0, 4000),
            'proposed_text' => $type === 'remove' ? null : mb_substr((string) $data['proposed_text'], 0, 4000),
            'explanation' => isset($data['explanation']) ? mb_substr($data['explanation'], 0, 2000) : null,
            'status' => 'proposed',
        ]);

        $this->audit->record($engagement, 'amendment.proposed', $who->actor(), $amendment, ['type' => $type, 'clause' => $amendment->clause_ref, 'version' => $version?->version_number]);
        $this->states->sync($engagement, $who->actor());
        $this->engagements->notifyOthers($engagement, $participant, 'amendment_proposed', $who->name().' proposed a change to '.$contract->title, route('engagements.show', $engagement), 'Review the change');

        return $amendment;
    }

    public function review(Amendment $amendment, EngagementAccess $who, string $decision, ?string $note = null): Amendment
    {
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException('Unknown decision.');
        }
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($amendment->engagement_id);
        $participant = $who->participantRecord();

        return DB::transaction(function () use ($amendment, $who, $decision, $note, $engagement, $participant) {
            $locked = Amendment::whereKey($amendment->id)->lockForUpdate()->first();
            if ($locked->status !== 'proposed') {
                throw new InvalidArgumentException('That change was already '.$locked->status.'.');
            }
            // Four eyes: nobody accepts or rejects their own proposal.
            if ($participant && $locked->participant_id === $participant->id) {
                throw new InvalidArgumentException('Someone else has to review your proposal.');
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $participant?->id,
                'reviewed_at' => now(),
                'review_note' => $note ? mb_substr($note, 0, 500) : null,
            ])->save();

            $this->audit->record($engagement, $decision === 'accepted' ? 'amendment.accepted' : 'amendment.rejected', $who->actor(), $locked, ['note' => $note]);
            $this->states->sync($engagement, $who->actor());
            $this->engagements->notifyOthers($engagement, $participant, 'amendment_reviewed', 'A proposed change was '.$decision, route('engagements.show', $engagement), 'Open the agreement');

            return $locked;
        });
    }

    public function withdraw(Amendment $amendment, EngagementAccess $who): Amendment
    {
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($amendment->engagement_id);
        $participant = $who->participantRecord();
        if ($amendment->participant_id !== $participant?->id && ! $who->can('manage_documents')) {
            throw new InvalidArgumentException('Only the author can withdraw a proposal.');
        }
        if ($amendment->status !== 'proposed') {
            throw new InvalidArgumentException('That change was already '.$amendment->status.'.');
        }
        $amendment->forceFill(['status' => 'withdrawn'])->save();
        $this->audit->record($engagement, 'amendment.withdrawn', $who->actor(), $amendment);
        $this->states->sync($engagement, $who->actor());

        return $amendment;
    }

    /** Ask every approver to approve the current version. Safe to call twice. */
    public function requestApproval(Contract $contract, EngagementAccess $who): int
    {
        $engagement = $this->engagementOf($contract);
        $version = $contract->currentVersionRecord();
        abort_unless($version, 422, 'There is no version to approve.');

        $approvers = $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')->whereNotNull('accepted_at')->get()
            ->filter(fn (EngagementParticipant $p) => $p->hasCapability('approve'));

        if ($approvers->isEmpty()) {
            throw new InvalidArgumentException('Nobody has been given the approve capability. Add an approver on the People tab, or record agreement directly.');
        }

        $created = 0;
        DB::transaction(function () use ($engagement, $version, $approvers, &$created, $who) {
            foreach ($approvers as $approver) {
                $row = EngagementApproval::firstOrCreate(
                    ['contract_version_id' => $version->id, 'participant_id' => $approver->id],
                    ['engagement_id' => $engagement->id, 'status' => 'pending', 'requested_at' => now()],
                );
                if ($row->wasRecentlyCreated) {
                    $created++;
                }
            }
            $this->audit->record($engagement, 'approval.requested', $who->actor(), $version, ['version' => $version->version_number, 'approvers' => $approvers->pluck('email')->all()]);
        });

        $this->states->sync($engagement, $who->actor());
        foreach ($approvers as $approver) {
            $this->engagements->notifyParticipant($engagement, $approver, 'approval_requested', 'Your approval is needed on version '.$version->version_number.' of '.$contract->title, route('engagements.show', $engagement).'#approvals', 'Review and approve');
        }

        return $created;
    }

    public function decideApproval(EngagementApproval $approval, EngagementAccess $who, string $decision, ?string $note = null): EngagementApproval
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Unknown decision.');
        }
        $participant = $who->participantRecord();
        if (! $participant || $approval->participant_id !== $participant->id) {
            throw new InvalidArgumentException('This approval belongs to someone else.');
        }

        $engagement = Engagement::withoutGlobalScopes()->findOrFail($approval->engagement_id);
        $contract = Contract::withoutGlobalScopes()->findOrFail($approval->version->contract_id);

        DB::transaction(function () use ($approval, $decision, $note, $engagement, $contract, $who) {
            $row = EngagementApproval::whereKey($approval->id)->lockForUpdate()->first();
            if ($row->status !== 'pending') {
                throw new InvalidArgumentException('You already decided on this version.');
            }
            if ($contract->current_version !== $row->version->version_number) {
                throw new InvalidArgumentException('A newer version exists. Approve that one instead.');
            }

            $row->forceFill(['status' => $decision, 'note' => $note ? mb_substr($note, 0, 500) : null, 'decided_at' => now()])->save();
            $this->audit->record($engagement, $decision === 'approved' ? 'approval.approved' : 'approval.rejected', $who->actor(), $row->version, ['version' => $row->version->version_number, 'note' => $note]);

            $open = EngagementApproval::where('contract_version_id', $row->contract_version_id)->where('status', 'pending')->exists();
            $rejected = EngagementApproval::where('contract_version_id', $row->contract_version_id)->where('status', 'rejected')->exists();
            if (! $open && ! $rejected) {
                $engagement->forceFill(['agreed_version_id' => $row->contract_version_id, 'agreed_at' => now()])->save();
                $this->audit->record($engagement, 'terms.agreed', Actor::system('approvals complete'), $row->version, ['version' => $row->version->version_number]);
            }
        });

        $this->states->sync($engagement, $who->actor());
        $this->engagements->notifyOthers($engagement, $participant, 'approval_decided', $who->name().' '.($decision === 'approved' ? 'approved' : 'did not approve').' version '.$approval->version->version_number, route('engagements.show', $engagement).'#approvals', 'Open the agreement');

        return $approval->fresh();
    }

    /** For an agreement nobody needs to approve: the owner or a manager records that the terms are agreed. */
    public function recordAgreement(Contract $contract, EngagementAccess $who): void
    {
        $engagement = $this->engagementOf($contract);
        $version = $contract->currentVersionRecord();
        abort_unless($version, 422);

        if ($engagement->participants()->whereNull('revoked_at')->get()->contains(fn ($p) => $p->hasCapability('approve'))) {
            throw new InvalidArgumentException('This engagement has approvers. Ask for their approval instead.');
        }
        $engagement->forceFill(['agreed_version_id' => $version->id, 'agreed_at' => now()])->save();
        $this->audit->record($engagement, 'terms.agreed', $who->actor(), $version, ['version' => $version->version_number]);
        $this->states->sync($engagement, $who->actor());
    }

    private function engagementOf(Contract $contract): Engagement
    {
        return Engagement::withoutGlobalScopes()->findOrFail($contract->engagement_id);
    }

    private function fraction(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $v)), 6);
    }

    /** @param list<int> $mentionIds */
    private function notifyMentionedAndOthers(Engagement $engagement, ?EngagementParticipant $author, array $mentionIds, string $message): void
    {
        $url = route('engagements.show', $engagement);
        foreach ($mentionIds as $id) {
            $p = $engagement->participants()->find($id);
            if ($p && (! $author || $p->id !== $author->id)) {
                $this->engagements->notifyParticipant($engagement, $p, 'mention', $message.' and mentioned you', $url, 'Open the agreement');
            }
        }
        $this->engagements->notifyOthers($engagement, $author, 'document_note', $message, $url, 'Open the agreement', array_map('intval', $mentionIds));
    }
}

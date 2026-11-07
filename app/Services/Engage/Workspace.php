<?php

namespace App\Services\Engage;

use App\Enums\EngagementState;
use App\Models\Amendment;
use App\Models\DocumentComment;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Models\Meeting;
use App\Models\SigningEnvelope;

/**
 * Everything the workspace header shows, computed from records: the stage rail with a plain fact under each
 * stage, the "next" strip with the one action this viewer should take, and the numbers on the tabs.
 */
class Workspace
{
    public const STAGES = ['Engage', 'Discuss', 'Negotiate', 'Approve', 'Sign', 'Complete'];

    public function __construct(
        public readonly Engagement $engagement,
        public readonly EngagementAccess $access,
    ) {}

    /** @return list<array{name: string, fact: string, status: 'done'|'now'|'later'}> */
    public function stages(): array
    {
        $current = $this->engagement->state->stage();
        $facts = $this->stageFacts();
        $out = [];

        foreach (self::STAGES as $i => $name) {
            $status = $current === null ? ($i === 0 ? 'later' : 'later') : ($i < $current ? 'done' : ($i === $current ? 'now' : 'later'));
            if ($this->engagement->state === EngagementState::Completed) {
                $status = 'done';
            }
            $out[] = ['name' => $name, 'fact' => $facts[$i], 'status' => $status];
        }

        return $out;
    }

    /** @return list<string> */
    private function stageFacts(): array
    {
        $e = $this->engagement;
        $people = $e->participants()->whereNull('revoked_at')->whereNull('declined_at')->get();
        $required = $people->where('required', true);
        $joined = $required->whereNotNull('accepted_at')->count();
        $contract = $e->primaryContract();
        $versionId = $contract?->currentVersionRecord()?->id;

        $engage = $people->count() <= 1 && ! $people->contains(fn ($p) => $p->invitation_sent_at)
            ? 'Nobody invited yet'
            : $joined.' of '.$required->count().' joined';

        $live = Meeting::where('engagement_id', $e->id)->where('status', 'live')->exists();
        $next = Meeting::where('engagement_id', $e->id)->where('status', 'scheduled')->orderBy('scheduled_for')->first();
        $held = Meeting::where('engagement_id', $e->id)->where('status', 'ended')->count();
        $discuss = $live ? 'Meeting live now'
            : ($next ? 'Meeting '.($next->scheduled_for?->format('j M, H:i') ?? 'booked')
            : ($held ? $held.' '.str('meeting')->plural($held).' held' : 'No meeting yet'));

        $negotiate = 'No document yet';
        $approve = 'Not reached';
        if ($contract) {
            $notes = DocumentComment::where('contract_id', $contract->id)->whereNull('parent_id')->where('status', 'open')->count();
            $changes = Amendment::where('contract_id', $contract->id)->where('status', 'proposed')->count();
            $negotiate = ($notes + $changes) === 0 ? 'Nothing open' : trim(($notes ? $notes.' open '.str('note')->plural($notes) : '').($notes && $changes ? ', ' : '').($changes ? $changes.' '.str('change')->plural($changes).' to review' : ''));

            $rows = $versionId ? EngagementApproval::where('contract_version_id', $versionId)->get() : collect();
            if ($rows->isNotEmpty()) {
                $approve = $rows->where('status', 'approved')->count().' of '.$rows->count().' approved';
            } elseif ($e->agreed_version_id && $e->agreed_version_id === $versionId) {
                $approve = 'Terms agreed';
            } else {
                $approve = 'Not requested';
            }
        }

        $envelope = SigningEnvelope::where('engagement_id', $e->id)->whereIn('status', ['sent', 'completed'])->orderByDesc('id')->first();
        $sign = 'Not sent';
        if ($envelope) {
            $total = $envelope->signers()->whereNot('status', 'reassigned')->count();
            $sign = $envelope->signers()->where('status', 'signed')->count().' of '.$total.' signed';
        }

        $complete = $e->state === EngagementState::Completed ? 'Stored and verifiable'
            : ($e->state === EngagementState::FullySigned ? 'Preparing the final copy' : '—');

        return [$engage, $discuss, $negotiate, $approve, $sign, $complete];
    }

    /** @return array{label: string, url: string|null, note: string|null} */
    public function nextAction(): array
    {
        $e = $this->engagement;
        $can = fn (string $a) => $this->access->can($a);
        $tab = fn (string $t) => route('engagements.'.$t, $e);

        return match ($e->state) {
            EngagementState::Draft => $can('manage_participants')
                ? ['label' => 'Invite the other party', 'url' => $tab('people').'#invite', 'note' => null]
                : ['label' => 'Waiting for the owner to invite the other party', 'url' => null, 'note' => null],
            EngagementState::InvitationSent, EngagementState::AwaitingParticipants => $can('manage_participants')
                ? ['label' => 'See who has joined', 'url' => $tab('people'), 'note' => null]
                : ['label' => 'Say hello in the discussion', 'url' => $tab('discussion'), 'note' => null],
            EngagementState::DiscussionScheduled => ['label' => 'See the meeting', 'url' => $tab('meetings'), 'note' => null],
            EngagementState::InDiscussion => $e->contracts()->exists()
                ? ['label' => 'Open the agreement', 'url' => $tab('show'), 'note' => null]
                : ($can('manage_documents')
                    ? ['label' => 'Upload the agreement', 'url' => $tab('show').'#upload', 'note' => null]
                    : ['label' => 'Waiting for the agreement to be uploaded', 'url' => null, 'note' => null]),
            EngagementState::Negotiation => ['label' => 'Review the open items', 'url' => $tab('show'), 'note' => null],
            EngagementState::AwaitingAmendments => $can('manage_documents')
                ? ['label' => 'Create the revised version', 'url' => $tab('show').'#revise', 'note' => null]
                : ['label' => 'Waiting for the revised version', 'url' => null, 'note' => null],
            EngagementState::AwaitingApproval => ['label' => 'Review the approvals', 'url' => $tab('show').'#approvals', 'note' => null],
            EngagementState::ReadyForSigning => ['label' => 'Go to signing', 'url' => $tab('signing'), 'note' => null],
            EngagementState::PartiallySigned, EngagementState::FullySigned => ['label' => 'See signing progress', 'url' => $tab('signing'), 'note' => null],
            EngagementState::Completed => ['label' => 'Open the signed agreement', 'url' => $tab('signing'), 'note' => null],
            default => ['label' => 'Read the history', 'url' => $tab('activity'), 'note' => null],
        };
    }

    public function penFor(?int $partyId): int
    {
        $ids = $this->engagement->parties->pluck('id')->values();
        $i = $partyId === null ? 0 : (int) $ids->search($partyId);

        return ($i % 4) + 1;
    }
}

<?php

namespace App\Services\Engage;

use App\Enums\EngagementState;
use App\Models\Amendment;
use App\Models\DocumentComment;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Models\Meeting;
use App\Models\SigningEnvelope;
use App\Support\Engage\Actor;

/**
 * The state of an engagement is worked out from what has actually happened (invitations accepted, meetings held,
 * comments and amendments open or closed, approvals given, signatures captured). Nobody sets it by hand.
 * Call sync() after any event that could change it.
 */
class EngagementStateMachine
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function evaluate(Engagement $engagement): EngagementState
    {
        if ($engagement->cancelled_at) {
            return EngagementState::Cancelled;
        }
        if ($engagement->declined_at) {
            return EngagementState::Declined;
        }
        if ($engagement->expired_at) {
            return EngagementState::Expired;
        }
        if ($engagement->completed_at) {
            return EngagementState::Completed;
        }

        $envelope = SigningEnvelope::query()
            ->where('engagement_id', $engagement->id)
            ->whereIn('status', ['sent', 'completed'])
            ->orderByDesc('id')
            ->first();

        if ($envelope) {
            if ($envelope->finalised_at) {
                return EngagementState::Completed;
            }
            if ($envelope->all_signed_at) {
                return EngagementState::FullySigned;
            }
            if ($envelope->signers()->where('status', 'signed')->exists()) {
                return EngagementState::PartiallySigned;
            }

            return EngagementState::ReadyForSigning;
        }

        $contract = $engagement->primaryContract();
        $currentVersionId = $contract?->currentVersionRecord()?->id;

        if ($contract && $currentVersionId) {
            $openThreads = DocumentComment::where('contract_id', $contract->id)->whereNull('parent_id')->where('status', 'open')->exists();
            $openAmendments = Amendment::where('contract_id', $contract->id)->where('status', 'proposed')->exists();
            $rejected = EngagementApproval::where('contract_version_id', $currentVersionId)->where('status', 'rejected')->exists();

            if ($openThreads || $openAmendments || $rejected) {
                return EngagementState::Negotiation;
            }

            if (Amendment::where('contract_id', $contract->id)->where('status', 'accepted')->whereNull('applied_in_version_id')->exists()) {
                return EngagementState::AwaitingAmendments;
            }

            if (EngagementApproval::where('contract_version_id', $currentVersionId)->where('status', 'pending')->exists()) {
                return EngagementState::AwaitingApproval;
            }

            if ($engagement->agreed_version_id === $currentVersionId) {
                return EngagementState::ReadyForSigning;
            }
        }

        if (Meeting::where('engagement_id', $engagement->id)->where('status', 'live')->exists()) {
            return EngagementState::InDiscussion;
        }

        $joined = $engagement->participants()->whereNull('revoked_at')->whereNotNull('accepted_at')->where('role', '!=', 'owner')->exists();
        $discussed = $engagement->messages()->where('is_system', false)->exists()
            || Meeting::where('engagement_id', $engagement->id)->where('status', 'ended')->exists();

        if ($joined && $discussed) {
            return EngagementState::InDiscussion;
        }

        if (Meeting::where('engagement_id', $engagement->id)->where('status', 'scheduled')->where('scheduled_for', '>', now()->subMinutes(30))->exists()) {
            return EngagementState::DiscussionScheduled;
        }

        $invited = $engagement->participants()->whereNull('revoked_at')->whereNotNull('invitation_sent_at')->exists();

        if (! $invited) {
            return EngagementState::Draft;
        }

        $requiredOutstanding = $engagement->participants()
            ->whereNull('revoked_at')->whereNull('declined_at')
            ->where('required', true)->whereNull('accepted_at')->exists();

        if ($requiredOutstanding) {
            return $engagement->participants()->whereNull('revoked_at')->whereNotNull('invitation_sent_at')->whereNotNull('accepted_at')->where('role', 'external')->exists()
                ? EngagementState::AwaitingParticipants
                : EngagementState::InvitationSent;
        }

        // Everyone required has joined and nothing is pending: talk it through.
        return EngagementState::InDiscussion;
    }

    /** Re-evaluate and, when the state moved, store it and write the audit event. */
    public function sync(Engagement $engagement, ?Actor $actor = null): EngagementState
    {
        $engagement->refresh();
        $next = $this->evaluate($engagement);

        if ($engagement->state !== $next) {
            $from = $engagement->state;
            $engagement->forceFill(['state' => $next, 'state_changed_at' => now()])->save();
            $this->audit->record($engagement, 'engagement.state_changed', $actor ?? Actor::system(), $engagement, [
                'from' => $from->value,
                'to' => $next->value,
            ]);
        }

        return $next;
    }
}

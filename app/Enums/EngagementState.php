<?php

namespace App\Enums;

/**
 * Where an engagement stands. The value is always derived from recorded facts by
 * App\Services\Engage\EngagementStateMachine, never typed in by a person.
 */
enum EngagementState: string
{
    case Draft = 'draft';
    case InvitationSent = 'invitation_sent';
    case AwaitingParticipants = 'awaiting_participants';
    case DiscussionScheduled = 'discussion_scheduled';
    case InDiscussion = 'in_discussion';
    case Negotiation = 'negotiation';
    case AwaitingAmendments = 'awaiting_amendments';
    case AwaitingApproval = 'awaiting_approval';
    case ReadyForSigning = 'ready_for_signing';
    case PartiallySigned = 'partially_signed';
    case FullySigned = 'fully_signed';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InvitationSent => 'Invitation sent',
            self::AwaitingParticipants => 'Awaiting participants',
            self::DiscussionScheduled => 'Discussion scheduled',
            self::InDiscussion => 'In discussion',
            self::Negotiation => 'Negotiation',
            self::AwaitingAmendments => 'Awaiting amendments',
            self::AwaitingApproval => 'Awaiting approval',
            self::ReadyForSigning => 'Ready for signing',
            self::PartiallySigned => 'Partially signed',
            self::FullySigned => 'Fully signed',
            self::Completed => 'Completed',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** Which of the six stages on the rail this state belongs to (0 = Engage ... 5 = Complete), null when closed without agreement. */
    public function stage(): ?int
    {
        return match ($this) {
            self::Draft, self::InvitationSent, self::AwaitingParticipants => 0,
            self::DiscussionScheduled, self::InDiscussion => 1,
            self::Negotiation, self::AwaitingAmendments => 2,
            self::AwaitingApproval, self::ReadyForSigning => 3,
            self::PartiallySigned => 4,
            self::FullySigned, self::Completed => 5,
            default => null,
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Declined, self::Cancelled, self::Expired], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }

    /** What the person looking at the engagement should do next, in plain words. */
    public function nextStep(): string
    {
        return match ($this) {
            self::Draft => 'Add the other party and send the invitation.',
            self::InvitationSent => 'Waiting for the other party to open the invitation.',
            self::AwaitingParticipants => 'Waiting for the required participants to join.',
            self::DiscussionScheduled => 'A meeting is booked. Review the document before it starts.',
            self::InDiscussion => 'Talk it through, then record any changes as amendments.',
            self::Negotiation => 'Resolve the open comments and amendments.',
            self::AwaitingAmendments => 'Accepted changes need to go into a new version of the document.',
            self::AwaitingApproval => 'Approvers need to approve the current version.',
            self::ReadyForSigning => 'Prepare the signing request and send it.',
            self::PartiallySigned => 'Waiting for the remaining signatures.',
            self::FullySigned => 'Everyone has signed. The final document is being prepared.',
            self::Completed => 'The signed agreement is stored and can be verified.',
            self::Declined => 'A party declined. No further action is needed.',
            self::Cancelled => 'This engagement was cancelled.',
            self::Expired => 'This engagement expired before it was signed.',
        };
    }
}

<?php

namespace App\Support\Engage;

use App\Enums\EngagementState;
use App\Models\EngagementAuditEvent;

/** Plain-words descriptions of audit events, for the activity history everyone on an engagement can read. */
final class AuditLabels
{
    public static function describe(EngagementAuditEvent $e): string
    {
        $d = $e->data ?? [];

        return match ($e->event) {
            'engagement.created' => 'Created the engagement '.($d['reference'] ?? ''),
            'engagement.state_changed' => 'Status changed to '.(EngagementState::tryFrom($d['to'] ?? '')?->label() ?? ($d['to'] ?? '')),
            'engagement.cancelled' => 'Cancelled the engagement',
            'engagement.declined' => 'The engagement was declined',
            'engagement.expired' => 'The engagement expired',
            'participant.added' => 'Added '.($d['email'] ?? 'a participant'),
            'participant.invited' => 'Invited '.($d['email'] ?? 'a participant'),
            'participant.revoked' => 'Removed '.($d['email'] ?? 'a participant'),
            'participant.joined' => 'Joined the engagement',
            'participant.verified' => 'Confirmed their email address',
            'participant.code_sent' => 'Asked for a sign-in code',
            'participant.code_failed' => 'Entered a wrong sign-in code',
            'participant.declined_invitation' => 'Declined the invitation',
            'message.posted' => 'Posted in the discussion',
            'document.uploaded' => 'Uploaded '.($d['name'] ?? 'a document'),
            'document.version_created' => 'Created version '.($d['version'] ?? '').($d['reason'] ?? false ? ' ('.$d['reason'].')' : ''),
            'document.viewed' => 'Opened the document',
            'document.downloaded' => 'Downloaded the document',
            'comment.added' => 'Added a note'.(isset($d['page']) ? ' on page '.$d['page'] : ''),
            'comment.replied' => 'Replied to a note',
            'comment.resolved' => 'Resolved a note',
            'comment.reopened' => 'Reopened a note',
            'comment.discussed' => 'Marked a note as discussed',
            'amendment.proposed' => 'Proposed a change',
            'amendment.accepted' => 'Accepted a proposed change',
            'amendment.rejected' => 'Rejected a proposed change',
            'amendment.withdrawn' => 'Withdrew a proposed change',
            'approval.requested' => 'Asked for approval of version '.($d['version'] ?? ''),
            'approval.approved' => 'Approved version '.($d['version'] ?? ''),
            'approval.rejected' => 'Did not approve version '.($d['version'] ?? ''),
            'terms.agreed' => 'Terms agreed for version '.($d['version'] ?? ''),
            'meeting.scheduled' => 'Scheduled the meeting '.($d['title'] ?? ''),
            'meeting.started' => 'The meeting started',
            'meeting.ended' => 'The meeting ended',
            'meeting.joined' => 'Joined the meeting',
            'meeting.left' => 'Left the meeting',
            'meeting.admitted' => 'Admitted '.($d['name'] ?? 'a person').' to the meeting',
            'meeting.removed' => 'Removed '.($d['name'] ?? 'a person').' from the meeting',
            'meeting.locked' => 'Locked the meeting',
            'meeting.unlocked' => 'Unlocked the meeting',
            'meeting.cancelled' => 'Cancelled the meeting',
            'signing.prepared' => 'Prepared a signing request',
            'signing.sent' => 'Sent the signing request',
            'signing.viewed' => 'Opened the signing request',
            'signing.consented' => 'Agreed to sign electronically',
            'signing.signed' => 'Signed',
            'signing.declined' => 'Declined to sign',
            'signing.reminded' => 'A reminder was sent to '.($d['email'] ?? 'a signer'),
            'signing.reassigned' => 'Signing was reassigned',
            'signing.cancelled' => 'Cancelled the signing request',
            'signing.expired' => 'The signing request expired',
            'signing.all_signed' => 'Every required signature is in',
            'signing.finalised' => 'The signed agreement and its evidence record were stored',
            'signing.finalise_failed' => 'Preparing the signed copy failed and will be retried',
            'signing.verified' => 'The signed agreement was checked against its record',
            default => ucfirst(str_replace(['.', '_'], ' ', $e->event)),
        };
    }
}

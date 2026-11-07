<?php

namespace App\Enums;

/**
 * What a participant may do in an engagement. "Approve", "sign" and "host" are capabilities that
 * can be added to any role; the roles below are the base permission sets.
 */
enum ParticipantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Reviewer = 'reviewer';
    case Participant = 'participant';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Engagement owner',
            self::Admin => 'Business administrator',
            self::Manager => 'Contract manager',
            self::Reviewer => 'Legal reviewer',
            self::Participant => 'Participant',
            self::External => 'External party',
        };
    }

    public function isInternalKind(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Manager, self::Reviewer], true);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        $everyone = ['view', 'comment', 'message', 'propose', 'join_meeting'];

        return match ($this) {
            self::Owner, self::Admin => [...$everyone, 'manage_participants', 'manage_documents', 'review_amendment',
                'schedule_meeting', 'host_meeting', 'prepare_signing', 'send_signing', 'request_approval', 'cancel', 'view_audit'],
            self::Manager => [...$everyone, 'manage_documents', 'review_amendment', 'schedule_meeting', 'host_meeting',
                'prepare_signing', 'send_signing', 'request_approval', 'view_audit'],
            self::Reviewer => [...$everyone, 'review_amendment', 'request_approval', 'view_audit'],
            self::Participant, self::External => $everyone,
        };
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}

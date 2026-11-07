<?php

namespace App\Support\Engage;

use App\Models\EngagementParticipant;
use App\Models\User;
use Illuminate\Http\Request;

/** Who did something, captured for the audit trail. */
final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?int $id,
        public readonly string $label,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    public static function user(User $user, ?Request $request = null): self
    {
        return new self('user', $user->id, $user->name.' <'.$user->email.'>', $request?->ip(), self::ua($request));
    }

    public static function participant(EngagementParticipant $participant, ?Request $request = null): self
    {
        return new self('participant', $participant->id, $participant->name.' <'.$participant->email.'>', $request?->ip(), self::ua($request));
    }

    public static function system(string $what = 'system'): self
    {
        return new self('system', null, $what);
    }

    private static function ua(?Request $request): ?string
    {
        $ua = $request?->userAgent();

        return $ua === null ? null : mb_substr($ua, 0, 255);
    }
}

<?php

namespace App\Services\Engage;

use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Notifications\EngagementNotice;
use App\Notifications\VerificationCodeNotification;
use App\Support\Engage\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitation links and email codes for people without an account.
 *
 * The emailed link holds a random token (only its SHA-256 is stored). Opening it shows who invited the person and asks
 * for a one-time code sent to the invited address; only after that does the browser session hold access, and
 * that access reaches exactly one engagement. A link can be revoked, replaced or left to expire.
 */
class ParticipantAccess
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** Mint a fresh link, store its hash, and return the plain token (shown once, in the email). */
    public function issueToken(EngagementParticipant $participant): string
    {
        $token = Str::random(64);

        $participant->forceFill([
            'access_token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addDays((int) config('engage.invitation_days')),
            'revoked_at' => null,
        ])->save();

        return $token;
    }

    public function findByToken(string $token): ?EngagementParticipant
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return EngagementParticipant::query()
            ->where('access_token_hash', hash('sha256', $token))
            ->first();
    }

    public function tokenUsable(?EngagementParticipant $participant): bool
    {
        return $participant !== null
            && $participant->isActive()
            && $participant->token_expires_at !== null
            && $participant->token_expires_at->isFuture();
    }

    public function sendInvitation(EngagementParticipant $participant, Engagement $engagement, string $invitedByName, Actor $actor): void
    {
        $token = $this->issueToken($participant);
        $url = route('join.show', ['token' => $token]);

        Notification::route('mail', $participant->email)->notify(new EngagementNotice(
            $engagement,
            'engagement_invitation',
            $invitedByName.' invited you to an engagement',
            $url,
            'Open the invitation',
            emailOnly: true,
        ));

        $participant->forceFill(['invitation_sent_at' => now()])->save();
        $this->audit->record($engagement, 'participant.invited', $actor, $participant, ['email' => $participant->email, 'role' => $participant->role->value]);
    }

    public function revoke(EngagementParticipant $participant, Engagement $engagement, Actor $actor): void
    {
        $participant->forceFill([
            'revoked_at' => now(),
            'access_token_hash' => null,
            'otp_hash' => null,
        ])->save();

        $this->audit->record($engagement, 'participant.revoked', $actor, $participant, ['email' => $participant->email]);
    }

    /**
     * Email a fresh code. Returns the seconds to wait when asked again too soon, or 0 when sent.
     */
    public function sendCode(EngagementParticipant $participant, Engagement $engagement): int
    {
        $wait = (int) config('engage.code_resend_seconds');
        if ($participant->otp_sent_at && $participant->otp_sent_at->gt(now()->subSeconds($wait))) {
            return max(1, $wait - (int) $participant->otp_sent_at->diffInSeconds(now()));
        }

        $code = (string) random_int(100000, 999999);
        $minutes = (int) config('engage.code_minutes');

        $participant->forceFill([
            'otp_hash' => $this->hashCode($participant, $code),
            'otp_sent_at' => now(),
            'otp_expires_at' => now()->addMinutes($minutes),
            'otp_attempts' => 0,
        ])->save();

        Notification::route('mail', $participant->email)->notify(new VerificationCodeNotification($engagement, $code, $minutes));
        $this->audit->record($engagement, 'participant.code_sent', Actor::participant($participant), $participant);

        return 0;
    }

    /** @return 'ok'|'wrong'|'expired'|'locked'|'none' */
    public function verifyCode(EngagementParticipant $participant, Engagement $engagement, string $code, Request $request): string
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (! $participant->otp_hash || ! $participant->otp_expires_at) {
            return 'none';
        }
        if ($participant->otp_expires_at->isPast()) {
            return 'expired';
        }
        if ($participant->otp_attempts >= (int) config('engage.code_attempts')) {
            return 'locked';
        }

        $participant->increment('otp_attempts');

        if (! hash_equals($participant->otp_hash, $this->hashCode($participant, $code))) {
            $this->audit->record($engagement, 'participant.code_failed', Actor::participant($participant, $request), $participant);

            return 'wrong';
        }

        $first = $participant->accepted_at === null;
        $participant->forceFill([
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'verified_at' => now(),
            'accepted_at' => $participant->accepted_at ?? now(),
            'last_seen_at' => now(),
        ])->save();

        $request->session()->regenerate();
        $held = (array) $request->session()->get(EngagementAccess::SESSION_KEY, []);
        $held[$engagement->id] = $participant->id;
        $request->session()->put(EngagementAccess::SESSION_KEY, $held);

        $this->audit->record($engagement, $first ? 'participant.joined' : 'participant.verified', Actor::participant($participant, $request), $participant, ['method' => 'email_code']);

        return 'ok';
    }

    private function hashCode(EngagementParticipant $participant, string $code): string
    {
        return hash_hmac('sha256', $participant->id.':'.$code, (string) config('app.key'));
    }
}

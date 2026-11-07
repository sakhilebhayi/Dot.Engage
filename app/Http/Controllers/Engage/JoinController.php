<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\EngagementParticipant;
use App\Models\User;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\EngagementService;
use App\Services\Engage\ParticipantAccess;
use App\Support\Engage\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The page behind an emailed invitation link. It shows who invited the person and why, never any content, and lets
 * them request a one-time code. A token that is wrong, expired or revoked gets one neutral page that says so.
 */
class JoinController extends Controller
{
    public function __construct(private readonly ParticipantAccess $access) {}

    private function resolve(string $token): ?EngagementParticipant
    {
        $participant = $this->access->findByToken($token);

        return $this->access->tokenUsable($participant) ? $participant : null;
    }

    public function show(Request $request, string $token)
    {
        $participant = $this->resolve($token);
        if (! $participant) {
            return response()->view('join.invalid', [], 410);
        }

        $engagement = $participant->engagement()->withoutGlobalScopes()->first();
        $invitedBy = $participant->invited_by ? User::find($participant->invited_by)?->name : null;

        return view('join.show', [
            'token' => $token,
            'participant' => $participant,
            'engagement' => $engagement,
            'invitedBy' => $invitedBy,
            'host' => $engagement->parties()->where('is_host', true)->first(),
            'masked' => $this->mask($participant->email),
            'codeSent' => $request->session()->pull('join.code_sent.'.$participant->id, false),
        ]);
    }

    public function sendCode(Request $request, string $token): RedirectResponse
    {
        $participant = $this->resolve($token);
        abort_unless($participant, 410);
        $engagement = $participant->engagement()->withoutGlobalScopes()->first();

        $wait = $this->access->sendCode($participant, $engagement);
        $request->session()->put('join.code_sent.'.$participant->id, true);

        return redirect()->route('join.show', $token)->with('status', $wait > 0
            ? 'A code was sent a moment ago. You can ask for another in '.$wait.' seconds.'
            : 'We sent a six-digit code to '.$this->mask($participant->email).'.');
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $participant = $this->resolve($token);
        abort_unless($participant, 410);
        $engagement = $participant->engagement()->withoutGlobalScopes()->first();

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $result = $this->access->verifyCode($participant, $engagement, $data['code'], $request);

        if ($result === 'ok') {
            return redirect()->route('engagements.show', $engagement);
        }

        $message = match ($result) {
            'wrong' => 'That code is not right. Check the latest email and try again.',
            'expired' => 'That code has expired. Ask for a new one.',
            'locked' => 'Too many wrong tries. Ask for a new code.',
            default => 'Ask for a code first.',
        };
        $request->session()->put('join.code_sent.'.$participant->id, true);

        return redirect()->route('join.show', $token)->withErrors(['code' => $message]);
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        $participant = $this->resolve($token);
        abort_unless($participant, 410);
        $engagement = $participant->engagement()->withoutGlobalScopes()->first();

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $participant->forceFill(['declined_at' => now(), 'decline_reason' => $data['reason'] ?? null])->save();
        app(AuditTrail::class)->record($engagement, 'participant.declined_invitation', Actor::participant($participant, $request), $participant, ['reason' => $data['reason'] ?? null]);
        app(EngagementService::class)->notifyOthers($engagement, $participant, 'invitation_declined', $participant->name.' declined the invitation', route('engagements.people', $engagement));

        return redirect()->route('join.show', $token)->with('status', 'You declined. The owner has been told.');
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }
}

<?php

namespace App\Services\Engage;

use App\Models\Contract;
use App\Models\Engagement;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingSignal;
use App\Notifications\EngagementNotice;
use App\Support\Engage\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Meetings tied to an engagement. Browsers connect to each other directly (WebRTC); this service is the meeting's
 * memory: who may be in the room, who is actually in it right now (a browser that stops reporting is not "in"), and the
 * short-lived messages two browsers pass to set up a connection.
 */
class MeetingService
{
    /** A joined person unseen this long is dropped from the roster. */
    private const LOST_DROP_SECONDS = 90;

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
        private readonly EngagementService $engagements,
        private readonly ParticipantAccess $access,
    ) {}

    public function create(Engagement $engagement, EngagementAccess $who, string $title, ?Carbon $at, int $minutes = 45, ?int $contractId = null): Meeting
    {
        if (! $engagement->isOpen()) {
            throw new InvalidArgumentException('This engagement is closed.');
        }
        if ($at && $at->lt(now()->subMinutes(5))) {
            throw new InvalidArgumentException('Pick a time in the future.');
        }
        if ($contractId && ! Contract::withoutGlobalScopes()->where('engagement_id', $engagement->id)->whereKey($contractId)->exists()) {
            throw new InvalidArgumentException('That document is not part of this engagement.');
        }

        $meeting = Meeting::create([
            'engagement_id' => $engagement->id,
            'created_by' => $who->user?->id,
            'title' => mb_substr($title, 0, 160) ?: 'Meeting',
            'kind' => $at ? 'scheduled' : 'instant',
            'status' => 'scheduled',
            'provider' => 'native',
            'room_key' => Str::random(32),
            'scheduled_for' => $at ?? now(),
            'duration_minutes' => max(10, min(480, $minutes)),
            'waiting_room' => (bool) config('engage.rtc.waiting_room'),
            'contract_id' => $contractId ?: $engagement->primaryContract()?->id,
        ]);

        $this->audit->record($engagement, 'meeting.scheduled', $who->actor(), $meeting, ['title' => $meeting->title, 'at' => $meeting->scheduled_for->toIso8601String(), 'kind' => $meeting->kind]);
        $this->states->sync($engagement, $who->actor());

        $when = $at ? $at->copy()->utc()->format('j M Y H:i').' UTC' : 'now';
        $invitees = $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')
            ->where(fn ($q) => $q->whereNotNull('invitation_sent_at')->orWhereNotNull('user_id'))->get();
        foreach ($invitees as $p) {
            if ($who->participant && $p->id === $who->participant->id) {
                continue;
            }
            $this->inviteTo($engagement, $meeting, $p, $who->name().' invited you to a meeting: '.$meeting->title.' ('.$when.')');
        }

        return $meeting;
    }

    public function inviteTo(Engagement $engagement, Meeting $meeting, $participant, string $message): void
    {
        if ($participant->user_id && $participant->user) {
            $participant->user->notify(new EngagementNotice($engagement, 'meeting_invited', $message, route('meetings.room', $meeting->room_key), 'Join the meeting'));

            return;
        }
        $token = $this->access->issueToken($participant);
        Notification::route('mail', $participant->email)->notify(new EngagementNotice(
            $engagement, 'meeting_invited', $message, route('join.show', ['token' => $token, 'to' => 'meeting:'.$meeting->room_key]), 'Join the meeting', emailOnly: true,
        ));
    }

    public function cancel(Meeting $meeting, EngagementAccess $who): void
    {
        if ($meeting->isOver() || $meeting->isLive()) {
            throw new InvalidArgumentException('Only a meeting that has not started can be cancelled.');
        }
        $engagement = $meeting->engagement()->withoutGlobalScopes()->first();
        $meeting->forceFill(['status' => 'cancelled', 'ended_at' => now(), 'ended_reason' => 'cancelled'])->save();
        $this->audit->record($engagement, 'meeting.cancelled', $who->actor(), $meeting, ['title' => $meeting->title]);
        $this->states->sync($engagement, $who->actor());
        $this->engagements->notifyOthers($engagement, $who->participant, 'meeting_cancelled', 'The meeting '.$meeting->title.' was cancelled', route('engagements.meetings', $engagement));
    }

    // ── Being in the room ────────────────────────────────────────────────────

    /** Ask to come in. Returns the attendee row; its state says whether they are waiting, admitted or refused. */
    public function enter(Meeting $meeting, EngagementAccess $who, string $clientId): MeetingAttendee
    {
        if ($meeting->isOver()) {
            throw new InvalidArgumentException('This meeting has ended.');
        }
        $engagement = $meeting->engagement()->withoutGlobalScopes()->first();
        $participant = $who->participantRecord();
        $isHost = $who->can('host_meeting');

        return DB::transaction(function () use ($meeting, $who, $clientId, $participant, $isHost) {
            $locked = Meeting::whereKey($meeting->id)->lockForUpdate()->first();
            $attendee = MeetingAttendee::where('meeting_id', $locked->id)->where(fn ($q) => $participant ? $q->where('participant_id', $participant->id) : $q->where('user_id', $who->user?->id))->first();

            if ($attendee && $attendee->state === 'removed') {
                throw new InvalidArgumentException('The host removed you from this meeting.');
            }
            if ($locked->locked && ! $isHost && (! $attendee || ! in_array($attendee->state, ['admitted', 'joined', 'left'], true))) {
                throw new InvalidArgumentException('The host has locked this meeting.');
            }

            $attendee ??= new MeetingAttendee(['meeting_id' => $locked->id, 'participant_id' => $participant?->id, 'user_id' => $who->user?->id, 'name' => $who->name()]);
            $attendee->forceFill(['is_host' => $isHost, 'client_id' => $clientId, 'last_seen_at' => now(), 'name' => $who->name()]);

            // A host walks in. Everyone else is admitted when the host is present, or waits.
            $hostPresent = MeetingAttendee::where('meeting_id', $locked->id)->where('is_host', true)->where('state', 'joined')->where('last_seen_at', '>', now()->subSeconds(MeetingAttendee::PRESENCE_SECONDS))->exists();
            $alreadyIn = in_array($attendee->state, ['admitted', 'joined', 'left'], true) && $attendee->exists;
            if ($isHost || $alreadyIn || (! $locked->waiting_room && $hostPresent)) {
                $attendee->state = $alreadyIn && $attendee->state === 'joined' ? 'joined' : 'admitted';
            } elseif ($attendee->state !== 'denied') {
                $attendee->state = 'waiting';
                $attendee->requested_at = $attendee->requested_at ?? now();
            }
            $attendee->save();

            return $attendee;
        });
    }

    /** Become part of the call once the browser has its camera and microphone sorted. */
    public function join(Meeting $meeting, MeetingAttendee $attendee, EngagementAccess $who): MeetingAttendee
    {
        $engagement = $meeting->engagement()->withoutGlobalScopes()->first();

        return DB::transaction(function () use ($meeting, $attendee, $who, $engagement) {
            $locked = Meeting::whereKey($meeting->id)->lockForUpdate()->first();
            $row = MeetingAttendee::whereKey($attendee->id)->lockForUpdate()->first();

            if ($locked->isOver()) {
                throw new InvalidArgumentException('This meeting has ended.');
            }
            if (! in_array($row->state, ['admitted', 'joined', 'left'], true)) {
                throw new InvalidArgumentException($row->state === 'waiting' ? 'You are in the waiting room.' : 'You cannot join this meeting.');
            }

            $present = MeetingAttendee::where('meeting_id', $locked->id)->where('state', 'joined')->where('id', '!=', $row->id)->where('last_seen_at', '>', now()->subSeconds(MeetingAttendee::PRESENCE_SECONDS))->count();
            if ($present >= (int) config('engage.rtc.max_participants')) {
                throw new InvalidArgumentException('This meeting is full ('.config('engage.rtc.max_participants').' people).');
            }

            $first = $locked->status !== 'live';
            if ($first) {
                $locked->forceFill(['status' => 'live', 'started_at' => $locked->started_at ?? now()])->save();
            }
            $row->forceFill(['state' => 'joined', 'joined_at' => now(), 'left_at' => null, 'last_seen_at' => now(), 'join_count' => $row->join_count + 1])->save();

            if ($first) {
                $this->audit->record($engagement, 'meeting.started', $who->actor(), $locked, ['title' => $locked->title]);
            }
            $this->audit->record($engagement, 'meeting.joined', $who->actor(), $locked, ['name' => $row->name]);

            return $row;
        });
    }

    public function leave(Meeting $meeting, MeetingAttendee $attendee, EngagementAccess $who): void
    {
        $engagement = $meeting->engagement()->withoutGlobalScopes()->first();
        if ($attendee->state === 'joined') {
            $attendee->forceFill(['state' => 'left', 'left_at' => now(), 'mic_on' => false, 'cam_on' => false, 'sharing' => false])->save();
            $this->audit->record($engagement, 'meeting.left', $who->actor(), $meeting, ['name' => $attendee->name]);
        }
        $this->endIfEmpty($meeting);
    }

    /** The host (or the last person leaving) ends it. */
    public function end(Meeting $meeting, EngagementAccess $who, string $reason = 'host_ended'): void
    {
        if ($meeting->isOver()) {
            return;
        }
        $engagement = $meeting->engagement()->withoutGlobalScopes()->first();
        DB::transaction(function () use ($meeting, $reason) {
            $meeting->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => $reason])->save();
            MeetingAttendee::where('meeting_id', $meeting->id)->whereIn('state', ['joined', 'admitted', 'waiting'])->update(['state' => 'left', 'left_at' => now(), 'mic_on' => false, 'cam_on' => false, 'sharing' => false]);
            MeetingSignal::where('meeting_id', $meeting->id)->delete();
        });
        $this->audit->record($engagement, 'meeting.ended', $who->actor(), $meeting, ['reason' => $reason]);
        $this->states->sync($engagement, $who->actor());
    }

    private function endIfEmpty(Meeting $meeting): void
    {
        $meeting->refresh();
        if ($meeting->isLive() && ! MeetingAttendee::where('meeting_id', $meeting->id)->where('state', 'joined')->where('last_seen_at', '>', now()->subSeconds(MeetingAttendee::PRESENCE_SECONDS))->exists()) {
            $engagement = $meeting->engagement()->withoutGlobalScopes()->first();
            $meeting->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => 'empty'])->save();
            $this->audit->record($engagement, 'meeting.ended', Actor::system('last person left'), $meeting, ['reason' => 'empty']);
            $this->states->sync($engagement, Actor::system('meeting'));
        }
    }

    // ── What the browser polls ───────────────────────────────────────────────

    public function touch(MeetingAttendee $attendee, array $state = []): void
    {
        $attendee->forceFill(array_filter([
            'last_seen_at' => now(),
            'mic_on' => isset($state['mic']) ? (bool) $state['mic'] : null,
            'cam_on' => isset($state['cam']) ? (bool) $state['cam'] : null,
            'sharing' => isset($state['sharing']) ? (bool) $state['sharing'] : null,
            'quality' => isset($state['quality']) && in_array($state['quality'], ['good', 'fair', 'poor'], true) ? $state['quality'] : null,
        ], fn ($v) => $v !== null))->save();
    }

    /** @return array<string, mixed> */
    public function snapshot(Meeting $meeting, MeetingAttendee $me, int $since): array
    {
        $meeting->refresh();
        $me->refresh();

        // Someone whose tab died stays "not responding" for a while, then drops off the list.
        MeetingAttendee::where('meeting_id', $meeting->id)->where('state', 'joined')->where('id', '!=', $me->id)
            ->where('last_seen_at', '<', now()->subSeconds(self::LOST_DROP_SECONDS))
            ->update(['state' => 'left', 'left_at' => now(), 'mic_on' => false, 'cam_on' => false, 'sharing' => false]);

        $roster = MeetingAttendee::where('meeting_id', $meeting->id)->whereIn('state', ['waiting', 'joined', 'admitted'])->orderBy('id')->get()
            ->map(fn (MeetingAttendee $a) => [
                'id' => $a->id, 'name' => $a->name, 'is_host' => $a->is_host, 'state' => $a->state === 'joined' && ! $a->isPresent() ? 'lost' : $a->state,
                'mic' => $a->mic_on, 'cam' => $a->cam_on, 'sharing' => $a->sharing, 'quality' => $a->quality, 'me' => $a->id === $me->id,
            ])->values();

        $signals = MeetingSignal::where('meeting_id', $meeting->id)
            ->where('id', '>', $since)
            ->where(fn ($q) => $q->where('to_attendee_id', $me->id)->orWhere(fn ($q) => $q->whereNull('to_attendee_id')->where('from_attendee_id', '!=', $me->id)))
            ->orderBy('id')->limit(200)->get(['id', 'from_attendee_id', 'type', 'payload']);

        return [
            'meeting' => ['status' => $meeting->status, 'locked' => $meeting->locked, 'title' => $meeting->title, 'ended_reason' => $meeting->ended_reason, 'waiting_room' => $meeting->waiting_room],
            'me' => ['id' => $me->id, 'state' => $me->state, 'is_host' => $me->is_host],
            'roster' => $roster,
            'signals' => $signals->map(fn ($s) => ['id' => $s->id, 'from' => $s->from_attendee_id, 'type' => $s->type, 'payload' => $s->payload])->values(),
            'last_id' => (int) ($signals->last()->id ?? $since),
        ];
    }

    public function signal(Meeting $meeting, MeetingAttendee $from, ?int $to, string $type, string $payload): MeetingSignal
    {
        if (! in_array($type, ['offer', 'answer', 'candidate', 'state', 'chat'], true)) {
            throw new InvalidArgumentException('Unknown signal.');
        }
        if (strlen($payload) > 24_000) {
            throw new InvalidArgumentException('That signal is too large.');
        }
        if ($from->state !== 'joined') {
            throw new InvalidArgumentException('Join the meeting first.');
        }
        if ($type === 'state' && ! $from->is_host && str_contains($payload, 'command')) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && array_key_exists('command', $decoded)) {
                throw new InvalidArgumentException('Only the host can send that.');
            }
        }
        if ($to !== null && ! MeetingAttendee::where('meeting_id', $meeting->id)->where('state', 'joined')->whereKey($to)->exists()) {
            throw new InvalidArgumentException('That person is not in the meeting.');
        }

        return MeetingSignal::create(['meeting_id' => $meeting->id, 'from_attendee_id' => $from->id, 'to_attendee_id' => $to, 'type' => $type, 'payload' => $payload, 'created_at' => now()]);
    }

    // ── Host controls ────────────────────────────────────────────────────────

    public function host(Meeting $meeting, EngagementAccess $who, string $action, ?MeetingAttendee $target): void
    {
        $engagement = $meeting->engagement()->withoutGlobalScopes()->first();

        match ($action) {
            'lock' => $this->setLock($meeting, true, $who, $engagement),
            'unlock' => $this->setLock($meeting, false, $who, $engagement),
            'end' => $this->end($meeting, $who),
            default => $this->targeted($meeting, $who, $engagement, $action, $target),
        };
    }

    private function setLock(Meeting $meeting, bool $locked, EngagementAccess $who, Engagement $engagement): void
    {
        $meeting->forceFill(['locked' => $locked])->save();
        $this->audit->record($engagement, $locked ? 'meeting.locked' : 'meeting.unlocked', $who->actor(), $meeting);
    }

    private function targeted(Meeting $meeting, EngagementAccess $who, Engagement $engagement, string $action, ?MeetingAttendee $target): void
    {
        if (! $target || $target->meeting_id !== $meeting->id) {
            throw new InvalidArgumentException('Pick a person in this meeting.');
        }

        switch ($action) {
            case 'admit':
                if ($target->state === 'waiting' || $target->state === 'denied') {
                    $target->forceFill(['state' => 'admitted'])->save();
                    $this->audit->record($engagement, 'meeting.admitted', $who->actor(), $meeting, ['name' => $target->name]);
                }
                break;
            case 'deny':
                $target->forceFill(['state' => 'denied'])->save();
                break;
            case 'remove':
                if ($target->is_host && ! $who->can('host_meeting')) {
                    throw new InvalidArgumentException('You cannot remove the host.');
                }
                $target->forceFill(['state' => 'removed', 'left_at' => now(), 'mic_on' => false, 'cam_on' => false, 'sharing' => false])->save();
                MeetingSignal::create(['meeting_id' => $meeting->id, 'from_attendee_id' => $this->hostRow($meeting, $who)->id, 'to_attendee_id' => $target->id, 'type' => 'state', 'payload' => json_encode(['command' => 'removed']), 'created_at' => now()]);
                $this->audit->record($engagement, 'meeting.removed', $who->actor(), $meeting, ['name' => $target->name]);
                break;
            case 'mute':
                MeetingSignal::create(['meeting_id' => $meeting->id, 'from_attendee_id' => $this->hostRow($meeting, $who)->id, 'to_attendee_id' => $target->id, 'type' => 'state', 'payload' => json_encode(['command' => 'mute']), 'created_at' => now()]);
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
    }

    private function hostRow(Meeting $meeting, EngagementAccess $who): MeetingAttendee
    {
        $participant = $who->participantRecord();

        return MeetingAttendee::where('meeting_id', $meeting->id)->where(fn ($q) => $participant ? $q->where('participant_id', $participant->id) : $q->where('user_id', $who->user?->id))->firstOrFail();
    }

    // ── Housekeeping (scheduler) ─────────────────────────────────────────────

    /** @return array{ended: int, signals: int} */
    public function cleanUp(): array
    {
        $ended = 0;
        $empty = (int) config('engage.rtc.empty_minutes');
        $maxH = (int) config('engage.rtc.max_hours');

        foreach (Meeting::where('status', 'live')->get() as $m) {
            $anyone = MeetingAttendee::where('meeting_id', $m->id)->where('state', 'joined')->where('last_seen_at', '>', now()->subMinutes($empty))->exists();
            $tooLong = $m->started_at && $m->started_at->lt(now()->subHours($maxH));
            if (! $anyone || $tooLong) {
                $this->endQuietly($m, $tooLong ? 'expired' : 'empty');
                $ended++;
            }
        }
        foreach (Meeting::where('status', 'scheduled')->where('scheduled_for', '<', now()->subMinutes(30)->subMinutes(0))->get() as $m) {
            if ($m->scheduled_for->copy()->addMinutes($m->duration_minutes + 60)->isPast()) {
                $this->endQuietly($m, 'expired');
                $ended++;
            }
        }
        $signals = MeetingSignal::where('created_at', '<', now()->subHour())->delete();

        return ['ended' => $ended, 'signals' => $signals];
    }

    private function endQuietly(Meeting $m, string $reason): void
    {
        $engagement = $m->engagement()->withoutGlobalScopes()->first();
        $m->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => $reason])->save();
        MeetingAttendee::where('meeting_id', $m->id)->whereIn('state', ['joined', 'admitted', 'waiting'])->update(['state' => 'left', 'left_at' => now(), 'mic_on' => false, 'cam_on' => false, 'sharing' => false]);
        if ($engagement) {
            $this->audit->record($engagement, 'meeting.ended', Actor::system('scheduler'), $m, ['reason' => $reason]);
            $this->states->sync($engagement, Actor::system('scheduler'));
        }
    }
}

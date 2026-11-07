<?php

namespace App\Http\Controllers\Engage;

use App\Enums\EngagementState;
use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\IceConfig;
use App\Services\Engage\MeetingService;
use App\Services\Engage\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MeetingController extends Controller
{
    public function __construct(private readonly MeetingService $meetings) {}

    private function resolve(Request $request, string $roomKey): array
    {
        $meeting = Meeting::where('room_key', $roomKey)->firstOrFail();
        $engagement = Engagement::withoutGlobalScopes()->findOrFail($meeting->engagement_id);
        $access = EngagementAccess::resolve($request, $engagement);
        abort_if($access === null, $request->user() ? 404 : 403);
        abort_unless($access->can('join_meeting') || $meeting->isOver(), 403, 'Your role does not allow that.');

        return [$meeting, $engagement, $access];
    }

    private function attendee(Request $request, Meeting $meeting, EngagementAccess $access): MeetingAttendee
    {
        $participant = $access->participantRecord();
        $row = MeetingAttendee::where('meeting_id', $meeting->id)->where(fn ($q) => $participant ? $q->where('participant_id', $participant->id) : $q->where('user_id', $access->user?->id))->first();
        abort_unless($row, 409, 'Enter the meeting first.');

        // A second tab or device replaces the first one, so nobody is in the call twice.
        $client = (string) $request->header('X-Client-Id', $request->input('client_id', ''));
        abort_if($client !== '' && $row->client_id !== null && $row->client_id !== $client, 409, 'You joined this meeting from another window.');

        return $row;
    }

    // ── Lists ────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $team = $request->user()->currentTeam;
        abort_unless($team, 403);
        $base = Meeting::with('engagement')->whereHas('engagement', fn ($q) => $q->where('team_id', $team->id));

        return view('meetings.index', [
            'live' => (clone $base)->where('status', 'live')->get(),
            'upcoming' => (clone $base)->where('status', 'scheduled')->orderBy('scheduled_for')->get(),
            'past' => (clone $base)->whereIn('status', ['ended', 'cancelled'])->orderByDesc('ended_at')->limit(20)->get(),
            'engagements' => Engagement::where('team_id', $team->id)->whereIn('state', array_map(fn ($s) => $s->value, EngagementState::open()))->orderBy('title')->get(),
        ]);
    }

    /** Create a meeting inside an engagement: now ("instant") or at a time. */
    public function store(Request $request, Engagement $engagement): RedirectResponse
    {
        $access = EngagementAccess::resolve($request, $engagement);
        abort_if($access === null, 404);
        abort_unless($access->can('schedule_meeting'), 403, 'Your role does not allow that.');

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'when' => ['nullable', 'date'],
            'tz_offset' => ['nullable', 'integer', 'between:-840,840'],
            'minutes' => ['nullable', 'integer', 'min:10', 'max:480'],
        ]);

        $at = null;
        if (! empty($data['when'])) {
            // The browser sends the local time and its offset from UTC, so a person in Johannesburg means Johannesburg time.
            $at = Carbon::parse($data['when'], 'UTC')->addMinutes((int) ($data['tz_offset'] ?? 0));
        }

        try {
            $meeting = $this->meetings->create($engagement, $access, trim($data['title'] ?? '') ?: ($at ? 'Meeting' : 'Quick meeting'), $at, (int) ($data['minutes'] ?? 45));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['meeting' => $e->getMessage()]);
        }

        return $at
            ? redirect()->route('engagements.meetings', $engagement)->with('status', 'Meeting scheduled. Everyone invited has been told.')
            : redirect()->route('meetings.room', $meeting->room_key);
    }

    public function cancel(Request $request, string $roomKey): RedirectResponse
    {
        [$meeting, $engagement, $access] = $this->resolve($request, $roomKey);
        abort_unless($access->can('schedule_meeting'), 403);

        try {
            $this->meetings->cancel($meeting, $access);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['meeting' => $e->getMessage()]);
        }

        return redirect()->route('engagements.meetings', $engagement)->with('status', 'Meeting cancelled.');
    }

    // ── The room ─────────────────────────────────────────────────────────────

    public function room(Request $request, string $roomKey)
    {
        [$meeting, $engagement, $access] = $this->resolve($request, $roomKey);
        if ($meeting->isOver()) {
            return response()->view('meetings.over', ['meeting' => $meeting, 'engagement' => $engagement, 'access' => $access], 410);
        }

        $ice = app(IceConfig::class)->forUser($access->name());

        return view('meetings.room', [
            'meeting' => $meeting, 'engagement' => $engagement->load('parties'), 'access' => $access,
            'ws' => new Workspace($engagement, $access),
            'config' => [
                'key' => $meeting->room_key, 'me' => $access->name(), 'isHost' => $access->can('host_meeting'), 'relay' => $ice['relay'], 'ice' => $ice['servers'],
                'maxParticipants' => (int) config('engage.rtc.max_participants'),
                'urls' => [
                    'enter' => route('meetings.enter', $meeting->room_key), 'poll' => route('meetings.poll', $meeting->room_key),
                    'join' => route('meetings.join', $meeting->room_key), 'signal' => route('meetings.signal', $meeting->room_key),
                    'state' => route('meetings.state', $meeting->room_key), 'leave' => route('meetings.leave', $meeting->room_key),
                    'host' => route('meetings.host', $meeting->room_key), 'ice' => route('meetings.ice', $meeting->room_key),
                    'agreement' => route('engagements.show', ['engagement' => $engagement, 'embed' => 1]),
                    'sign' => route('engagements.sign', ['engagement' => $engagement, 'embed' => 1]),
                    'leaveTo' => $access->isInternal() ? route('engagements.meetings', $engagement) : route('engagements.show', $engagement),
                ],
                'canSign' => $access->can('sign') && $engagement->envelopes()->where('status', 'sent')->exists(),
                'hasDocument' => $engagement->contracts()->exists(),
            ],
        ]);
    }

    // ── JSON API used by the room ────────────────────────────────────────────

    public function enter(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $client = (string) $request->validate(['client_id' => ['required', 'string', 'max:40']])['client_id'];

        try {
            $a = $this->meetings->enter($meeting, $access, $client);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['attendee_id' => $a->id, 'state' => $a->state, 'is_host' => $a->is_host]);
    }

    public function poll(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $me = $this->attendee($request, $meeting, $access);
        $this->meetings->touch($me);

        return response()->json($this->meetings->snapshot($meeting, $me, (int) $request->query('since', 0)))->header('Cache-Control', 'no-store');
    }

    public function join(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $me = $this->attendee($request, $meeting, $access);

        try {
            $this->meetings->join($meeting, $me, $access);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $ice = app(IceConfig::class)->forUser($access->name());

        return response()->json(['ice' => $ice['servers'], 'relay' => $ice['relay'], 'attendee_id' => $me->id])->header('Cache-Control', 'no-store');
    }

    public function ice(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $this->attendee($request, $meeting, $access);
        $ice = app(IceConfig::class)->forUser($access->name());

        return response()->json(['ice' => $ice['servers'], 'relay' => $ice['relay']])->header('Cache-Control', 'no-store');
    }

    public function signal(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $me = $this->attendee($request, $meeting, $access);
        $data = $request->validate(['to' => ['nullable', 'integer'], 'type' => ['required', 'string', 'max:16'], 'payload' => ['required', 'string', 'max:24000']]);

        try {
            $s = $this->meetings->signal($meeting, $me, $data['to'] ?? null, $data['type'], $data['payload']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $s->id], 201);
    }

    public function state(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $me = $this->attendee($request, $meeting, $access);
        $data = $request->validate(['mic' => ['nullable', 'boolean'], 'cam' => ['nullable', 'boolean'], 'sharing' => ['nullable', 'boolean'], 'quality' => ['nullable', 'in:good,fair,poor']]);
        $this->meetings->touch($me, $data);

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        $participant = $access->participantRecord();
        $me = MeetingAttendee::where('meeting_id', $meeting->id)->where(fn ($q) => $participant ? $q->where('participant_id', $participant->id) : $q->where('user_id', $access->user?->id))->first();
        if ($me) {
            $this->meetings->leave($meeting, $me, $access);
        }

        return response()->json(['ok' => true]);
    }

    public function host(Request $request, string $roomKey): JsonResponse
    {
        [$meeting, , $access] = $this->resolve($request, $roomKey);
        abort_unless($access->can('host_meeting'), 403, 'Only a host can do that.');
        $data = $request->validate(['action' => ['required', 'in:admit,deny,remove,mute,lock,unlock,end'], 'attendee_id' => ['nullable', 'integer']]);
        $target = isset($data['attendee_id']) ? MeetingAttendee::where('meeting_id', $meeting->id)->find($data['attendee_id']) : null;

        try {
            $this->meetings->host($meeting, $access, $data['action'], $target);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}

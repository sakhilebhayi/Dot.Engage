<?php

namespace App\Http\Controllers\Engage;

use App\Enums\EngagementKind;
use App\Enums\EngagementState;
use App\Enums\ParticipantRole;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Services\Engage\EngagementStateMachine;
use App\Services\Engage\Workspace;
use App\Support\Engage\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class EngagementController extends Controller
{
    public function __construct(
        private readonly EngagementService $engagements,
        private readonly EngagementStateMachine $states,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->currentTeam, 403, 'Pick or create a business first.');

        $query = Engagement::where('team_id', $user->currentTeam->id)->with('parties');
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('reference', 'like', $like)
                ->orWhereHas('parties', fn ($p) => $p->where('name', 'like', $like)));
        }

        $filter = $request->query('show', 'open');
        if ($filter === 'open') {
            $query->whereIn('state', array_map(fn ($s) => $s->value, EngagementState::open()));
        } elseif ($filter === 'closed') {
            $query->whereIn('state', ['completed', 'declined', 'cancelled', 'expired']);
        }

        return view('engagements.index', [
            'engagements' => $query->orderByDesc('updated_at')->paginate(25)->withQueryString(),
            'filter' => $filter,
            'q' => $q,
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->currentTeam, 403, 'Pick or create a business first.');
        Gate::authorize('create', Contract::class);

        return view('engagements.create', ['kinds' => EngagementKind::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->currentTeam, 403);
        Gate::authorize('create', Contract::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::enum(EngagementKind::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'counterparty' => ['required', 'string', 'max:160'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $engagement = $this->engagements->create(
            $user->currentTeam, $user, $data['title'], EngagementKind::from($data['kind']), $data['description'] ?? null,
            $data['counterparty'], isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null, Actor::user($user, $request),
        );

        return redirect()->route('engagements.people', $engagement)->with('status', 'Engagement created. Now add the people who need to take part.');
    }

    private function access(Request $request, Engagement $engagement, string $ability = 'view'): EngagementAccess
    {
        $access = EngagementAccess::resolve($request, $engagement);
        abort_if($access === null, $request->user() ? 404 : 403);
        abort_unless($access->can($ability), 403, 'Your role does not allow that.');

        if ($access->participant && $access->isExternal()) {
            $access->participant->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $access;
    }

    private function workspace(Request $request, Engagement $engagement, string $tab, string $view, array $data = [], string $ability = 'view')
    {
        $access = $this->access($request, $engagement, $ability);
        $engagement->load('parties');
        $workspace = new Workspace($engagement, $access);

        return view($view, array_merge($data, [
            'engagement' => $engagement,
            'access' => $access,
            'ws' => $workspace,
            'tab' => $tab,
        ]));
    }

    public function show(Request $request, Engagement $engagement)
    {
        return $this->workspace($request, $engagement, 'agreement', $request->boolean('embed') ? 'engagements.agreement-embed' : 'engagements.agreement', [
            'contract' => $engagement->primaryContract(),
        ]);
    }

    public function discussion(Request $request, Engagement $engagement)
    {
        return $this->workspace($request, $engagement, 'discussion', 'engagements.discussion', [
            'messages' => $engagement->messages()->orderBy('id')->limit(500)->get(),
        ]);
    }

    public function post(Request $request, Engagement $engagement): RedirectResponse
    {
        $access = $this->access($request, $engagement, 'message');
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $this->engagements->postMessage($engagement, $access, trim($data['body']));

        return redirect()->route('engagements.discussion', $engagement)->withFragment('latest');
    }

    public function people(Request $request, Engagement $engagement)
    {
        return $this->workspace($request, $engagement, 'people', 'engagements.people', [
            'participants' => $engagement->participants()->with('party')->orderBy('id')->get(),
            'roles' => [
                ParticipantRole::Admin, ParticipantRole::Manager, ParticipantRole::Reviewer, ParticipantRole::Participant, ParticipantRole::External,
            ],
        ]);
    }

    public function invite(Request $request, Engagement $engagement): RedirectResponse
    {
        $access = $this->access($request, $engagement, 'manage_participants');

        $data = $request->validate([
            'party_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, [ParticipantRole::Admin, ParticipantRole::Manager, ParticipantRole::Reviewer, ParticipantRole::Participant, ParticipantRole::External]))],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['in:approve,sign,host'],
            'required' => ['nullable', 'boolean'],
        ]);

        $party = $engagement->parties()->findOrFail($data['party_id']);
        $role = ParticipantRole::from($data['role']);

        // Outside the owning business, only the two outside roles make sense.
        if (! $party->is_host && $role->isInternalKind()) {
            $role = ParticipantRole::External;
        }

        try {
            $this->engagements->addParticipant(
                $engagement, $party, $data['name'], $data['email'], $role, $data['capabilities'] ?? [], (bool) ($data['required'] ?? true),
                $access->actor($request), $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('engagements.people', $engagement)->with('status', $party->is_host ? $data['name'].' was added.' : 'Invitation sent to '.$data['email'].'.');
    }

    public function revoke(Request $request, Engagement $engagement, EngagementParticipant $participant): RedirectResponse
    {
        $access = $this->access($request, $engagement, 'manage_participants');
        abort_unless($participant->engagement_id === $engagement->id, 404);

        try {
            $this->engagements->revokeParticipant($engagement, $participant, $access->actor($request));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['participant' => $e->getMessage()]);
        }

        return redirect()->route('engagements.people', $engagement)->with('status', $participant->name.' no longer has access.');
    }

    public function resend(Request $request, Engagement $engagement, EngagementParticipant $participant): RedirectResponse
    {
        $access = $this->access($request, $engagement, 'manage_participants');
        abort_unless($participant->engagement_id === $engagement->id && ! $participant->user_id, 404);

        $this->engagements->resendInvitation($engagement, $participant, $access->actor($request), $request->user()?->name ?? $access->name());

        return redirect()->route('engagements.people', $engagement)->with('status', 'A fresh invitation went to '.$participant->email.'. The old link no longer works.');
    }

    public function meetings(Request $request, Engagement $engagement)
    {
        return $this->workspace($request, $engagement, 'meetings', 'engagements.meetings', [
            'meetings' => $engagement->meetings()->orderByRaw("case status when 'live' then 0 when 'scheduled' then 1 else 2 end")->orderByDesc('scheduled_for')->get(),
        ]);
    }

    public function activity(Request $request, Engagement $engagement)
    {
        $access = $this->access($request, $engagement, 'view');
        $canAudit = $access->can('view_audit');

        return $this->workspace($request, $engagement, 'activity', 'engagements.activity', [
            'events' => $canAudit ? $engagement->auditEvents()->orderByDesc('id')->limit(300)->get() : collect(),
            'canAudit' => $canAudit,
            'chain' => $canAudit ? app(AuditTrail::class)->verify($engagement) : null,
        ]);
    }

    public function cancel(Request $request, Engagement $engagement): RedirectResponse
    {
        $access = $this->access($request, $engagement, 'cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->engagements->cancel($engagement, $data['reason'], $access->actor($request));

        return redirect()->route('engagements.show', $engagement)->with('status', 'The engagement was cancelled. Everyone involved has been told.');
    }
}

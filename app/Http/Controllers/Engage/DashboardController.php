<?php

namespace App\Http\Controllers\Engage;

use App\Enums\EngagementState;
use App\Http\Controllers\Controller;
use App\Models\Amendment;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementApproval;
use App\Models\EngagementParticipant;
use App\Models\Meeting;
use App\Models\SigningEnvelope;
use App\Models\SigningSigner;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if (! $user->currentTeam) {
            return redirect()->route('teams.create');
        }

        $team = $user->currentTeam;
        $open = Engagement::where('team_id', $team->id)->whereIn('state', array_map(fn ($s) => $s->value, EngagementState::open()));

        // What waits for this person, from real rows.
        $mine = EngagementParticipant::where('user_id', $user->id)->orWhere('email', $user->email)->pluck('id');
        $waiting = collect();

        foreach (EngagementApproval::with(['version.contract', 'participant'])->whereIn('participant_id', $mine)->where('status', 'pending')->get() as $a) {
            $e = Engagement::withoutGlobalScopes()->find($a->engagement_id);
            if ($e && $e->isOpen()) {
                $waiting[] = ['engagement' => $e, 'what' => 'Approve version '.($a->version?->version_number ?? ''), 'url' => route('engagements.show', $e).'#approvals', 'since' => $a->requested_at];
            }
        }

        foreach (SigningSigner::with('envelope.engagement')->whereIn('participant_id', $mine)->whereIn('status', ['notified', 'viewed', 'pending'])->get() as $s) {
            $env = $s->envelope;
            if ($env && $env->status === 'sent' && $env->engagement?->isOpen() && $s->status !== 'pending') {
                $waiting[] = ['engagement' => $env->engagement, 'what' => 'Sign the agreement', 'url' => route('engagements.signing', $env->engagement), 'since' => $s->notified_at];
            }
        }

        $reviewable = Engagement::where('team_id', $team->id)->whereIn('state', ['negotiation', 'awaiting_amendments'])->get();
        foreach ($reviewable as $e) {
            $n = Amendment::where('engagement_id', $e->id)->where('status', 'proposed')->count();
            if ($n > 0 && $e->owner_id === $user->id) {
                $waiting[] = ['engagement' => $e, 'what' => $n.' '.str('change')->plural($n).' to review', 'url' => route('engagements.show', $e), 'since' => $e->state_changed_at];
            }
        }

        $upcoming = Meeting::with('engagement')
            ->whereHas('engagement', fn ($q) => $q->where('team_id', $team->id))
            ->whereIn('status', ['scheduled', 'live'])
            ->orderByRaw("case when status = 'live' then 0 else 1 end")->orderBy('scheduled_for')->limit(5)->get();

        $awaitingSignature = SigningEnvelope::with('engagement')
            ->whereHas('engagement', fn ($q) => $q->where('team_id', $team->id))
            ->where('status', 'sent')->orderByDesc('sent_at')->limit(6)->get();

        $outstandingChanges = Amendment::where('status', 'proposed')
            ->whereIn('engagement_id', Engagement::where('team_id', $team->id)->pluck('id'))->count();

        // The landing: who arrived, the one thing to do next, and the most recent engagement shown as it stands.
        $canStart = $user->can('create', Contract::class);
        $latest = Engagement::with('parties')->where('team_id', $team->id)->orderByDesc('updated_at')->orderByDesc('id')->first();

        $active = (clone $open)->with('parties')->orderByDesc('updated_at')->limit(10)->get();
        $completed = Engagement::with('parties')->where('team_id', $team->id)->where('state', 'completed')->orderByDesc('completed_at')->limit(5)->get();
        $closed = Engagement::where('team_id', $team->id)->whereIn('state', ['declined', 'cancelled', 'expired'])->orderByDesc('updated_at')->limit(5)->get();
        $waiting = $waiting->sortBy('since')->values();

        // The engagement shown on the sheet. ?e=<reference> may only pick one of the rows already loaded for this business;
        // anything else (another business, a made-up reference) falls back to the default: the first thing waiting, else the latest.
        $loaded = $active->concat($completed)->concat($closed)->when($latest, fn ($c) => $c->push($latest))->unique('id');
        $requested = $request->query('e');
        $selected = is_string($requested) ? $loaded->firstWhere('reference', $requested) : null;
        $selected ??= $waiting->map(fn ($w) => $w['engagement'])->first(fn ($e) => (int) $e->team_id === (int) $team->id);
        $selected ??= $latest;
        $selected?->loadMissing('parties');

        $workspace = null;
        if ($selected && ($access = EngagementAccess::forUser($selected, $user, $request))) {
            $workspace = new Workspace($selected, $access);
        }

        return view('dashboard', [
            'headline' => $this->headline($waiting->count(), ! $latest && $waiting->isEmpty() && $upcoming->isEmpty()),
            'selected' => $selected,
            'sheetStages' => $workspace?->stages() ?? [],
            'sheetAction' => $workspace?->nextAction(),
            'team' => $team,
            'firstName' => Str::of($user->name)->trim()->before(' ')->value(),
            'canStart' => $canStart,
            'primary' => $canStart
                ? ['label' => 'Start an engagement', 'url' => route('engagements.create')]
                : ['label' => 'See the engagements', 'url' => route('engagements.index')],
            'latest' => $latest,
            'stages' => Workspace::STAGES,
            'waiting' => $waiting,
            'active' => $active,
            'activeCount' => (clone $open)->count(),
            'upcoming' => $upcoming,
            'awaitingSignature' => $awaitingSignature,
            'outstandingChanges' => $outstandingChanges,
            'completed' => $completed,
            'closed' => $closed,
        ]);
    }

    /** The state of the desk in one sentence, built from the count of what waits for this person. */
    private function headline(int $waiting, bool $blank): string
    {
        if ($blank) {
            return 'No engagements yet.';
        }
        if ($waiting === 0) {
            return 'You are clear.';
        }
        $words = [1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine'];

        return $waiting === 1 ? 'One thing waits for you.' : ($words[$waiting] ?? $waiting).' things wait for you.';
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Engagement;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use App\Support\Ecosystem\Refusal;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in person keeps working only while their company holds Dot.Engage in InfoDot (R-50).
 *
 * Two uses:
 *  - `entitled` on the signed-in area: the company is the person's current one.
 *  - `entitled:workspace` on the engagement and meeting routes, which outside parties also use: the company is the
 *    business that owns the engagement, and only when the signed-in person reaches it as a member of that business.
 *    A guest holding an invitation, a signer, a meeting guest, or a signed-in person from another business is an
 *    outside party and is never asked about: their link keeps working.
 *
 * Only a clear "this company does not hold the platform" refuses. No answer (outage, wrong secret, unreadable body,
 * a company InfoDot does not know) carries on exactly as before. A personal team, or any team that is not an InfoDot
 * company, follows what the sign-in answer said about the personal workspace: refused only on an exact "no".
 */
class EnsureCompanyHoldsPlatform
{
    /** Session key: redeem's `entitled` for the person's personal workspace (true, false, or not set). */
    public const PERSONAL_KEY = 'ecosystem.personal_entitled';

    public function __construct(private readonly CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next, string $scope = 'current'): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->entitlement->enabled()) {
            return $next($request);
        }

        if ($scope === 'workspace') {
            $team = $this->owningTeam($request, $user);

            // Not a member of the business that owns it: an outside party, never asked about.
            if (! $team instanceof Team) {
                return $next($request);
            }
        } else {
            $team = $user->currentTeam;
        }

        // A personal team, or any team that is not an InfoDot company: InfoDot is never asked about it. What it said
        // about the person's personal workspace at sign-in decides, and only an exact "no" refuses.
        if (! $team instanceof Team || blank($team->infodot_team_uuid)) {
            return $this->personalWorkspaceRefused($request)
                ? $this->refuse($request, $user, 'no_personal_access', null)
                : $next($request);
        }

        $answer = $this->entitlement->for($team);

        if ($answer['state'] !== CompanyEntitlement::NOT_HELD) {
            return $next($request);
        }

        return $this->refuse($request, $user, $answer['status'], $team->name);
    }

    /** True only when the sign-in answer said, in so many words, that the personal workspace does not hold this platform. */
    private function personalWorkspaceRefused(Request $request): bool
    {
        return $request->hasSession() && $request->session()->get(self::PERSONAL_KEY) === false;
    }

    private function refuse(Request $request, User $user, ?string $reason, ?string $company): Response
    {
        $words = Refusal::words($reason, $company);

        // Livewire re-runs this middleware for a component update on a fake copy of the page's request and throws
        // away any response that is not a redirect, so only an exception stops the update.
        if (app(HandleRequests::class)->isLivewireRoute()) {
            abort(403, $words['title'].'.');
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $words['title'].'.'], 403);
        }

        // The businesses the person could work in instead. When the personal workspace is closed to them, a team
        // that is not an InfoDot company would only show this page again, so it is not offered.
        $others = $user->allTeams()
            ->reject(fn (Team $other) => $other->id === $user->current_team_id)
            ->when($this->personalWorkspaceRefused($request), fn ($teams) => $teams->filter(fn (Team $other) => filled($other->infodot_team_uuid)))
            ->values();

        return response()->view('errors.not-entitled', ['words' => $words, 'platformsUrl' => $this->entitlement->platformsUrl(), 'others' => $others], 403);
    }

    /** The business that owns the engagement or meeting in the URL, when the signed-in person belongs to it. */
    private function owningTeam(Request $request, User $user): ?Team
    {
        $engagement = $request->route('engagement');

        if (is_string($engagement)) {
            $engagement = Engagement::withoutGlobalScopes()->where('reference', $engagement)->first();
        }

        if (! $engagement instanceof Engagement && is_string($roomKey = $request->route('roomKey'))) {
            $engagement = Meeting::withoutGlobalScopes()->where('room_key', $roomKey)->first()?->engagement()->withoutGlobalScopes()->first();
        }

        $team = $engagement instanceof Engagement ? $engagement->team : null;

        return $team instanceof Team && $user->belongsToTeam($team) ? $team : null;
    }
}

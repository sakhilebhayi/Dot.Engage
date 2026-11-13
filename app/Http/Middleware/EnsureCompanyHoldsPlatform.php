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
 * a company InfoDot does not know, a team that is not an InfoDot company) carries on exactly as before.
 */
class EnsureCompanyHoldsPlatform
{
    public function __construct(private readonly CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next, string $scope = 'current'): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->entitlement->enabled()) {
            return $next($request);
        }

        $team = $scope === 'workspace' ? $this->owningTeam($request, $user) : $user->currentTeam;

        if (! $team instanceof Team || blank($team->infodot_team_uuid)) {
            return $next($request);
        }

        $answer = $this->entitlement->for($team);

        if ($answer['state'] !== CompanyEntitlement::NOT_HELD) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => Refusal::words($answer['status'], $team->name)['title'].'.'], 403);
        }

        return response()->view('errors.not-entitled', [
            'words' => Refusal::words($answer['status'], $team->name),
            'platformsUrl' => $this->entitlement->platformsUrl(),
            'others' => $user->allTeams()->reject(fn (Team $other) => $other->id === $user->current_team_id)->values(),
        ], 403);
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

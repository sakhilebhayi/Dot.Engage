<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot (owner and admin become admin, editor stays
 *   editor, viewer and anything unrecognised become the read-only viewer).
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - A company InfoDot marks as not entitled to this platform is not created, updated or made current (R-50).
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $listed = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');

        // R-50: a company InfoDot marks `entitled: false` does not hold this platform. It is neither created nor
        // updated here and cannot be the person's current company. It is still "listed", so an existing membership
        // is left exactly as it is (nothing is removed because a subscription lapsed). A team without the key comes
        // from an InfoDot that does not send it yet and is admitted as before.
        $held = config('ecosystem.infodot.entitlement_check', true) ? $listed->filter(fn ($t) => self::admits($t)) : $listed;

        DB::transaction(function () use ($user, $listed, $held, $identity) {
            foreach ($held as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            $this->revoke($user, $listed->keys()->all());
            $this->forgetCachedTeams($user);

            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $held->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);

                return;
            }

            $this->leaveCompanyThatIsNotHeld($user, $listed->keys()->diff($held->keys())->all(), $held->keys()->all());
        });
    }

    /**
     * Whether this sign-in answer admits the company: `entitled` exactly true, or no such key at all.
     *
     * @param  array<string, mixed>  $team
     */
    public static function admits(array $team): bool
    {
        return ! array_key_exists('entitled', $team) || $team['entitled'] === true;
    }

    /**
     * The person's current company here may be one that no longer holds the platform (it was current at their last
     * visit). Move them to a company that does hold it, or to their personal team.
     *
     * @param  list<string>  $notHeld
     * @param  list<string>  $held
     */
    private function leaveCompanyThatIsNotHeld(User $user, array $notHeld, array $held): void
    {
        $current = $user->currentTeam;

        if (! $current instanceof Team || ! in_array($current->infodot_team_uuid, $notHeld, true)) {
            return;
        }

        $next = $user->allTeams()->first(fn (Team $t) => in_array($t->infodot_team_uuid, $held, true))
            ?? $user->allTeams()->first(fn (Team $t) => (bool) $t->personal_team);

        if ($next) {
            $user->switchTeam($next);
            $this->forgetCachedTeams($user);
        }
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = self::localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    /**
     * InfoDot role -> this app's Jetstream role key. Least privilege: anything that is not
     * explicitly owner, admin or editor (viewer, an empty or an unknown role) is the read-only viewer.
     */
    public static function localRole(string $infodotRole): string
    {
        return match ($infodotRole) {
            'owner', 'admin' => 'admin',
            'editor' => 'editor',
            default => 'viewer',
        };
    }
}

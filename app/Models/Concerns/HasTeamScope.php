<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Engage is team-scoped via Jetstream Teams. Every model that owns a
 * team_id column applies this trait so a query against it is scoped to
 * the authenticated user's current team by default, the same way
 * Dot.Mines' HasTeamFilters scopes every tenant-owned model -- the goal
 * is that a forgotten where('team_id', ...) call in a future controller,
 * Livewire component, or route closure can no longer leak another team's
 * rows, because the model itself never returns unscoped results while a
 * user is authenticated.
 *
 * mass-assignment still sets team_id explicitly at create time (see each
 * controller/Livewire component's create()/store()); this scope only
 * governs reads. Console commands and queue jobs run without an
 * authenticated user, so Auth::check() is false there and the scope is a
 * no-op -- reports like GenerateTeamActivityReport that intentionally
 * iterate every team still work unmodified.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (Auth::check() && Auth::user()->currentTeam) {
                $builder->where($builder->getModel()->getTable().'.team_id', Auth::user()->currentTeam->id);
            }
        });
    }
}

<?php

namespace App\Actions\Jetstream;

use App\Models\Engagement;
use App\Models\Team;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team.
     *
     * A business that holds signed agreements keeps them for the retention period (ENGAGE_RETENTION_YEARS, default 7):
     * deleting the team would delete the signed copies, their evidence records and the audit trail with it.
     */
    public function delete(Team $team): void
    {
        $years = (int) config('engage.retention_years');
        $held = Engagement::withoutGlobalScopes()->withTrashed()
            ->where('team_id', $team->id)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>', now()->subYears($years))
            ->count();

        if ($held > 0) {
            throw ValidationException::withMessages([
                'team' => 'This business holds '.$held.' signed '.str('agreement')->plural($held).' that must be kept for '.$years.' years. Download them first and ask the platform operator to remove the business once the retention period allows.',
            ]);
        }

        $team->purge();
    }
}

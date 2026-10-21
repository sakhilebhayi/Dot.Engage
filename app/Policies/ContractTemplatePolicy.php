<?php

namespace App\Policies;

use App\Models\ContractTemplate;
use App\Models\User;

class ContractTemplatePolicy
{
    /**
     * Any team member may list templates belonging to their current team.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view a template when they belong to its team.
     */
    public function view(User $user, ContractTemplate $template): bool
    {
        return $user->belongsToTeam($template->team);
    }

    /**
     * A team member whose role may create records (not a viewer) may save a new template.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasTeamPermission($user->currentTeam, 'create');
    }

    /**
     * Only the creator or a team admin may edit a template.
     */
    public function update(User $user, ContractTemplate $template): bool
    {
        return $user->hasTeamPermission($template->team, 'update')
            && ($template->created_by === $user->id
                || $user->hasTeamRole($template->team, 'admin'));
    }

    /**
     * Only the creator or a team admin may delete a template.
     */
    public function delete(User $user, ContractTemplate $template): bool
    {
        return $user->hasTeamPermission($template->team, 'update')
            && ($template->created_by === $user->id
                || $user->hasTeamRole($template->team, 'admin'));
    }
}

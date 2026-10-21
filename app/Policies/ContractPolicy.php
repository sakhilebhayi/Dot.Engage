<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/**
 * Roles are enforced through Jetstream permissions: 'create' for making a new record,
 * 'update' for changing, removing, sharing or sending anything that already exists.
 * The read-only viewer role holds only 'read', so every mutating ability below denies it.
 */
class ContractPolicy
{
    /**
     * Any team member may list contracts belonging to their current team.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view a contract when they belong to the same team.
     */
    public function view(User $user, Contract $contract): bool
    {
        return $user->belongsToTeam($contract->team);
    }

    /**
     * A team member whose role may create records (not a viewer) may create a contract
     * in their current team.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasTeamPermission($user->currentTeam, 'create');
    }

    /**
     * Only the creator or a team admin may edit a contract.
     * Signed contracts are immutable for everyone.
     */
    public function update(User $user, Contract $contract): bool
    {
        if ($contract->status === 'signed') {
            return false;
        }

        return $user->hasTeamPermission($contract->team, 'update')
            && ($contract->created_by === $user->id
                || $user->hasTeamRole($contract->team, 'admin'));
    }

    /**
     * Only the creator or a team admin may soft-delete a contract. The 'update'
     * permission (held by editors and admins, not viewers) is the write gate here:
     * editors have no 'delete' permission yet could always delete their own contracts.
     */
    public function delete(User $user, Contract $contract): bool
    {
        return $user->hasTeamPermission($contract->team, 'update')
            && ($contract->created_by === $user->id
                || $user->hasTeamRole($contract->team, 'admin'));
    }

    /**
     * Only a team admin may restore a soft-deleted contract.
     */
    public function restore(User $user, Contract $contract): bool
    {
        return $user->belongsToTeam($contract->team)
            && $user->hasTeamRole($contract->team, 'admin');
    }

    /**
     * Only a team admin may permanently delete a contract.
     */
    public function forceDelete(User $user, Contract $contract): bool
    {
        return $user->belongsToTeam($contract->team)
            && $user->hasTeamRole($contract->team, 'admin');
    }

    /**
     * Sharing sends notifications to teammates, so it needs a role that may change
     * things; a viewer can read a contract but not push it at other people.
     */
    public function share(User $user, Contract $contract): bool
    {
        return $user->hasTeamPermission($contract->team, 'update');
    }

    /**
     * A user may sign a contract as long as their role may create records (a signature
     * is one), they belong to the team and have not already signed it.
     */
    public function sign(User $user, Contract $contract): bool
    {
        return $user->hasTeamPermission($contract->team, 'create')
            && ! $contract->signatures()->where('user_id', $user->id)->exists();
    }
}

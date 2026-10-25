<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoSession;

class VideoSessionPolicy
{
    /**
     * Any team member may browse sessions belonging to their team.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view a session only if they belong to the same team.
     */
    public function view(User $user, VideoSession $videoSession): bool
    {
        return $user->belongsToTeam($videoSession->team);
    }

    /**
     * A team member whose role may create records (not a viewer) may initiate a session.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasTeamPermission($user->currentTeam, 'create');
    }

    /**
     * Only the session initiator or a team admin may update session details.
     */
    public function update(User $user, VideoSession $videoSession): bool
    {
        return $user->hasTeamPermission($videoSession->team, 'update')
            && ($videoSession->initiated_by === $user->id
                || $user->hasTeamRole($videoSession->team, 'admin'));
    }

    /**
     * Only the initiator or a team admin may end / delete a session.
     */
    public function delete(User $user, VideoSession $videoSession): bool
    {
        return $user->hasTeamPermission($videoSession->team, 'update')
            && ($videoSession->initiated_by === $user->id
                || $user->hasTeamRole($videoSession->team, 'admin'));
    }

    /**
     * Only team admins may restore a session record.
     */
    public function restore(User $user, VideoSession $videoSession): bool
    {
        return $user->belongsToTeam($videoSession->team)
            && $user->hasTeamRole($videoSession->team, 'admin');
    }

    /**
     * Only team admins may permanently delete a session record.
     */
    public function forceDelete(User $user, VideoSession $videoSession): bool
    {
        return $user->belongsToTeam($videoSession->team)
            && $user->hasTeamRole($videoSession->team, 'admin');
    }

    /**
     * Any team member (viewers included) may join an active session: joining only
     * listens and watches, it writes nothing.
     */
    public function join(User $user, VideoSession $videoSession): bool
    {
        return $user->belongsToTeam($videoSession->team)
            && $videoSession->status === 'active';
    }

    /**
     * A participant may capture a signature during an active session.
     */
    public function sign(User $user, VideoSession $videoSession): bool
    {
        return $user->hasTeamPermission($videoSession->team, 'create')
            && $videoSession->status === 'active'
            && $videoSession->contract_id !== null;
    }
}

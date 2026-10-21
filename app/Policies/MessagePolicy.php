<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Participants of the conversation may list its messages.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view a message only if they are a participant in the conversation.
     */
    public function view(User $user, Message $message): bool
    {
        return $message->conversation
            ->participants()->where('users.id', $user->id)->exists();
    }

    /**
     * Any participant whose role may create records (not a viewer) may send a message.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasTeamPermission($user->currentTeam, 'create');
    }

    /**
     * Only the original sender may edit their own message.
     */
    public function update(User $user, Message $message): bool
    {
        return $message->user_id === $user->id
            && $user->hasTeamPermission($message->conversation->team, 'update');
    }

    /**
     * The sender or a team admin may soft-delete a message.
     */
    public function delete(User $user, Message $message): bool
    {
        $team = $message->conversation->team;

        return ($message->user_id === $user->id && $user->hasTeamPermission($team, 'update'))
            || $user->hasTeamRole($team, 'admin');
    }

    /**
     * Only a team admin may restore a soft-deleted message.
     */
    public function restore(User $user, Message $message): bool
    {
        return $user->hasTeamRole($message->conversation->team, 'admin');
    }

    /**
     * Only a team admin may permanently delete a message.
     */
    public function forceDelete(User $user, Message $message): bool
    {
        return $user->hasTeamRole($message->conversation->team, 'admin');
    }
}

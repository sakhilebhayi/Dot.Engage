<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Any authenticated user may start a new conversation.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view a conversation only if they are a participant.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists();
    }

    /**
     * A team member whose role may create records (not a viewer) may start a conversation.
     */
    public function create(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasTeamPermission($user->currentTeam, 'create');
    }

    /**
     * Only team admins may rename a group conversation.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists()
            && $user->hasTeamRole($conversation->team, 'admin');
    }

    /**
     * Only team admins may soft-delete a conversation.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->hasTeamRole($conversation->team, 'admin');
    }

    /**
     * Only team admins may restore a conversation.
     */
    public function restore(User $user, Conversation $conversation): bool
    {
        return $user->hasTeamRole($conversation->team, 'admin');
    }

    /**
     * Only team admins may permanently delete a conversation.
     */
    public function forceDelete(User $user, Conversation $conversation): bool
    {
        return $user->hasTeamRole($conversation->team, 'admin');
    }

    /**
     * A participant whose role may create records (not a viewer) may send messages.
     * A viewer who is a participant can still read the conversation.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists()
            && $user->hasTeamPermission($conversation->team, 'create');
    }
}

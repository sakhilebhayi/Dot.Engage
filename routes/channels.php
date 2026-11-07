<?php

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// ── Default Jetstream / Laravel Notifications channel ────────────────────────
// Authorises the standard private channel used by Laravel's database
// notifications and the Notification Bell / Tray Livewire components.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ── Team channel ─────────────────────────────────────────────────────────────
// Used for team-wide notifications.
// Any confirmed member of the team may subscribe.
Broadcast::channel('team.{teamId}', function ($user, $teamId) {
    return $user->belongsToTeam(Team::findOrFail($teamId));
});

// ── Conversation channel ──────────────────────────────────────────────────────
// Used by the MessageSent event so participants receive new messages live.
// Only users who are listed as participants of the conversation may subscribe.
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    if (! $conversation) {
        return false;
    }

    return $conversation->participants()->where('users.id', $user->id)->exists();
});

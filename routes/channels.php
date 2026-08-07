<?php

use App\Models\Conversation;
use App\Models\Team;
use App\Models\VideoSession;
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
// Used by ContractSigned, VideoSessionStarted, VideoSessionEnded,
// SignatureRequestedDuringCall, and VideoSessionInviteNotification events.
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

// ── Video session channel ─────────────────────────────────────────────────────
// Presence channel used for WebRTC signalling, in-call document overlay,
// and signature events during a live call.
// Only members of the session's team may join the presence channel.
Broadcast::channel('video-session.{sessionId}', function ($user, $sessionId) {
    $session = VideoSession::find($sessionId);

    if (! $session) {
        return false;
    }

    if (! $user->belongsToTeam(Team::findOrFail($session->team_id))) {
        return false;
    }

    // Return user info so presence channel can expose participant list.
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});

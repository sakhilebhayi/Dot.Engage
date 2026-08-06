<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {

    // ── Dashboard ───────────────────────────────────────────────────────────
    // Scoped to the authenticated user's current team — a prior pass (commit
    // 5dae85f) added these queries without team scoping, which leaked
    // cross-tenant aggregate counts (every team's contracts/conversations/
    // sessions) into every team's dashboard. Fixed here.
    Route::get('/dashboard', function () {
        $teamId = \Illuminate\Support\Facades\Auth::user()->currentTeam->id;

        $stats = [
            'total_contracts'       => \App\Models\Contract::where('team_id', $teamId)->count(),
            'pending_signatures'    => \App\Models\Contract::where('team_id', $teamId)->where('status', 'pending')->count(),
            'signed_contracts'      => \App\Models\Contract::where('team_id', $teamId)->where('status', 'signed')->count(),
            'active_conversations'  => \App\Models\Conversation::where('team_id', $teamId)->whereNotNull('last_message_at')->count(),
            'active_video_sessions' => \App\Models\VideoSession::where('team_id', $teamId)->whereIn('status', ['waiting', 'active'])->count(),
        ];

        $recentContracts = \App\Models\Contract::with('creator')
            ->where('team_id', $teamId)
            ->latest()
            ->limit(5)
            ->get();

        $activeConversations = \App\Models\Conversation::withCount('participants')
            ->where('team_id', $teamId)
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();

        return view('dashboard', compact('stats', 'recentContracts', 'activeConversations'));
    })->name('dashboard');

    // ── Contracts ───────────────────────────────────────────────────────────
    // Livewire components handle all mutations; these are page-view routes only.
    Route::get('/contracts', fn () => view('contracts.index'))
        ->name('contracts.index');

    Route::get('/contracts/create', fn () => view('contracts.create'))
        ->name('contracts.create');

    Route::get('/contracts/{contract}', fn (\App\Models\Contract $contract) => view('contracts.show', ['contractId' => $contract->id]))
        ->name('contracts.show');

    Route::get('/contracts/{contract}/edit', fn (\App\Models\Contract $contract) => view('contracts.edit', ['contractId' => $contract->id]))
        ->name('contracts.edit');

    // ── Chat ─────────────────────────────────────────────────────────────────
    Route::get('/chat', fn () => view('chat.index'))
        ->name('chat.index');

    Route::get('/chat/{conversation}', fn (\App\Models\Conversation $conversation) => view('chat.show', ['conversationId' => $conversation->id]))
        ->name('chat.show');

    // ── Video Sessions ────────────────────────────────────────────────────────
    // {room} matches room_id (UUID string) — NOT the numeric primary key.
    Route::get('/video', fn () => view('video.index'))
        ->name('video.index');

    Route::get('/video/{room}', function (string $room) {
        $session = \App\Models\VideoSession::where('room_id', $room)->firstOrFail();
        return view('video.room', ['sessionId' => $session->id]);
    })->name('video.room');

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::get('/notifications', fn () => view('notifications.index'))
        ->name('notifications.index');
});

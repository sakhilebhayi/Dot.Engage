<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\VideoSession;
use Illuminate\Support\Facades\Auth;
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
        if (! Auth::user()->currentTeam) {
            return redirect()->route('teams.create');
        }

        $teamId = Auth::user()->currentTeam->id;

        $stats = [
            'total_contracts' => Contract::where('team_id', $teamId)->count(),
            'pending_signatures' => Contract::where('team_id', $teamId)->where('status', 'pending')->count(),
            'signed_contracts' => Contract::where('team_id', $teamId)->where('status', 'signed')->count(),
            'active_conversations' => Conversation::where('team_id', $teamId)->whereNotNull('last_message_at')->count(),
            'active_video_sessions' => VideoSession::where('team_id', $teamId)->whereIn('status', ['waiting', 'active'])->count(),
        ];

        $recentContracts = Contract::with('creator')
            ->where('team_id', $teamId)
            ->latest()
            ->limit(5)
            ->get();

        $activeConversations = Conversation::withCount('participants')
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

    Route::get('/contracts/{contract}', fn (Contract $contract) => view('contracts.show', ['contractId' => $contract->id]))
        ->name('contracts.show');

    Route::get('/contracts/{contract}/edit', fn (Contract $contract) => view('contracts.edit', ['contractId' => $contract->id]))
        ->name('contracts.edit');

    // ── Chat ─────────────────────────────────────────────────────────────────
    Route::get('/chat', fn () => view('chat.index'))
        ->name('chat.index');

    Route::get('/chat/{conversation}', fn (Conversation $conversation) => view('chat.show', ['conversationId' => $conversation->id]))
        ->name('chat.show');

    // ── Video Sessions ────────────────────────────────────────────────────────
    // {room} matches room_id (UUID string) — NOT the numeric primary key.
    Route::get('/video', fn () => view('video.index'))
        ->name('video.index');

    Route::get('/video/{room}', function (string $room) {
        $session = VideoSession::where('room_id', $room)->firstOrFail();

        return view('video.room', ['sessionId' => $session->id]);
    })->name('video.room');

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::get('/notifications', fn () => view('notifications.index'))
        ->name('notifications.index');

    // ── Operator: Dependency Patches ───────────────────────────────────────────
    // Livewire component handles approve/reject; this is a page-view route only.
    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/dependency-patches', fn () => view('operator.dependency-patches'))
            ->name('dependency-patches.index');
    });
});

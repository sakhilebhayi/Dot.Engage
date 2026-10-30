<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\ExternalSigningController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\VideoSession;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->name('ecosystem.auth');

// Browsers POST Content-Security-Policy violation reports here (the policy is report-only, see SecurityHeaders). The browser sends
// no cookie, CSRF token or login, so the route drops the web group's session and CSRF middleware: a report neither starts a
// session nor needs one. Throttled per IP; the controller keeps only the violated directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:60,1,csp-report')
    ->name('csp.report');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

// ── External Signing ────────────────────────────────────────────────────────
// Guest-facing (no auth:sanctum) -- security is the `signed` middleware
// validating the emailed link itself, not a session, matching the
// DocuSign/PandaDoc emailed-link trust model. See ExternalSigningController.
Route::middleware('signed')->prefix('sign')->name('external.contracts.')->group(function () {
    Route::get('/{signer}', [ExternalSigningController::class, 'show'])->name('show');
    Route::get('/{signer}/download', [ExternalSigningController::class, 'download'])->name('download');
    Route::post('/{signer}', [ExternalSigningController::class, 'sign'])->name('sign');
});

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

    // Must be registered before /contracts/{contract} -- otherwise "templates"
    // is swallowed by that route's implicit Contract binding.
    Route::get('/contracts/templates', fn () => view('contracts.templates'))
        ->name('contracts.templates');

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

// Public liveness probe for the cron scheduler and queue worker, watched from GitHub every
// 15 minutes by .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

// Guardian observability report for Dot.Brain (dot-guardian/v1): operational
// detail, so behind a bearer token (fails closed with 503 when none is
// configured) rather than public like /up and /up/scheduler.
Route::get('/guardian/health', GuardianHealthController::class)
    ->middleware(AuthenticateGuardian::class)
    ->name('guardian.health');

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}

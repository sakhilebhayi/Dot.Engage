<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\Engage\LegacyController;
use App\Http\Controllers\Engage\TemplateController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Models\Conversation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
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

// ── Links from the earlier signing system ────────────────────────────────────
// Signing links issued before the engagement workspace existed are retired: the sender issues a new request, which
// asks the signer to confirm their email address before anything is shown.
Route::any('/sign/{signer}/{rest?}', [LegacyController::class, 'retiredSigningLink'])->where('rest', '.*')->name('external.contracts.retired');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    'entitled',
])->group(function () {

    // ── Contracts (now documents inside engagements) ────────────────────────
    Route::get('/contracts', fn () => redirect()->route('engagements.index'))->name('contracts.index');
    Route::get('/contracts/create', fn () => redirect()->route('engagements.create'))->name('contracts.create');
    Route::get('/contracts/templates', [TemplateController::class, 'index'])->name('contracts.templates');
    Route::post('/contracts/templates', [TemplateController::class, 'store'])->name('contracts.templates.store');
    Route::delete('/contracts/templates/{template}', [TemplateController::class, 'destroy'])->name('contracts.templates.destroy');
    Route::get('/contracts/{contract}', [LegacyController::class, 'contract'])->name('contracts.show');
    Route::get('/contracts/{contract}/edit', [LegacyController::class, 'contract'])->name('contracts.edit');

    // ── Chat ─────────────────────────────────────────────────────────────────
    Route::get('/chat', fn () => view('chat.index'))
        ->name('chat.index');

    Route::get('/chat/{conversation}', fn (Conversation $conversation) => view('chat.show', ['conversationId' => $conversation->id]))
        ->name('chat.show');

    // ── Video (replaced by meetings inside engagements) ─────────────────────
    Route::get('/video', fn () => redirect()->route('meetings.index'));
    Route::get('/video/{room}', fn () => redirect()->route('meetings.index'));

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

require __DIR__.'/engage.php';

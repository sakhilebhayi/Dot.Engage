<?php

use App\Http\Controllers\Engage\AgreementController;
use App\Http\Controllers\Engage\DashboardController;
use App\Http\Controllers\Engage\DocumentController;
use App\Http\Controllers\Engage\EngagementController;
use App\Http\Controllers\Engage\JoinController;
use App\Http\Controllers\Engage\LegalController;
use App\Http\Controllers\Engage\MeetingController;
use App\Http\Controllers\Engage\SigningController;
use App\Http\Controllers\Engage\VerifyController;
use App\Models\Contract;
use App\Models\Engagement;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Engagements
|--------------------------------------------------------------------------
| {engagement} is the reference (ENG-2026-00001). It is bound without the team scope on purpose: outside
| participants are not members of the team, and a signed-in person from another business can be an invited party.
| Access is decided by App\Services\Engage\EngagementAccess in every controller, never by the binding.
*/

Route::bind('engagement', fn (string $reference) => Engagement::withoutGlobalScopes()->where('reference', $reference)->firstOrFail());
Route::bind('document', fn (string $id) => Contract::withoutGlobalScopes()->findOrFail($id));

// R-50: `entitled` (the signed-in area) asks InfoDot whether the person's current company still holds Dot.Engage.
// `entitled:workspace` (the engagement and meeting routes below, which outside parties also use) asks only about a
// signed-in member of the business that owns the engagement; an invited guest, a signer or a meeting guest is never
// asked about, so their links keep working. The join, verify and legal routes carry neither.

// Invitation links for people without an account: no sign-in, no content until the emailed code is entered.
Route::prefix('join')->name('join.')->group(function () {
    Route::get('{token}', [JoinController::class, 'show'])->name('show');
    Route::post('{token}/code', [JoinController::class, 'sendCode'])->middleware('throttle:20,1,code')->name('code');
    Route::post('{token}/verify', [JoinController::class, 'verify'])->middleware('throttle:30,1,verify')->name('verify');
    Route::post('{token}/decline', [JoinController::class, 'decline'])->middleware('throttle:6,1,decline')->name('decline');
});

Route::get('/verify/{reference?}', [VerifyController::class, 'show'])->where('reference', 'ENG-[0-9]{4}-[0-9]{5}')->name('verify.show');
Route::post('/verify', [VerifyController::class, 'check'])->middleware('throttle:20,1,verify.check')->name('verify.check');
Route::get('/how-signing-works', [LegalController::class, 'signatures'])->name('legal.signatures');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'entitled'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/agreements', [AgreementController::class, 'index'])->name('agreements.index');
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::get('/engagements', [EngagementController::class, 'index'])->name('engagements.index');
    Route::get('/engagements/create', [EngagementController::class, 'create'])->name('engagements.create');
    Route::post('/engagements', [EngagementController::class, 'store'])->name('engagements.store');
});

// The workspace: open to signed-in team members and to verified outside participants.
Route::prefix('e/{engagement}')->middleware('entitled:workspace')->name('engagements.')->group(function () {
    Route::get('/', [EngagementController::class, 'show'])->name('show');
    Route::get('discussion', [EngagementController::class, 'discussion'])->name('discussion');
    Route::post('discussion', [EngagementController::class, 'post'])->middleware('throttle:60,1,post')->name('post');
    Route::get('people', [EngagementController::class, 'people'])->name('people');
    Route::post('people', [EngagementController::class, 'invite'])->middleware('throttle:30,1,invite')->name('invite');
    Route::post('people/{participant}/revoke', [EngagementController::class, 'revoke'])->name('revoke');
    Route::post('people/{participant}/resend', [EngagementController::class, 'resend'])->middleware('throttle:10,1,resend')->name('resend');
    Route::get('meetings', [EngagementController::class, 'meetings'])->name('meetings');
    Route::get('activity', [EngagementController::class, 'activity'])->name('activity');
    Route::post('cancel', [EngagementController::class, 'cancel'])->name('cancel');
    Route::post('decline', [EngagementController::class, 'decline'])->middleware('throttle:10,1,engagement.decline')->name('decline');
    Route::patch('details', [EngagementController::class, 'update'])->name('update');
    Route::patch('people/{participant}', [EngagementController::class, 'updateParticipant'])->name('participant.update');
});

// Documents, notes, proposed changes and approvals. Every handler resolves EngagementAccess itself.
Route::prefix('e/{engagement}')->middleware('entitled:workspace')->group(function () {
    Route::post('documents', [DocumentController::class, 'upload'])->middleware('throttle:20,1,documents.upload')->name('documents.upload');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->middleware('throttle:120,1,documents.file')->name('documents.file');
    Route::get('documents/{document}/state', [DocumentController::class, 'state'])->middleware('throttle:240,1,documents.state')->name('documents.state');
    Route::post('documents/{document}/share', [DocumentController::class, 'share'])->middleware('throttle:20,1,documents.share')->name('documents.share');
    Route::get('documents/{document}/compare', [DocumentController::class, 'compare'])->name('documents.compare');
    Route::post('documents/{document}/revise', [DocumentController::class, 'revise'])->middleware('throttle:10,1,documents.revise')->name('documents.revise');
    Route::post('documents/{document}/versions', [DocumentController::class, 'newVersion'])->middleware('throttle:20,1,documents.version')->name('documents.version');
    Route::post('documents/{document}/comments', [DocumentController::class, 'comment'])->middleware('throttle:60,1,documents.comment')->name('documents.comment');
    Route::post('comments/{comment}/replies', [DocumentController::class, 'reply'])->middleware('throttle:60,1,comments.reply')->name('comments.reply');
    Route::post('comments/{comment}/status', [DocumentController::class, 'commentStatus'])->middleware('throttle:60,1,comments.status')->name('comments.status');
    Route::post('documents/{document}/amendments', [DocumentController::class, 'propose'])->middleware('throttle:30,1,amendments.propose')->name('amendments.propose');
    Route::post('amendments/{amendment}/review', [DocumentController::class, 'reviewAmendment'])->middleware('throttle:60,1,amendments.review')->name('amendments.review');
    Route::post('amendments/{amendment}/withdraw', [DocumentController::class, 'withdrawAmendment'])->middleware('throttle:60,1,amendments.withdraw')->name('amendments.withdraw');
    Route::post('documents/{document}/approvals', [DocumentController::class, 'requestApproval'])->middleware('throttle:20,1,approvals.request')->name('approvals.request');
    Route::post('approvals/{approval}/decision', [DocumentController::class, 'decideApproval'])->middleware('throttle:30,1,approvals.decide')->name('approvals.decide');
    Route::post('documents/{document}/agreement', [DocumentController::class, 'recordAgreement'])->middleware('throttle:20,1,documents.agree')->name('documents.agree');
});

// Signing: preparing a request, signing it, and collecting the finished agreement.
Route::prefix('e/{engagement}')->middleware('entitled:workspace')->group(function () {
    Route::get('signing', [SigningController::class, 'tab'])->name('engagements.signing');
    Route::get('sign', [SigningController::class, 'page'])->name('engagements.sign');
    Route::post('sign', [SigningController::class, 'submit'])->middleware('throttle:20,1,signing.submit')->name('signing.submit');
    Route::post('sign/decline', [SigningController::class, 'decline'])->middleware('throttle:10,1,signing.decline')->name('signing.decline');
    Route::post('signing/draft', [SigningController::class, 'draft'])->middleware('throttle:60,1,signing.draft')->name('signing.draft');
    Route::get('signing/{envelope}/state', [SigningController::class, 'state'])->middleware('throttle:240,1,signing.state')->name('signing.state');
    Route::post('signing/{envelope}/fields', [SigningController::class, 'fields'])->middleware('throttle:60,1,signing.fields')->name('signing.fields');
    Route::post('signing/{envelope}/send', [SigningController::class, 'send'])->middleware('throttle:10,1,signing.send')->name('signing.send');
    Route::post('signing/{envelope}/cancel', [SigningController::class, 'cancel'])->middleware('throttle:10,1,signing.cancel')->name('signing.cancel');
    Route::post('signers/{signer}/remind', [SigningController::class, 'remind'])->middleware('throttle:10,1,signing.remind')->name('signing.remind');
    Route::post('signers/{signer}/reassign', [SigningController::class, 'reassign'])->middleware('throttle:10,1,signing.reassign')->name('signing.reassign');
    Route::get('signing/{envelope}/download/{kind}', [SigningController::class, 'download'])->middleware('throttle:60,1,signing.download')->name('signing.download');
});

// Meetings. The room and its JSON API are open to signed-in members and verified outside participants alike.
Route::post('/e/{engagement}/meetings', [MeetingController::class, 'store'])->middleware(['entitled:workspace', 'throttle:20,1,meetings.store'])->name('meetings.store');
Route::prefix('meetings/{roomKey}')->middleware('entitled:workspace')->group(function () {
    Route::get('/', [MeetingController::class, 'room'])->name('meetings.room');
    Route::post('cancel', [MeetingController::class, 'cancel'])->name('meetings.cancel');
    Route::post('enter', [MeetingController::class, 'enter'])->middleware('throttle:meeting-enter')->name('meetings.enter');
    Route::get('poll', [MeetingController::class, 'poll'])->middleware('throttle:meeting-poll')->name('meetings.poll');
    Route::post('join', [MeetingController::class, 'join'])->middleware('throttle:meeting-join')->name('meetings.join');
    Route::get('ice', [MeetingController::class, 'ice'])->middleware('throttle:meeting-ice')->name('meetings.ice');
    Route::post('signal', [MeetingController::class, 'signal'])->middleware('throttle:meeting-signal')->name('meetings.signal');
    Route::post('state', [MeetingController::class, 'state'])->middleware('throttle:meeting-state')->name('meetings.state');
    Route::post('leave', [MeetingController::class, 'leave'])->middleware('throttle:meeting-leave')->name('meetings.leave');
    Route::post('host', [MeetingController::class, 'host'])->middleware('throttle:meeting-host')->name('meetings.host');
});

<?php

use App\Http\Controllers\Engage\AgreementController;
use App\Http\Controllers\Engage\DashboardController;
use App\Http\Controllers\Engage\DocumentController;
use App\Http\Controllers\Engage\EngagementController;
use App\Http\Controllers\Engage\JoinController;
use App\Http\Controllers\Engage\LegalController;
use App\Http\Controllers\Engage\MeetingController;
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

// Invitation links for people without an account: no sign-in, no content until the emailed code is entered.
Route::prefix('join')->name('join.')->group(function () {
    Route::get('{token}', [JoinController::class, 'show'])->name('show');
    Route::post('{token}/code', [JoinController::class, 'sendCode'])->middleware('throttle:6,1')->name('code');
    Route::post('{token}/verify', [JoinController::class, 'verify'])->middleware('throttle:10,1')->name('verify');
    Route::post('{token}/decline', [JoinController::class, 'decline'])->middleware('throttle:6,1')->name('decline');
});

Route::get('/how-signing-works', [LegalController::class, 'signatures'])->name('legal.signatures');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/agreements', [AgreementController::class, 'index'])->name('agreements.index');
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::post('/meetings/instant', [MeetingController::class, 'instant'])->name('meetings.instant');
    Route::get('/meetings/{roomKey}', [MeetingController::class, 'room'])->name('meetings.room');
    Route::get('/engagements', [EngagementController::class, 'index'])->name('engagements.index');
    Route::get('/engagements/create', [EngagementController::class, 'create'])->name('engagements.create');
    Route::post('/engagements', [EngagementController::class, 'store'])->name('engagements.store');
});

// The workspace: open to signed-in team members and to verified outside participants.
Route::prefix('e/{engagement}')->name('engagements.')->group(function () {
    Route::get('/', [EngagementController::class, 'show'])->name('show');
    Route::get('discussion', [EngagementController::class, 'discussion'])->name('discussion');
    Route::post('discussion', [EngagementController::class, 'post'])->middleware('throttle:60,1')->name('post');
    Route::get('people', [EngagementController::class, 'people'])->name('people');
    Route::post('people', [EngagementController::class, 'invite'])->middleware('throttle:30,1')->name('invite');
    Route::post('people/{participant}/revoke', [EngagementController::class, 'revoke'])->name('revoke');
    Route::post('people/{participant}/resend', [EngagementController::class, 'resend'])->middleware('throttle:10,1')->name('resend');
    Route::get('meetings', [EngagementController::class, 'meetings'])->name('meetings');
    Route::get('signing', [EngagementController::class, 'signing'])->name('signing');
    Route::get('activity', [EngagementController::class, 'activity'])->name('activity');
    Route::post('cancel', [EngagementController::class, 'cancel'])->name('cancel');
});

// Documents, notes, proposed changes and approvals. Every handler resolves EngagementAccess itself.
Route::prefix('e/{engagement}')->group(function () {
    Route::post('documents', [DocumentController::class, 'upload'])->middleware('throttle:20,1')->name('documents.upload');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->middleware('throttle:120,1')->name('documents.file');
    Route::get('documents/{document}/state', [DocumentController::class, 'state'])->middleware('throttle:240,1')->name('documents.state');
    Route::get('documents/{document}/compare', [DocumentController::class, 'compare'])->name('documents.compare');
    Route::post('documents/{document}/revise', [DocumentController::class, 'revise'])->middleware('throttle:10,1')->name('documents.revise');
    Route::post('documents/{document}/versions', [DocumentController::class, 'newVersion'])->middleware('throttle:20,1')->name('documents.version');
    Route::post('documents/{document}/comments', [DocumentController::class, 'comment'])->middleware('throttle:60,1')->name('documents.comment');
    Route::post('comments/{comment}/replies', [DocumentController::class, 'reply'])->middleware('throttle:60,1')->name('comments.reply');
    Route::post('comments/{comment}/status', [DocumentController::class, 'commentStatus'])->middleware('throttle:60,1')->name('comments.status');
    Route::post('documents/{document}/amendments', [DocumentController::class, 'propose'])->middleware('throttle:30,1')->name('amendments.propose');
    Route::post('amendments/{amendment}/review', [DocumentController::class, 'reviewAmendment'])->middleware('throttle:60,1')->name('amendments.review');
    Route::post('amendments/{amendment}/withdraw', [DocumentController::class, 'withdrawAmendment'])->middleware('throttle:60,1')->name('amendments.withdraw');
    Route::post('documents/{document}/approvals', [DocumentController::class, 'requestApproval'])->middleware('throttle:20,1')->name('approvals.request');
    Route::post('approvals/{approval}/decision', [DocumentController::class, 'decideApproval'])->middleware('throttle:30,1')->name('approvals.decide');
    Route::post('documents/{document}/agreement', [DocumentController::class, 'recordAgreement'])->middleware('throttle:20,1')->name('documents.agree');
});

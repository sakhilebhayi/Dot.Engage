<?php

namespace Tests\Support;

use App\Enums\EngagementKind;
use App\Enums\ParticipantRole;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\Engage\DocumentService;
use App\Services\Engage\EngagementService;
use App\Support\Engage\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

trait BuildsEngagements
{
    private bool $storageFaked = false;

    protected function makeOwner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function makeEngagement(?User $owner = null, string $counterparty = 'Acme Supplies (Pty) Ltd'): Engagement
    {
        $owner ??= $this->makeOwner();

        return app(EngagementService::class)->create(
            $owner->currentTeam, $owner, 'Supply agreement 2026', EngagementKind::Supplier, 'Annual supply terms', $counterparty,
        );
    }

    /** Invite an outside party and return [participant, plain token] (mail is faked by the caller). */
    protected function inviteExternal(Engagement $engagement, string $email = 'thandi@acme.example', array $capabilities = ['sign'], ParticipantRole $role = ParticipantRole::External): array
    {
        Notification::fake();
        $party = $engagement->parties()->where('is_host', false)->first();
        $actor = Actor::user($engagement->owner);

        $participant = app(EngagementService::class)->addParticipant(
            $engagement, $party, 'Thandi Mokoena', $email, $role, $capabilities, true, $actor, $engagement->owner,
        );

        $token = null;
        Notification::assertSentOnDemand(EngagementNotice::class, function ($notification) use (&$token) {
            $token = basename(parse_url($notification->url, PHP_URL_PATH));

            return true;
        });

        return [$participant->fresh(), $token];
    }

    protected function verifyParticipant(EngagementParticipant $participant): EngagementParticipant
    {
        $participant->forceFill(['verified_at' => now(), 'accepted_at' => $participant->accepted_at ?? now()])->save();

        return $participant->fresh();
    }

    protected function addTeamMember(Team $team, string $role = 'editor'): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** Upload a real, small PDF as the engagement's first document. */
    protected function addDocument(Engagement $engagement, string $title = 'Supply agreement', bool $share = true): Contract
    {
        if (! $this->storageFaked) {
            Storage::fake('contracts');
            Storage::fake('signatures');
            $this->storageFaked = true;
        }

        $bytes = Pdf::loadView('pdf.demo-agreement', ['title' => $title, 'host' => 'Host Co', 'other' => 'Other Co'])->setPaper('a4')->output();
        $file = UploadedFile::fake()->createWithContent('agreement.pdf', $bytes);

        return app(DocumentService::class)->upload($engagement, $file, $title, Actor::user($engagement->owner), $engagement->owner->id, null, $share);
    }

    /** A small black scribble on a transparent background, as a data URI. */
    protected function signatureDataUri(int $w = 300, int $h = 100): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagesetthickness($img, 4);
        $black = imagecolorallocate($img, 10, 10, 10);
        imageline($img, 10, 80, 100, 20, $black);
        imageline($img, 100, 20, 150, 80, $black);
        imageline($img, 150, 80, 280, 30, $black);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}

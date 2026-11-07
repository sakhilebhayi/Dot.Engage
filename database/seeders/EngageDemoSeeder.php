<?php

namespace Database\Seeders;

use App\Enums\EngagementKind;
use App\Enums\ParticipantRole;
use App\Models\User;
use App\Services\Engage\DocumentService;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Support\Engage\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * Local and test data only: a business with one engagement in progress, so every page has something real to show.
 * Refuses to run in production.
 */
class EngageDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The demo seeder never runs in production.');

            return;
        }

        $owner = User::firstOrCreate(['email' => 'owner@demo.test'], [
            'name' => 'Naledi Dlamini', 'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        if (! $owner->currentTeam) {
            $team = $owner->ownedTeams()->create(['name' => 'Dlamini Logistics', 'personal_team' => false]);
            $owner->switchTeam($team);
        }
        $owner = $owner->fresh();

        $service = app(EngagementService::class);
        $engagement = $service->create(
            $owner->currentTeam, $owner, 'Cold-chain transport agreement 2026', EngagementKind::Supplier,
            'Twelve-month transport terms between Dlamini Logistics and Karoo Fresh Produce.', 'Karoo Fresh Produce (Pty) Ltd',
        );

        $party = $engagement->parties()->where('is_host', false)->first();
        $service->addParticipant($engagement, $party, 'Thandi Mokoena', 'thandi@karoofresh.example', ParticipantRole::External, ['sign', 'approve'], true, Actor::user($owner), $owner);
        $service->addParticipant($engagement, $party, 'Pieter van Wyk', 'pieter@karoofresh.example', ParticipantRole::Reviewer, ['approve'], false, Actor::user($owner), $owner);

        $access = EngagementAccess::forUser($engagement, $owner);

        $pdf = Pdf::loadView('pdf.demo-agreement', [
            'title' => 'Cold-chain transport agreement', 'host' => $owner->currentTeam->name, 'other' => 'Karoo Fresh Produce (Pty) Ltd',
        ])->setPaper('a4')->output();
        $path = sys_get_temp_dir().'/engage-demo-'.uniqid().'.pdf';
        file_put_contents($path, $pdf);
        app(DocumentService::class)->upload($engagement, new UploadedFile($path, 'cold-chain-agreement.pdf', 'application/pdf', null, true), 'Cold-chain transport agreement', Actor::user($owner), $owner->id, null, true);
        @unlink($path);

        $service->postMessage($engagement, $access, 'Thanks for making time. The draft follows our standard terms; the rate card is in schedule 2. @Thandi, tell us what you want changed.');
    }
}

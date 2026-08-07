<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\ContractVersion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Known demo users (predictable credentials for testing) ──────────
        $alice = User::firstOrCreate(['email' => 'alice@demo.test'], [
            'name' => 'Alice Demo',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $bob = User::firstOrCreate(['email' => 'bob@demo.test'], [
            'name' => 'Bob Demo',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $carol = User::firstOrCreate(['email' => 'carol@demo.test'], [
            'name' => 'Carol Demo',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // ── 2. Team owned by Alice, Bob and Carol as members ──────────────────
        $team = Team::firstOrCreate(
            ['name' => 'Dot.Engage Demo Team'],
            ['user_id' => $alice->id, 'personal_team' => false]
        );

        $alice->update(['current_team_id' => $team->id]);

        // Attach members (Jetstream pivot: team_user) — sync avoids duplicates.
        $team->users()->syncWithoutDetaching([
            $alice->id => ['role' => 'admin'],
            $bob->id => ['role' => 'editor'],
            $carol->id => ['role' => 'editor'],
        ]);

        // ── 3. Contracts ──────────────────────────────────────────────────────
        // 3a. Fully signed contract
        $signedContract = Contract::create([
            'team_id' => $team->id,
            'created_by' => $alice->id,
            'title' => 'Service Level Agreement 2026',
            'description' => 'Annual SLA between all demo team members.',
            'file_path' => 'contracts/demo-sla-2026.pdf',
            'status' => 'signed',
            'expires_at' => now()->addYear(),
        ]);

        ContractVersion::create([
            'contract_id' => $signedContract->id,
            'created_by' => $alice->id,
            'version_number' => 1,
            'file_path' => 'contracts/demo-sla-2026.pdf',
            'change_notes' => 'Initial upload.',
        ]);

        foreach ([$alice, $bob, $carol] as $signer) {
            ContractSignature::create([
                'contract_id' => $signedContract->id,
                'user_id' => $signer->id,
                'signature_image_path' => 'signatures/demo_sig_'.$signer->id.'.png',
                'ip_address' => '127.0.0.1',
                'signed_at' => now()->subDays(rand(1, 5)),
            ]);
        }

        // 3b. Pending contract (uploaded, awaiting signatures)
        $pendingContract = Contract::create([
            'team_id' => $team->id,
            'created_by' => $bob->id,
            'title' => 'Non-Disclosure Agreement',
            'description' => 'Mutual NDA for project collaboration.',
            'file_path' => 'contracts/demo-nda.pdf',
            'status' => 'pending',
            'expires_at' => now()->addMonths(3),
        ]);

        ContractVersion::create([
            'contract_id' => $pendingContract->id,
            'created_by' => $bob->id,
            'version_number' => 1,
            'file_path' => 'contracts/demo-nda.pdf',
            'change_notes' => 'Initial upload.',
        ]);

        // Bob already signed the NDA
        ContractSignature::create([
            'contract_id' => $pendingContract->id,
            'user_id' => $bob->id,
            'signature_image_path' => 'signatures/demo_sig_'.$bob->id.'.png',
            'ip_address' => '127.0.0.1',
            'signed_at' => now()->subDay(),
        ]);

        // 3c. Draft contract (uploaded but not submitted)
        Contract::create([
            'team_id' => $team->id,
            'created_by' => $carol->id,
            'title' => 'Freelance Services Contract',
            'description' => 'Draft for upcoming project work.',
            'file_path' => 'contracts/demo-freelance.pdf',
            'status' => 'draft',
            'expires_at' => null,
        ]);

        // 3d. Additional random contracts for bulk
        Contract::factory()->count(5)->pending()->create([
            'team_id' => $team->id,
            'created_by' => $alice->id,
        ]);

        // ── 4. Conversations & Messages ───────────────────────────────────────
        // 4a. Direct conversation: Alice ↔ Bob
        $directConv = Conversation::create([
            'team_id' => $team->id,
            'name' => null,
            'is_group' => false,
            'last_message_at' => now()->subMinutes(10),
        ]);

        $directConv->participants()->attach([
            $alice->id => ['last_read_at' => now()->subMinutes(10)],
            $bob->id => ['last_read_at' => now()->subMinutes(30)],
        ]);

        $messages = [
            [$alice->id, 'Hey Bob, I\'ve uploaded the SLA for review.'],
            [$bob->id,   'Got it! I\'ll take a look shortly.'],
            [$alice->id, 'Let me know if you need any changes.'],
            [$bob->id,   'Looks good — signed it just now.'],
            [$alice->id, 'Perfect, waiting on Carol now.'],
        ];

        $ts = now()->subMinutes(count($messages) * 5);
        foreach ($messages as [$userId, $body]) {
            Message::create([
                'conversation_id' => $directConv->id,
                'user_id' => $userId,
                'body' => $body,
                'type' => 'text',
                'read_at' => $userId === $alice->id ? $ts : null,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $ts = $ts->addMinutes(5);
        }

        // 4b. Group conversation: all three members
        $groupConv = Conversation::create([
            'team_id' => $team->id,
            'name' => 'Project Alpha',
            'is_group' => true,
            'last_message_at' => now()->subHour(),
        ]);

        $groupConv->participants()->attach([
            $alice->id => ['last_read_at' => now()->subHour()],
            $bob->id => ['last_read_at' => now()->subHours(2)],
            $carol->id => ['last_read_at' => now()->subHours(3)],
        ]);

        $groupMessages = [
            [$alice->id, 'Welcome to the Project Alpha channel!'],
            [$carol->id, 'Thanks Alice! Excited to get started.'],
            [$bob->id,   'I\'ve shared the NDA — please sign when you can.'],
            [$carol->id, 'On it. Will sign today.'],
        ];

        $ts2 = now()->subHours(4);
        foreach ($groupMessages as [$userId, $body]) {
            Message::create([
                'conversation_id' => $groupConv->id,
                'user_id' => $userId,
                'body' => $body,
                'type' => 'text',
                'read_at' => now(),
                'created_at' => $ts2,
                'updated_at' => $ts2,
            ]);
            $ts2 = $ts2->addMinutes(15);
        }

        // ── 5. Video Sessions ────────────────────────────────────────────────
        // 5a. Completed session
        VideoSession::create([
            'team_id' => $team->id,
            'initiated_by' => $alice->id,
            'contract_id' => $signedContract->id,
            'room_id' => Str::uuid()->toString(),
            'status' => 'ended',
            'started_at' => now()->subDay()->setTime(10, 0),
            'ended_at' => now()->subDay()->setTime(10, 45),
        ]);

        // 5b. Active session (illustrates the live-session UI)
        VideoSession::create([
            'team_id' => $team->id,
            'initiated_by' => $bob->id,
            'contract_id' => $pendingContract->id,
            'room_id' => Str::uuid()->toString(),
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'ended_at' => null,
        ]);

        $this->command->info('✓ Demo data seeded: 3 users, 1 team, 8 contracts, 2 conversations, 9 messages, 2 video sessions.');
    }
}

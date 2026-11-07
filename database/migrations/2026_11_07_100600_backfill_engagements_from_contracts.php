<?php

use App\Models\Engagement;
use App\Services\Engage\AuditTrail;
use App\Services\Engage\EngagementStateMachine;
use App\Support\Engage\Actor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Every contract that existed before engagements becomes the first document of an engagement of its own, so nothing
     * disappears and every old address still leads somewhere. Signed contracts become completed engagements with their
     * old signatures kept; unsigned ones start as drafts (their old signing links stop working: the owner re-invites).
     */
    public function up(): void
    {
        $contracts = DB::table('contracts')->whereNull('engagement_id')->whereNull('deleted_at')->orderBy('id')->get();
        if ($contracts->isEmpty()) {
            return;
        }

        $audit = app(AuditTrail::class);
        $seq = [];
        $created = [];

        foreach ($contracts as $c) {
            DB::transaction(function () use ($c, $audit, &$seq, &$created) {
                $year = (int) date('Y', strtotime($c->created_at ?? 'now'));
                $seq[$year] ??= (int) DB::table('engagements')->where('reference', 'like', 'ENG-'.$year.'-%')->count();
                $seq[$year]++;
                $reference = sprintf('ENG-%d-%05d', $year, $seq[$year]);

                $signed = $c->status === 'signed';
                $lastSigned = $signed ? DB::table('contract_signatures')->where('contract_id', $c->id)->max('signed_at') : null;
                $externals = DB::table('contract_external_signers')->where('contract_id', $c->id)->get();

                $engagementId = DB::table('engagements')->insertGetId([
                    'team_id' => $c->team_id, 'reference' => $reference, 'title' => $c->title, 'kind' => 'general',
                    'description' => $c->description, 'owner_id' => $c->created_by,
                    'state' => $signed ? 'completed' : (in_array($c->status, ['expired']) ? 'expired' : 'draft'),
                    'state_changed_at' => now(), 'expires_at' => $c->expires_at,
                    'completed_at' => $signed ? ($lastSigned ?? $c->updated_at) : null,
                    'expired_at' => $c->status === 'expired' ? ($c->expires_at ?? now()) : null,
                    'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
                ]);

                $teamName = DB::table('teams')->where('id', $c->team_id)->value('name') ?? 'Your business';
                $hostParty = DB::table('engagement_parties')->insertGetId(['engagement_id' => $engagementId, 'name' => $teamName, 'is_host' => true, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $otherParty = DB::table('engagement_parties')->insertGetId(['engagement_id' => $engagementId, 'name' => 'Other party', 'is_host' => false, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

                $owner = DB::table('users')->where('id', $c->created_by)->first();
                $seen = [];
                if ($owner) {
                    $seen[strtolower($owner->email)] = true;
                    DB::table('engagement_participants')->insert(['engagement_id' => $engagementId, 'party_id' => $hostParty, 'user_id' => $owner->id, 'name' => $owner->name, 'email' => strtolower($owner->email), 'role' => 'owner', 'capabilities' => json_encode(['host']), 'required' => true, 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach (DB::table('contract_signatures')->where('contract_id', $c->id)->whereNotNull('user_id')->get() as $sig) {
                    $u = DB::table('users')->where('id', $sig->user_id)->first();
                    if ($u && ! isset($seen[strtolower($u->email)])) {
                        $seen[strtolower($u->email)] = true;
                        DB::table('engagement_participants')->insert(['engagement_id' => $engagementId, 'party_id' => $hostParty, 'user_id' => $u->id, 'name' => $u->name, 'email' => strtolower($u->email), 'role' => 'participant', 'capabilities' => json_encode(['sign']), 'required' => false, 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                foreach ($externals as $ext) {
                    if (isset($seen[strtolower($ext->email)])) {
                        continue;
                    }
                    $seen[strtolower($ext->email)] = true;
                    DB::table('engagement_participants')->insert([
                        'engagement_id' => $engagementId, 'party_id' => $otherParty, 'name' => $ext->name, 'email' => strtolower($ext->email), 'role' => 'external',
                        'capabilities' => json_encode(['sign']), 'required' => true, 'invited_by' => $ext->invited_by, 'invitation_sent_at' => $ext->created_at,
                        'accepted_at' => $ext->signed_at ?? $ext->viewed_at, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                // Versions: the original file becomes version 1; later rows (signed copies) shift up by one.
                $disk = Storage::disk('contracts');
                $versions = DB::table('contract_versions')->where('contract_id', $c->id)->orderByDesc('version_number')->get();
                foreach ($versions as $v) {
                    $hash = $v->file_path && $disk->exists($v->file_path) ? hash('sha256', (string) $disk->get($v->file_path)) : null;
                    DB::table('contract_versions')->where('id', $v->id)->update([
                        'version_number' => $v->version_number + 1,
                        'kind' => str_starts_with((string) $v->change_notes, 'Signed by all parties') ? 'signed' : 'revision',
                        'sha256' => $hash, 'size' => $hash ? $disk->size($v->file_path) : null, 'mime' => 'application/pdf', 'stampable' => false,
                        'label' => 'Version '.($v->version_number + 1),
                    ]);
                }
                $current = 1;
                if ($c->file_path) {
                    $exists = $disk->exists($c->file_path);
                    $bytes = $exists ? (string) $disk->get($c->file_path) : null;
                    $isPdf = $bytes !== null && str_starts_with($bytes, '%PDF-');
                    DB::table('contract_versions')->insert([
                        'contract_id' => $c->id, 'created_by' => $c->created_by, 'version_number' => 1, 'kind' => 'original', 'file_path' => $c->file_path,
                        'sha256' => $bytes !== null ? hash('sha256', $bytes) : null, 'size' => $bytes !== null ? strlen($bytes) : null,
                        'mime' => $isPdf ? 'application/pdf' : null, 'stampable' => false, 'label' => 'First version', 'change_notes' => 'Imported from the earlier contract list',
                        'created_at' => $c->created_at, 'updated_at' => $c->created_at,
                    ]);
                    $current = (int) (DB::table('contract_versions')->where('contract_id', $c->id)->max('version_number') ?? 1);
                    DB::table('contracts')->where('id', $c->id)->update(['mime' => $isPdf ? 'application/pdf' : null, 'sha256' => $bytes !== null ? hash('sha256', $bytes) : null]);
                }

                DB::table('contracts')->where('id', $c->id)->update(['engagement_id' => $engagementId, 'current_version' => $current, 'locked_at' => $signed ? now() : null, 'shared_at' => $c->created_at]);

                $audit->record($engagementId, 'engagement.created', Actor::system('migration'), null, ['reference' => $reference, 'migrated_from_contract' => $c->id, 'title' => $c->title]);

                $created[] = $engagementId;
            });
        }

        // Work the live state out from what was imported (an unsigned contract with a pending signer is "invitation sent", not a draft).
        foreach ($created as $id) {
            try {
                $engagement = Engagement::withoutGlobalScopes()->find($id);
                if ($engagement && $engagement->isOpen()) {
                    app(EngagementStateMachine::class)->sync($engagement, Actor::system('migration'));
                }
            } catch (Throwable $e) {
                report($e); // the state is recomputed the next time anything happens in the engagement
            }
        }
    }

    public function down(): void
    {
        // Engagements created here stay: the audit trail is append-only, so the migration does not undo them.
    }
};

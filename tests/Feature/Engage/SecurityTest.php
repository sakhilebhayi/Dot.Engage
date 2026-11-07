<?php

namespace Tests\Feature\Engage;

use App\Actions\Jetstream\DeleteTeam;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\SigningEnvelope;
use App\Models\Team;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Support\Engage\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private Engagement $a;

    private Engagement $b;

    private Contract $docA;

    private Contract $docB;

    private EngagementParticipant $guestA;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->a = $this->makeEngagement();
        $this->b = $this->makeEngagement();
        $this->docA = $this->addDocument($this->a, 'A doc');
        $this->docB = $this->addDocument($this->b, 'B doc');
        [$g] = $this->inviteExternal($this->a, 'guest@a.example', ['sign']);
        $this->guestA = $this->verifyParticipant($g);
    }

    private function asGuestA(): static
    {
        return $this->withSession([EngagementAccess::SESSION_KEY => [$this->a->id => $this->guestA->id]]);
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_another_business_cannot_open_read_or_change_an_engagement(): void
    {
        $intruder = $this->b->owner;

        $this->actingAs($intruder)->get(route('engagements.show', $this->a))->assertNotFound();
        foreach (['discussion', 'people', 'meetings', 'signing', 'activity'] as $tab) {
            $this->actingAs($intruder)->get(route('engagements.'.$tab, $this->a))->assertNotFound();
        }
        $this->actingAs($intruder)->get(route('documents.file', [$this->a, $this->docA]))->assertNotFound();
        $this->actingAs($intruder)->postJson(route('documents.comment', [$this->a, $this->docA]), ['body' => 'hi'])->assertNotFound();
        $this->actingAs($intruder)->post(route('engagements.post', $this->a), ['body' => 'hi'])->assertNotFound();
        $this->actingAs($intruder)->post(route('engagements.cancel', $this->a), ['reason' => 'x'])->assertNotFound();
        $this->assertTrue($this->a->fresh()->isOpen());
    }

    public function test_the_engagement_list_never_shows_another_businesses_engagements(): void
    {
        $this->actingAs($this->b->owner)->get(route('engagements.index'))->assertOk()->assertSee($this->b->reference)->assertDontSee($this->a->reference);
        $this->actingAs($this->b->owner)->get(route('engagements.index', ['q' => 'ENG']))->assertDontSee($this->a->reference);
    }

    public function test_an_outside_party_of_one_engagement_cannot_reach_another(): void
    {
        $this->asGuestA()->get(route('engagements.show', $this->b))->assertForbidden();
        $this->asGuestA()->get(route('documents.file', [$this->b, $this->docB]))->assertForbidden();
        $this->asGuestA()->get(route('documents.file', [$this->a, $this->docB]))->assertNotFound();
        $this->asGuestA()->postJson(route('documents.comment', [$this->b, $this->docB]), ['body' => 'x'])->assertForbidden();
    }

    // ── Outside parties ──────────────────────────────────────────────────────

    public function test_an_outside_party_cannot_use_owner_or_manager_actions(): void
    {
        $this->asGuestA()->post(route('engagements.invite', $this->a), ['party_id' => 1, 'name' => 'X', 'email' => 'x@y.example', 'role' => 'admin'])->assertForbidden();
        $this->asGuestA()->post(route('engagements.cancel', $this->a), ['reason' => 'x'])->assertForbidden();
        $this->asGuestA()->post(route('documents.upload', $this->a), ['file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')])->assertForbidden();
        $this->asGuestA()->postJson(route('signing.draft', $this->a), ['mode' => 'parallel', 'signers' => [['participant_id' => $this->guestA->id]]])->assertForbidden();
        $this->asGuestA()->post(route('meetings.store', $this->a))->assertForbidden();
        $this->assertTrue($this->a->fresh()->isOpen());
    }

    public function test_the_audit_details_are_shown_to_the_owner(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->actingAs($this->a->owner)->post(route('engagements.post', $this->a), ['body' => 'hello']);

        $this->actingAs($this->a->owner)->get(route('engagements.activity', $this->a))->assertSee('Chain intact')->assertSee('203.0.113.77');
    }

    public function test_the_audit_details_are_kept_from_outside_parties(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->actingAs($this->a->owner)->post(route('engagements.post', $this->a), ['body' => 'hello']);
        auth()->logout();

        $this->asGuestA()->get(route('engagements.activity', $this->a))->assertOk()->assertDontSee('Chain intact')->assertDontSee('203.0.113.77')->assertSee('Posted in the discussion');
    }

    public function test_removing_a_participant_cuts_their_access_at_once(): void
    {
        $this->asGuestA()->get(route('engagements.show', $this->a))->assertOk();

        app(EngagementService::class)->revokeParticipant($this->a, $this->guestA, Actor::user($this->a->owner));

        $this->asGuestA()->get(route('engagements.show', $this->a))->assertForbidden();
        $this->asGuestA()->get(route('documents.file', [$this->a, $this->docA]))->assertForbidden();
    }

    public function test_an_unverified_session_cannot_see_content(): void
    {
        $this->guestA->forceFill(['verified_at' => null])->save();

        $this->asGuestA()->get(route('engagements.show', $this->a))->assertForbidden();
    }

    public function test_a_verification_older_than_the_session_limit_is_not_enough(): void
    {
        $this->guestA->forceFill(['verified_at' => now()->subHours(13)])->save();

        $this->asGuestA()->get(route('engagements.show', $this->a))->assertForbidden();
    }

    public function test_an_expired_or_invented_invitation_link_shows_nothing_about_the_engagement(): void
    {
        $this->get(route('join.show', str_repeat('z', 64)))->assertStatus(410)->assertDontSee($this->a->title);
        $this->get('/join/short')->assertStatus(410);

        $this->guestA->forceFill(['token_expires_at' => now()->subMinute()])->save();
        [, $token] = [null, null];
        $this->get(route('join.show', ['token' => 'a'.substr(hash('sha256', 'x'), 0, 63)]))->assertStatus(410);
    }

    public function test_the_invitation_page_shows_who_invited_but_never_clauses_or_documents(): void
    {
        $fresh = $this->makeEngagement();
        [, $token] = $this->inviteExternal($fresh, 'new@z.example');
        $doc = $this->addDocument($fresh, 'Confidential rate card');

        $this->get(route('join.show', $token))->assertOk()->assertSee($fresh->title)->assertDontSee('Confidential rate card');
    }

    public function test_code_requests_are_rate_limited(): void
    {
        $fresh = $this->makeEngagement();
        [, $token] = $this->inviteExternal($fresh, 'rate@z.example');

        $codes = [];
        for ($i = 0; $i < 25; $i++) {
            $codes[] = $this->post(route('join.code', $token))->status();
        }

        $this->assertContains(429, $codes);
    }

    // ── Team roles ───────────────────────────────────────────────────────────

    public function test_a_read_only_team_member_can_look_and_nothing_else(): void
    {
        $viewer = $this->addTeamMember($this->a->team, 'viewer');

        $this->actingAs($viewer)->get(route('engagements.show', $this->a))->assertOk();
        $this->actingAs($viewer)->post(route('engagements.post', $this->a), ['body' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('documents.comment', [$this->a, $this->docA]), ['body' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('engagements.invite', $this->a), ['party_id' => 1, 'name' => 'X', 'email' => 'x@y.example', 'role' => 'participant'])->assertForbidden();
        $this->actingAs($viewer)->post(route('meetings.store', $this->a))->assertForbidden();
    }

    // ── Files and content ────────────────────────────────────────────────────

    public function test_an_image_renamed_to_pdf_is_refused(): void
    {
        $png = UploadedFile::fake()->image('contract.pdf');

        $this->actingAs($this->a->owner)->post(route('documents.upload', $this->a), ['file' => $png, 'title' => 'x'])->assertSessionHasErrors('document');
        $this->assertSame(1, $this->a->contracts()->count());
    }

    public function test_an_empty_file_and_an_oversized_file_are_refused(): void
    {
        config(['engage.max_upload_kb' => 4]);

        $this->actingAs($this->a->owner)->post(route('documents.upload', $this->a), ['file' => UploadedFile::fake()->createWithContent('e.pdf', '')])->assertSessionHasErrors();
        $this->actingAs($this->a->owner)->post(route('documents.upload', $this->a), ['file' => UploadedFile::fake()->createWithContent('big.pdf', '%PDF-1.4'.str_repeat('x', 20000))])->assertSessionHasErrors('file');
    }

    public function test_user_written_text_is_escaped_where_it_is_shown(): void
    {
        $owner = $this->a->owner;
        $this->actingAs($owner)->post(route('engagements.post', $this->a), ['body' => '<script>alert(1)</script> @Naledi'])->assertRedirect();

        $html = $this->actingAs($owner)->get(route('engagements.discussion', $this->a))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_search_box_treats_wildcards_as_text(): void
    {
        $this->actingAs($this->a->owner)->get(route('engagements.index', ['q' => '%']))->assertOk()->assertDontSee($this->a->reference);
        $this->actingAs($this->a->owner)->get(route('engagements.index', ['q' => "' OR 1=1 --"]))->assertOk()->assertDontSee($this->a->reference);
    }

    public function test_signed_downloads_need_the_right_role(): void
    {
        $this->a->participants()->where('role', 'owner')->update(['capabilities' => ['host']]);
        // A finished envelope with stored files.
        Storage::disk('contracts')->put('signed/env1-final.pdf', '%PDF-1.4 final');
        Storage::disk('contracts')->put('signed/env1-evidence.json', '{}');
        $env = SigningEnvelope::create([
            'engagement_id' => $this->a->id, 'contract_id' => $this->docA->id, 'contract_version_id' => $this->docA->currentVersionRecord()->id,
            'document_sha256' => str_repeat('a', 64), 'status' => 'completed', 'finalised_at' => now(), 'final_path' => 'signed/env1-final.pdf', 'certificate_path' => 'signed/env1-final.pdf',
        ]);
        $this->a->forceFill(['state' => 'completed', 'completed_at' => now()])->save();

        $this->asGuestA()->get(route('signing.download', [$this->a, $env, 'final']))->assertOk();
        $this->asGuestA()->get(route('signing.download', [$this->a, $env, 'evidence']))->assertOk(); // a signer may keep the record
        $this->flushSession();
        $this->actingAs($this->b->owner)->get(route('signing.download', [$this->a, $env, 'final']))->assertNotFound();
        auth()->logout();
        $this->flushSession();
        $this->get(route('signing.download', [$this->a, $env, 'final']))->assertForbidden();
        $this->asGuestA()->get(route('signing.download', [$this->a, $env, 'secrets']))->assertNotFound();
    }

    public function test_verification_pages_reveal_no_names_or_titles(): void
    {
        $env = SigningEnvelope::create([
            'engagement_id' => $this->a->id, 'contract_id' => $this->docA->id, 'contract_version_id' => $this->docA->currentVersionRecord()->id,
            'document_sha256' => str_repeat('b', 64), 'final_sha256' => str_repeat('c', 64), 'status' => 'completed', 'finalised_at' => now(), 'evidence' => '{}', 'evidence_sha256' => hash('sha256', '{}'),
        ]);

        $this->get(route('verify.show', $this->a->reference))->assertOk()->assertSee($this->a->reference)->assertDontSee($this->a->title)->assertDontSee('guest@a.example');
    }

    public function test_a_business_with_signed_agreements_cannot_be_deleted_within_the_retention_period(): void
    {
        $this->a->forceFill(['state' => 'completed', 'completed_at' => now()->subYear()])->save();
        $team = $this->a->team;

        try {
            app(DeleteTeam::class)->delete($team);
            $this->fail('The team was deleted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('signed agreement', $e->errors()['team'][0]);
        }
        $this->assertNotNull(Team::find($team->id));

        $this->travel(8)->years();
        app(DeleteTeam::class)->delete($team->fresh());
        $this->assertNull(Team::find($team->id));
    }
}

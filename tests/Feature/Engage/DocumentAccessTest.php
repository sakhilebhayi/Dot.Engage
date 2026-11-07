<?php

namespace Tests\Feature\Engage;

use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Services\Engage\EngagementAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class DocumentAccessTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private Engagement $engagement;

    private Contract $private;

    private Contract $shared;

    private EngagementParticipant $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->engagement = $this->makeEngagement();
        $this->private = $this->addDocument($this->engagement, 'Internal draft', false);
        $this->shared = $this->addDocument($this->engagement, 'Shared agreement', true);
        [$guest] = $this->inviteExternal($this->engagement, 'guest@acme.example', ['sign']);
        $this->guest = $this->verifyParticipant($guest);
    }

    private function asGuest(): static
    {
        return $this->withSession([EngagementAccess::SESSION_KEY => [$this->engagement->id => $this->guest->id]]);
    }

    public function test_an_outside_party_only_sees_documents_that_were_shared(): void
    {
        $access = EngagementAccess::forParticipant($this->engagement->fresh(), $this->guest->fresh());

        $this->assertSame(['Shared agreement'], $access->documents()->pluck('title')->all());
        $this->assertFalse($access->canSeeDocument($this->private));
        $this->assertTrue($access->canSeeDocument($this->shared));
    }

    public function test_a_team_member_sees_everything(): void
    {
        $access = EngagementAccess::forUser($this->engagement->fresh(), $this->engagement->owner);

        $this->assertCount(2, $access->documents());
    }

    public function test_the_private_document_cannot_be_fetched_or_discussed_by_an_outside_party(): void
    {
        $this->asGuest()->get(route('documents.file', [$this->engagement, $this->private]))->assertNotFound();
        $this->asGuest()->getJson(route('documents.state', [$this->engagement, $this->private]))->assertNotFound();
        $this->asGuest()->postJson(route('documents.comment', [$this->engagement, $this->private]), ['body' => 'hello'])->assertNotFound();
        $this->asGuest()->get(route('documents.file', [$this->engagement, $this->shared]))->assertOk()->assertHeader('Cache-Control');
    }

    public function test_the_agreement_page_for_an_outside_party_lists_only_shared_documents(): void
    {
        $response = $this->asGuest()->get(route('engagements.show', $this->engagement))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Shared agreement', $html);
        $this->assertStringNotContainsString('Internal draft', $html);
    }

    public function test_sharing_is_recorded_and_tells_the_others(): void
    {
        $this->actingAs($this->engagement->owner)->post(route('documents.share', [$this->engagement, $this->private]))->assertRedirect();

        $this->assertTrue($this->private->fresh()->isShared());
        $this->assertTrue($this->engagement->auditEvents()->where('event', 'document.shared')->exists());
        $this->asGuest()->get(route('documents.file', [$this->engagement, $this->private]))->assertOk();
    }

    public function test_an_outside_party_cannot_share_documents(): void
    {
        $this->asGuest()->post(route('documents.share', [$this->engagement, $this->private]))->assertForbidden();
        $this->assertFalse($this->private->fresh()->isShared());
    }

    public function test_a_document_from_another_engagement_is_never_reachable_through_this_one(): void
    {
        $other = $this->makeEngagement();
        $foreign = $this->addDocument($other, 'Foreign');

        $this->actingAs($this->engagement->owner)->get(route('documents.file', [$this->engagement, $foreign]))->assertNotFound();
        $this->asGuest()->get(route('documents.file', [$this->engagement, $foreign]))->assertNotFound();
    }

    public function test_the_file_is_never_cached_or_indexed_and_is_served_with_nosniff(): void
    {
        $r = $this->actingAs($this->engagement->owner)->get(route('documents.file', [$this->engagement, $this->shared]))->assertOk();

        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('noindex, nofollow', $r->headers->get('X-Robots-Tag'));
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
    }
}

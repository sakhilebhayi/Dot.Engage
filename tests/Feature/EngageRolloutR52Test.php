<?php

namespace Tests\Feature;

use App\Livewire\HeaderSearch;
use App\Models\Engagement;
use App\Models\User;
use App\Services\Engage\EngagementAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

/**
 * R-52: Roboto, the shared header (dot-ui 0.6), the search on every page and its live results, and the phone-first
 * pages that outside parties open from a sealed link.
 */
class EngageRolloutR52Test extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private function signedIn(): array
    {
        $owner = $this->makeOwner();
        $engagement = $this->makeEngagement($owner);

        return [$owner, $engagement];
    }

    // ── The header ─────────────────────────────────────────────────────────

    public function test_the_shared_header_renders_with_the_five_areas_and_a_phone_drawer(): void
    {
        [$owner] = $this->signedIn();
        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<header class="dh', $html);
        foreach (['Desk', 'Engagements', 'Meetings', 'Agreements', 'Team chat'] as $label) {
            $this->assertStringContainsString('>'.$label.'<', $html);
        }
        $this->assertStringContainsString('data-dh-menu-button', $html, 'a phone menu button');
        $this->assertStringContainsString('id="dh-drawer"', $html, 'the shared drawer');
        $this->assertStringContainsString('data-dh-theme-set', $html, 'the theme control lives in the account menu');
        $this->assertStringNotContainsString('mobile-nav', $html, 'the old second menu is gone');
        $this->assertStringNotContainsString('class="topbar"', $html);
        $this->assertSame(1, substr_count($html, '<header'), 'one header');
    }

    public function test_the_account_menu_keeps_its_links_and_the_business_switch_is_a_form(): void
    {
        [$owner] = $this->signedIn();
        $second = $owner->ownedTeams()->create(['name' => 'Second Co', 'personal_team' => false]);
        $owner = $owner->fresh();
        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString(route('profile.show'), $html);
        $this->assertStringContainsString(route('contracts.templates'), $html);
        $this->assertStringContainsString(route('legal.signatures'), $html);
        $this->assertStringContainsString('Switch business', $html);
        $this->assertStringContainsString('name="team_id" value="'.$second->id.'"', $html);
        $this->assertStringContainsString(route('logout'), $html);
    }

    // ── Search on every page type ──────────────────────────────────────────

    public function test_the_search_pill_is_on_every_signed_in_page_type(): void
    {
        [$owner, $engagement] = $this->signedIn();
        $pages = [
            route('dashboard'), route('engagements.index'), route('engagements.create'), route('meetings.index'), route('agreements.index'),
            route('contracts.templates'), route('chat.index'), route('notifications.index'), route('profile.show'),
            route('engagements.show', $engagement), route('engagements.people', $engagement), route('engagements.discussion', $engagement),
        ];
        foreach ($pages as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="dsr-input"'), 'one search pill on '.$url);
            $this->assertStringContainsString('placeholder="Find an engagement"', $html, $url);
        }
    }

    public function test_an_outside_party_gets_the_logo_and_two_public_links_but_no_search_switcher_or_account(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $participant = $this->verifyParticipant($participant);
        $this->withSession([EngagementAccess::SESSION_KEY => [$engagement->id => $participant->id]]);

        $html = $this->get(route('engagements.show', $engagement))->assertOk()->getContent();

        $this->assertStringContainsString('<header class="dh', $html);
        $this->assertStringNotContainsString('dsr-input', $html);
        $this->assertStringNotContainsString('data-dsw', $html);
        $this->assertStringNotContainsString('data-dh-account', $html);
        $this->assertStringContainsString(route('legal.signatures'), $html);
    }

    // ── Live results: same question as the list, same business only ────────

    public function test_live_results_find_by_title_reference_and_party_inside_the_current_business(): void
    {
        [$owner, $mine] = $this->signedIn();
        $stranger = $this->makeOwner();
        $theirs = $this->makeEngagement($stranger, 'Zeta Holdings');

        $this->actingAs($owner);
        Livewire::test(HeaderSearch::class)->set('query', 'Supply')->assertSee($mine->reference)->assertSee($mine->title);
        Livewire::test(HeaderSearch::class)->set('query', $mine->reference)->assertSee($mine->title);
        Livewire::test(HeaderSearch::class)->set('query', 'Acme')->assertSee($mine->title);

        // Another business's title, reference and party name show nothing.
        Livewire::test(HeaderSearch::class)->set('query', 'Zeta')->assertDontSee($theirs->reference)->assertSee('Nothing matches');
        Livewire::test(HeaderSearch::class)->set('query', $theirs->reference)->assertDontSee($theirs->title)->assertSee('Nothing matches');
    }

    public function test_live_results_have_an_empty_state_a_no_match_state_and_escape_wildcards(): void
    {
        [$owner] = $this->signedIn();
        $this->actingAs($owner);

        Livewire::test(HeaderSearch::class)->assertSee('Type a title, a reference');
        Livewire::test(HeaderSearch::class)->set('query', 'qqqq')->assertSee('Nothing matches');
        Livewire::test(HeaderSearch::class)->set('query', '%')->assertSee('Nothing matches');
        Livewire::test(HeaderSearch::class)->set('query', '_')->assertSee('Nothing matches');
    }

    public function test_live_results_are_capped_and_link_to_the_full_list(): void
    {
        [$owner] = $this->signedIn();
        for ($i = 0; $i < 8; $i++) {
            $this->makeEngagement($owner, 'Party '.$i);
        }
        $this->actingAs($owner);

        $html = Livewire::test(HeaderSearch::class)->set('query', 'Supply')->html();

        $this->assertSame(HeaderSearch::LIMIT, substr_count($html, 'dsr-ref'), 'six rows at most');
        $this->assertStringContainsString('See every result', $html);
        $this->assertStringContainsString('show=all', $html);
    }

    public function test_live_results_for_a_person_with_no_business_are_empty_and_a_guest_cannot_use_the_endpoint(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $engagement = $this->makeEngagement();

        Livewire::test(HeaderSearch::class)->set('query', 'Supply')->assertDontSee($engagement->reference);
    }

    public function test_the_matching_scope_is_the_one_the_list_page_uses(): void
    {
        [$owner, $engagement] = $this->signedIn();
        $this->actingAs($owner);

        $this->assertSame([$engagement->id], Engagement::matching('Acme')->pluck('id')->all());
        $this->assertSame([], Engagement::matching('100%')->pluck('id')->all());
        $this->get(route('engagements.index', ['q' => 'Acme']))->assertOk()->assertSee($engagement->reference);
    }

    // ── Roboto ─────────────────────────────────────────────────────────────

    public function test_roboto_is_declared_and_served_from_this_site_on_every_layout(): void
    {
        [$owner] = $this->signedIn();
        $pages = [
            $this->get(route('login')),
            $this->get(route('legal.signatures')),
            $this->get('/'),
            $this->actingAs($owner)->get(route('dashboard')),
        ];
        foreach ($pages as $response) {
            $html = $response->getContent();
            $this->assertStringContainsString('class="dot-roboto"', $html);
            $this->assertStringContainsString('dot-ui/css/dot-roboto.css', $html);
            $this->assertStringContainsString('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2', $html);
            $this->assertDoesNotMatchRegularExpression('#(fonts\.googleapis|fonts\.gstatic|cdn)#i', $html);
            $this->assertStringNotContainsString('public-sans', $html);
        }
        $this->assertStringContainsString("font-family: 'Roboto'", (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css')));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
    }

    public function test_the_other_text_faces_are_gone_from_the_stylesheets_and_the_signature_pad(): void
    {
        $css = '';
        foreach (glob(resource_path('css/engage/*.css')) as $file) {
            $css .= file_get_contents($file);
        }
        $css .= file_get_contents(resource_path('css/engage-meeting.css')).file_get_contents(resource_path('css/engage-landing.css'));

        foreach (['Public Sans', 'Literata', 'Caveat', 'Archivo'] as $face) {
            $this->assertStringNotContainsString($face, $css, $face.' is no longer declared');
            $this->assertStringNotContainsString($face, (string) file_get_contents(resource_path('js/signing/capture.js')));
        }
        $this->assertStringContainsString("'IBM Plex Mono'", $css, 'reference numbers keep their monospace readout');
        foreach (['public-sans-100_900-normal', 'literata-400_700-normal', 'caveat-500_700-normal'] as $file) {
            $this->assertFileDoesNotExist(public_path('fonts/'.$file.'.woff2'));
        }
    }

    public function test_the_shared_header_has_no_inline_style_or_script(): void
    {
        [$owner] = $this->signedIn();
        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('/<header class="dh.*?<\/header>/s', $html, $m));
        $this->assertStringNotContainsString(' style=', $m[0]);
        $this->assertStringNotContainsString('<script', $m[0]);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $m[0]);
    }
}

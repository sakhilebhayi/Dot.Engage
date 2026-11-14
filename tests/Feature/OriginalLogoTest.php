<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The platform logo is original artwork. It is shown, never redrawn, recoloured or replaced: only its size and the
 * light/dark file choice are presentation. These tests pin the files and keep invented brand drawings out of the layouts.
 */
class OriginalLogoTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO_SHA256 = '3ac4e027b986075780b928e6930c1961230bebff8ad19e6ad5b8589793ac0c12';

    private const LOGO_LIGHT_SHA256 = '17ceb5e346b20b54bd294c57baf5c29a7da70c28a7403b6ed3c1bfbe1b5ec290';

    /** git blob hashes of the first commits that introduced each file */
    private const LOGO_BLOB = 'daa98835bb9d578f40ff8f0cd396ffbee7d3ceee';

    private const LOGO_LIGHT_BLOB = '4024c2883f56c43890e5b346345cb7f4d6449570';

    public function test_logo_files_are_the_original_artwork_byte_for_byte(): void
    {
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', public_path('images/logo.png')));
        $this->assertSame(self::LOGO_LIGHT_SHA256, hash_file('sha256', public_path('images/logo-light.png')));
        $this->assertSame(self::LOGO_BLOB, $this->gitBlob(public_path('images/logo.png')));
        $this->assertSame(self::LOGO_LIGHT_BLOB, $this->gitBlob(public_path('images/logo-light.png')));
    }

    private function gitBlob(string $path): string
    {
        $data = file_get_contents($path);

        return sha1('blob '.strlen($data)."\0".$data);
    }

    public function test_no_view_contains_the_old_inline_mark(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/components/mark.blade.php'));

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();
            $this->assertStringNotContainsString('<x-mark', $source, $file->getRelativePathname());
            $this->assertStringNotContainsString('components.mark', $source, $file->getRelativePathname());
            // the seal ring drawn for the old mark: circles at r=12 and r=6.2 with a wax dot at 25.5,7.5
            $this->assertDoesNotMatchRegularExpression('/cx="25\.5\d*"\s+cy="7\.5/', $source, $file->getRelativePathname());
            $this->assertStringNotContainsString('viewBox="0 0 32 32"', $source, $file->getRelativePathname());
        }
    }

    /** @return array<string, array{string}> */
    public static function publicPages(): array
    {
        return [
            'welcome page' => ['/'],
            'sign-in (guest layout)' => ['/login'],
            'refusal / error layout' => ['/_test-only-error-render/404'],
            'refusal 403' => ['/_test-only-error-render/403'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_layouts_show_the_real_logo(string $path): void
    {
        $this->assertShowsRealLogo($this->get($path)->getContent());
    }

    public function test_signed_in_layout_shows_the_real_logo(): void
    {
        $user = User::factory()->create();
        $team = $user->ownedTeams()->create(['name' => 'Logo Co', 'personal_team' => false]);
        $user->switchTeam($team);

        $this->assertShowsRealLogo($this->actingAs($user->fresh())->get('/dashboard')->assertOk()->getContent());
    }

    public function test_plain_layout_and_jetstream_logo_components_show_the_real_logo(): void
    {
        $this->assertShowsRealLogo(Blade::render('<x-plain-layout>x</x-plain-layout>'));
        $this->assertShowsRealLogo(Blade::render('<x-application-logo />'));
        $this->assertShowsRealLogo(Blade::render('<x-application-mark />'));
        $this->assertShowsRealLogo(Blade::render('<x-authentication-card-logo />'));
    }

    public function test_mail_header_shows_the_real_logo_by_absolute_url(): void
    {
        $markdown = app(Markdown::class);
        $markdown->render('emails.team-invitation', ['acceptUrl' => 'https://engage.test/x', 'invitation' => (object) ['team' => (object) ['name' => 'Co']]]);
        $html = (string) view('mail::message', ['slot' => 'Hello'])->render();

        $this->assertMatchesRegularExpression('#<img src="https?://[^"]+/images/logo-light\.png" class="logo" alt="Dot\.Engage">#', $html);
        $this->assertStringNotContainsString('laravel.com/img', $html);
    }

    private function assertShowsRealLogo(string $html): void
    {
        $this->assertMatchesRegularExpression('#<img class="logo-on-light" src="[^"]*/images/logo\.png" alt="Dot\.Engage" width="\d+" height="\d+">#', $html);
        $this->assertMatchesRegularExpression('#<img class="logo-on-dark" src="[^"]*/images/logo-light\.png"[^>]* width="\d+" height="\d+"[^>]*>#', $html);

        // no drawn brand mark: the only inline <svg> elements allowed in a layout are the shared icon sprite and <use> references to it
        preg_match_all('#<svg\b[^>]*>(.*?)</svg>#s', $html, $svgs);
        foreach ($svgs[0] as $svg) {
            if (str_contains($svg, '<symbol') || str_contains($svg, 'aria-label="Seal of')) {
                continue; // the shared icon sprite and the agreement-evidence seal illustration, neither is the brand mark
            }
            $this->assertStringNotContainsString('<circle', $svg, 'A drawn circle mark is back in a layout.');
        }
    }
}

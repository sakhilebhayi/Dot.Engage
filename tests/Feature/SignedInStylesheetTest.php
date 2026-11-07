<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The signed-in layout used to load the Tailwind Play CDN script (v3) and a second
 * copy of Alpine from unpkg at run time. It now loads the Vite-built stylesheet and
 * script, and Livewire's own Alpine is the only one on the page.
 */
class SignedInStylesheetTest extends TestCase
{
    use RefreshDatabase;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // TestCase::setUp() stubs Vite out (withoutVite); put a real one back, reading a manifest
        // in a throw-away public path, so the test sees the tags the layout really emits.
        $this->publicPath = sys_get_temp_dir().'/engage-signed-in-vite-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->publicPath.'/build/assets');
        File::put($this->publicPath.'/build/manifest.json', json_encode([
            'resources/css/engage.css' => ['file' => 'assets/engage-TEST.css', 'src' => 'resources/css/engage.css', 'isEntry' => true],
            'resources/css/signed-in.css' => ['file' => 'assets/signed-in-TEST.css', 'src' => 'resources/css/signed-in.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/app-TEST.js', 'src' => 'resources/js/app.js', 'isEntry' => true],
            'resources/js/engage.js' => ['file' => 'assets/engage-TEST.js', 'src' => 'resources/js/engage.js', 'isEntry' => true],
        ]));
        $this->app->usePublicPath($this->publicPath);
        $this->app->instance(Vite::class, new Vite);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_signed_in_pages_load_the_built_assets_and_no_runtime_cdn_scripts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('build/assets/engage-TEST.css', $html);
        $this->assertStringContainsString('build/assets/signed-in-TEST.css', $html);
        $this->assertStringContainsString('build/assets/app-TEST.js', $html);
        $this->assertStringContainsString('build/assets/engage-TEST.js', $html);

        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
        $this->assertStringNotContainsString('unpkg.com', $html);
        $this->assertStringNotContainsString('tailwind.config', $html);
        // Alpine arrives with @livewireScripts; no page script may load another one.
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src="[^"]*alpine/i', $html);
        $this->assertStringContainsString('/livewire/livewire', $html);
    }

    public function test_the_design_system_loads_before_the_utility_sheet_that_the_jetstream_pages_still_use(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'signed-in-TEST.css'), strpos($html, 'engage-TEST.css'));
        // Fonts are self-hosted: nothing asks Google for them.
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function test_every_signed_in_page_uses_the_layout_that_loads_the_build(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        foreach (['/dashboard', '/chat', '/engagements', '/engagements/create', '/agreements', '/meetings', '/contracts/templates', '/notifications', '/user/profile', '/teams/create'] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('build/assets/signed-in-TEST.css', $html, "$url does not load the signed-in stylesheet");
            $this->assertStringNotContainsString('cdn.tailwindcss.com', $html, "$url still loads the Tailwind CDN script");
            $this->assertStringNotContainsString('unpkg.com', $html, "$url still loads a script from unpkg");
        }
    }

    public function test_the_pagination_classes_are_listed_in_the_stylesheet_source(): void
    {
        // Livewire's pagination view lives in vendor/, which the deploy's frontend build never
        // sees, so its classes are spelled out in resources/css/signed-in.css (@source inline).
        // If a Livewire update changes the view, this fails until the list is refreshed.
        $css = File::get(base_path('resources/css/signed-in.css'));
        preg_match_all("/@source inline\\('([^']*)'\\);/", $css, $matches);
        $listed = preg_split('/\s+/', trim(implode(' ', $matches[1])));

        foreach (['tailwind', 'simple-tailwind'] as $view) {
            $path = base_path("vendor/livewire/livewire/src/Features/SupportPagination/views/{$view}.blade.php");
            if (! File::exists($path)) {
                $this->markTestSkipped('Livewire pagination view not found.');
            }

            preg_match_all('/class="([^"]*)"/', File::get($path), $classAttributes);
            $used = [];
            foreach ($classAttributes[1] as $attribute) {
                foreach (preg_split('/\s+/', trim($attribute)) as $class) {
                    if ($class !== '' && ! str_contains($class, '{{') && ! str_contains($class, '}}') && ! str_starts_with($class, '@')) {
                        $used[] = $class;
                    }
                }
            }

            $this->assertSame([], array_values(array_diff(array_unique($used), $listed)), "resources/css/signed-in.css is missing classes used by livewire::{$view}");
        }
    }

    public function test_the_signed_in_stylesheet_does_not_import_preflight_or_the_plugins(): void
    {
        // The CDN pages had preflight off and no forms/typography plugin; importing either would
        // restyle every button, input and heading (see the header of the file).
        $css = File::get(base_path('resources/css/signed-in.css'));
        $code = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertDoesNotMatchRegularExpression("/@import\\s+['\"]tailwindcss['\"]/", $code);
        $this->assertStringNotContainsString('preflight', $code);
        $this->assertStringNotContainsString('@plugin', $code);
    }
}

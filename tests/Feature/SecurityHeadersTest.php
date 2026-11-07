<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baseline browser protections are on every response, a route's own stricter header is kept, and the report-only policy reports to /csp-report. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_baseline_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Meetings: camera, microphone and screen sharing for this site only, the rest stays denied.
        $this->assertSame(
            'camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(), usb=()',
            $response->headers->get('Permissions-Policy'),
        );
        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        config(['security.hsts_seconds' => 15552000]);

        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');

        $secure = $this->get('https://localhost/');
        $this->assertSame('max-age=15552000', $secure->headers->get('Strict-Transport-Security'), 'max-age only: no includeSubDomains, no preload');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/')->headers->has('Strict-Transport-Security'));
    }

    public function test_a_stricter_header_set_by_the_route_is_not_overwritten(): void
    {
        Route::get('/__strict', fn () => response('x')->header('X-Frame-Options', 'DENY'));

        $this->get('/__strict')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_policy_is_enforced_and_reports_to_the_csp_endpoint(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_the_kill_switch_makes_the_policy_report_only_again(): void
    {
        config(['security.csp_report_only' => true]);

        $response = $this->get('/');

        $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_nothing_is_loaded_from_outside_this_site_except_the_default_avatar(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

        preg_match('/script-src ([^;]+);/', $policy, $script);
        $this->assertStringNotContainsString('http', $script[1], 'no outside script origin');
        $this->assertStringContainsString("'unsafe-inline'", $script[1], 'Livewire and Jetstream render inline scripts');
        $this->assertStringContainsString("'unsafe-eval'", $script[1], 'Alpine evaluates its expressions with the Function constructor');

        $this->assertDoesNotMatchRegularExpression('/style-src [^;]*https:/', $policy, 'no Google Fonts stylesheet');
        $this->assertDoesNotMatchRegularExpression('/font-src [^;]*https:/', $policy);
        $this->assertMatchesRegularExpression('/img-src [^;]*data: blob:[^;]*https:\/\/ui-avatars\.com/', $policy, "Jetstream's default avatar");
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("frame-src 'self';", $policy);
        $this->assertStringNotContainsString('daily.co', $policy);
        $this->assertStringContainsString("worker-src 'self' blob:", $policy, 'the PDF reader runs in a worker');
    }

    public function test_violation_reports_are_accepted_without_a_session_a_csrf_token_or_a_login(): void
    {
        Log::spy();
        $body = json_encode(['csp-report' => [
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/steal?token=SECRET123',
            'document-uri' => 'https://engage.example/x?code=SECRET456',
        ]]);

        // The framework skips CSRF verification while the environment is "testing"; switch it so the route's exemption is really exercised.
        $this->app['env'] = 'local';

        $first = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);
        $first->assertNoContent();
        $this->assertSame([], $first->headers->getCookies(), 'a report starts no session and sets no cookie');
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();   // the same pair is logged once a day

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'csp.violation' && $context === ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example'])
            ->once();
    }

    public function test_the_report_endpoint_accepts_json_and_both_report_shapes_and_ignores_garbage(): void
    {
        $this->postJson('/csp-report', ['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'inline']])->assertSuccessful();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], 'not json at all')->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'https://images.example/a.png']],
        ]))->assertNoContent();
    }

    public function test_the_report_endpoint_logs_only_the_directive_and_the_host(): void
    {
        Log::spy();
        $this->postJson('/csp-report', ['csp-report' => [
            'effective-directive' => 'font-src',
            'blocked-uri' => 'https://fonts.other.example/a.woff2?key=SECRET789',
            'script-sample' => 'SECRET-SAMPLE',
        ]])->assertNoContent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'csp.violation' && $context === ['directive' => 'font-src', 'blocked_host' => 'fonts.other.example'])
            ->once();
    }

    public function test_the_report_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/csp-report', ['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'inline']])->assertNoContent();
        }

        $this->postJson('/csp-report', ['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'inline']])->assertStatus(429);
    }
}

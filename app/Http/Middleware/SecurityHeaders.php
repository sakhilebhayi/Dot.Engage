<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone, so a stricter route-level
 * value (for example the contract PDF responses, which set their own X-Frame-Options) always wins.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS only) Strict-Transport-Security.
 * HSTS is max-age only, with no includeSubDomains and no preload: this domain has many sibling subdomains that are not all ready for it.
 * SECURITY_HSTS_SECONDS in .env changes the age, and 0 turns the header off (see config/security.php).
 *
 * The Content-Security-Policy is REPORT-ONLY (Content-Security-Policy-Report-Only): the browser reports what the policy would have blocked to
 * /csp-report (App\Http\Controllers\CspReportController) and blocks nothing. It is written to be compatible with Livewire and Jetstream, which
 * need inline scripts and 'unsafe-eval' (Alpine evaluates its expressions with the Function constructor). The listed outside origins are the ones
 * the Blade layouts actually load; add one only after a layout starts using it. Enforcing the policy is a later step, taken from the reports.
 */
class SecurityHeaders
{
    /**
     * Meetings use the camera, the microphone and screen sharing, in this page and in no other. Everything else stays denied.
     */
    private const PERMISSIONS_POLICY = 'camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            // Enforced. SECURITY_CSP_REPORT_ONLY=true switches it back to report-only without a deploy of code.
            (config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy(),
        ];

        $hstsSeconds = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hstsSeconds > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hstsSeconds;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Everything is served from this site: scripts, styles, fonts and the PDF worker. 'unsafe-inline' and 'unsafe-eval' stay on
     * script-src because Livewire and Alpine need them. The one outside origin is ui-avatars.com, Jetstream's default profile photo.
     */
    private function policy(): string
    {
        return "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."font-src 'self'; "
            ."img-src 'self' data: blob: https://ui-avatars.com; "
            ."media-src 'self' blob:; "
            ."worker-src 'self' blob:; "
            // Local development talks to Reverb over plaintext ws:; production websockets are wss: only.
            ."connect-src 'self'".(app()->isLocal() ? ' ws:' : '').'; '
            ."frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; "
            ."form-action 'self' https://infodot.co.za https://*.infodot.co.za; "
            .'report-uri /csp-report';
    }
}

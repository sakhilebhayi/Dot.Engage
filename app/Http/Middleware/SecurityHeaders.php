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
     * The in-app video calls (Daily.co) need the camera, the microphone and screen sharing, so Engage allows them for itself and for the
     * Daily call frame: Daily's call is embedded as a cross-origin iframe, and a page that allows a feature for 'self' alone also blocks it
     * inside that frame. Everything else stays denied.
     */
    private const PERMISSIONS_POLICY = 'camera=(self "https://*.daily.co"), microphone=(self "https://*.daily.co"), display-capture=(self "https://*.daily.co"), geolocation=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Content-Security-Policy-Report-Only' => $this->policy(),
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
     * Outside origins in use: unpkg.com (Alpine on the landing page), Google Fonts (stylesheet host and font host), Unsplash (landing and
     * sign-in photographs), ui-avatars.com (Jetstream's HasProfilePhoto default avatar) and *.daily.co, the embedded video call frame.
     */
    private function policy(): string
    {
        return "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            ."font-src 'self' data: https://fonts.gstatic.com; "
            ."img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com; "
            ."media-src 'self' blob:; "
            // Local development talks to Reverb over plaintext ws:; production websockets are wss: only.
            ."connect-src 'self' wss: https:".(app()->isLocal() ? ' ws:' : '').'; '
            ."frame-src 'self' https://*.daily.co; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; "
            .'report-uri /csp-report';
    }
}

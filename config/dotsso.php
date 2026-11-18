<?php

use App\Sso\EngageProvisioner;

/*
 * Dot.Engage's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 *
 * Outside parties (invitees, signers, meeting guests) are NOT logins. They enter through /join/{token} and an emailed
 * code, and /e/{reference}, /verify and the signing pages stay exactly as they are: the package only takes over
 * /login, /register, /forgot-password and /reset-password, none of which an outside party ever uses.
 */
return [
    'provisioner' => EngageProvisioner::class,

    // Engage has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may be any same-origin path (signed-in pages, workspaces at /e/ENG-..., team invitations) except the
    // package's own and the sign-in pages. /join is never a deep link: an outside party's link is not a sign-in.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|join)(/|\?|$)#i'],
    ],

    // Never intercepted: nothing extra. The machine doors (Sanctum, /up, /guardian, /csp-report) are outside the pages the
    // package takes over, and /join, /verify and /e/* are not auth pages at all.
    'allow_paths' => [],
];
